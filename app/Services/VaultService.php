<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trip;
use App\Models\VaultFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The vault: private files (tickets, passports) for one trip.
 *
 * Rules in one place:
 *   - organiser: upload, open, delete, end the trip.   member: upload, open.   viewer: open only.
 *   - Files live in a PRIVATE S3 bucket under a random key. Nobody gets a permanent link: every "open"
 *     is checked here and answered with a link that dies after 60 seconds.
 *   - When a trip ends (end date passed, or the organiser taps "End trip now") every file is deleted from
 *     S3. The database rows and the audit log stay as a record.
 *
 * Same style as BedService: failures come back as an ActionResult (not an exception) so the audit row
 * of a refused attempt is still saved.
 *
 * LOCK ORDER (same everywhere, so two actions can never deadlock): member row first, then trip row.
 * The trip row lock is what stops an upload and "End trip now" from racing: whichever takes the lock
 * first wins, and the other one sees the result.
 */
class VaultService
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('lobby.vault.disk'));
    }

    // ---- upload --------------------------------------------------------------------------------------

    public function upload(Trip $trip, Member $actor, UploadedFile $file): ActionResult
    {
        // Cheap checks first, so a refused person never causes a pointless upload to S3.
        if ($problem = $this->cannotUpload($trip, $actor)) {
            return $problem;
        }
        if (Trip::whereKey($trip->id)->whereNotNull('ended_at')->exists()) {
            return ActionResult::fail('This trip has ended, so the vault is closed.', 409);
        }

        // Random key: nobody can guess it, and the real file name (which may say "passport_john") never
        // appears in S3. Uses the trip id, not the join code (the code is what lets people in).
        $key = 'trips/'.$trip->id.'/'.bin2hex(random_bytes(16));
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream'); // detected from the bytes, not the client's claim

        // 1) Store the bytes first (slow, over the network, so no database lock is held meanwhile).
        $stream = fopen($file->getRealPath(), 'r');
        try {
            $stored = $this->disk()->put($key, $stream, ['mimetype' => $mime]);
        } catch (Throwable $e) {
            report($e);

            return ActionResult::fail('Could not store the file. Please try again.', 500);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($stored === false) {
            return ActionResult::fail('Could not store the file. Please try again.', 500);
        }

        // 2) Record it. If anything goes wrong from here on, remove the stored bytes again so nothing is orphaned.
        // (If the whole server dies right here, the bucket's 30-day lifecycle rule cleans the leftover.)
        try {
            $result = DB::transaction(fn () => $this->record($trip, $actor, $file, $key, $mime), 3);
        } catch (Throwable $e) {
            $this->discard($key);
            throw $e;
        }

        if (! $result->ok) {
            $this->discard($key);
        }

        return $result;
    }

    private function record(Trip $trip, Member $actor, UploadedFile $file, string $key, string $mime): ActionResult
    {
        // Lock the person, then the trip. Re-checked here because time has passed since the cheap check.
        $member = Member::where('trip_id', $trip->id)->whereKey($actor->id)->lockForUpdate()->first();
        $fresh = Trip::whereKey($trip->id)->lockForUpdate()->first();

        if ($problem = $this->cannotUpload($trip, $actor, $member)) {
            return $problem;
        }
        if ($fresh->hasEnded()) {
            return ActionResult::fail('This trip has ended, so the vault is closed.', 409);
        }

        $count = VaultFile::where('trip_id', $trip->id)->whereNull('deleted_at')->count();
        if ($count >= config('lobby.vault.max_files')) {
            return ActionResult::fail('The vault is full ('.config('lobby.vault.max_files').' files). Ask the organiser to delete one.', 422);
        }

        $name = $this->cleanName($file->getClientOriginalName());
        $row = VaultFile::create([
            'trip_id' => $trip->id,
            'uploaded_by_member_id' => $actor->id,
            'original_name' => $name,
            's3_key' => $key,
            'mime_type' => mb_substr($mime, 0, 100),
            'size_bytes' => (int) $file->getSize(),
        ]);
        AuditLog::record($trip->id, $actor->id, 'file_uploaded', 'file', $row->id, [
            'name' => $name, 'size_bytes' => $row->size_bytes,
        ]);

        return ActionResult::ok('Uploaded '.$name.'.');
    }

    // ---- open (60-second link) -------------------------------------------------------------------------

    /** Any active member (viewers too). The ActionResult's `value` is the link. */
    public function openLink(Trip $trip, Member $actor, int $fileId): ActionResult
    {
        if (Trip::whereKey($trip->id)->whereNotNull('ended_at')->exists()) {
            return ActionResult::fail('This trip has ended, so its files were deleted.', 410);
        }

        // Scoped to THIS trip: a file id from another trip finds nothing (404, no hints about whether it exists).
        $file = VaultFile::where('trip_id', $trip->id)->whereKey($fileId)->whereNull('deleted_at')->first();
        if ($file === null) {
            $this->refused($trip, $actor, 'open_file', 'file_not_found');

            return ActionResult::fail('That file is not there any more.', 404);
        }

        $seconds = (int) config('lobby.vault.link_seconds');
        try {
            $url = $this->disk()->temporaryUrl($file->s3_key, now()->addSeconds($seconds), [
                // Shown as the file name when it opens or is saved. ASCII only: header-safe.
                'ResponseContentDisposition' => 'inline; filename="'.$this->headerSafeName($file->original_name).'"',
                'ResponseContentType' => $file->mime_type,
            ]);
        } catch (Throwable $e) {
            report($e);

            return ActionResult::fail('Could not open the file. Please try again.', 500);
        }

        // Every issued link is on the record: who looked at which file, and when.
        AuditLog::record($trip->id, $actor->id, 'file_viewed', 'file', $file->id, ['name' => $file->original_name]);

        return ActionResult::ok('', $url);
    }

    // ---- delete one file -------------------------------------------------------------------------------

    public function delete(Trip $trip, Member $actor, int $fileId): ActionResult
    {
        if ($problem = $this->cannotOrganise($trip, $actor, 'delete_file')) {
            return $problem;
        }

        $file = VaultFile::where('trip_id', $trip->id)->whereKey($fileId)->whereNull('deleted_at')->first();
        if ($file === null) {
            return ActionResult::fail('That file is already gone.', 404);
        }

        // S3 first. If this fails we change nothing, so the file is still listed and can be deleted again.
        // (Deleting a key that is already gone is not an error in S3, so a retry is always safe.)
        try {
            $this->disk()->delete($file->s3_key);
        } catch (Throwable $e) {
            report($e);

            return ActionResult::fail('Could not delete the file. Please try again.', 500);
        }

        DB::transaction(function () use ($trip, $actor, $file) {
            $row = VaultFile::whereKey($file->id)->lockForUpdate()->first();
            if ($row->deleted_at === null) {
                $row->forceFill(['deleted_at' => now()])->save();
                AuditLog::record($trip->id, $actor->id, 'file_deleted', 'file', $row->id, ['name' => $row->original_name]);
            }
        }, 3);

        return ActionResult::ok('Deleted '.$file->original_name.'.');
    }

    // ---- end of trip -----------------------------------------------------------------------------------

    /** The organiser's "End trip now": closes the trip and deletes every file straight away. */
    public function endTrip(Trip $trip, Member $actor): ActionResult
    {
        if ($problem = $this->cannotOrganise($trip, $actor, 'end_trip')) {
            return $problem;
        }

        $newlyEnded = DB::transaction(function () use ($trip, $actor) {
            Member::where('trip_id', $trip->id)->whereKey($actor->id)->lockForUpdate()->first();
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->first();
            if ($locked->hasEnded()) {
                return false; // harmless repeat (double tap): nothing new happened, so nothing to log
            }
            $locked->forceFill(['ended_at' => now()])->save();
            AuditLog::record($trip->id, $actor->id, 'trip_ended', 'trip', $trip->id, ['reason' => 'organiser_ended_it']);

            return true;
        }, 3);

        // The slow part (S3 calls) runs AFTER the database lock is released.
        $failed = $this->purgeTrip($trip);

        if ($failed > 0) {
            return ActionResult::ok('Trip ended. '.$failed.' file(s) could not be deleted yet; the system keeps retrying every few minutes.');
        }

        return ActionResult::ok($newlyEnded ? 'Trip ended. All vault files were deleted.' : 'This trip had already ended.');
    }

    /** Scheduled job step 1: trips whose end date has passed get closed (once). Returns how many. */
    public function closeFinishedTrips(): int
    {
        $closed = 0;

        // Ids first, then one small transaction each. Re-checking inside makes a repeated run harmless.
        $ids = Trip::whereNull('ended_at')->whereDate('ends_on', '<', today())->pluck('id');
        foreach ($ids as $id) {
            $closed += DB::transaction(function () use ($id) {
                $trip = Trip::whereKey($id)->lockForUpdate()->first();
                if ($trip === null || $trip->hasEnded()) {
                    return 0;
                }
                $trip->forceFill(['ended_at' => now()])->save();
                AuditLog::record($trip->id, null, 'trip_ended', 'trip', $trip->id, ['reason' => 'end_date_passed']);

                return 1;
            }, 3);
        }

        return $closed;
    }

    /** Scheduled job step 2: empty the vault of every ended trip that still has files. Returns failures. */
    public function purgeEndedTrips(): int
    {
        $failures = 0;
        foreach (Trip::whereNotNull('ended_at')->whereNull('vault_purged_at')->pluck('id') as $id) {
            $failures += $this->purgeTrip(Trip::findOrFail($id));
        }

        return $failures;
    }

    /**
     * Delete every file of one ended trip from S3. Safe to run again and again (retry-safe):
     *   - deleting a missing S3 key is fine, and rows already marked deleted are skipped;
     *   - one failing file never stops the others, and the trip is only marked "purged" when ALL are gone,
     *     so the next run picks up exactly what is left.
     * Returns the number of failures (0 = vault empty). Failures go to the log and the error_logs table.
     */
    public function purgeTrip(Trip $trip): int
    {
        $failed = 0;
        $deleted = 0;

        foreach (VaultFile::where('trip_id', $trip->id)->whereNull('deleted_at')->orderBy('id')->get() as $file) {
            try {
                $this->disk()->delete($file->s3_key);
                $file->forceFill(['deleted_at' => now()])->save();
                $deleted++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        // Belt and braces: also sweep the trip's whole folder, in case a crash once left a file with no database row.
        try {
            $this->disk()->deleteDirectory('trips/'.$trip->id);
        } catch (Throwable $e) {
            report($e);
            $failed++;
        }

        if ($failed === 0) {
            // Only the run that flips the flag writes the audit row, so two overlapping runs log it once.
            $flipped = Trip::whereKey($trip->id)->whereNull('vault_purged_at')->update(['vault_purged_at' => now()]);
            if ($flipped === 1) {
                AuditLog::record($trip->id, null, 'vault_purged', 'trip', $trip->id, ['files_deleted' => $deleted]);
            }
        }

        return $failed;
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    // Re-reads the person from the database, so a stale object (just demoted or removed) cannot slip through.
    private function cannotUpload(Trip $trip, Member $actor, ?Member $fresh = null): ?ActionResult
    {
        $fresh ??= Member::where('trip_id', $trip->id)->whereKey($actor->id)->first();

        if ($fresh === null || $fresh->isRemoved() || ! $fresh->canUpload()) {
            $this->refused($trip, $actor, 'upload_file', 'role_cannot_upload');

            return ActionResult::fail('Viewers can look at files but not upload them.', 403);
        }

        return null;
    }

    private function cannotOrganise(Trip $trip, Member $actor, string $attempted): ?ActionResult
    {
        $fresh = Member::where('trip_id', $trip->id)->whereKey($actor->id)->first();

        if ($fresh === null || $fresh->isRemoved() || ! $fresh->isOrganiser()) {
            $this->refused($trip, $actor, $attempted, 'not_organiser');

            return ActionResult::fail('Only the organiser can do that.', 403);
        }

        return null;
    }

    // One place that writes the "someone tried something they may not" row.
    private function refused(Trip $trip, Member $actor, string $attempted, string $reason): void
    {
        AuditLog::record($trip->id, $actor->id, 'action_refused', null, null, ['attempted' => $attempted, 'reason' => $reason]);
    }

    private function discard(string $key): void
    {
        try {
            $this->disk()->delete($key);
        } catch (Throwable $e) {
            report($e); // leftover is caught by the bucket's lifecycle rule
        }
    }

    // Display name: no path pieces, no control characters, not absurdly long. Blade escapes it on output.
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 150));

        return $name === '' ? 'file' : $name;
    }

    // Used inside an HTTP header: letters, digits and a few safe symbols only.
    private function headerSafeName(string $name): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $name));

        return $safe === '' ? 'file' : $safe;
    }
}
