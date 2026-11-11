<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

/**
 * Adding and removing lines of the trip plan. Only the organiser may change the plan; everyone in the
 * trip may read it (that is the controller's job). The role is checked here, against the database,
 * because the page-level middleware is only for tidiness.
 */
class ItineraryService
{
    /** @param  array<string, mixed>  $data  already validated by StoreItineraryItemRequest */
    public function add(Trip $trip, Member $actor, array $data): ActionResult
    {
        if ($problem = $this->cannotChange($trip, $actor, 'plan_add')) {
            return $problem;
        }

        return DB::transaction(function () use ($trip, $actor, $data) {
            // Lock the trip row so two quick taps cannot both pass the "not too many items" check.
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasEnded()) {
                return ActionResult::fail('This trip has ended, so the plan can no longer change.');
            }

            if ($locked->itineraryItems()->count() >= config('lobby.plan.max_items')) {
                return ActionResult::fail('The plan is full ('.config('lobby.plan.max_items').' items). Remove one first.');
            }

            $item = $locked->itineraryItems()->create($data + ['created_by_member_id' => $actor->id]);

            AuditLog::record($trip->id, $actor->id, 'plan_item_added', 'plan_item', $item->id, [
                'kind' => $item->kind,
                'title' => mb_substr($item->title, 0, 80),
            ]);

            return ActionResult::ok('Added to the plan.');
        }, 3);
    }

    public function delete(Trip $trip, Member $actor, int $itemId): ActionResult
    {
        if ($problem = $this->cannotChange($trip, $actor, 'plan_delete')) {
            return $problem;
        }

        return DB::transaction(function () use ($trip, $actor, $itemId) {
            // Scoped to this trip: an id from another trip finds nothing.
            $item = $trip->itineraryItems()->whereKey($itemId)->lockForUpdate()->first();

            if ($item === null) {
                return ActionResult::fail('That item is already gone.', 404);
            }

            // Items that came from a booking are owned by that booking: it would just put them back on its next change.
            if ($item->booking_id !== null) {
                return ActionResult::fail('That item comes from a booking. Remove the booking to remove it.');
            }

            $item->delete();

            AuditLog::record($trip->id, $actor->id, 'plan_item_deleted', 'plan_item', $itemId, [
                'kind' => $item->kind,
                'title' => mb_substr($item->title, 0, 80),
            ]);

            return ActionResult::ok('Removed from the plan.');
        }, 3);
    }

    // Same rule as the other services: look the person up fresh, so a just-removed or just-demoted organiser is stopped.
    private function cannotChange(Trip $trip, Member $actor, string $attempted): ?ActionResult
    {
        $fresh = Member::where('trip_id', $trip->id)->whereKey($actor->id)->first();

        if ($fresh === null || $fresh->isRemoved() || ! $fresh->isOrganiser()) {
            AuditLog::record($trip->id, $actor->id, 'action_refused', null, null, [
                'attempted' => $attempted,
                'reason' => 'not_organiser',
            ]);

            return ActionResult::fail('Only the organiser can change the plan.', 403);
        }

        return null;
    }
}
