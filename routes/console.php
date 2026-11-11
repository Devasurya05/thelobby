<?php

use Illuminate\Support\Facades\Schedule;

// Every minute: release holds whose time is up. Needs `php artisan schedule:work` locally
// (or one cron line on the server: * * * * * php artisan schedule:run).
// withoutOverlapping: if a run is still going, the next one waits instead of piling on top.
// Even if the scheduler is off, the screen stays correct because expired holds are shown as free.
Schedule::command('lobby:expire-holds')->everyMinute()->withoutOverlapping();
