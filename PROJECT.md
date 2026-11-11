# The Lobby: PROJECT.md (handoff overview)

Last updated: 2026-11-11

## What this is
A group-trip app: people pick beds/rooms in a live lobby without clashing (row locking + 2-minute holds), then share documents in a private vault (private S3 bucket, 60-second pre-signed links, roles) that deletes itself when the trip ends.

Design principles: no double booking, temporary holds, one source of truth, right access for the right person, private data does not live forever, everything is logged.

## Stack
- PHP 8.4.27 (winget, includes PHP2Shell fix), Laravel 13.42, Blade, jQuery (CDN, polling ~2s)
- MySQL 8.4.9 local (Oracle Community Server); AWS RDS MySQL in production
- AWS (Free plan only, never upgrade): EC2, RDS, S3, IAM. Region TBD (ap-south-1 or ap-northeast-1)
- Composer 2.11.2 (composer.phar, checksum verified)

## Environment (Windows, PowerShell)
- MySQL runs as a plain process (no service): `powershell -File scripts\start-mysql.ps1` after every reboot. Data directory lives outside the project folder. DBs: `thelobby`, `thelobby_test`; user `thelobby`. Passwords only in `.env` (never committed).
- `.env` already points Laravel at local MySQL; `APP_NAME="The Lobby"`.
- Default users/password_reset tables were removed (we use `members`). `sessions`, `cache`, `jobs` tables kept. config/auth.php still mentions the deleted User model (harmless, unused).
- Not installed yet: AWS CLI (not needed, console is fine).

## Structure (current)
Standard Laravel 13 skeleton plus `scripts/start-mysql.ps1`. Models in `app/Models` (Trip, Member, Room, Bed, VaultFile, AuditLog, ErrorLog); migrations in `database/migrations` (2026_11_11_00000N_*). Also: `app/Support` (MemberToken, RoomListParser), `app/Http/Middleware/ResolveMember.php`, controllers Home/Trip/Join/Lobby, Form Requests CreateTripRequest/JoinTripRequest, views in `resources/views` (layouts/app, home, join, lobby), CSS in `public/css/app.css`. Routes: `/` home, `POST /trips`, `GET /join/{code?}`, `POST /join`, `GET /t/{code}` (lobby, behind `trip.member`). Step 4 added: `app/Services` (BedService, BedResult, LobbyState), BedController, `app/Console/Commands/ExpireHolds.php` (`lobby:expire-holds`, scheduled every minute in routes/console.php), `config/lobby.php`, `public/js/lobby.js`, tests/Feature/LobbyTest.php. Routes added: `GET /t/{code}/state`, `POST /t/{code}/beds/{bedId}/{grab|confirm|release}`. Not built yet: demo:race, error logging + /admin/errors, Members page, Audit log page, QR code, Vault.

## Decisions log (append only)
- 2026-11-11: Name is "The Lobby". Repo name `thelobby`.
- 2026-11-11: Real MySQL 8.4 (not XAMPP/MariaDB), same family as AWS RDS MySQL.
- 2026-11-11: PHP via winget zip build (thread-safe, no installer), php.ini made from php.ini-development with needed extensions enabled. Composer via checksum-verified composer.phar.
- 2026-11-11: MySQL runs as a hidden background process bound to 127.0.0.1 instead of a Windows service (service install needs admin).
- 2026-11-11: `beds.taken_by_member_id` is UNIQUE so the database itself enforces one bed per person. Members are soft-removed (`removed_at`), never deleted.
- 2026-11-11: `audit_logs.trip_id` uses restrictOnDelete and the AuditLog model throws on update/delete (append-only). `error_logs` has no foreign keys on purpose so logging an error can never fail.
- 2026-11-11: Auth = random 40-char token in an encrypted HttpOnly, SameSite=Lax cookie named `lobby_{code}` with path `/t/{code}`; DB stores only sha256(token). Middleware looks up by (trip from URL, token hash), so a token from one trip cannot open another. Removed members get 403 on the next request.
- 2026-11-11: Rooms/beds are entered as plain text, one room per line (`Room 1: Window, Door`); limits 60 rooms, 10 beds/room, 200 beds total. Member names are unique per trip (case-insensitive, app-level check). Max 200 members per trip. POST /trips and /join throttled to 10/min/IP.
- 2026-11-11: Tests run on real MySQL (`thelobby_test`), not SQLite, because the locking behaviour must be real.
- 2026-11-11: Concurrency design: every bed action = DB::transaction(attempts 3) + lockForUpdate, locking the member row first, then bed rows. Retry covers MySQL deadlocks (proved with real parallel processes). Failures are returned as BedResult, never thrown, so the rejection audit row commits.
- 2026-11-11: Viewers cannot pick beds (watch only). A confirmed bed cannot be released (swaps are roadmap). Hold length is HOLD_SECONDS in .env (default 120).
- 2026-11-11: The screen polls GET /state every 2s; state is built from the DB each time with expired holds shown as free, so it is right even if the scheduler is not running. Countdown uses seconds_left (not timestamps) so wrong phone clocks do not matter.
- 2026-11-11: Rate limit for bed actions is 30/min per member (not per IP: friends share a Wi-Fi IP). Middleware priority list is adjusted so login runs before the limiter.
- 2026-11-11: MySQL starts with --mysqlx=OFF (no extra network port). It is a background process, not a service: after a reboot or app restart run scripts\start-mysql.ps1 (tests hang/fail with "connection refused" otherwise).
- 2026-11-11: Models use `$fillable` (mass-assignment protection); `Member::$hidden` includes token_hash.
