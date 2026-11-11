<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;
use App\Services\BedService;
use App\Support\MemberToken;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Live proof that the lobby never double-books.
 *
 *   php artisan demo:race ABC123            (races for the first free bed)
 *   php artisan demo:race ABC123 17 --racers=5
 *
 * It creates temporary "racer" members, starts one PHP process per racer, and makes all of them
 * grab the SAME bed at the SAME instant. Exactly one may win; the rest must be rejected.
 * Afterwards it tidies up (frees the bed, soft-removes the racers) and prints the audit trail.
 */
class DemoRace extends Command
{
    protected $signature = 'demo:race
        {code : the trip join code}
        {bedId? : id of the bed to fight over (default: the first free bed)}
        {--racers=2 : how many simultaneous racers (2-10)}';

    protected $description = 'Prove no double booking: several processes grab the same bed at the same instant';

    public function handle(BedService $beds): int
    {
        // Creates fake members and writes to a real trip, so never on a live server.
        if (app()->isProduction()) {
            $this->error('demo:race is disabled in production.');

            return self::FAILURE;
        }

        $racerCount = (int) $this->option('racers');
        if ($racerCount < 2 || $racerCount > 10) {
            $this->error('--racers must be between 2 and 10.');

            return self::FAILURE;
        }

        $trip = Trip::where('join_code', strtoupper($this->argument('code')))->first();
        if ($trip === null) {
            $this->error('No trip with that join code.');

            return self::FAILURE;
        }

        $bed = $this->pickBed($trip, $this->argument('bedId'));
        if ($bed === null) {
            $this->error('No free bed to race for (pass a bed id of a free bed).');

            return self::FAILURE;
        }

        $this->info("Trip \"{$trip->name}\", bed \"{$bed->label}\" (id {$bed->id}), {$racerCount} racers.");

        $racers = $this->createRacers($trip, $racerCount);

        try {
            $results = $this->runRace($trip, $bed, $racers);
            $ok = $this->report($trip, $bed, $racers, $results);
        } finally {
            // Always tidy up, even if something above failed.
            $this->cleanUp($beds, $trip, $bed, $racers);
        }

        $this->printAudit($trip, $bed, $racers);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function pickBed(Trip $trip, mixed $bedId): ?Bed
    {
        if ($bedId !== null) {
            $bed = $trip->beds()->find((int) $bedId);

            return $bed?->isAvailable() ? $bed : null;
        }

        // First bed (by id) that is free or has an expired hold.
        return $trip->beds()->orderBy('id')->get()->first(fn (Bed $b) => $b->isAvailable());
    }

    /** Temporary members. They never get a usable cookie: they only exist to be audit actors. */
    private function createRacers(Trip $trip, int $count): array
    {
        $suffix = Str::upper(Str::random(3));
        $racers = [];

        for ($i = 1; $i <= $count; $i++) {
            $racers[] = $trip->members()->create([
                'name' => "Racer {$i} (demo {$suffix})",
                'role' => Member::ROLE_MEMBER,
                'token_hash' => MemberToken::hash(MemberToken::generate()),
            ]);
        }

        return $racers;
    }

    /** Start one process per racer, all pointed at the same start time, and collect their answers. */
    private function runRace(Trip $trip, Bed $bed, array $racers): array
    {
        // Booting Laravel in each process takes a moment: give them 3 seconds to get ready.
        $startAt = sprintf('%.4F', microtime(true) + 3);

        $processes = [];
        foreach ($racers as $racer) {
            $process = new Process([
                PHP_BINARY, base_path('artisan'), 'demo:race-worker',
                (string) $trip->id, (string) $racer->id, (string) $bed->id, $startAt,
            ]);
            $process->start();
            $processes[$racer->id] = $process;
        }

        $this->line('All racers are warming up, firing in 3 seconds...');

        $results = [];
        foreach ($processes as $memberId => $process) {
            $process->wait();
            // The worker prints one JSON line (the last line of its output).
            $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
            $data = $lines ? json_decode(end($lines), true) : null;

            $results[$memberId] = is_array($data)
                ? $data
                : ['ok' => false, 'message' => 'WORKER FAILED: '.trim($process->getErrorOutput()), 'fired_ms' => 0, 'took_ms' => 0, 'broken' => true];
        }

        return $results;
    }

    /** Print the scoreboard and check the one rule that matters. Returns true if the rule held. */
    private function report(Trip $trip, Bed $bed, array $racers, array $results): bool
    {
        $rows = [];
        foreach ($racers as $racer) {
            $r = $results[$racer->id];
            $rows[] = [$racer->name, $r['ok'] ? 'WON' : 'lost', $r['message'], $r['fired_ms'].' ms', $r['took_ms'].' ms'];
        }
        $this->newLine();
        $this->table(['Racer', 'Result', 'Message', 'Fired at', 'Took'], $rows);

        $winners = collect($results)->where('ok', true)->count();
        $broken = collect($results)->contains(fn ($r) => $r['broken'] ?? false);

        // Do not just trust what the workers said: check the database itself.
        $bed->refresh();
        $holder = $bed->held_by_member_id;
        $dbAgrees = $bed->status === Bed::HELD && $holder !== null && ($results[$holder]['ok'] ?? false);

        if ($winners === 1 && $dbAgrees && ! $broken) {
            $this->info("PASS: exactly 1 winner, {$winners} of ".count($racers).' got the bed, the database agrees. No double booking.');

            return true;
        }

        $this->error("FAIL: {$winners} winners reported (expected exactly 1), database holder: ".($holder ?? 'none').'.');

        return false;
    }

    /** Free the bed and soft-remove the racers (members are never hard-deleted: the audit log points at them). */
    private function cleanUp(BedService $beds, Trip $trip, Bed $bed, array $racers): void
    {
        $bed->refresh();
        if ($bed->status === Bed::HELD && $bed->held_by_member_id !== null) {
            $holder = collect($racers)->firstWhere('id', $bed->held_by_member_id);
            if ($holder) {
                $beds->release($trip, $holder, $bed->id);
            }
        }

        foreach ($racers as $racer) {
            $racer->forceFill(['removed_at' => now()])->save();
        }
    }

    private function printAudit(Trip $trip, Bed $bed, array $racers): void
    {
        $names = collect($racers)->pluck('name', 'id');

        $logs = AuditLog::where('trip_id', $trip->id)
            ->whereIn('member_id', $names->keys())
            ->orderBy('id')
            ->get();

        $this->newLine();
        $this->line('Audit trail for this race (every attempt is recorded, winners and losers):');
        $this->table(
            ['#', 'Who', 'Action', 'Why'],
            $logs->map(fn (AuditLog $l) => [
                $l->id,
                $names[$l->member_id] ?? '?',
                $l->action,
                $l->details['reason'] ?? '',
            ])->all(),
        );
    }
}
