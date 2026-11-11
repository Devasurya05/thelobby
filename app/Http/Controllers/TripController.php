<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateTripRequest;
use App\Services\TripService;
use App\Support\MemberToken;

class TripController extends Controller
{
    /** How long a "dates will come from a booking" trip keeps its placeholder dates if no booking ever arrives. */
    private const PLACEHOLDER_DAYS = 60;

    public function __construct(private TripService $trips) {}

    // Creates the trip, its rooms and beds, and the organiser, then logs the organiser in.
    public function store(CreateTripRequest $request)
    {
        if ($request->fromBooking()) {
            // No rooms and no real dates yet: the first booking sent to this trip's code fills them in.
            $created = $this->trips->create(
                $request->validated('trip_name'),
                $request->validated('organiser_name'),
                now()->toDateString(),
                now()->addDays(self::PLACEHOLDER_DAYS)->toDateString(),
                [],
                awaitingBooking: true,
            );
        } else {
            $created = $this->trips->create(
                $request->validated('trip_name'),
                $request->validated('organiser_name'),
                $request->validated('starts_on'),
                $request->validated('ends_on'),
                $request->rooms(),
            );
        }

        return redirect()
            ->route('lobby', $created->trip->join_code)
            ->withCookie(MemberToken::cookie($created->trip, $created->token));
    }
}
