<?php

namespace App\Http\Controllers;

use App\Services\LobbyState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LobbyController extends Controller
{
    // The trip page: the shell of the lobby. The beds themselves are drawn by jQuery from /state.
    public function show(Request $request)
    {
        return view('lobby', [
            // Put there by the ResolveMember middleware.
            'trip' => $request->attributes->get('trip'),
            'member' => $request->attributes->get('member'),
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
