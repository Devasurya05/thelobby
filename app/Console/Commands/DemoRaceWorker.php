<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Trip;
use App\Services\BedService;
use Illuminate\Console\Command;

/**
 * Internal helper for `demo:race`: ONE racer, running as its own PHP process.
 *
 * Why a separate process? A single PHP script runs one thing at a time. To get two grabs that
 * really hit MySQL at the same instant (like two phones on PHP-FPM) we need two processes.
 * Each worker boots Laravel, then waits until the shared start time and fires.
 */
class DemoRaceWorker extends Command
{
    protected $signature = 'demo:race-worker {tripId} {memberId} {bedId} {startAt : unix time with decimals}';

    protected $description = 'Internal: one racer for demo:race (do not run by hand)';

    protected $hidden = true;

    public function handle(BedService $beds): int
    {
        $trip = Trip::findOrFail((int) $this->argument('tripId'));
        $member = Member::findOrFail((int) $this->argument('memberId'));
        $startAt = (float) $this->argument('startAt');

        // Busy-wait (tiny sleeps) until the agreed moment, so all racers fire together.
        while (microtime(true) < $startAt) {
            usleep(200);
        }

        $fired = microtime(true);
        $result = $beds->grab($trip, $member, (int) $this->argument('bedId'));
        $done = microtime(true);

        // One JSON line; the parent command reads it.
        $this->line(json_encode([
            'member_id' => $member->id,
            'ok' => $result->ok,
            'message' => $result->message,
            'fired_ms' => round(($fired - $startAt) * 1000, 1),
            'took_ms' => round(($done - $fired) * 1000, 1),
        ]));

        return self::SUCCESS;
    }
}
