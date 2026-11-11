<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;

/**
 * Builds the JSON the lobby screen draws: every room, every bed, who has it, and what "you" can do.
 *
 * One source of truth: this reads straight from the database every time, and every phone
 * asks for it every ~2 seconds, so everybody sees the same thing.
 */
class LobbyState
{
    public static function build(Trip $trip, Member $member): array
    {
        // Eager loading: 3 queries in total instead of one per bed (the "N+1 problem").
        $rooms = $trip->rooms()
            ->with(['beds' => fn ($q) => $q->orderBy('id'), 'beds.heldBy:id,name', 'beds.takenBy:id,name'])
            ->orderBy('id')
            ->get();

        $counts = ['free' => 0, 'held' => 0, 'taken' => 0];
        $takenLabel = null;
        $heldBedId = null;

        $roomData = [];
        foreach ($rooms as $room) {
            $beds = [];
            foreach ($room->beds as $bed) {
                // An expired hold shows as free even before the scheduler has cleaned it up.
                $status = $bed->holdHasExpired() ? Bed::FREE : $bed->status;
                $counts[$status]++;

                $mine = $bed->held_by_member_id === $member->id && $status === Bed::HELD
                    || $bed->taken_by_member_id === $member->id;

                if ($bed->taken_by_member_id === $member->id) {
                    $takenLabel = $room->name.' · '.$bed->label;
                }
                if ($mine && $status === Bed::HELD) {
                    $heldBedId = $bed->id;
                }

                $beds[] = [
                    'id' => $bed->id,
                    'label' => $bed->label,
                    'status' => $status,
                    // Names are sent as plain data; the browser inserts them with .text(), never as HTML.
                    'by' => match ($status) {
                        Bed::HELD => $bed->heldBy?->name,
                        Bed::TAKEN => $bed->takenBy?->name,
                        default => null,
                    },
                    'mine' => $mine,
                    // Seconds instead of a timestamp, so a phone with a wrong clock still counts down correctly.
                    'seconds_left' => $status === Bed::HELD
                        ? max(0, $bed->hold_expires_at->timestamp - now()->timestamp)
                        : null,
                ];
            }
            $roomData[] = ['id' => $room->id, 'name' => $room->name, 'beds' => $beds];
        }

        return [
            'trip' => ['name' => $trip->name, 'ended' => $trip->hasEnded()],
            'me' => [
                'name' => $member->name,
                'role' => $member->role,
                'can_pick' => $member->canPickBeds(),
                'taken_label' => $takenLabel,
                'held_bed_id' => $heldBedId,
            ],
            'counts' => $counts,
            'rooms' => $roomData,
        ];
    }
}
