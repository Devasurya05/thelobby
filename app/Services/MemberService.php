<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

/**
 * Organiser actions on other people: remove a member, change a role.
 *
 * Same pattern as BedService: one transaction, row locks, failures returned as results (not thrown)
 * so the audit row of a refused attempt is still saved.
 *
 * LOCK ORDER matches BedService: the person's MEMBER row first, then their bed rows. Using the same
 * order everywhere is what keeps two different actions from deadlocking each other.
 */
class MemberService
{
    // Roles an organiser may hand out. "organiser" is deliberately not on the list: one organiser per trip.
    public const ASSIGNABLE_ROLES = [Member::ROLE_MEMBER, Member::ROLE_VIEWER];

    /**
     * Remove someone. Their cookie stops working on their very next request (ResolveMember checks
     * removed_at every time). Their bed hold or confirmed bed is freed so others can use it.
     */
    public function remove(Trip $trip, Member $actor, int $targetId): ActionResult
    {
        return DB::transaction(function () use ($trip, $actor, $targetId) {
            if ($problem = $this->cannotManage($trip, $actor, 'remove_member', $targetId)) {
                return $problem;
            }

            $target = $this->lockTarget($trip, $targetId);
            if ($target === null) {
                return ActionResult::fail('That person is not in this trip.', 404);
            }
            if ($target->isOrganiser()) {
                $this->refuse($trip, $actor, 'remove_member', $target, 'target_is_organiser');

                return ActionResult::fail('The organiser cannot be removed.', 422);
            }
            if ($target->isRemoved()) {
                return ActionResult::fail($target->name.' was already removed.', 409);
            }

            // Free everything they had: a hold AND a confirmed bed (a ghost with a bed would block it forever).
            $freed = Bed::where('trip_id', $trip->id)
                ->where(fn ($q) => $q->where('held_by_member_id', $target->id)->orWhere('taken_by_member_id', $target->id))
                ->lockForUpdate()
                ->get();

            foreach ($freed as $bed) {
                $wasTaken = $bed->status === Bed::TAKEN;
                $bed->forceFill([
                    'status' => Bed::FREE,
                    'held_by_member_id' => null,
                    'taken_by_member_id' => null,
                    'hold_expires_at' => null,
                ])->save();
                AuditLog::record($trip->id, $actor->id, 'bed_released', 'bed', $bed->id, [
                    'reason' => 'member_removed',
                    'member_id' => $target->id,
                    'was' => $wasTaken ? 'taken' : 'held',
                ]);
            }

            // forceFill: removed_at is not mass-assignable on purpose.
            $target->forceFill(['removed_at' => now()])->save();
            AuditLog::record($trip->id, $actor->id, 'member_removed', 'member', $target->id, ['name' => $target->name]);

            return ActionResult::ok($target->name.' was removed from the trip.');
        }, 3);
    }

    /** Switch someone between member and viewer. A viewer cannot pick beds, so their open hold is released. */
    public function changeRole(Trip $trip, Member $actor, int $targetId, string $role): ActionResult
    {
        return DB::transaction(function () use ($trip, $actor, $targetId, $role) {
            if ($problem = $this->cannotManage($trip, $actor, 'change_role', $targetId)) {
                return $problem;
            }

            if (! in_array($role, self::ASSIGNABLE_ROLES, true)) {
                return ActionResult::fail('Pick member or viewer.', 422);
            }

            $target = $this->lockTarget($trip, $targetId);
            if ($target === null) {
                return ActionResult::fail('That person is not in this trip.', 404);
            }
            if ($target->isOrganiser()) {
                $this->refuse($trip, $actor, 'change_role', $target, 'target_is_organiser');

                return ActionResult::fail("The organiser's role cannot be changed.", 422);
            }
            if ($target->isRemoved()) {
                return ActionResult::fail($target->name.' was removed from the trip.', 409);
            }
            if ($target->role === $role) {
                return ActionResult::ok($target->name.' is already a '.$role.'.');
            }

            $from = $target->role;
            $target->forceFill(['role' => $role])->save();

            if ($role === Member::ROLE_VIEWER) {
                $holds = Bed::where('trip_id', $trip->id)->where('status', Bed::HELD)
                    ->where('held_by_member_id', $target->id)->lockForUpdate()->get();
                foreach ($holds as $bed) {
                    $bed->forceFill(['status' => Bed::FREE, 'held_by_member_id' => null, 'hold_expires_at' => null])->save();
                    AuditLog::record($trip->id, $actor->id, 'bed_released', 'bed', $bed->id, [
                        'reason' => 'became_viewer',
                        'member_id' => $target->id,
                    ]);
                }
            }

            AuditLog::record($trip->id, $actor->id, 'role_changed', 'member', $target->id, [
                'name' => $target->name, 'from' => $from, 'to' => $role,
            ]);

            return ActionResult::ok($target->name.' is now a '.$role.'.');
        }, 3);
    }

    // ---- helpers -----------------------------------------------------------------------------------

    // Only an ACTIVE organiser may do this. The actor row is re-read so a stale object cannot slip through,
    // and a refused attempt (say a member poking at the URL by hand) is audited.
    private function cannotManage(Trip $trip, Member $actor, string $attempted, int $targetId): ?ActionResult
    {
        $fresh = Member::where('trip_id', $trip->id)->whereKey($actor->id)->first();

        if ($fresh === null || $fresh->isRemoved() || ! $fresh->isOrganiser()) {
            AuditLog::record($trip->id, $actor->id, 'action_refused', 'member', $targetId, [
                'attempted' => $attempted,
                'reason' => 'not_organiser',
            ]);

            return ActionResult::fail('Only the organiser can do that.', 403);
        }

        return null;
    }

    // Scoped to the trip, so an id from another trip finds nothing (404, not "forbidden": no hints).
    private function lockTarget(Trip $trip, int $targetId): ?Member
    {
        return Member::where('trip_id', $trip->id)->whereKey($targetId)->lockForUpdate()->first();
    }

    private function refuse(Trip $trip, Member $actor, string $attempted, Member $target, string $reason): void
    {
        AuditLog::record($trip->id, $actor->id, 'action_refused', 'member', $target->id, [
            'attempted' => $attempted,
            'reason' => $reason,
        ]);
    }
}
