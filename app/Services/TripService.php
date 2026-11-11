<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trip;
use App\Support\MemberToken;
use Illuminate\Support\Facades\DB;

/**
 * Creates a trip with its organiser (and rooms). Used by the "Create a trip" form and by "create a trip for this
 * booking" on the booking sites, so both ways follow exactly the same steps.
 */
class TripService
{
    /**
     * @param  list<array{name: string, beds: list<string>}>  $rooms  may be empty (a trip that gets its rooms from a booking)
     * @param  array<string, scalar>  $auditExtra  extra facts for the "trip created" audit row
     */
    public function create(
        string $name,
        string $organiserName,
        string $startsOn,
        string $endsOn,
        array $rooms,
        bool $awaitingBooking = false,
        array $auditExtra = [],
    ): CreatedTrip {
        $token = MemberToken::generate();

        // One transaction: either the whole trip is created or nothing is.
        // (Without it, a crash half-way could leave a trip with no organiser.)
        [$trip, $organiser] = DB::transaction(function () use ($name, $organiserName, $startsOn, $endsOn, $rooms, $awaitingBooking, $token, $auditExtra) {
            $trip = Trip::create([
                'name' => $name,
                'join_code' => Trip::newJoinCode(),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);
            if ($awaitingBooking) {
                // Not mass-assignable on purpose: only this service decides it.
                $trip->forceFill(['awaiting_booking' => true])->save();
            }

            $organiser = $trip->members()->create([
                'name' => $organiserName,
                'role' => Member::ROLE_ORGANISER,
                'token_hash' => MemberToken::hash($token), // only the hash is stored
            ]);

            foreach ($rooms as $roomData) {
                $room = $trip->rooms()->create(['name' => $roomData['name']]);
                foreach ($roomData['beds'] as $label) {
                    $room->beds()->create(['trip_id' => $trip->id, 'label' => $label]);
                }
            }

            AuditLog::record($trip->id, $organiser->id, 'trip_created', 'trip', $trip->id, ['rooms' => count($rooms)] + $auditExtra);

            return [$trip, $organiser];
        });

        return new CreatedTrip($trip, $organiser, $token);
    }
}
