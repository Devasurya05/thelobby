# The Lobby: TASKS.md

## Checklist (order from brief section 8)
- [x] 1. Environment: PHP 8.4.27, Composer, MySQL 8.4.9, Laravel 13 project created, DB connected, default migrations run
- [x] 1b. User: `git init` and first commit done (no secrets). PUSH PENDING: create public empty GitHub repo `thelobby`, then `git push -u origin main`
- [x] 2. Migrations + models (trips, members, rooms, beds, vault_files, audit_logs, error_logs). Verified: migrate:fresh OK, unique taken_by_member_id blocks a 2nd bed, AuditLog is append-only
- [x] 3. Create trip / join trip / token auth middleware (14 tests pass on real MySQL; smoke-tested over HTTP incl. CSRF 419)
- [ ] 4. Lobby: state endpoint, grab, confirm, release, expiry command, audit log, jQuery UI
- [ ] 5. `demo:race` command + lobby tests
- [ ] 5b. Local phone test on same Wi-Fi (`php artisan serve --host=0.0.0.0`, firewall, `schedule:work`)
- [ ] 6. AWS: Free-plan account, budget alert, private encrypted S3 bucket + lifecycle rule, IAM user for local dev
- [ ] 7. Vault: upload, 60s pre-signed links, roles, remove member, End trip now + purge, tests
- [ ] 8. Deploy: RDS, EC2 + IAM role, Nginx/PHP-FPM, cron, migrate, test demo live
- [ ] 9. README, docs/STUDY_GUIDE.md, PROJECT.md, TASKS.md final
- [ ] 10. Optional: SNS alerts, CloudWatch

Cross-cutting: error logging (daily log + error_logs table + /admin/errors), configurable hold time in .env, QR code, mobile-first UI, HEIC upload handling, demo-files/.

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
- User to commit step 3 (suggested message: "Add create/join trip and token cookie auth with tests").
