<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trip;
use App\Models\VaultFile;
use App\Services\VaultService;
use App\Support\MemberToken;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The vault: roles, 60-second links, delete, "End trip now" and the scheduled purge.
 * Uses Storage::fake('s3'): files go to a throw-away folder, never to the real bucket.
 */
class VaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fake disk cannot sign links, so we teach it a fake signing rule. It still carries the expiry,
        // which lets the tests check "60 seconds".
        Storage::fake('s3')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options) => 'https://signed.test/'.$path.'?expires='.$expiration->timestamp
        );
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function makeTrip(string $endsOn = '2099-12-05'): Trip
    {
        return Trip::create(['name' => 'Test trip', 'join_code' => Trip::newJoinCode(), 'starts_on' => '2026-12-01', 'ends_on' => $endsOn]);
    }

    /** @return array{0: Member, 1: string} */
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
        return $this->withCookie(MemberToken::cookieName($trip->join_code), $token)->withCredentials();
    }

    private function url(Trip $trip, string $path): string
    {
        return "/t/{$trip->join_code}/{$path}";
    }

    private function pdf(string $name = 'ticket.pdf', int $kb = 50): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    /** Upload as the given person and return the created row. */
    private function upload(Trip $trip, string $token, ?UploadedFile $file = null): ?VaultFile
    {
        $this->as($trip, $token)->post($this->url($trip, 'vault'), ['file' => $file ?? $this->pdf()]);

        return VaultFile::where('trip_id', $trip->id)->latest('id')->first();
    }

    // ---- who may do what ---------------------------------------------------------------------------

    public function test_everyone_in_the_trip_can_open_the_vault_page_but_strangers_cannot(): void
    {
        $trip = $this->makeTrip();
        [, $org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [, $viewer] = $this->makeMember($trip, 'Vik', Member::ROLE_VIEWER);

        // Stranger first: once withCookie() is used the test client keeps sending that cookie.
        $this->get($this->url($trip, 'vault'))->assertRedirect(route('join.show', $trip->join_code));
        $this->as($trip, $org)->get($this->url($trip, 'vault'))->assertOk()->assertSee('Add a file')->assertSee('End trip now');
        $this->as($trip, $viewer)->get($this->url($trip, 'vault'))->assertOk()->assertDontSee('Add a file')->assertDontSee('End trip now');
    }

    public function test_member_and_organiser_can_upload_and_the_file_is_stored_under_a_random_key(): void
    {
        $trip = $this->makeTrip();
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia, $miaToken] = $this->makeMember($trip, 'Mia');

        $byMia = $this->upload($trip, $miaToken, $this->pdf('flight tickets.pdf'));
        $byOlga = $this->upload($trip, $orgToken, $this->pdf('hotel.pdf'));

        $this->assertNotNull($byMia);
        $this->assertSame($mia->id, $byMia->uploaded_by_member_id);
        $this->assertSame('flight tickets.pdf', $byMia->original_name);
        $this->assertSame($org->id, $byOlga->uploaded_by_member_id);

        // Stored under trips/{trip id}/{random}: the real name never appears in S3.
        $this->assertMatchesRegularExpression('#^trips/'.$trip->id.'/[0-9a-f]{32}$#', $byMia->s3_key);
        Storage::disk('s3')->assertExists($byMia->s3_key);
        $this->assertSame(2, AuditLog::where('trip_id', $trip->id)->where('action', 'file_uploaded')->count());
    }

    public function test_viewer_cannot_upload_and_it_is_logged(): void
    {
        $trip = $this->makeTrip();
        [$viewer, $token] = $this->makeMember($trip, 'Vik', Member::ROLE_VIEWER);

        $this->as($trip, $token)->post($this->url($trip, 'vault'), ['file' => $this->pdf()])
            ->assertRedirect()->assertSessionHas('problem');

        $this->assertSame(0, VaultFile::count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $log = AuditLog::where('action', 'action_refused')->firstOrFail();
        $this->assertSame($viewer->id, $log->member_id);
        $this->assertEquals(['attempted' => 'upload_file', 'reason' => 'role_cannot_upload'], $log->details);
    }

    public function test_wrong_file_types_and_big_files_are_rejected(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');

        $this->as($trip, $token)->post($this->url($trip, 'vault'), ['file' => UploadedFile::fake()->create('run.php', 5, 'text/x-php')])->assertSessionHasErrors('file');
        $this->as($trip, $token)->post($this->url($trip, 'vault'), ['file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')])->assertSessionHasErrors('file');
        $this->as($trip, $token)->post($this->url($trip, 'vault'), ['file' => $this->pdf('big.pdf', config('lobby.vault.max_kb') + 1)])->assertSessionHasErrors('file');
        $this->as($trip, $token)->post($this->url($trip, 'vault'), [])->assertSessionHasErrors('file');

        $this->assertSame(0, VaultFile::count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_photos_including_heic_are_accepted(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');

        $this->upload($trip, $token, UploadedFile::fake()->create('passport.jpg', 20, 'image/jpeg'));
        $this->upload($trip, $token, UploadedFile::fake()->create('IMG_0001.heic', 20, 'image/heic'));

        $this->assertSame(2, VaultFile::count());
    }

    public function test_file_names_are_cleaned_and_escaped(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');

        $path = $this->upload($trip, $token, $this->pdf('../../etc/passwd.pdf'));
        $this->assertSame('passwd.pdf', $path->original_name);

        // A name that tries to inject HTML must come out as harmless text.
        $evil = $this->upload($trip, $token, $this->pdf('<img src=x onerror=alert(1)>.pdf'));
        $this->assertNotNull($evil);
        $page = $this->as($trip, $token)->get($this->url($trip, 'vault'))->assertOk();
        $page->assertDontSee('<img src=x', false);
        $page->assertSee('&lt;img src=x onerror=alert(1)&gt;.pdf', false);
    }

    public function test_vault_is_full_after_the_file_limit_and_the_extra_upload_is_not_left_in_storage(): void
    {
        config(['lobby.vault.max_files' => 2]);
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');

        $this->upload($trip, $token);
        $this->upload($trip, $token);
        $this->as($trip, $token)->post($this->url($trip, 'vault'), ['file' => $this->pdf('third.pdf')])->assertSessionHas('problem');

        $this->assertSame(2, VaultFile::count());
        $this->assertCount(2, Storage::disk('s3')->allFiles()); // the refused third file was cleaned up
    }

    // ---- 60-second links ---------------------------------------------------------------------------

    public function test_any_member_even_a_viewer_gets_a_link_that_lives_60_seconds(): void
    {
        $trip = $this->makeTrip();
        [, $miaToken] = $this->makeMember($trip, 'Mia');
        [$viewer, $viewerToken] = $this->makeMember($trip, 'Vik', Member::ROLE_VIEWER);
        $file = $this->upload($trip, $miaToken);

        $response = $this->as($trip, $viewerToken)->get($this->url($trip, "vault/{$file->id}/open"));

        $response->assertRedirectContains('https://signed.test/'.$file->s3_key);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        preg_match('/expires=(\d+)/', $response->headers->get('Location'), $m);
        $this->assertEqualsWithDelta(now()->timestamp + 60, (int) $m[1], 3); // 60 seconds, not a minute more
        $log = AuditLog::where('action', 'file_viewed')->firstOrFail();
        $this->assertSame($viewer->id, $log->member_id);
        $this->assertSame($file->id, $log->target_id);
    }

    public function test_a_file_of_another_trip_cannot_be_opened(): void
    {
        $tripA = $this->makeTrip();
        $tripB = $this->makeTrip();
        [, $tokenA] = $this->makeMember($tripA, 'Ann');
        [, $tokenB] = $this->makeMember($tripB, 'Bob');
        $secret = $this->upload($tripB, $tokenB);

        // Ann is a real member of trip A, but asks trip A's URL for trip B's file id.
        $response = $this->as($tripA, $tokenA)->get($this->url($tripA, "vault/{$secret->id}/open"));

        $response->assertRedirect(route('vault.index', $tripA->join_code))->assertSessionHas('problem');
        $this->assertStringNotContainsString('signed.test', (string) $response->headers->get('Location'));
        $this->assertSame(1, AuditLog::where('trip_id', $tripA->id)->where('action', 'action_refused')->count());
    }

    public function test_a_removed_member_loses_the_vault_immediately(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia, $miaToken] = $this->makeMember($trip, 'Mia');
        $file = $this->upload($trip, $orgToken);

        $this->as($trip, $miaToken)->get($this->url($trip, "vault/{$file->id}/open"))->assertRedirectContains('signed.test');

        $mia->forceFill(['removed_at' => now()])->save();

        $this->as($trip, $miaToken)->get($this->url($trip, 'vault'))->assertForbidden();
        $this->as($trip, $miaToken)->get($this->url($trip, "vault/{$file->id}/open"))->assertForbidden();
        $this->as($trip, $miaToken)->post($this->url($trip, 'vault'), ['file' => $this->pdf()])->assertForbidden();
    }

    public function test_opening_is_rate_limited(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');
        $file = $this->upload($trip, $token); // counts as 1 of the 20 allowed per minute

        for ($i = 0; $i < 19; $i++) {
            $this->as($trip, $token)->get($this->url($trip, "vault/{$file->id}/open"))->assertRedirectContains('signed.test');
        }
        $this->as($trip, $token)->get($this->url($trip, "vault/{$file->id}/open"))
            ->assertRedirect(route('vault.index', $trip->join_code))->assertSessionHas('problem');
        $this->assertSame(1, AuditLog::where('action', 'rate_limited')->count());
    }

    // ---- delete one file --------------------------------------------------------------------------

    public function test_organiser_can_delete_a_file_and_it_is_really_gone(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $file = $this->upload($trip, $orgToken);

        $this->as($trip, $orgToken)->post($this->url($trip, "vault/{$file->id}/delete"))->assertSessionHas('status');

        Storage::disk('s3')->assertMissing($file->s3_key);
        $this->assertNotNull($file->fresh()->deleted_at);
        $this->assertSame(1, AuditLog::where('action', 'file_deleted')->count());
        $this->as($trip, $orgToken)->get($this->url($trip, "vault/{$file->id}/open"))->assertSessionHas('problem'); // no link for a deleted file
    }

    public function test_a_member_cannot_delete_files_or_end_the_trip(): void
    {
        $trip = $this->makeTrip();
        [, $miaToken] = $this->makeMember($trip, 'Mia');
        $file = $this->upload($trip, $miaToken);

        $this->as($trip, $miaToken)->post($this->url($trip, "vault/{$file->id}/delete"))->assertForbidden();
        $this->as($trip, $miaToken)->post($this->url($trip, 'end-trip'))->assertForbidden();

        Storage::disk('s3')->assertExists($file->s3_key);
        $this->assertNull($file->fresh()->deleted_at);
        $this->assertNull($trip->fresh()->ended_at);
    }

    public function test_the_service_itself_also_refuses_non_organisers_and_logs_it(): void
    {
        // The route middleware is the first lock; this proves the second one inside the service.
        $trip = $this->makeTrip();
        [$mia, $miaToken] = $this->makeMember($trip, 'Mia');
        $file = $this->upload($trip, $miaToken);

        $service = app(VaultService::class);
        $this->assertFalse($service->delete($trip, $mia, $file->id)->ok);
        $this->assertFalse($service->endTrip($trip, $mia)->ok);

        $this->assertNull($trip->fresh()->ended_at);
        $this->assertSame(2, AuditLog::where('action', 'action_refused')->count());
    }

    public function test_if_s3_fails_to_delete_nothing_is_marked_deleted_so_it_can_be_retried(): void
    {
        $trip = $this->makeTrip();
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $file = $this->upload($trip, $orgToken);

        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('delete')->andThrow(new RuntimeException('S3 is down'));
        Storage::set('s3', $broken);

        $result = app(VaultService::class)->delete($trip, $org, $file->id);

        $this->assertFalse($result->ok);
        $this->assertNull($file->fresh()->deleted_at); // still listed, still deletable
        $this->assertSame(0, AuditLog::where('action', 'file_deleted')->count());
    }

    // ---- end of trip -----------------------------------------------------------------------------

    public function test_end_trip_now_deletes_every_file_closes_the_vault_and_keeps_the_audit_log(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [, $miaToken] = $this->makeMember($trip, 'Mia');
        $a = $this->upload($trip, $orgToken);
        $b = $this->upload($trip, $miaToken);
        $auditBefore = AuditLog::where('trip_id', $trip->id)->count();

        $this->as($trip, $orgToken)->post($this->url($trip, 'end-trip'))->assertSessionHas('status');

        Storage::disk('s3')->assertMissing($a->s3_key);
        Storage::disk('s3')->assertMissing($b->s3_key);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $trip->refresh();
        $this->assertNotNull($trip->ended_at);
        $this->assertNotNull($trip->vault_purged_at);
        $this->assertSame(2, VaultFile::whereNotNull('deleted_at')->count());

        // The history survives and gained entries; nothing was removed.
        $this->assertGreaterThan($auditBefore, AuditLog::where('trip_id', $trip->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'trip_ended')->count());
        $this->assertSame(1, AuditLog::where('action', 'vault_purged')->count());

        // Vault is closed: no uploads, no links, and the page says so.
        $this->as($trip, $miaToken)->post($this->url($trip, 'vault'), ['file' => $this->pdf()])->assertSessionHas('problem');
        $this->as($trip, $miaToken)->get($this->url($trip, "vault/{$a->id}/open"))->assertSessionHas('problem');
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->as($trip, $miaToken)->get($this->url($trip, 'vault'))->assertOk()->assertSee('This trip has ended');
    }

    public function test_tapping_end_trip_twice_is_harmless(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $this->upload($trip, $orgToken);

        $this->as($trip, $orgToken)->post($this->url($trip, 'end-trip'));
        $this->as($trip, $orgToken)->post($this->url($trip, 'end-trip'))->assertSessionHas('status');

        $this->assertSame(1, AuditLog::where('action', 'trip_ended')->count());
        $this->assertSame(1, AuditLog::where('action', 'vault_purged')->count());
    }

    // ---- the scheduled purge --------------------------------------------------------------------

    public function test_the_purge_command_closes_trips_whose_end_date_passed_and_leaves_others_alone(): void
    {
        $over = $this->makeTrip(now()->subDay()->toDateString());
        $today = $this->makeTrip(now()->toDateString());  // the end date itself is still the trip
        $future = $this->makeTrip(now()->addDays(3)->toDateString());
        $files = [];
        foreach ([$over, $today, $future] as $trip) {
            [, $token] = $this->makeMember($trip, 'Mia');
            $files[$trip->id] = $this->upload($trip, $token);
        }

        Artisan::call('vault:purge');

        Storage::disk('s3')->assertMissing($files[$over->id]->s3_key);
        Storage::disk('s3')->assertExists($files[$today->id]->s3_key);
        Storage::disk('s3')->assertExists($files[$future->id]->s3_key);
        $this->assertNotNull($over->fresh()->vault_purged_at);
        $this->assertNull($today->fresh()->ended_at);
        $this->assertNull($future->fresh()->ended_at);
        $this->assertSame('end_date_passed', AuditLog::where('action', 'trip_ended')->firstOrFail()->details['reason']);
    }

    public function test_running_the_purge_again_changes_nothing(): void
    {
        $over = $this->makeTrip(now()->subDay()->toDateString());
        [, $token] = $this->makeMember($over, 'Mia');
        $this->upload($over, $token);

        $this->assertSame(0, Artisan::call('vault:purge'));
        $auditAfterFirst = AuditLog::count();
        $this->assertSame(0, Artisan::call('vault:purge'));

        $this->assertSame($auditAfterFirst, AuditLog::count()); // no duplicate "ended"/"purged" rows
    }

    public function test_purge_failure_is_not_silent_and_the_next_run_finishes_the_job(): void
    {
        $over = $this->makeTrip(now()->subDay()->toDateString());
        [, $token] = $this->makeMember($over, 'Mia');
        $file = $this->upload($over, $token);

        // Run 1: S3 is unreachable.
        $real = Storage::disk('s3');
        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('delete')->andThrow(new RuntimeException('S3 is down'));
        $broken->shouldReceive('deleteDirectory')->andThrow(new RuntimeException('S3 is down'));
        Storage::set('s3', $broken);

        $this->assertSame(1, Artisan::call('vault:purge')); // non-zero exit code = visible failure
        $this->assertNull($over->fresh()->vault_purged_at);    // NOT marked as done
        $this->assertNull($file->fresh()->deleted_at);

        // Run 2: S3 is back. Nothing was lost, so it simply completes.
        Storage::set('s3', $real);
        $this->assertSame(0, Artisan::call('vault:purge'));
        $this->assertNotNull($over->fresh()->vault_purged_at);
        $this->assertNotNull($file->fresh()->deleted_at);
        Storage::disk('s3')->assertMissing($file->s3_key);
    }

    public function test_purge_also_sweeps_a_stray_file_that_has_no_database_row(): void
    {
        $over = $this->makeTrip(now()->subDay()->toDateString());
        Storage::disk('s3')->put('trips/'.$over->id.'/orphan-from-a-crash', 'x');
        Storage::disk('s3')->put('trips/999999/other-trips-file', 'x');

        Artisan::call('vault:purge');

        Storage::disk('s3')->assertMissing('trips/'.$over->id.'/orphan-from-a-crash');
        Storage::disk('s3')->assertExists('trips/999999/other-trips-file'); // only this trip's folder is touched
    }
}
