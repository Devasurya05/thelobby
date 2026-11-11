<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What happens when someone taps faster than the rate limit allows (HTTP 429).
 *
 * Without this, throttled taps vanish without trace. Now the FIRST throttled tap in each minute
 * writes one `rate_limited` audit row. Only one per person per minute, so a script hammering the
 * button cannot flood the audit table: the flood itself is bounded by the limiter, and this row
 * records that it happened.
 */
class ThrottledResponse
{
    public static function make(Request $request, array $headers, int $limitPerMinute = 30): Response
    {
        $trip = $request->attributes->get('trip');
        $member = $request->attributes->get('member');

        if ($trip && $member) {
            // Cache::add only succeeds if the key does not exist yet: "first time this minute".
            if (Cache::add('rate-limited-logged:'.$member->id, true, 60)) {
                try {
                    AuditLog::record($trip->id, $member->id, 'rate_limited', null, null, [
                        'route' => $request->route()?->uri(),
                        'limit_per_minute' => $limitPerMinute,
                    ]);
                } catch (Throwable $e) {
                    // Failing to write the note must never turn "slow down" into a crash.
                    report($e);
                }
            }
        }

        // The lobby screen talks JSON. A plain page (the vault's forms and links) gets sent back with a red message.
        if (! $request->expectsJson() && $trip) {
            return redirect()->route('vault.index', $trip->join_code)->with('problem', 'Too many requests. Wait a moment and try again.');
        }

        return response()->json(['ok' => false, 'message' => 'Too many taps. Wait a moment and try again.'], 429, $headers);
    }
}
