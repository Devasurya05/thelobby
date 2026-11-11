<?php

namespace App\Console\Commands;

use App\Services\VaultService;
use Illuminate\Console\Command;

/**
 * The "self-destruct". Runs every few minutes from the scheduler:
 *   1. closes trips whose end date has passed,
 *   2. deletes every vault file of every ended trip from S3 (the audit log is kept).
 *
 * Retry-safe: if S3 is down, the failed files stay on the list and the next run tries again.
 * It exits with a failure code (and the error is logged) so a problem is never silent.
 */
class PurgeVaults extends Command
{
    protected $signature = 'vault:purge';

    protected $description = 'Close finished trips and delete their vault files from S3';

    public function handle(VaultService $vault): int
    {
        $closed = $vault->closeFinishedTrips();
        $failures = $vault->purgeEndedTrips();

        $this->info("Trips closed: {$closed}. Delete failures: {$failures}.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
