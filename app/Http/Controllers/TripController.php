<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateTripRequest;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trip;
use App\Support\MemberToken;
use Illuminate\Support\Facades\DB;

class TripController extends Controller
{
    // Creates the trip, its rooms and beds, and the organiser, then logs the organiser in.
    public function store(CreateTripRequest $request)
    {
        $token = MemberToken::generate();

        // One transaction: either the whole trip is created or nothing is.
        // (Without it, a crash half-way could leave a trip with no organiser.)
        [$trip, $organiser] = DB::transaction(function () use ($request, $token) {
            $trip = Trip::create([
                'name' => $request->validated('trip_name'),
                'join_code' => Trip::newJoinCode(),
                'starts_on' => $request->validated('starts_on'),
                'ends_on' => $request->validated('ends_on'),
            ]);

            $organiser = $trip->members()->create([
                'name' => $request->validated('organiser_name'),
                'role' => Member::ROLE_ORGANISER,
                'token_hash' => MemberToken::hash($token), // only the hash is stored
            ]);

            foreach ($request->rooms() as $roomData) {
                $room = $trip->rooms()->create(['name' => $roomData['name']]);
                foreach ($roomData['beds'] as $label) {
                    $room->beds()->create(['trip_id' => $trip->id, 'label' => $label]);
                }
            }

            AuditLog::record($trip->id, $organiser->id, 'trip_created', 'trip', $trip->id, [
                'rooms' => count($request->rooms()),
            ]);

            return [$trip, $organiser];
        });

        return redirect()
            ->route('lobby', $trip->join_code)
            ->withCookie(MemberToken::cookie($trip, $token));
    }
}
