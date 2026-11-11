<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;
use App\Services\BedService;
use App\Services\MemberService;
use App\Support\MemberToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The organiser's Members page (remove, change role) and the Audit log page. */
class MembersTest extends TestCase
{
    use RefreshDatabase;

    // ---- helpers -------------------------------------------------------------------------------

    private function makeTrip(string $name = 'Test trip', int $beds = 3): Trip
    {
        $trip = Trip::create(['name' => $name, 'join_code' => Trip::newJoinCode(), 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-05']);
        $room = $trip->rooms()->create(['name' => 'Room 1']);
        for ($i = 1; $i <= $beds; $i++) {
            $room->beds()->create(['trip_id' => $trip->id, 'label' => "Bed $i"]);
        }

        return $trip;
    }

    /** @return array{0: Member, 1: string} member and plain token */
    private function makeMember(Trip $trip, string $name, string $role = Member::ROLE_MEMBER): array
    {
        $token = MemberToken::generate();

        return [
            $trip->members()->create(['name' => $name, 'role' => $role, 'token_hash' => MemberToken::hash($token)]),
            $token,
        ];
    }

    private function as(Trip $trip, string $token)
    {
        // withCredentials: Laravel's JSON helpers do not send cookies unless told to.
        return $this->withCookie(MemberToken::cookieName($trip->join_code), $token)->withCredentials();
    }

    private function bed(Trip $trip, int $n): Bed
    {
        return $trip->beds()->orderBy('id')->skip($n - 1)->firstOrFail();
    }

    private function url(Trip $trip, string $path): string
    {
        return "/t/{$trip->join_code}/{$path}";
    }

    // ---- who can open the pages --------------------------------------------------------------------

    public function test_only_the_organiser_can_open_members_and_audit_pages(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [, $memberToken] = $this->makeMember($trip, 'Mia');
        [, $viewerToken] = $this->makeMember($trip, 'Vik', Member::ROLE_VIEWER);

        // Not logged in first: once withCookie() is used, the test client keeps sending that cookie.
        foreach (['members', 'audit'] as $page) {
            $this->get($this->url($trip, $page))->assertRedirect(route('join.show', $trip->join_code));
        }

        foreach (['members', 'audit'] as $page) {
            $this->as($trip, $orgToken)->get($this->url($trip, $page))->assertOk();
            $this->as($trip, $memberToken)->get($this->url($trip, $page))->assertStatus(403);
            $this->as($trip, $viewerToken)->get($this->url($trip, $page))->assertStatus(403);
        }
    }

    public function test_a_member_cannot_remove_or_promote_by_posting_directly(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia, $miaToken] = $this->makeMember($trip, 'Mia');
        [$bob] = $this->makeMember($trip, 'Bob');

        $this->as($trip, $miaToken)->post($this->url($trip, "members/{$bob->id}/remove"))->assertStatus(403);
        $this->as($trip, $miaToken)->post($this->url($trip, "members/{$bob->id}/role"), ['role' => 'viewer'])->assertStatus(403);

        $this->assertNull($bob->fresh()->removed_at);
        $this->assertSame(Member::ROLE_MEMBER, $bob->fresh()->role);

        // Defence in depth: even if the middleware were skipped, the service refuses and audits it.
        $result = app(MemberService::class)->remove($trip, $mia, $bob->id);
        $this->assertFalse($result->ok);
        $this->assertSame(403, $result->status);
        $this->assertNull($bob->fresh()->removed_at);
        $this->assertSame(1, AuditLog::where('action', 'action_refused')->where('member_id', $mia->id)->count());
    }

    // ---- removing -----------------------------------------------------------------------------------

    public function test_removing_a_member_blocks_them_at_once_and_frees_their_beds(): void
    {
        $trip = $this->makeTrip();
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia, $miaToken] = $this->makeMember($trip, 'Mia');
        [$bob] = $this->makeMember($trip, 'Bob');
        $beds = app(BedService::class);

        $beds->grab($trip, $mia, $this->bed($trip, 1)->id);
        $beds->confirm($trip, $mia, $this->bed($trip, 1)->id);   // Mia has a CONFIRMED bed
        $beds->grab($trip, $bob, $this->bed($trip, 2)->id);
        $beds->grab($trip, $bob, $this->bed($trip, 3)->id);       // Bob HOLDS bed 3 (bed 2 released by the switch)

        $this->as($trip, $miaToken)->getJson($this->url($trip, 'state'))->assertOk();

        $this->as($trip, $orgToken)->post($this->url($trip, "members/{$mia->id}/remove"))
            ->assertRedirect(route('members.index', $trip->join_code))
            ->assertSessionHas('status', 'Mia was removed from the trip.');
        $this->as($trip, $orgToken)->post($this->url($trip, "members/{$bob->id}/remove"))->assertSessionHas('status');

        // Their very next request is refused...
        $this->as($trip, $miaToken)->getJson($this->url($trip, 'state'))->assertStatus(403);
        // ...their beds are free for others...
        $this->assertSame(0, Bed::where('trip_id', $trip->id)->where('status', '!=', Bed::FREE)->count());
        $this->assertNotNull($mia->fresh()->removed_at);
        // ...and the audit log says who did it.
        $this->assertSame([$org->id, $org->id], AuditLog::where('action', 'member_removed')->orderBy('id')->pluck('member_id')->all());
        $this->assertSame(2, AuditLog::where('action', 'bed_released')->where('details->reason', 'member_removed')->count());
    }

    public function test_the_organiser_cannot_be_removed_or_demoted(): void
    {
        $trip = $this->makeTrip();
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);

