<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The heart of The Lobby: grabbing, confirming, releasing and expiring beds.
 *
 * THE BIG IDEA (read this first)
 * Two people can tap the same bed at the same moment. Each tap is a separate web request, and
 * with PHP-FPM those requests really do run in parallel. Without protection both could read
 * "bed is free" and both write "bed is mine": a double booking.
 *
 * The protection is a database row lock:
 *   DB::transaction(...)  +  ->lockForUpdate()   (SQL: SELECT ... FOR UPDATE)
 * The first request locks the bed's row. The second request's SELECT ... FOR UPDATE simply WAITS
 * until the first one commits, and then sees the up-to-date row ("held by someone else") and is
 * rejected. One key to the room: whoever holds the key decides, everyone else queues.
 *
 * LOCK ORDER (also important): always lock the MEMBER row first, then bed rows.
 *  - Locking the member serialises that one person's own actions, so a double-tap on two
 *    different beds cannot leave them holding both.
 *  - Two people swapping beds at the same instant can still deadlock (each waits for the other's
 *    row). MySQL notices and kills one of them; the "3" passed to DB::transaction() makes Laravel
 *    simply run the whole closure again.
 *
 * FastAPI comparison: this is what you would do with SQLAlchemy's `with_for_update()` inside
 * `async with session.begin():`.
 */
class BedService
{
    /** Tap a free bed: hold it for HOLD_SECONDS. Taking a new bed releases your previous hold. */
    public function grab(Trip $trip, Member $member, int $bedId): BedResult
    {
        return DB::transaction(function () use ($trip, $member, $bedId) {
            $member = $this->lockMember($member);

            if ($problem = $this->cannotPlay($trip, $member, 'grab', $bedId)) {
                return $problem;
            }

            // Lock this bed's row. Scoped to the trip, so a bed id from another trip finds nothing.
            $bed = $this->lockBed($trip, $bedId);
            if ($bed === null) {
                return BedResult::fail('That bed does not exist.', 404);
            }

            // Everyone may take at most one bed (the UNIQUE index on beds.taken_by_member_id
            // enforces the same rule in the database even if this check were ever wrong).
            if ($this->hasTakenBed($member)) {
                $this->log($trip, $member, 'grab_rejected', $bed, ['reason' => 'already_has_bed']);

                return BedResult::fail('You already have a bed.');
            }

            if (! $bed->isAvailable()) {
                // Double-tap on a bed you already hold: nothing to change, nothing to complain about.
                if ($bed->status === Bed::HELD && $bed->held_by_member_id === $member->id) {
                    return BedResult::ok('You are already holding this bed.');
                }

                // Somebody else got here first (this is the losing side of a race).
                $this->log($trip, $member, 'grab_rejected', $bed, [
                    'reason' => $bed->status === Bed::TAKEN ? 'taken' : 'held_by_someone_else',
                    'held_by_member_id' => $bed->held_by_member_id,
                    'taken_by_member_id' => $bed->taken_by_member_id,
                ]);

                return BedResult::fail('Someone just grabbed this bed. Pick another one.');
            }

            // An expired hold counts as free. Record that the old hold ran out before we overwrite it.
            if ($bed->holdHasExpired()) {
                $this->log($trip, $bed->held_by_member_id, 'hold_expired', $bed);
            }

            // One active hold per person: letting go of the previous one (and locking its row too).
            $oldHolds = Bed::where('trip_id', $trip->id)
                ->where('status', Bed::HELD)
                ->where('held_by_member_id', $member->id)
                ->where('id', '!=', $bed->id)
                ->lockForUpdate()
                ->get();

            foreach ($oldHolds as $old) {
                $this->free($old);
                $this->log($trip, $member, 'bed_released', $old, ['reason' => 'picked_another_bed']);
            }

            $seconds = config('lobby.hold_seconds');
            // forceFill: status/held_by are not mass-assignable on purpose (see Bed model).
            $bed->forceFill([
                'status' => Bed::HELD,
                'held_by_member_id' => $member->id,
                'hold_expires_at' => now()->addSeconds($seconds),
            ])->save();

            $this->log($trip, $member, 'bed_held', $bed, ['hold_seconds' => $seconds]);

            return BedResult::ok('Bed held. Confirm it before the timer runs out.');
        }, 3);
    }

