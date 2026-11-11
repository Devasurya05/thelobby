<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    public static function make(Request $request, array $headers): JsonResponse
    {
        $trip = $request->attributes->get('trip');
        $member = $request->attributes->get('member');

        if ($trip && $member) {
            // Cache::add only succeeds if the key does not exist yet: "first time this minute".
            if (Cache::add('rate-limited-logged:'.$member->id, true, 60)) {
                try {
                    AuditLog::record($trip->id, $member->id, 'rate_limited', null, null, [
                        'route' => $request->route()?->uri(),
                        'limit_per_minute' => 30,
                    ]);
                } catch (Throwable $e) {
                    // Failing to write the note must never turn "slow down" into a crash.
                    report($e);
                }
            }
        }

        return response()->json(['ok' => false, 'message' => 'Too many taps. Wait a moment and try again.'], 429, $headers);
    }
}
