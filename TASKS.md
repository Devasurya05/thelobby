# The Lobby: TASKS.md

## Checklist (order from brief section 8)
- [x] 1. Environment: PHP 8.4.27, Composer, MySQL 8.4.9, Laravel 13 project created, DB connected, default migrations run
- [x] 1b. User: `git init` and first commit done (no secrets). PUSH PENDING: create public empty GitHub repo `thelobby`, then `git push -u origin main`
- [x] 2. Migrations + models (trips, members, rooms, beds, vault_files, audit_logs, error_logs). Verified: migrate:fresh OK, unique taken_by_member_id blocks a 2nd bed, AuditLog is append-only
- [x] 3. Create trip / join trip / token auth middleware (14 tests pass on real MySQL; smoke-tested over HTTP incl. CSRF 419)
- [x] 4. Lobby: state endpoint, grab, confirm, release, expiry command, audit log, jQuery UI (34 tests pass; verified with real concurrent processes and in a browser)
- [x] 5. `demo:race` command (37 tests pass; lobby tests in tests/Feature/LobbyTest.php, race command tests in tests/Feature/DemoRaceTest.php)
- [x] 5a. Error logging (daily log + error_logs table + /admin/errors with ADMIN_KEY + friendly error pages), audit gaps closed (rate_limited, action_refused, release_rejected), invite link (copy / WhatsApp / share) instead of QR. 49 tests pass.
- [ ] 5b. Local phone test on same Wi-Fi (`php artisan serve --host=0.0.0.0`, firewall, `schedule:work`)
- [ ] 6. AWS: Free-plan account, budget alert, private encrypted S3 bucket + lifecycle rule, IAM user for local dev
- [ ] 7. Vault: upload, 60s pre-signed links, roles, remove member, End trip now + purge, tests
- [ ] 8. Deploy: RDS, EC2 + IAM role, Nginx/PHP-FPM, cron, migrate, test demo live
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
- Privacy fix: CLAUDE.md and docs/PROJECT_BRIEF.md are git-ignored (public repo must not hold interview/company notes). Repo recreated by the user and pushed clean.
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