    /** Confirm your held bed: it becomes yours permanently. */
    public function confirm(Trip $trip, Member $member, int $bedId): BedResult
    {
        return DB::transaction(function () use ($trip, $member, $bedId) {
            $member = $this->lockMember($member);

            if ($problem = $this->cannotPlay($trip, $member, 'confirm', $bedId)) {
                return $problem;
            }

            $bed = $this->lockBed($trip, $bedId);
            if ($bed === null) {
                return BedResult::fail('That bed does not exist.', 404);
            }

            if ($bed->status !== Bed::HELD || $bed->held_by_member_id !== $member->id) {
                $this->log($trip, $member, 'confirm_rejected', $bed, ['reason' => 'not_your_hold']);

                return BedResult::fail('You are not holding this bed.');
            }

            // Too late: time ran out. Free the bed now so everyone sees the truth.
            if ($bed->holdHasExpired()) {
                $this->free($bed);
                $this->log($trip, $member, 'hold_expired', $bed);

                return BedResult::fail('Your hold ran out. Grab the bed again if it is still free.');
            }

            if ($this->hasTakenBed($member)) {
                $this->log($trip, $member, 'confirm_rejected', $bed, ['reason' => 'already_has_bed']);

                return BedResult::fail('You already have a bed.');
            }

            $bed->forceFill([
                'status' => Bed::TAKEN,
                'taken_by_member_id' => $member->id,
                'held_by_member_id' => null,
                'hold_expires_at' => null,
            ])->save();

            $this->log($trip, $member, 'bed_confirmed', $bed);

            return BedResult::ok('Bed confirmed. It is yours!');
        }, 3);
    }

    /** Let go of a bed you are only holding (a confirmed bed cannot be released). */
    public function release(Trip $trip, Member $member, int $bedId): BedResult
    {
        return DB::transaction(function () use ($trip, $member, $bedId) {
            $member = $this->lockMember($member);

            if ($problem = $this->cannotPlay($trip, $member, 'release', $bedId)) {
                return $problem;
            }

            $bed = $this->lockBed($trip, $bedId);
            if ($bed === null) {
                return BedResult::fail('That bed does not exist.', 404);
            }

            if ($bed->status !== Bed::HELD || $bed->held_by_member_id !== $member->id) {
                $this->log($trip, $member, 'release_rejected', $bed, ['reason' => 'not_your_hold']);

                return BedResult::fail('You are not holding this bed.');
            }

            $expired = $bed->holdHasExpired();
            $this->free($bed);
            $this->log($trip, $member, $expired ? 'hold_expired' : 'bed_released', $bed);

            return BedResult::ok('Bed released.');
        }, 3);
    }

    /**
     * Run every minute by the scheduler: free every hold whose time is up and log it.
     * Safe to run twice or at the same time as a grab: each bed is re-checked under its lock.
     * If one bed fails we report it and carry on; the next run (a minute later) tries it again.
     *
     * @return int how many holds were released
     */
    public function expireHolds(): int
    {
        $released = 0;

        $ids = Bed::where('status', Bed::HELD)->where('hold_expires_at', '<=', now())->pluck('id');

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, &$released) {
                    $bed = Bed::whereKey($id)->lockForUpdate()->first();

                    // Re-check under the lock: the holder may have confirmed or released it a moment ago.
                    if ($bed === null || $bed->status !== Bed::HELD || ! $bed->holdHasExpired()) {
                        return;
                    }

                    $holderId = $bed->held_by_member_id;
                    $this->free($bed);
                    $this->log($bed->trip_id, $holderId, 'hold_expired', $bed);
                    $released++;
                }, 3);
            } catch (Throwable $e) {
                // Never silently skip: report() sends it to the error log, and the bed stays
                // expired-but-held, so the next scheduler run picks it up again.
                report($e);
            }
        }

        return $released;
    }

    // ---- small helpers -----------------------------------------------------------------------

    // Lock the member's own row first. Fresh copy, so we see the latest removed_at / role.
    private function lockMember(Member $member): Member
    {
        return Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
    }

    private function lockBed(Trip $trip, int $bedId): ?Bed
    {
        return Bed::where('trip_id', $trip->id)->whereKey($bedId)->lockForUpdate()->first();
    }

    private function hasTakenBed(Member $member): bool
    {
        return Bed::where('taken_by_member_id', $member->id)->exists();
    }

    // Reasons somebody may not touch beds at all. Returns a failure to hand back, or null if fine.
    // Every refusal is written to the audit log too (who tried what), because someone poking at
    // actions they are not allowed to do is exactly what an audit trail is for.
    private function cannotPlay(Trip $trip, Member $member, string $attempted, int $bedId): ?BedResult
    {
        $reason = match (true) {
            $member->isRemoved() => 'removed',
            ! $member->canPickBeds() => 'viewer',
            $trip->hasEnded() => 'trip_ended',
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        AuditLog::record($trip->id, $member->id, 'action_refused', 'bed', $bedId, [
            'attempted' => $attempted,
            'reason' => $reason,
        ]);

        return match ($reason) {
            'removed' => BedResult::fail('You have been removed from this trip.', 403),
            'viewer' => BedResult::fail('Viewers can watch the lobby but not pick beds.', 403),
            'trip_ended' => BedResult::fail('This trip has ended.'),
        };
    }

    private function free(Bed $bed): void
    {
        $bed->forceFill([
            'status' => Bed::FREE,
            'held_by_member_id' => null,
            'hold_expires_at' => null,
        ])->save();
    }

    // One place that writes the audit entry, in the same transaction as the change it describes.
    private function log(Trip|int $trip, Member|int|null $member, string $action, Bed $bed, array $details = []): void
    {
        AuditLog::record(
            $trip instanceof Trip ? $trip->id : $trip,
            $member instanceof Member ? $member->id : $member,
            $action,
            'bed',
            $bed->id,
            $details,
        );
    }
}
