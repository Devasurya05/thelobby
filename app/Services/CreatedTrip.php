<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Trip;

/** What creating a trip hands back: the trip, its organiser, and the organiser's plain login token (for the cookie). */
final class CreatedTrip
{
    public function __construct(
        public readonly Trip $trip,
        public readonly Member $organiser,
        public readonly string $token,
    ) {}
}
