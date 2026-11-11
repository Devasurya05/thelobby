<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;
use App\Services\BedService;
use App\Support\MemberToken;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bed rules: holds, confirms, expiry, one-bed-per-person, roles, and the JSON endpoints.
 * (The truly simultaneous race is exercised by the demo:race command, because PHPUnit runs one request at a time.)
 */
class LobbyTest extends TestCase
{
    use RefreshDatabase;

    private BedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BedService::class);
    }

    // ---- helpers -------------------------------------------------------------------------------

    /** A trip with $bedCount free beds in one room. */
    private function makeTrip(int $bedCount = 3): Trip
    {
        $trip = Trip::create([
            'name' => 'Test trip', 'join_code' => Trip::newJoinCode(),
            'starts_on' => '2026-12-01', 'ends_on' => '2026-12-05',
        ]);
        $room = $trip->rooms()->create(['name' => 'Room 1']);
        for ($i = 1; $i <= $bedCount; $i++) {
            $room->beds()->create(['trip_id' => $trip->id, 'label' => "Bed $i"]);
        }

        return $trip;
    }

    /** Returns [member, plain token] so HTTP tests can log in as them. */
    private function makeMember(Trip $trip, string $name, string $role = Member::ROLE_MEMBER): array
    {
        $token = MemberToken::generate();
        $member = $trip->members()->create(['name' => $name, 'role' => $role, 'token_hash' => MemberToken::hash($token)]);

        return [$member, $token];
    }

    private function bedNumber(Trip $trip, int $n): Bed
    {
        return $trip->beds()->orderBy('id')->skip($n - 1)->firstOrFail();
    }

    private function actions(Trip $trip, string $action): array
    {
        return AuditLog::where('trip_id', $trip->id)->where('action', $action)->pluck('member_id')->all();
    }

    private function http(Trip $trip, string $token)
    {
        // withCredentials: Laravel's JSON helpers do not send cookies unless told to.
        return $this->withCookie(MemberToken::cookieName($trip->join_code), $token)->withCredentials();
    }

    // ---- grab ----------------------------------------------------------------------------------

    public function test_grabbing_a_free_bed_holds_it_for_the_configured_time(): void
    {
        config(['lobby.hold_seconds' => 30]);
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $result = $this->service->grab($trip, $asha, $bed->id);

        $this->assertTrue($result->ok);
        $bed->refresh();
        $this->assertSame(Bed::HELD, $bed->status);
        $this->assertSame($asha->id, $bed->held_by_member_id);
        $this->assertEqualsWithDelta(30, now()->diffInSeconds($bed->hold_expires_at, false), 2);
        $this->assertSame([$asha->id], $this->actions($trip, 'bed_held'));
    }

    public function test_grabbing_a_bed_held_by_someone_else_is_rejected_and_logged(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        [$ravi] = $this->makeMember($trip, 'Ravi');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $asha, $bed->id);
        $result = $this->service->grab($trip, $ravi, $bed->id);

        $this->assertFalse($result->ok);
        $this->assertSame(409, $result->status);
        $this->assertStringContainsString('Someone just grabbed', $result->message);
        $this->assertSame($asha->id, $bed->fresh()->held_by_member_id); // Asha still has it
        $this->assertSame([$ravi->id], $this->actions($trip, 'grab_rejected')); // rejection was saved, not rolled back
    }

    public function test_grabbing_a_bed_whose_hold_has_expired_succeeds(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        [$ravi] = $this->makeMember($trip, 'Ravi');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $asha, $bed->id);
        $this->travel(config('lobby.hold_seconds') + 1)->seconds(); // Asha's time is up; scheduler has NOT run

        $result = $this->service->grab($trip, $ravi, $bed->id);

        $this->assertTrue($result->ok);
        $this->assertSame($ravi->id, $bed->fresh()->held_by_member_id);
        $this->assertSame([$asha->id], $this->actions($trip, 'hold_expired')); // Asha's lost hold is on record
    }

    public function test_one_active_hold_per_person_grabbing_another_bed_releases_the_first(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');

        $this->service->grab($trip, $asha, $this->bedNumber($trip, 1)->id);
        $this->service->grab($trip, $asha, $this->bedNumber($trip, 2)->id);

        $this->assertSame(Bed::FREE, $this->bedNumber($trip, 1)->status);
        $this->assertSame(Bed::HELD, $this->bedNumber($trip, 2)->status);
        $this->assertSame(1, $trip->beds()->where('held_by_member_id', $asha->id)->count());
        $this->assertSame([$asha->id], $this->actions($trip, 'bed_released'));
    }

    public function test_double_tapping_your_own_held_bed_is_harmless(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $asha, $bed->id);
        $again = $this->service->grab($trip, $asha, $bed->id);

        $this->assertTrue($again->ok);
        $this->assertCount(1, $this->actions($trip, 'bed_held'));
    }

    public function test_a_bed_id_from_another_trip_is_not_found(): void
    {
        $tripA = $this->makeTrip();
        $tripB = $this->makeTrip();
        [$asha] = $this->makeMember($tripA, 'Asha');

        $result = $this->service->grab($tripA, $asha, $this->bedNumber($tripB, 1)->id);

        $this->assertFalse($result->ok);
        $this->assertSame(404, $result->status);
        $this->assertSame(Bed::FREE, $this->bedNumber($tripB, 1)->status);
    }

    // ---- confirm -------------------------------------------------------------------------------

    public function test_confirming_a_hold_takes_the_bed_permanently(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $asha, $bed->id);
        $result = $this->service->confirm($trip, $asha, $bed->id);

        $this->assertTrue($result->ok);
        $bed->refresh();
        $this->assertSame(Bed::TAKEN, $bed->status);
        $this->assertSame($asha->id, $bed->taken_by_member_id);
        $this->assertNull($bed->held_by_member_id);
        $this->assertNull($bed->hold_expires_at);
        $this->assertSame([$asha->id], $this->actions($trip, 'bed_confirmed'));
    }

    public function test_a_member_cannot_take_two_beds(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');

        $this->service->grab($trip, $asha, $this->bedNumber($trip, 1)->id);
        $this->service->confirm($trip, $asha, $this->bedNumber($trip, 1)->id);

        $second = $this->service->grab($trip, $asha, $this->bedNumber($trip, 2)->id);

        $this->assertFalse($second->ok);
        $this->assertSame(Bed::FREE, $this->bedNumber($trip, 2)->status);
        $this->assertSame(1, $trip->beds()->where('taken_by_member_id', $asha->id)->count());
    }

    public function test_the_database_itself_refuses_a_second_taken_bed(): void
    {
        // Even if the PHP code had a bug, the UNIQUE index on taken_by_member_id stops it.
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');

        $this->bedNumber($trip, 1)->forceFill(['status' => Bed::TAKEN, 'taken_by_member_id' => $asha->id])->save();

        $this->expectException(QueryException::class);
        $this->bedNumber($trip, 2)->forceFill(['status' => Bed::TAKEN, 'taken_by_member_id' => $asha->id])->save();
    }

    public function test_confirming_after_the_hold_expired_fails_and_frees_the_bed(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $asha, $bed->id);
        $this->travel(config('lobby.hold_seconds') + 1)->seconds();
        $result = $this->service->confirm($trip, $asha, $bed->id);

        $this->assertFalse($result->ok);
        $this->assertSame(Bed::FREE, $bed->fresh()->status);
        $this->assertSame([$asha->id], $this->actions($trip, 'hold_expired'));
    }

    public function test_you_cannot_confirm_someone_elses_hold(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        [$ravi] = $this->makeMember($trip, 'Ravi');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $asha, $bed->id);
        $result = $this->service->confirm($trip, $ravi, $bed->id);

        $this->assertFalse($result->ok);
        $this->assertSame(Bed::HELD, $bed->fresh()->status);
        $this->assertSame([$ravi->id], $this->actions($trip, 'confirm_rejected'));
    }

    // ---- release and expiry --------------------------------------------------------------------

    public function test_releasing_your_hold_frees_the_bed(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $asha, $bed->id);
        $this->assertTrue($this->service->release($trip, $asha, $bed->id)->ok);

        $this->assertSame(Bed::FREE, $bed->fresh()->status);
        $this->assertSame([$asha->id], $this->actions($trip, 'bed_released'));
    }

    public function test_expire_holds_frees_only_expired_ones_logs_them_and_is_safe_to_rerun(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        [$ravi] = $this->makeMember($trip, 'Ravi');

        $this->service->grab($trip, $asha, $this->bedNumber($trip, 1)->id);
        $this->travel(config('lobby.hold_seconds') - 10)->seconds();
        $this->service->grab($trip, $ravi, $this->bedNumber($trip, 2)->id); // Ravi grabs later
        $this->travel(15)->seconds(); // Asha's hold is now expired, Ravi's is not

        $this->assertSame(1, $this->service->expireHolds());
        $this->assertSame(Bed::FREE, $this->bedNumber($trip, 1)->status);
        $this->assertSame(Bed::HELD, $this->bedNumber($trip, 2)->status);
        $this->assertSame([$asha->id], $this->actions($trip, 'hold_expired'));

        $this->assertSame(0, $this->service->expireHolds()); // running it again changes nothing
        $this->assertCount(1, $this->actions($trip, 'hold_expired'));
    }

    public function test_expiry_command_runs(): void
    {
        $trip = $this->makeTrip();
        [$asha] = $this->makeMember($trip, 'Asha');
        $this->service->grab($trip, $asha, $this->bedNumber($trip, 1)->id);
        $this->travel(config('lobby.hold_seconds') + 5)->seconds();

        $this->artisan('lobby:expire-holds')->expectsOutput('Released 1 expired hold(s).')->assertSuccessful();
        $this->assertSame(Bed::FREE, $this->bedNumber($trip, 1)->status);
    }

    // ---- who may play --------------------------------------------------------------------------

    public function test_viewers_removed_members_and_ended_trips_cannot_grab(): void
    {
        $trip = $this->makeTrip();
        [$viewer] = $this->makeMember($trip, 'Vera', Member::ROLE_VIEWER);
        [$gone] = $this->makeMember($trip, 'Gone');
        [$asha] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $this->assertSame(403, $this->service->grab($trip, $viewer, $bed->id)->status);

        $gone->forceFill(['removed_at' => now()])->save();
        $this->assertSame(403, $this->service->grab($trip, $gone, $bed->id)->status);

        $trip->forceFill(['ended_at' => now()])->save();
        $this->assertFalse($this->service->grab($trip->fresh(), $asha, $bed->id)->ok);

        $this->assertSame(Bed::FREE, $bed->fresh()->status);
    }

    public function test_refused_attempts_are_written_to_the_audit_log(): void
    {
        $trip = $this->makeTrip();
        [$viewer] = $this->makeMember($trip, 'Vera', Member::ROLE_VIEWER);
        [$gone] = $this->makeMember($trip, 'Gone');
        [$asha] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $this->service->grab($trip, $viewer, $bed->id);
        $gone->forceFill(['removed_at' => now()])->save();
        $this->service->confirm($trip, $gone, $bed->id);
        $this->service->release($trip, $asha, $bed->id);   // Asha is not holding it
        $trip->forceFill(['ended_at' => now()])->save();
        $this->service->grab($trip->fresh(), $asha, $bed->id);

        $refused = AuditLog::where('action', 'action_refused')->orderBy('id')->get();
        $this->assertSame(
            [[$viewer->id, 'grab', 'viewer'], [$gone->id, 'confirm', 'removed'], [$asha->id, 'grab', 'trip_ended']],
            $refused->map(fn ($l) => [$l->member_id, $l->details['attempted'], $l->details['reason']])->all(),
        );
        $this->assertSame([$asha->id], $this->actions($trip, 'release_rejected'));
    }

    // ---- HTTP endpoints ------------------------------------------------------------------------

    public function test_state_endpoint_shows_beds_marks_mine_and_shows_expired_holds_as_free(): void
    {
        $trip = $this->makeTrip();
        [$asha, $ashaToken] = $this->makeMember($trip, 'Asha');
        [$ravi] = $this->makeMember($trip, 'Ravi');

        $this->service->grab($trip, $asha, $this->bedNumber($trip, 1)->id);
        $this->service->grab($trip, $ravi, $this->bedNumber($trip, 2)->id);

        $json = $this->http($trip, $ashaToken)->getJson("/t/{$trip->join_code}/state")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('trip.name', 'Test trip')
            ->assertJsonPath('counts', ['free' => 1, 'held' => 2, 'taken' => 0])
            ->assertJsonPath('rooms.0.beds.0.by', 'Asha')
            ->assertJsonPath('rooms.0.beds.0.mine', true)
            ->assertJsonPath('rooms.0.beds.1.by', 'Ravi')
            ->assertJsonPath('rooms.0.beds.1.mine', false)
            ->json();

        $this->assertStringNotContainsString('token_hash', json_encode($json));
        $this->assertGreaterThan(0, $json['rooms'][0]['beds'][0]['seconds_left']);
        $this->assertSame($json['rooms'][0]['beds'][0]['id'], $json['me']['held_bed_id']);

        // After the hold runs out the screen shows the bed as free, with no scheduler run needed.
        $this->travel(config('lobby.hold_seconds') + 1)->seconds();
        $this->http($trip, $ashaToken)->getJson("/t/{$trip->join_code}/state")
            ->assertJsonPath('counts', ['free' => 3, 'held' => 0, 'taken' => 0])
            ->assertJsonPath('rooms.0.beds.0.status', 'free');
    }

    public function test_grab_endpoint_returns_state_and_a_clear_409_for_the_loser(): void
    {
        $trip = $this->makeTrip();
        [, $ashaToken] = $this->makeMember($trip, 'Asha');
        [, $raviToken] = $this->makeMember($trip, 'Ravi');
        $bed = $this->bedNumber($trip, 1);
        $url = "/t/{$trip->join_code}/beds/{$bed->id}/grab";

        $this->http($trip, $ashaToken)->postJson($url)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('state.me.held_bed_id', $bed->id);

        $this->http($trip, $raviToken)->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Someone just grabbed this bed. Pick another one.')
            ->assertJsonPath('state.rooms.0.beds.0.by', 'Asha'); // the loser's screen is corrected at once
    }

    public function test_full_flow_over_http_grab_confirm(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);

        $this->http($trip, $token)->postJson("/t/{$trip->join_code}/beds/{$bed->id}/grab")->assertOk();
        $this->http($trip, $token)->postJson("/t/{$trip->join_code}/beds/{$bed->id}/confirm")
            ->assertOk()
            ->assertJsonPath('state.me.taken_label', 'Room 1 · Bed 1');

        $this->assertSame(Bed::TAKEN, $bed->fresh()->status);
    }

    public function test_bed_actions_need_a_login_and_reject_get_requests(): void
    {
        $trip = $this->makeTrip();
        $bed = $this->bedNumber($trip, 1);

        $this->postJson("/t/{$trip->join_code}/beds/{$bed->id}/grab")->assertStatus(401);
        $this->getJson("/t/{$trip->join_code}/state")->assertStatus(401);

        [, $token] = $this->makeMember($trip, 'Asha');
        $this->http($trip, $token)->get("/t/{$trip->join_code}/beds/{$bed->id}/grab")->assertStatus(405);
    }

    public function test_rate_limit_is_per_person_not_shared(): void
    {
        $trip = $this->makeTrip();
        [, $ashaToken] = $this->makeMember($trip, 'Asha');
        [, $raviToken] = $this->makeMember($trip, 'Ravi');
        $bed = $this->bedNumber($trip, 1);
        $url = "/t/{$trip->join_code}/beds/{$bed->id}/release"; // harmless action, still counted

        for ($i = 0; $i < 30; $i++) {
            $this->http($trip, $ashaToken)->postJson($url);
        }

        $this->http($trip, $ashaToken)->postJson($url)->assertStatus(429);   // Asha is out of taps...
        $this->http($trip, $raviToken)->postJson($url)->assertStatus(409);   // ...Ravi is not affected (409 = normal answer)
    }

    public function test_hitting_the_rate_limit_is_audited_once_per_minute_not_per_tap(): void
    {
        $trip = $this->makeTrip();
        [$asha, $ashaToken] = $this->makeMember($trip, 'Asha');
        $bed = $this->bedNumber($trip, 1);
        $url = "/t/{$trip->join_code}/beds/{$bed->id}/release";

        for ($i = 0; $i < 30; $i++) {
            $this->http($trip, $ashaToken)->postJson($url);
        }
        // 20 more taps while blocked: all refused, but only ONE audit row is written for the burst.
        for ($i = 0; $i < 20; $i++) {
            $this->http($trip, $ashaToken)->postJson($url)
                ->assertStatus(429)
                ->assertJsonPath('message', 'Too many taps. Wait a moment and try again.');
        }
        $this->assertSame([$asha->id], $this->actions($trip, 'rate_limited'));

        // A minute later the limit has reset, and a NEW burst is recorded again.
        $this->travel(61)->seconds();
        for ($i = 0; $i < 31; $i++) {
            $this->http($trip, $ashaToken)->postJson($url);
        }
        $this->assertCount(2, $this->actions($trip, 'rate_limited'));
    }
}
