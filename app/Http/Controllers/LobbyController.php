<?php

namespace App\Http\Controllers;

use App\Services\LobbyState;
use App\Support\PlanLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LobbyController extends Controller
{
    // The trip page: the shell of the lobby. The beds themselves are drawn by jQuery from /state.
    public function show(Request $request)
    {
        $trip = $request->attributes->get('trip');

        // "Where" on the lobby page = the first stay on the plan (the hotel or villa). No separate place field
        // to keep in sync: the Plan is the one source of truth, and later the booking connection fills it.
        $stay = $trip->itineraryItems()
            ->where('kind', 'stay')
            ->where(fn ($q) => $q->whereNotNull('place')->orWhereNotNull('address'))
            ->orderBy('starts_on')->orderBy('starts_time')->orderBy('id')
            ->first();

        return view('lobby', [
            // Put there by the ResolveMember middleware.
            'trip' => $trip,
            'member' => $request->attributes->get('member'),
            'stay' => $stay,
            'mapLink' => $stay ? PlanLinks::map($stay->place, $stay->address) : null,
            'googleLink' => PlanLinks::googleCalendarForTrip($trip),
            'planCount' => $trip->itineraryItems()->count(),
        ]);
    }

    // Polled every ~2 seconds by every open lobby screen.
    public function state(Request $request): JsonResponse
    {
        return response()
            ->json(LobbyState::build($request->attributes->get('trip'), $request->attributes->get('member')))
            // Never let a browser or proxy reuse an old answer: stale beds would look like double booking.
            ->header('Cache-Control', 'no-store');
    }
}