        $this->as($trip, $orgToken)->post($this->url($trip, "members/{$org->id}/remove"))
            ->assertSessionHas('problem', 'The organiser cannot be removed.');
        $this->as($trip, $orgToken)->post($this->url($trip, "members/{$org->id}/role"), ['role' => 'viewer'])
            ->assertSessionHas('problem', "The organiser's role cannot be changed.");

        $org->refresh();
        $this->assertNull($org->removed_at);
        $this->assertSame(Member::ROLE_ORGANISER, $org->role);
        $this->assertSame(2, AuditLog::where('action', 'action_refused')->where('details->reason', 'target_is_organiser')->count());
    }

    public function test_members_of_other_trips_are_invisible_and_double_removal_is_harmless(): void
    {
        $trip = $this->makeTrip();
        $other = $this->makeTrip('Other trip');
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$stranger] = $this->makeMember($other, 'Stranger');
        [$bob] = $this->makeMember($trip, 'Bob');
        $service = app(MemberService::class);

        $result = $service->remove($trip, $org, $stranger->id);
        $this->assertSame(404, $result->status);
        $this->assertNull($stranger->fresh()->removed_at);

        $this->assertTrue($service->remove($trip, $org, $bob->id)->ok);
        $second = $service->remove($trip, $org, $bob->id);
        $this->assertFalse($second->ok);
        $this->assertSame(1, AuditLog::where('action', 'member_removed')->count());   // not logged twice
    }

    // ---- roles --------------------------------------------------------------------------------------

    public function test_changing_to_viewer_releases_the_hold_and_back_to_member_works(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia] = $this->makeMember($trip, 'Mia');
        app(BedService::class)->grab($trip, $mia, $this->bed($trip, 1)->id);

        $this->as($trip, $orgToken)->post($this->url($trip, "members/{$mia->id}/role"), ['role' => 'viewer'])
            ->assertSessionHas('status', 'Mia is now a viewer.');

        $this->assertSame(Member::ROLE_VIEWER, $mia->fresh()->role);
        $this->assertSame(Bed::FREE, $this->bed($trip, 1)->status);
        $this->assertFalse(app(BedService::class)->grab($trip, $mia->fresh(), $this->bed($trip, 1)->id)->ok);

        $change = AuditLog::where('action', 'role_changed')->sole();
        $this->assertEquals(['name' => 'Mia', 'from' => 'member', 'to' => 'viewer'], $change->details);   // JSON does not keep key order

        $this->as($trip, $orgToken)->post($this->url($trip, "members/{$mia->id}/role"), ['role' => 'member']);
        $this->assertTrue(app(BedService::class)->grab($trip, $mia->fresh(), $this->bed($trip, 1)->id)->ok);
    }

    public function test_nobody_can_be_made_organiser_or_given_an_invented_role(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia] = $this->makeMember($trip, 'Mia');

        foreach (['organiser', 'admin', '', 'MEMBER'] as $bad) {
            $this->as($trip, $orgToken)->post($this->url($trip, "members/{$mia->id}/role"), ['role' => $bad])
                ->assertSessionHasErrors('role');
        }
        $this->assertSame(Member::ROLE_MEMBER, $mia->fresh()->role);
        $this->assertSame(0, AuditLog::where('action', 'role_changed')->count());
    }

    // ---- the pages themselves -----------------------------------------------------------------------

    public function test_members_page_lists_people_with_beds_and_escapes_names(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$evil] = $this->makeMember($trip, '<script>alert(1)</script>');
        [$gone] = $this->makeMember($trip, 'Gone');
        $beds = app(BedService::class);
        $beds->grab($trip, $evil, $this->bed($trip, 1)->id);
        $beds->confirm($trip, $evil, $this->bed($trip, 1)->id);
        $gone->forceFill(['removed_at' => now()])->save();

        $this->as($trip, $orgToken)->get($this->url($trip, 'members'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('bed: Room 1')
            ->assertSee('removed');
    }

    public function test_audit_page_shows_friendly_text_hides_other_trips_and_pages_back(): void
    {
        $trip = $this->makeTrip();
        $other = $this->makeTrip('Other trip');
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia] = $this->makeMember($trip, 'Mia');
        [$spy] = $this->makeMember($other, 'Elsewhere');

        app(BedService::class)->grab($trip, $mia, $this->bed($trip, 1)->id);
        AuditLog::record($other->id, $spy->id, 'bed_held', 'bed', 999);

        $this->as($trip, $orgToken)->get($this->url($trip, 'audit'))
            ->assertOk()
            ->assertSee('Held a bed')
            ->assertSee('Mia')
            ->assertSee('Room 1 · Bed 1')
            ->assertDontSee('Elsewhere');

        // 60 more rows: the first page stops at 50 and offers "Older entries".
        for ($i = 0; $i < 60; $i++) {
            AuditLog::record($trip->id, $org->id, 'member_joined', 'member', $mia->id);
        }
        $first = $this->as($trip, $orgToken)->get($this->url($trip, 'audit'))->assertOk()->assertSee('Older entries');
        $this->assertSame(50, substr_count($first->getContent(), 'class="card audit-row"'));

        $oldest = AuditLog::where('trip_id', $trip->id)->orderByDesc('id')->skip(49)->first()->id;
        $second = $this->as($trip, $orgToken)->get($this->url($trip, 'audit').'?before='.$oldest)
            ->assertOk()->assertDontSee('Older entries')->assertSee('Newest');
        $this->assertGreaterThan(0, substr_count($second->getContent(), 'class="card audit-row"'));
    }
}
