<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Member;
use App\Models\Trip;
use App\Support\MemberToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a trip, joining it, and the token-cookie login.
 * RefreshDatabase wipes the test database (thelobby_test, real MySQL) between tests.
 */
class TripAccessTest extends TestCase
{
    use RefreshDatabase;

    private function validTripData(array $overrides = []): array
    {
        return array_merge([
            'trip_name' => 'Goa villa',
            'organiser_name' => 'Asha',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-05',
            'rooms' => "Room 1: Window, Door\nRoom 2: Bed 1, Bed 2, Bed 3\nRoom 3",
        ], $overrides);
    }

    /** Creates a trip through the real endpoint and returns [trip, plain organiser token, response]. */
    private function createTrip(array $overrides = []): array
    {
        $response = $this->post('/trips', $this->validTripData($overrides));
        $trip = Trip::firstOrFail();
        $token = $response->getCookie(MemberToken::cookieName($trip->join_code))->getValue();

        return [$trip, $token, $response];
    }

    private function asMember(Trip $trip, string $token)
    {
        return $this->withCookie(MemberToken::cookieName($trip->join_code), $token);
    }

    public function test_creating_a_trip_makes_organiser_rooms_beds_and_audit_entry(): void
    {
        [$trip, $token, $response] = $this->createTrip();

        $response->assertRedirect('/t/'.$trip->join_code);
        $this->assertSame(8, strlen($trip->join_code));
        $this->assertSame(3, $trip->rooms()->count());
        $this->assertSame(6, $trip->beds()->count()); // 2 + 3 + 1
        $this->assertSame(6, $trip->beds()->where('status', Bed::FREE)->count());

        $organiser = $trip->members()->firstOrFail();
        $this->assertSame(Member::ROLE_ORGANISER, $organiser->role);
        $this->assertDatabaseHas('audit_logs', ['trip_id' => $trip->id, 'action' => 'trip_created']);
    }

    public function test_only_the_token_hash_is_stored_and_cookie_is_httponly_lax_and_scoped(): void
    {
        [$trip, $token, $response] = $this->createTrip();

        $organiser = $trip->members()->firstOrFail();
        $this->assertSame(MemberToken::hash($token), $organiser->token_hash);
        $this->assertNotSame($token, $organiser->token_hash);
        $this->assertSame(64, strlen($organiser->token_hash));

        $cookie = $response->getCookie(MemberToken::cookieName($trip->join_code), false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/t/'.$trip->join_code, $cookie->getPath());
    }

    public function test_organiser_can_open_the_lobby_with_their_cookie(): void
    {
        [$trip, $token] = $this->createTrip();

        $this->asMember($trip, $token)->get('/t/'.$trip->join_code)
            ->assertOk()
            ->assertSee('Goa villa')
            ->assertSee('Asha');
    }

    public function test_joining_creates_a_member_and_logs_in(): void
    {
        [$trip] = $this->createTrip();

        $response = $this->post('/join', ['code' => strtolower($trip->join_code), 'name' => 'Ravi']);

        $response->assertRedirect('/t/'.$trip->join_code);
        $ravi = $trip->members()->where('name', 'Ravi')->firstOrFail();
        $this->assertSame(Member::ROLE_MEMBER, $ravi->role);
        $this->assertDatabaseHas('audit_logs', ['member_id' => $ravi->id, 'action' => 'member_joined']);

        $token = $response->getCookie(MemberToken::cookieName($trip->join_code))->getValue();
        $this->asMember($trip, $token)->get('/t/'.$trip->join_code)->assertOk();
    }

    public function test_duplicate_name_in_same_trip_is_rejected_case_insensitively(): void
    {
        [$trip] = $this->createTrip();

        $this->post('/join', ['code' => $trip->join_code, 'name' => 'asha'])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, $trip->members()->count());
    }

