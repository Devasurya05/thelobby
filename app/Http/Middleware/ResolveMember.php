<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Models\Trip;
use App\Support\MemberToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before every /t/{code}/... route. Answers two questions:
 *   1. Which trip is this?       (from the code in the URL)
 *   2. Who is making the request? (from the token cookie)
 *
 * FastAPI comparison: this is like a dependency (Depends) that returns the current user,
 * except it is attached to a whole group of routes. Controllers read the results with
 * $request->attributes->get('trip') and ->get('member').
 *
 * Because the trip comes from the URL and the member must belong to THAT trip,
 * a token from one trip can never open another trip.
 */
class ResolveMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = strtoupper((string) $request->route('code'));

        $trip = Trip::where('join_code', $code)->first();
        abort_if($trip === null, 404);

        $token = $request->cookie(MemberToken::cookieName($code));

        // Look the member up by the HASH of the cookie value (we never store the token itself).
        // We deliberately do NOT filter out removed members here, so we can tell the difference
        // between "never joined" and "was removed".
        $member = $token
            ? Member::where('trip_id', $trip->id)->where('token_hash', MemberToken::hash($token))->first()
            : null;

        if ($member === null) {
            // The polling JavaScript wants JSON; a browser wants to be sent to the join page.
            return $request->expectsJson()
                ? response()->json(['message' => 'Please join this trip first.'], 401)
                : redirect()->route('join.show', $code);
        }

        // Removed by the organiser: access stops on the very next request.
        // Checked on every request because the token lookup above hits the database every time.
        abort_if($member->isRemoved(), 403, 'You have been removed from this trip.');

        $request->attributes->set('trip', $trip);
        $request->attributes->set('member', $member);

        return $next($request);
    }
}
