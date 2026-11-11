<?php

namespace App\Console\Commands;

use App\Services\BedService;
use Illuminate\Console\Command;

class ExpireHolds extends Command
{
    protected $signature = 'lobby:expire-holds';

    protected $description = 'Release every bed hold whose time has run out and log it in the audit trail';

    public function handle(BedService $beds): int
    {
        $count = $beds->expireHolds();
        $this->info("Released {$count} expired hold(s).");

        return self::SUCCESS;
    }
}