    public function test_unknown_code_and_ended_trip_cannot_be_joined(): void
    {
        [$trip] = $this->createTrip();

        $this->post('/join', ['code' => 'ZZZZZZZZ', 'name' => 'Ravi'])->assertSessionHasErrors('code');

        // forceFill because ended_at is deliberately NOT mass-assignable (users can never set it via a form).
        $trip->forceFill(['ended_at' => now()])->save();
        $this->post('/join', ['code' => $trip->join_code, 'name' => 'Ravi'])->assertSessionHasErrors('code');
    }

    public function test_no_cookie_means_redirect_to_join_page_or_401_for_json(): void
    {
        [$trip] = $this->createTrip();

        $this->get('/t/'.$trip->join_code)->assertRedirect('/join/'.$trip->join_code);
        $this->getJson('/t/'.$trip->join_code)->assertStatus(401);
    }

    public function test_unknown_trip_is_404(): void
    {
        $this->get('/t/ZZZZZZZZ')->assertNotFound();
    }

    public function test_a_wrong_or_forged_token_is_denied(): void
    {
        [$trip] = $this->createTrip();

        $this->asMember($trip, 'not-a-real-token')->get('/t/'.$trip->join_code)
            ->assertRedirect('/join/'.$trip->join_code);
    }

    public function test_a_token_from_one_trip_does_not_open_another_trip(): void
    {
        [$tripA, $tokenA] = $this->createTrip();

        // Second trip, created by someone else.
        $this->post('/trips', $this->validTripData(['trip_name' => 'Other trip', 'organiser_name' => 'Zed']));
        $tripB = Trip::where('id', '!=', $tripA->id)->firstOrFail();

        // Present trip A's token under trip B's cookie name: must be refused.
        $this->withCookie(MemberToken::cookieName($tripB->join_code), $tokenA)
            ->get('/t/'.$tripB->join_code)
            ->assertRedirect('/join/'.$tripB->join_code);
    }

    public function test_a_removed_member_is_denied_on_the_next_request(): void
    {
        [$trip] = $this->createTrip();
        $response = $this->post('/join', ['code' => $trip->join_code, 'name' => 'Ravi']);
        $token = $response->getCookie(MemberToken::cookieName($trip->join_code))->getValue();

        $this->asMember($trip, $token)->get('/t/'.$trip->join_code)->assertOk();

        $trip->members()->where('name', 'Ravi')->update(['removed_at' => now()]);

        $this->asMember($trip, $token)->get('/t/'.$trip->join_code)->assertForbidden();
        // withCredentials(): Laravel's getJson() does not send cookies unless told to (the polling JS will).
        $this->asMember($trip, $token)->withCredentials()->getJson('/t/'.$trip->join_code)->assertForbidden();
    }

    public function test_trip_validation_rejects_bad_input(): void
    {
        // end before start
        $this->post('/trips', $this->validTripData(['ends_on' => '2026-11-30']))->assertSessionHasErrors('ends_on');
        // no rooms
        $this->post('/trips', $this->validTripData(['rooms' => "  \n "]))->assertSessionHasErrors('rooms');
        // missing name
        $this->post('/trips', $this->validTripData(['trip_name' => '']))->assertSessionHasErrors('trip_name');
        // too many beds in one room
        $beds = implode(',', range(1, 11));
        $this->post('/trips', $this->validTripData(['rooms' => "Big room: $beds"]))->assertSessionHasErrors('rooms');

        $this->assertSame(0, Trip::count());
    }

    public function test_user_supplied_names_are_escaped_in_the_page(): void
    {
        [$trip, $token] = $this->createTrip(['trip_name' => '<script>alert(1)</script>']);

        $this->asMember($trip, $token)->get('/t/'.$trip->join_code)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_audit_log_rows_cannot_be_edited(): void
    {
        [$trip] = $this->createTrip();
        $row = AuditLog::firstOrFail();

        $this->expectException(\LogicException::class);
        $row->update(['action' => 'tampered']);
    }
}
