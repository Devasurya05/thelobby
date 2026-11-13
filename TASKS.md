# The Lobby: TASKS.md

## Checklist (order from brief section 8)
- [x] 1. Environment: PHP 8.4.27, Composer, MySQL 8.4.9, Laravel 13 project created, DB connected, default migrations run
- [x] 1b. User: `git init` and first commit done (no secrets). PUSH PENDING: create public empty GitHub repo `thelobby`, then `git push -u origin main`
- [x] 2. Migrations + models (trips, members, rooms, beds, vault_files, audit_logs, error_logs). Verified: migrate:fresh OK, unique taken_by_member_id blocks a 2nd bed, AuditLog is append-only
- [x] 3. Create trip / join trip / token auth middleware (14 tests pass on real MySQL; smoke-tested over HTTP incl. CSRF 419)
- [x] 4. Lobby: state endpoint, grab, confirm, release, expiry command, audit log, jQuery UI (34 tests pass; verified with real concurrent processes and in a browser)
- [x] 5. `demo:race` command (37 tests pass; lobby tests in tests/Feature/LobbyTest.php, race command tests in tests/Feature/DemoRaceTest.php)
- [x] 5a. Error logging (daily log + error_logs table + /admin/errors with ADMIN_KEY + friendly error pages), audit gaps closed (rate_limited, action_refused, release_rejected), invite link (copy / WhatsApp / share) instead of QR. 49 tests pass.
- [x] 5b. Local phone test on same Wi-Fi: PASSED (user confirmed: two devices, grab/confirm/expire/race all fine). Firewall rule "The Lobby dev (port 8000)" was added by the user; remove it when not testing (see log). on same Wi-Fi (`php artisan serve --host=0.0.0.0`, firewall, `schedule:work`)
- [x] 5c. Members page (organiser: list, change role member/viewer, remove with bed release) + Audit log page (organiser, readable, newest first, "older" paging). 58 tests pass.
- [x] 6. DONE 2026-11-11, real bucket proven by a 9-check self-test (see session log "step 6 finished"). Original notes: (account created, Free plan confirmed ($100 credits, ends 2027-05-11), region = Mumbai. DONE by user: root MFA, zero-spend budget, `lobby-admin` IAM user with MFA, stage C private S3 bucket in ap-south-1 (Block Public Access on, ACLs off, versioning off, SSE-S3, HTTPS-only bucket policy, lifecycle rule: expire `trips/` after 30 days + abort incomplete multipart uploads after 1 day; known limit: 30 days counts from upload, not trip end). NEXT: stage D local-dev IAM user `lobby-local-dev` (policy limited to this bucket's `trips/` prefix, keys only in .env), then install `league/flysystem-aws-s3-v3` (ask the user first) and test) AWS: Free-plan account, budget alert, private encrypted S3 bucket + lifecycle rule, IAM user for local dev
- [x] 7. Vault: upload, 60s pre-signed links, roles, remove member, End trip now + purge, tests. DONE 2026-11-11: 79 tests pass; real S3 end-to-end script passed (16 checks). Not yet clicked through in a browser/phone by the user.
- [x] 8. DONE 2026-11-11 (live over HTTPS, 24/24 smoke checks pass) Deploy: RDS, EC2 + IAM role, Nginx/PHP-FPM, cron, migrate, test demo live
- [ ] 9. README, docs/STUDY_GUIDE.md, PROJECT.md, TASKS.md final
- [ ] 10. Optional: SNS alerts, CloudWatch

Cross-cutting: configurable hold time in .env (done), invite LINK instead of QR (done), mobile-first UI, HEIC upload handling, demo-files/, Members page, Audit log page. (Error logging: done.)

## Session log (append only)

### 2026-11-11 (session 1)
Done:
- Wrote private instruction files (kept out of git, see .gitignore).
- Installed PHP 8.4.27 (winget), Composer 2.11.2, MySQL 8.4.9. Initialised MySQL data dir, created DBs `thelobby`/`thelobby_test` and app user. Scaffolded Laravel 13.42, configured `.env`, ran default migrations OK.
Problems and fixes:
- winget PHP has no php.ini: created one and enabled extensions.
- PATH not refreshed in the same shell: use full paths or refresh PATH.
Next:
- Ask the user to confirm repo name, give git init commands, then start step 2 (migrations + models).

### 2026-11-11 (session 1, continued)
Done:
- User made first git commit (3d71477), `.env` confirmed ignored. Push failed only because the GitHub repo did not exist yet.
- Removed default users/password_reset tables, User model, UserFactory; kept `sessions` table (needed for CSRF). DatabaseSeeder is empty.
- Wrote 7 migrations + 7 models (Trip, Member, Room, Bed, VaultFile, AuditLog, ErrorLog) with relations, role/status constants, Bed::isAvailable() (expired hold counts as free).
- Verified with a throwaway script: relations work, DB unique constraint blocks a 2nd taken bed, AuditLog throws on update/delete.
Problems and fixes:
- `php artisan tinker --execute` mangles quotes in PowerShell; use a small PHP script file instead.
Next:
- Step 3: create trip / join trip / token-cookie auth middleware (members.token_hash, HttpOnly SameSite=Lax cookie per trip), join_code generator, routes, Blade home page.
- User still to do: create public empty GitHub repo `thelobby`, then `git push -u origin main`; commit the new migrations/models.
  (DONE later in the session: repo was recreated, private files removed from git, pushed as commit d3e30cf.)

### 2026-11-11 (session 1, step 3)
Done:
- Privacy fix: CLAUDE.md and docs/PROJECT_BRIEF.md are git-ignored (public repo must not hold private notes). Repo recreated by the user and pushed clean.
- Step 3 built: `MemberToken` (token + sha256 hash + per-trip HttpOnly/SameSite=Lax cookie scoped to /t/{code}), `ResolveMember` middleware (alias `trip.member`; 404 unknown trip, redirect/401 if no valid cookie, 403 if removed), `TripController@store`, `JoinController`, `LobbyController` (stub page), Form Requests, `RoomListParser` (textarea "Room 1: Window, Door"), `Trip::newJoinCode()`, `AuditLog::record()`.
- Plain CSS in public/css/app.css (mobile-first). Views: layouts/app, home, join, lobby (stub). No jQuery yet.
- phpunit.xml now uses MySQL `thelobby_test` (PHP here has no SQLite driver, and locking must be tested on real MySQL). 14 tests in tests/Feature/TripAccessTest.php pass.
- Pint run for formatting.
Problems and fixes:
- Laravel `getJson()` does not send cookies unless `->withCredentials()`: use it in every JSON test that needs a logged-in member (the lobby state tests will).
- `ended_at` is not mass-assignable on purpose: use `forceFill()->save()` in tests.
- Home page had two fields both called `name`: renamed trip field to `trip_name`.
Next:
- Step 4: lobby. `BedService` (grab/confirm/release with DB::transaction + lockForUpdate), state JSON endpoint, expiry command + scheduler, HOLD_SECONDS in .env, jQuery polling UI (add jQuery from cdnjs with SRI), audit entries, throttle on grab/confirm, tests.
- User to commit step 3 (suggested message: "Add create/join trip and token cookie auth with tests"). (DONE by user.)

### 2026-11-11 (session 1, step 4: the lobby)
Done:
- `config/lobby.php` (HOLD_SECONDS default 120, LOBBY_POLL_MS 2000), `.env`/`.env.example` have HOLD_SECONDS.
- `app/Services/BedService.php`: grab / confirm / release / expireHolds. Every action is a DB::transaction (3 attempts, retries deadlocks) that first locks the MEMBER row then the BED row(s) with lockForUpdate. Failures return a `BedResult` (not exceptions) so the `grab_rejected` audit row is committed. Expired hold = free (audit `hold_expired` written when it is overwritten). One active hold per person; one taken bed per person (also UNIQUE in DB). Viewers, removed members and ended trips cannot play.
- `LobbyState::build()` = the JSON for the screen (eager loaded, seconds_left not timestamps, expired holds shown as free, no token hashes). `BedController` (grab/confirm/release return ok/message/fresh state, 409 for conflicts). Routes: `GET /t/{code}/state`, `POST /t/{code}/beds/{bedId}/(grab|confirm|release)`.
- Rate limit `lobby-actions` = 30/min PER MEMBER (AppServiceProvider). `bootstrap/app.php` calls `prependToPriorityList(before: ThrottleRequests, prepend: ResolveMember)` so the limiter knows who is asking.
- `php artisan lobby:expire-holds` + scheduler entry (everyMinute, withoutOverlapping) in routes/console.php.
- jQuery UI: `resources/views/lobby.blade.php`, `public/js/lobby.js` (poll every 2s with setTimeout chain, redraw only when something changed, local 1s countdown, `.text()` only for user data, CSRF header), CSS appended. jQuery 3.7.1 from cdnjs with SRI hash (verified by computing the hash of the downloaded file).
- Tests: tests/Feature/LobbyTest.php (20 tests). Total 34 tests, 136 assertions, all pass on real MySQL.
- REAL concurrency verification (throwaway script, not in repo) with separate PHP processes against MySQL: 8 people same bed in the same millisecond -> exactly 1 winner, 7 `grab_rejected` logged; one person tapping 2 beds x4 at once -> exactly 1 hold; two people swapping onto each other's EXPIRED holds -> MySQL reported 12 real deadlocks over 6 rounds and the retry resolved every one (both ended with the bed they wanted).
- Browser check: create trip, grab (countdown), confirm, reload keeps login, taken bed shown red with "Taken by you".
Problems and fixes:
- Laravel passes route params to controllers BY POSITION: `{code}` from the prefix arrives before `{bedId}`, so BedController methods take `(Request, string $code, int $bedId)`.
- ThrottleRequests is a "priority" middleware and was sorted BEFORE ResolveMember, so the per-member limiter saw no member (shared IP key). Fixed with prependToPriorityList. `route:list` shows declared order, not the sorted order; a test (test_rate_limit_is_per_person_not_shared) guards this.
- MySQL had been killed (no service, background process died with the app restart; log showed no crash) and the tests then hung on connecting. Restart with `powershell -File scripts\start-mysql.ps1`. Script now also passes `--mysqlx=OFF` (X Protocol port 33060 had been listening on all interfaces).
- The green "Bed held" message disappeared after 6s and shifted the page up, making buttons jump away from a tap: notice is now a fixed floating toast.
- `php artisan serve` is slow/single-threaded on Windows (a tap can take ~1-3 s to show). Real parallel behaviour comes with PHP-FPM on EC2.
Next:
- Step 5: `php artisan demo:race {tripCode} {bedId}` (two truly concurrent grab requests with Laravel `Http::pool`; needs two members' tokens, so the command should create two temporary demo members or take member ids; prints who won). Then 5b: phone test on same Wi-Fi (`php artisan serve --host=0.0.0.0 --port=8000`, Windows firewall rule, `schedule:work` in a second terminal) with HOLD_SECONDS=30.
- Also soon: error logging (daily log + error_logs table + /admin/errors + ADMIN_KEY), QR code on lobby page, Members page, Audit log page (these were not built yet).
- User to commit step 4. Suggested message: "Add live lobby: bed holds with row locking, expiry job, polling UI and tests". (DONE by user, commit 3a81141.)

### 2026-11-11 (session 1, step 5: demo:race)
Done:
- `php artisan demo:race {code} {bedId?} --racers=2..10`: creates temporary racer members, spawns one PHP process per racer (hidden `demo:race-worker`), all fire BedService::grab at the same instant, prints a scoreboard, checks the database agrees (exactly 1 winner), prints the audit trail, tidies up (frees bed, soft-removes racers). Disabled in production. Live run with 5 racers: 1 WON, 4 lost, all within ~0.1 ms of each other, audit shows 4 grab_rejected.
- tests/Feature/DemoRaceTest.php (3 tests). Total 37 tests, 147 assertions, Pint clean. Dev DB reset with migrate:fresh (empty).
Problems and fixes:
- Test that fakes production env made Laravel prompt for confirmation during the DatabaseMigrations teardown (migrate:fresh): reset env to `testing` in a finally block.
Next:
- 5b phone test on Wi-Fi, then error logging, Members page, Audit log page, QR code, then AWS (step 6) and the vault (step 7).
  (User committed step 5. Error logging, audit gaps and invite link were done next, see below.)

### 2026-11-11 (session 1, step 5a: audit gaps, error logging, invite link)
Done:
- Audit gaps closed. `rate_limited`: when the 30/min limit is hit, `ThrottledResponse` writes ONE audit row per member per minute (Cache::add guard) and answers 429 JSON. `action_refused` (details: attempted + reason viewer/removed/trip_ended) from `BedService::cannotPlay`. `release_rejected` when releasing a bed you do not hold. Harmless double-tap on your own held bed is still not logged (noise).
- Error logging: `LOG_STACK=daily` (14 days). `ErrorRecorder` (called from `$exceptions->report()` in bootstrap/app.php) writes unexpected errors to `error_logs`: route PATTERN not the real URL (no join codes), no query string, QueryException stored via the driver message only (no bound values), never throws. 404/403/419/429/validation are not "reported" so they are not recorded.
- Friendly pages: resources/views/errors/{403,404,419,429,500,503} on a standalone `errors/friendly` view (does not use the main layout, because on some errors no session exists and the layout needs a CSRF token). Real detail only in log/table.
- `/admin/login` + `/admin/errors` + logout. `ADMIN_KEY` in .env (>=16 chars; empty/short = admin pages 404). `AdminOnly` middleware (alias `admin`), key compared with hash_equals, login throttled 5/min/IP, session regenerated on login. Trip organisers are NOT admins.
- Invite: the lobby card now has a readonly link box + Copy link (clipboard API, falls back to execCommand on plain http), a WhatsApp link (wa.me prefilled text) and a Share button shown only where `navigator.share` exists. QR dropped (no library, less attack surface).
- `.env.example` fixed (MySQL placeholders, APP_NAME, LOG_STACK=daily, empty ADMIN_KEY). Real `.env` got LOG_STACK=daily and a generated ADMIN_KEY.
- Tests: ErrorLoggingTest (9) + 2 new in LobbyTest. Total 49 tests, 226 assertions, Pint clean.
- Live check: 40 rapid taps -> 30 processed and audited, 10 got 429, exactly 1 `rate_limited` row, 0 error_logs rows.
Problems and fixes:
- `runningInConsole()` is also true in PHPUnit, so the first ErrorRecorder version saved web errors as "artisan": now it treats a request as CLI only if it also has no matched route.
- Pint on `bootstrap/` reformatted generated cache files: run Pint on app tests config routes only.
- The embedded browser pane blocks clipboard access, so only the fallback message path of Copy link was seen. A real tap on a real phone/browser is still to be confirmed during the phone test.
Next:
- 5b phone test on Wi-Fi (also confirm Copy link and WhatsApp on the phone), Members page, Audit log page, then AWS (step 6) and the vault (step 7).

### 2026-11-11 (session 1, step 5b: phone test + small fixes)
Done:
- User ran the phone test on Wi-Fi (scoped Windows firewall rule: port 8000, Public profile, local subnet only; `php artisan serve --host=0.0.0.0`; `schedule:work`): everything worked.
- "Not logged in after closing and reopening": the login cookie lasts 30 days, so closing the browser does not log you out. The real gap: the cookie is scoped to /t/CODE, so the HOME page cannot see it and gives no way back into a trip (also, localhost and the LAN IP are different sites with separate cookies). Fix: lobby.js keeps a small list in localStorage (trip code, trip name, your name; NO token) and the home page shows "Your trips on this device" (public/js/home.js, plain JS, textContent only, codes validated with a regex).
- Lobby poll now stops on 404 (trip deleted) instead of retrying forever; 403 and 404 also remove the trip from the local list.
- Home page: join card and create card now look different (tinted card with left accent vs top accent, plus an "or organise your own" divider).
- Added scripts/stop-mysql.ps1 (clean mysqladmin shutdown; root password read from the file outside the repo, passed through MYSQL_PWD, never on the command line).
- Verified in the browser: trip appears on the home page, a trip name containing HTML is shown as plain text, clicking it opens the lobby logged in. 49 tests pass, Pint clean.
Cleanup advice given: Ctrl+C in the server and scheduler windows; remove the firewall rule with `Remove-NetFirewallRule -DisplayName "The Lobby dev (port 8000)"` in an Administrator PowerShell; set HOLD_SECONDS back to 120 in .env for normal use.
Next:
- Members page + Audit log page, then AWS (step 6) and the vault (step 7).

### 2026-11-11 (session 1, step 5c: Members + Audit pages)
Done:
- `MemberService` (remove, changeRole): one transaction, locks the target MEMBER row then their bed rows (same order as BedService, so no new deadlock pattern), returns an `ActionResult` (BedResult was renamed to `ActionResult` because both services share it). Rules: only an active organiser (re-read from the DB, not trusted from the request); the organiser cannot be removed or demoted; "organiser" can never be handed out (ASSIGNABLE_ROLES = member, viewer); a member from another trip is a 404; removing twice is a no-op with no second audit row. Removing frees the person's hold AND confirmed bed (audit `bed_released`, reason member_removed); becoming a viewer frees their open hold (a confirmed bed stays). Audit rows: `member_removed`, `role_changed`, `action_refused` (reasons not_organiser, target_is_organiser).
- Pages behind new middleware `trip.organiser` (`RequireOrganiser`, 403 for non-organisers): `GET /t/{code}/members`, `POST .../members/{id}/remove`, `POST .../members/{id}/role` (Form Request validates the role), `GET /t/{code}/audit`. Members page: cards, role badge, "removed" badge, confirmed bed, role dropdown + Remove with a "are you sure?" prompt (public/js/members.js, data-confirm). Audit page: friendly labels via `AuditText` (raw action code shown small), bed/person names looked up in one query each, 50 per page with `?before=<id>` paging, IP addresses not shown. Lobby page has an "Organiser tools" card with both links.
- Tests: tests/Feature/MembersTest.php (9). Total 58 tests, 297 assertions, Pint clean. Browser check: removing a person with the confirm prompt works and the audit page reads well.
Problems and fixes:
- In tests `withCookie()` sticks to the test client for every later request in that test (an "anonymous" request after a logged-in one was still logged in): do anonymous checks first.
- JSON content of `details` does not keep key order: compare with assertEquals, not assertSame.
Next:
- Step 6: AWS (the user does the console clicks; I give exact steps and check free-plan eligibility first), then step 7 the vault.

### 2026-11-11 (session 1, step 6: AWS stages A-D)
Done:
- User created the AWS account (Free plan, $100 credits, ends 2027-05-11, region Mumbai ap-south-1), root MFA, zero-spend budget, IAM user `lobby-admin` (AdministratorAccess, MFA, console only), private S3 bucket (Block Public Access on, ACLs off, versioning off, SSE-S3, HTTPS-only bucket policy, lifecycle rule on `trips/` 30 days), IAM policy `thelobby-vault-access` (Put/Get/Delete/AbortMultipart on `trips/*` + ListBucket with prefix `trips/*`), IAM user `lobby-local-dev` (no console, one access key, policy above). Keys only in the local `.env` (shape-checked, values never read).
- Installed `league/flysystem-aws-s3-v3` ^3.0 (resolved 3.35.3; aws-sdk-php 3.403.3). `config/filesystems.php` s3 disk now has `throw => true` so failures are never silent.
Problems and fixes:
- `composer require pkg "^3.0"` in PowerShell: composer.bat swallows the `^` and pinned exactly 3.0.0 (the oldest). Fixed by editing composer.json to `^3.0` and `composer update league/flysystem-aws-s3-v3 --with-dependencies`. In future, edit composer.json by hand or double the caret.
- First real bucket self-test FAILED: `cURL error 60: unable to get local issuer certificate`. Windows PHP has no CA certificate bundle (`curl.cainfo` and `openssl.cafile` are empty). Needs the Mozilla CA bundle (cacert.pem from curl.se), checksum-verified, and two php.ini lines. Waiting for the user's OK to download it. The two "denied" checks in that run were false passes (they failed on SSL too), so nothing is proven yet. EC2 will not have this problem.
Next:
- Get the CA bundle (with user OK), re-run the self-test (upload, read, 60s link, plain URL refused, expired link dead, write outside `trips/` denied, list root denied, delete), then stage 7 the vault.

### 2026-11-11 (session 1, step 6 finished: real bucket proven)
Done:
- User approved the download. Fetched the Mozilla CA bundle (`cacert.pem`, 200,116 bytes) from curl.se into its own folder outside the repo (`tools\php-certs`), sha256 matched the value curl.se publishes. Set `curl.cainfo` and `openssl.cafile` in the winget PHP's php.ini (machine setup, not part of the repo).
- Re-ran the real-bucket self-test (a throwaway script outside the repo): ALL 9 CHECKS PASSED. Upload to `trips/`, read back, 60-second pre-signed link returns the file (HTTP 200), plain URL without signature refused (403, proves the bucket is private), link with 3-second life is dead after 6 seconds (403), write outside `trips/` denied, listing the bucket root denied, delete works, file really gone. Both "denied" checks were re-checked and fail with a genuine `AccessDenied` from AWS (not a connection error).
- Full suite: 58 tests pass. Pint clean on app, tests, config, routes.
- User asked for a plain-language explanation of AWS and every step (account, Free plan, MFA, budget, IAM admin vs local-dev user, bucket settings, policies, access key, pre-signed links, why EC2 uses a role). Given in chat; to be written into docs/STUDY_GUIDE.md in step 9.
Uncommitted for the user to commit: composer.json, composer.lock, config/filesystems.php, PROJECT.md, TASKS.md. Suggested message: "Add S3 adapter and s3 disk config (throw on failure)". Before committing run `git status` and confirm `.env`, `CLAUDE.md` and `docs/PROJECT_BRIEF.md` are NOT listed.
Next:
- Step 7, the vault: upload (HEIC handling, size/type limits), 60s pre-signed links, roles (organiser: upload/view/delete/remove/roles/end trip; member: upload+view; viewer: view only), End trip now + scheduled purge that deletes S3 files but keeps the audit log, tests with `Storage::fake`.
- Later step 8: on EC2 use an IAM instance role (no keys) and delete the `lobby-local-dev` access key at teardown.

### 2026-11-11 (session 1, step 7: the vault)
Done:
- `app/Services/VaultService.php`: `upload`, `openLink`, `delete`, `endTrip`, `closeFinishedTrips`, `purgeEndedTrips`, `purgeTrip`. `VaultController`, `UploadVaultFileRequest`, view `vault.blade.php`, `public/js/vault.js`, command `vault:purge` (scheduled every 5 minutes, withoutOverlapping). Lobby page got an "Open the vault" card. `members.js` was renamed `confirm.js` (shared by Members and Vault pages).
- Routes (inside the trip group): `GET /t/{code}/vault`, `POST /vault` (upload), `GET /vault/{id}/open` (checks, audits, redirects to a fresh 60s link), organiser only: `POST /vault/{id}/delete`, `POST /end-trip`. New limiter `vault-actions` (20/min per member). `ThrottledResponse` now redirects back with a red message for normal pages and keeps JSON for the lobby screen.
- Roles: organiser upload/open/delete/end trip; member upload/open; viewer open only. Removed members get 403 on everything (middleware). Checked twice for organiser actions (route middleware + inside the service with a fresh DB read; refusals are audited).
- Files: allowed pdf/jpg/png/webp/heic/heif, 10 MB each, 40 per trip (config/lobby.php `vault`). Real content type is detected from the bytes. Stored under `trips/{trip_id}/{32 random hex}` (the real name is never in S3, it lives only in the database). HEIC is stored as-is (phones normally convert to JPEG; no extra image package). Link responses carry `Content-Disposition: inline` with an ASCII-safe name and `Cache-Control: no-store`.
- Self-destruct: "End trip now" (organiser) or `vault:purge` after `ends_on` has passed (trip ends the day AFTER `ends_on`, app timezone UTC). Purge deletes every file from S3, marks rows `deleted_at`, sweeps the whole `trips/{id}/` folder for crash leftovers, sets `trips.vault_purged_at` only when everything succeeded. Retry-safe: failures are reported (log + error_logs), exit code 1, the trip stays "not purged", the next run finishes. Audit log is never touched. New audit actions: `file_uploaded`, `file_viewed`, `file_deleted`, `trip_ended`, `vault_purged` (+ `action_refused` for refusals).
- Concurrency: upload stores bytes first (no DB lock held during network), then records the row in a transaction that locks member then trip row. "End trip now" takes the same locks, so an upload racing the end either lands before (and is purged) or sees "trip ended" (and its bytes are removed again).
- Local PHP: raised `post_max_size` to 12M and `upload_max_filesize` to 11M in the winget php.ini (default 2M would reject photos). On EC2 set the same in php.ini AND Nginx `client_max_body_size 12m`.
- Tests: `tests/Feature/VaultTest.php` (21 tests, `Storage::fake('s3')`, real MySQL). Full suite 79 pass, Pint clean. Real-bucket script (scratchpad, test DB `thelobby_test`): 16/16 pass: upload, content type, viewer refused, link returns exact bytes, link `X-Amz-Expires=60`, plain URL 403, member cannot end trip, end trip deletes the object, old link 404, folder empty.
Known limits (for README): organiser only can delete a single file (an uploader cannot delete their own); file names stay in the database and audit log after the purge (contents do not); the 30-day lifecycle rule counts from upload so a ticket uploaded more than 30 days before the end is deleted early by AWS (the app would then show a link to a missing file); `trip.organiser` 403s a non-organiser hitting delete/end-trip by URL before the audit row is written (the service-level refusal is audited, covered by a test).
Next:
- User clicks through the vault in a browser/phone (upload a PDF and a photo, open, delete, End trip now on a throw-away trip). Then step 8: deploy (RDS db.t3.micro MySQL single-AZ, EC2 t3.micro with IAM instance role, Nginx/PHP-FPM, cron for `schedule:run`, security groups). Check Free-plan eligibility for each service first.
Commit (user): everything new from this step. Suggested message: "Add vault: private S3 uploads, 60-second links, roles, end trip and purge job". Run `git status` first: `.env`, `CLAUDE.md`, `docs/PROJECT_BRIEF.md` must NOT be listed.

### 2026-11-11 (session 1, step 8 started: deploy plan and scripts)
Done (code side, nothing run on AWS yet):
- `deploy/` folder (public-safe, placeholders only): `setup-server.sh <repo-url> <bucket> <app-url>` (Nginx, PHP 8.4 from the ondrej repo with a hard stop if PHP < 8.4.27, Composer with checksum check, RDS CA bundle, clone, production .env without AWS keys, permissions, Nginx site, www-data cron for `schedule:run`), `create-db-user.sh <rds-endpoint>` (creates database `thelobby` and a LIMITED user, generates its password and writes it straight into .env, master password typed hidden and never saved), `release.sh` (git pull, composer, migrate --force, config/route/view cache, reload php-fpm; run after every push), `nginx-thelobby.conf` (only public/ is served, only index.php executes, 12 MB uploads, security headers).
- Server layout decision: repo at `/var/www/thelobby` owned by the login user; `storage/` and `bootstrap/cache/` owned by `www-data`; every artisan command and the cron run as `www-data` so log/cache files never end up with the wrong owner.
Plan (AWS console stages, user does them, screenshots back): A security groups (`thelobby-web`: SSH from my IP only, 80, 443; `thelobby-db`: 3306 from `thelobby-web` only), B RDS MySQL 8.4 db.t3.micro single-AZ, NOT publicly accessible, self-managed password, no Multi-AZ, no performance insights, C IAM role `thelobby-ec2-role` (EC2 service) with the existing `thelobby-vault-access` policy, D EC2 Ubuntu 24.04 t3.micro + role + `thelobby-web` + key pair (the .pem stays on the laptop; `*.pem` is gitignored) + Elastic IP (check Free-plan eligibility; fallback: never stop the instance), E SSH from PowerShell and run the scripts, F smoke test from a phone on mobile data, G free name + HTTPS (DuckDNS + Let's Encrypt, plan B CloudFront), H cron/purge/vault check on the live site.
Schedule: Wed deploy over HTTP; Thu name + padlock; Fri test with friends + README/study guide drafts; Sat freeze; Sun dress rehearsal; Mon smoke check. Plan B for the demo: run locally (real S3) + screen recording.
Open question: `demo:race` refuses to run when APP_ENV=production; decide Thursday (demo from laptop, or add a safe switch).
User must commit and push `deploy/` (and this file) before the server can clone it.

### 2026-11-11 (session 1, step 8: first server run)
- Launched: EC2 t3.micro Ubuntu 24.04 (x86), 16 GiB gp3, role `thelobby-ec2-role`, IMDSv2 required, group `thelobby-web`; Elastic IP allocated and associated (the Free plan allows it). RDS `thelobby-db` created (MySQL 8.4.11, db.t3.micro, single-AZ, private, 20 GiB gp3, 1-day backups, Database Insights Standard). Security groups and role as planned.
- Problem: `add-apt-repository ppa:ondrej/php` timed out. From this server every Launchpad host (api.launchpad.net, ppa.launchpadcontent.net, keyserver.ubuntu.com) times out, while github.com, getcomposer.org, packages.sury.org and the normal Ubuntu mirrors work. Ubuntu's own PHP is 8.3.6, below the security minimum, so it was not an option.
- Fix: `setup-server.sh` now adds the PHP maintainer's own repository by hand (packages.sury.org, signing key downloaded to /usr/share/keyrings, `signed-by`), which offers PHP 8.4.27 for Ubuntu 24.04 (checked). Script still stops if PHP < 8.4.27. Correction to the earlier decision log line: the PHP source is packages.sury.org, not the ondrej PPA.

### 2026-11-11 (session 1, step 8: app released on the server)
- Second setup run succeeded (PHP from packages.sury.org). `create-db-user.sh` created database `thelobby` + limited user (password written into the server's .env only). `release.sh` ran all 10 migrations on RDS and cached config/routes/views: "Released commit 820c06b".
- Bug found and fixed: `create-db-user.sh` used `sed -i`, which replaced `.env` with a new file owned by the login user's group, so `www-data` could not read it and Laravel silently fell back to its default (SQLite), failing with "Database file ... database.sqlite does not exist". Fixed on the server with `chgrp www-data` + `chmod 640`, and the script now re-applies that at the end (push pending from the user). Lesson: a missing/unreadable .env shows up as a wrong-database error, not as a permissions error.
- Next: open the Elastic IP in a browser, create a trip, upload to the vault (proves the EC2 IAM role reaches S3 with no keys), test from a phone on mobile data. Then free name + HTTPS (Thursday).

### 2026-11-11 (session 1, step 8: free name + HTTPS in progress)
- DuckDNS name `<name>.duckdns.org` created (free; the user signed in with GitHub and passed the CAPTCHA themselves) and pointed at the Elastic IP; `nslookup` from the laptop confirms. The DuckDNS token was never shared.
- `deploy/enable-https.sh <domain> <email>`: checks the name points at this server, asks the user to type "yes" to the Let's Encrypt terms, installs certbot, sets Nginx `server_name`, gets the certificate (redirect HTTP to HTTPS), sets `APP_URL=https://...` and `SESSION_SECURE_COOKIE=true`, restores `.env` group, re-caches config.
- Bug: first run died with "MY_IP: unbound variable" (apostrophe in a `${2:?...}` message; see BUILD_LOG error 18). Fixed; user must push, run release.sh on the server, then re-run enable-https.sh.
- `docs/BUILD_LOG.md` created: timeline, how the core ideas work, 18 errors with cause/fix/lesson, deploy instructions. Keep appending. It is the raw material for docs/STUDY_GUIDE.md.

### 2026-11-11 (session 1, step 8 finished: live on HTTPS, proven end to end)
- HTTPS done with a free DuckDNS name + Let's Encrypt (`enable-https.sh`); `certbot renew --dry-run` succeeds; the renewal runs from a systemd timer. Checked from outside: trusted certificate, HTTP 301 to HTTPS, secure cookies, `.env`/`.git`/vendor/logs/artisan unreachable.
- Server checks passed: www-data crontab runs `schedule:run` every minute (confirmed in `journalctl -u cron`), `schedule:list` shows `lobby:expire-holds` and `vault:purge`, `vault:purge` reports 0 failures, nginx/php8.4-fpm/cron/certbot.timer all active. `HOLD_SECONDS=30` set on the server with the new `deploy/set-env.sh`.
- New: `demo:race --allow-production` (explicit opt-in; default still refuses in production), `deploy/set-env.sh KEY VALUE` (safe .env edit that restores the group), `scripts/smoke-test.php <url> [--quick]`.
- `scripts/smoke-test.php` against the live site: ALL 24 CHECKS PASSED: creates a trip, five people join, five simultaneous grabs of one bed give exactly one winner (statuses 200,409,409,409,409), confirm, a confirmed bed cannot be grabbed, a hold expires after 30s, PDF upload, signed link (X-Amz-Expires=60, no-store), exact bytes, unsigned URL 403, stranger refused, End trip, old link dead (404). This also proves the EC2 IAM role reaches S3 without keys. Use it on Monday morning (join/create are limited to 10/min per IP: wait a minute between runs).
- Test trip "Smoke test (automated)" is left in the database (ended, files deleted).
- Remaining before Monday: README.md (Mermaid diagrams, security decisions, demo script, limits, teardown), docs/STUDY_GUIDE.md (plain-language explanations of every concept and step, likely questions and answers), test with real friends on other networks, set HOLD_SECONDS to the right demo value, final handoff. After the demo period: teardown (server, database, Elastic IP, local-dev key, budget/payment, close account).

### 2026-11-11 (session 1, runbook)
- `docs/RUNBOOK.md` written (public-safe): the one rule (site runs on AWS, closing windows changes nothing; never stop the server), health check, SSH in/out, ship code, change a setting safely, where to look when something breaks, Monday-morning checklist, running and stopping locally (Ctrl+C, stop-mysql, firewall rule add/remove), AWS console login (lobby-admin + MFA, Mumbai), teardown in order. READ IT FIRST for any "how do I run/stop/fix" question. Keep it updated when an operating step changes.

### 2026-11-11 (session 1, evening: give up a confirmed bed + UI redesign)
- Done: `BedService::giveUp()` + route + button + 4 tests (confirmed beds can now be handed back; audit rows `bed_given_up` / `give_up_rejected`). Whole UI redesigned (paper-and-ink look, new layout/menu, home page with practice lobby, restyled lobby, people, vault, activity, join, admin and error pages). Details and the two small errors are in docs/BUILD_LOG.md Part 8. Suite: 84 passed; Pint clean; checked in a real browser at phone width (no horizontal scroll on any page type, titles and descriptions present, link crawl clean).
- NOT yet live: the user must commit and push, then run `bash /var/www/thelobby/deploy/release.sh` on the server, then `php scripts\smoke-test.php <site> --quick` (expect ALL PASSED).
- Next (agreed order): calendar + maps (an itinerary table; .ics download and Google Calendar link; "Open in maps" link), then fake booking sites (hotel + flight) with provider adapters and a signed webhook that fills the trip in (fallback: import by booking reference). Protect the hotel + webhook part if time is short. Then README + docs/STUDY_GUIDE.md, friends test, Saturday freeze, Sunday rehearsal, Monday smoke check.

### 2026-11-11 (session 1, night: Plan page, calendar files, map links)
- Done: new Plan tab (itinerary items), `.ics` calendar downloads (trip dates + items, or one item), Google Calendar links, "Open in maps" links, organiser-only add/remove with audit rows, 19 new tests (suite 103 passed, Pint clean). See docs/BUILD_LOG.md Part 9. Migration `2026_11_11_000008` must run on the server: `release.sh` does it (`migrate --force`).
- NOT yet live: user must commit, push, run `bash /var/www/thelobby/deploy/release.sh` on the server, then `php scripts\smoke-test.php <site> --quick`.
- Next: fake booking sites (hotel + flight) with provider adapters and a signed webhook that creates plan items and rooms (fallback: import by booking reference). Rule: a booking change that would remove a bed someone holds is not applied silently; warn the organiser. Then README + docs/STUDY_GUIDE.md, friends test, Saturday freeze, Sunday rehearsal, Monday smoke check.

### 2026-11-11 (session 1, late night: lobby page as the main page, separate Create/Join)
- Done: lobby shows When/Where/Plan at a glance (place = first stay on the plan, map link, calendar links); Vault block above a smaller Invite section; end-of-page link row; home page has separate `#create` and `#join` sections and all links point to them. 106 tests pass, Pint clean. Details in docs/BUILD_LOG.md Part 10.
- NOT yet live: commit, push, `bash /var/www/thelobby/deploy/release.sh` on the server, `php scripts\smoke-test.php <site> --quick`.
- Next: fake booking sites (hotel + flight), adapters, signed webhook (fallback: import by reference); then README + docs/STUDY_GUIDE.md, friends test, Saturday freeze, Sunday rehearsal, Monday smoke check.

### 2026-11-11 (session 1, night: booking sites, translators, signed webhook)
- Done: DemoStay and DemoFly pretend sites under `/demo`, standard booking format, two plain hand-written translators with sample-message tests, pull (import by reference) and push (signed webhook, staged until the organiser approves, later versions auto-apply), the bed rule (never remove a held or confirmed bed; shows "Needs attention"), Bookings section on the Plan page, `demo:webhook` command, rate-limit counter fix, smoke test extended (36 checks). 205 tests pass, Pint clean; proven on a running local server (real HTTP webhook with CSRF on, forged requests get 401). Details and errors 22 to 28 in docs/BUILD_LOG.md Part 11; operating notes in docs/RUNBOOK.md section 5b.
- NOT yet live: user must commit, push, `bash /var/www/thelobby/deploy/release.sh` on the server (it runs the 3 new migrations), then `php scripts\smoke-test.php <site> --quick` (expect ALL PASSED, about 36 checks). No new server settings are needed (DEMO_PARTNERS defaults to true; secrets are derived from APP_KEY).
- Next: README.md and docs/STUDY_GUIDE.md (include the translator/adapter idea, signed webhook, bed rule, idempotency, why organiser approval, limits), friends test from other networks, Saturday freeze, Sunday rehearsal, Monday smoke check. After the demo period: teardown (RUNBOOK section 9).

### 2026-11-12 (session 1: simpler trip setup)
- Done: room rows instead of the typed format; "They will come from a booking" option on Create (trip created with names only, waiting box, first booking auto-applied, real dates taken from it); "Create a new trip for this booking" and "Already made a trip? Put its code here" on both booking sites, with a check for an existing trip; link to the demo sites on the home page, menu and footer; trip dates follow the booking that gave them (never into the past). 231 tests pass, Pint clean, smoke test extended and passing locally. Details and errors 29 to 31: docs/BUILD_LOG.md Part 12.
- NOT yet live: user must commit, push, `bash /var/www/thelobby/deploy/release.sh` (runs migration `2026_11_12_000012`), then `php scripts\smoke-test.php <site> --quick` (expect ALL PASSED, about 46 checks).
- Next: README.md and docs/STUDY_GUIDE.md, friends test from other networks, Saturday freeze, Sunday rehearsal, Monday smoke check.

### 2026-11-12 (session 1: audit before the docs)
- Checked: 231 tests pass, Pint clean, `composer audit` reports no advisories, PHP 8.4.27 and Laravel 13.42 locally. Committed files scanned for secrets, addresses, account identifiers, emails, local paths and private wording: clean after a tidy of this file (generic private wording removed, the live address replaced by a placeholder). Live site (read-only checks): on the latest version, HTTP redirects to HTTPS, `/.env`, `/.git/config`, vendor, artisan, logs not reachable, unsigned webhook gets 401, security headers present (no HSTS header: optional hardening).
- Remember: the local MySQL does not start by itself after a reboot or when its window is closed. If `php artisan test` seems to hang, start it first: `powershell -File scripts\start-mysql.ps1` (tests wait for the database instead of failing quickly).
- Still to do: README.md, docs/STUDY_GUIDE.md, friends test from other networks, final HOLD_SECONDS decision, Saturday freeze, Sunday rehearsal, Monday smoke check.

### 2026-11-12 (session 1: README)
- Done: README.md written to replace the default Laravel text (what it does, architecture and data-model diagrams in Mermaid, how the hard parts work, security decisions table, run-it-locally steps, settings table, tests and checks, project layout, AWS deployment summary, demo script, known limitations, roadmap). Every technical claim was checked against the code, all links point to files that exist, and the file has no private wording, addresses or local paths. It deliberately has no live address: add one by hand if wanted.
- Still to do: docs/STUDY_GUIDE.md (plain-language explanations of every concept and step, likely questions and answers; link it from the README when it exists), friends test from other networks, final HOLD_SECONDS decision, Saturday freeze, Sunday rehearsal, Monday smoke check.

### 2026-11-12 (session 1: tagline and intro slides)
- Done: tagline and home-page copy changed to "A shared space for every group trip." (files: `resources/views/home.blade.php`, `resources/views/layouts/app.blade.php`, `README.md`, plus `text-wrap: pretty` on the hero paragraph in `public/css/app.css`). 231 tests pass. Six-slide local deck built in `presentation/` (`TheLobby.pptx` and a PDF copy); the folder is in `.gitignore`.
- NOT yet live: the copy change needs commit, push, then `bash /var/www/thelobby/deploy/release.sh` on the server (no migration this time).
- Still to do: docs/STUDY_GUIDE.md, friends test from other networks, final HOLD_SECONDS decision, Saturday freeze, Sunday rehearsal, Monday smoke check.

### 2026-11-13 (session 1: full-width layout, versioned assets)
- Done: the page now uses the full screen width. The logo sits at the left edge and the menu at the right edge (side gap 20px on phones, growing to at most 40px; edit `clamp(20px, 2vw, 40px)` on `.wrap` in public/css/app.css to change it). Paragraphs and forms keep a narrower limit (`.measure`) so lines stay readable, and the bed grid now fits as many columns as the width allows. Checked at 1900, 1366, 1024, 768 and 375 pixels wide: no sideways scroll, logo and menu at the edges, menu collapses on phones.
- Done: every stylesheet and script link now carries the file's last-changed time (`App\Support\Assets::url()`), so a release can never be hidden by a browser holding the old file. 3 new tests (234 pass), Pint clean.
- NOT yet live: commit, push, `bash /var/www/thelobby/deploy/release.sh` on the server, then a hard refresh (Ctrl+Shift+R) once.
