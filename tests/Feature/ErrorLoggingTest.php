<?php

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\Member;
use App\Models\Trip;
use App\Support\ErrorRecorder;
use App\Support\MemberToken;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/** Error logging (error_logs table, friendly pages, no leaks) and the ADMIN_KEY-protected viewer. */
class ErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'a-long-enough-admin-key';

    // ---- what users see and what gets recorded --------------------------------------------------

    public function test_unexpected_error_is_saved_and_the_user_sees_only_a_friendly_page(): void
    {
        config(['app.debug' => false]);   // like production
        Route::get('/boom-test', fn () => throw new RuntimeException('db password is hunter2'));

        $this->get('/boom-test')
            ->assertStatus(500)
            ->assertSee('Something went wrong')
            ->assertDontSee('hunter2')
            ->assertDontSee('RuntimeException');

        $log = ErrorLog::sole();
        $this->assertSame('db password is hunter2', $log->message);   // the OWNER can see the truth...
        $this->assertSame(RuntimeException::class, $log->exception_class);
        $this->assertSame('boom-test', $log->url);
        $this->assertSame('GET', $log->method);
    }

    public function test_json_errors_are_generic_too(): void
    {
        config(['app.debug' => false]);
        Route::get('/boom-json', fn () => throw new RuntimeException('secret detail'));

        $this->getJson('/boom-json')->assertStatus(500)->assertJsonPath('message', 'Server Error');
    }

    public function test_normal_not_found_is_friendly_and_not_logged_as_an_error(): void
    {
        $this->get('/no-such-page')->assertStatus(404)->assertSee('Page not found');
        $this->assertSame(0, ErrorLog::count());
    }

    public function test_removed_member_sees_a_clear_403_message(): void
    {
        $trip = Trip::create(['name' => 'T', 'join_code' => Trip::newJoinCode(), 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02']);
        $token = MemberToken::generate();
        $trip->members()->create(['name' => 'Gone', 'role' => Member::ROLE_MEMBER, 'token_hash' => MemberToken::hash($token)])
            ->forceFill(['removed_at' => now()])->save();

        $this->withCookie(MemberToken::cookieName($trip->join_code), $token)
            ->get("/t/{$trip->join_code}")
            ->assertStatus(403)
            ->assertSee('You have been removed from this trip.');
        $this->assertSame(0, ErrorLog::count());
    }

    public function test_query_errors_are_stored_without_the_bound_values(): void
    {
        $e = new QueryException(
            'mysql', 'insert into members (name) values (?)', ['SECRET-NAME'],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation'),
        );

        ErrorRecorder::record($e);

        $log = ErrorLog::sole();
        $this->assertStringContainsString('Integrity constraint violation', $log->message);
        $this->assertStringNotContainsString('SECRET-NAME', $log->message);
    }

    // ---- the admin page --------------------------------------------------------------------------

    public function test_admin_pages_are_off_when_no_usable_key_is_configured(): void
    {
        config(['lobby.admin_key' => '']);
        $this->get('/admin/login')->assertStatus(404);
        $this->withSession(['lobby_admin' => true])->get('/admin/errors')->assertStatus(404);

        config(['lobby.admin_key' => 'too-short']);   // under 16 characters counts as "not set"
        $this->get('/admin/login')->assertStatus(404);
        $this->post('/admin/login', ['key' => 'too-short'])->assertStatus(404);
    }

    public function test_errors_page_needs_login(): void
    {
        config(['lobby.admin_key' => self::KEY]);
        $this->get('/admin/errors')->assertRedirect('/admin/login');
    }

    public function test_wrong_key_is_refused_and_right_key_logs_in(): void
    {
        config(['lobby.admin_key' => self::KEY]);

        $this->post('/admin/login', ['key' => 'wrong'])->assertSessionHasErrors('key')->assertSessionMissing('lobby_admin');
        $this->post('/admin/login', ['key' => self::KEY])->assertRedirect('/admin/errors')->assertSessionHas('lobby_admin', true);
    }

    public function test_admin_sees_errors_with_html_escaped(): void
    {
        config(['lobby.admin_key' => self::KEY]);
        ErrorLog::create([
            'message' => '<script>alert(1)</script>', 'exception_class' => 'X', 'method' => 'GET', 'url' => 't/{code}',
        ]);

        $this->withSession(['lobby_admin' => true])->get('/admin/errors')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_trip_members_are_not_admins(): void
    {
        config(['lobby.admin_key' => self::KEY]);
        $trip = Trip::create(['name' => 'T', 'join_code' => Trip::newJoinCode(), 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02']);
        $token = MemberToken::generate();
        $trip->members()->create(['name' => 'Org', 'role' => Member::ROLE_ORGANISER, 'token_hash' => MemberToken::hash($token)]);

        $this->withCookie(MemberToken::cookieName($trip->join_code), $token)
            ->get('/admin/errors')->assertRedirect('/admin/login');
    }
}
