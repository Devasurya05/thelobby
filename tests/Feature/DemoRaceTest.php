<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * demo:race starts REAL separate PHP processes, and those can only see data that is committed.
 * RefreshDatabase wraps each test in an uncommitted transaction (they would see nothing), so this
 * test class uses DatabaseMigrations instead: it really migrates and really commits.
 */
class DemoRaceTest extends TestCase
{
    use DatabaseMigrations;

    private function makeTrip(): Trip
    {
        $trip = Trip::create([
            'name' => 'Race trip', 'join_code' => Trip::newJoinCode(),
            'starts_on' => '2026-12-01', 'ends_on' => '2026-12-05',
        ]);
        $room = $trip->rooms()->create(['name' => 'Room 1']);
        $room->beds()->create(['trip_id' => $trip->id, 'label' => 'Window']);

        return $trip;
    }

    public function test_race_has_exactly_one_winner_and_cleans_up(): void
    {
        $trip = $this->makeTrip();

        $this->artisan('demo:race', ['code' => $trip->join_code, '--racers' => 4])
            ->expectsOutputToContain('PASS: exactly 1 winner')
            ->assertSuccessful();

        // Exactly one bed_held and three rejections were audited...
        $this->assertSame(1, AuditLog::where('trip_id', $trip->id)->where('action', 'bed_held')->count());
        $this->assertSame(3, AuditLog::where('trip_id', $trip->id)->where('action', 'grab_rejected')->count());

        // ...and the demo tidied up: bed is free again, racers are soft-removed (not deleted).
        $this->assertSame(Bed::FREE, Bed::where('trip_id', $trip->id)->first()->status);
        $this->assertSame(4, Member::where('trip_id', $trip->id)->whereNotNull('removed_at')->count());
    }

    public function test_race_refuses_bad_input(): void
    {
        $this->artisan('demo:race', ['code' => 'NOPE'])->assertFailed();

        $trip = $this->makeTrip();
        $this->artisan('demo:race', ['code' => $trip->join_code, '--racers' => 1])->assertFailed();
        $this->assertSame(0, Member::count(), 'no racers should be created when input is invalid');
    }

    public function test_race_refuses_to_run_in_production(): void
    {
        $trip = $this->makeTrip();
        $this->app['env'] = 'production';

        try {
            $this->artisan('demo:race', ['code' => $trip->join_code])->assertFailed();
            $this->assertSame(0, Member::count());
        } finally {
            // Put it back: DatabaseMigrations runs migrate:fresh when the test ends, and Laravel
            // refuses to do that without a confirmation prompt while it believes it is production.
            $this->app['env'] = 'testing';
        }
    }

    public function test_the_allow_production_flag_gets_past_the_production_guard(): void
    {
        $this->app['env'] = 'production';

        try {
            // A wrong trip code proves the command got PAST the production guard (it now fails for a different reason).
            $this->artisan('demo:race', ['code' => 'ZZZZZZZZ', '--allow-production' => true])
                ->expectsOutputToContain('No trip with that join code.')
                ->assertFailed();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
