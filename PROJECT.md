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
Standard Laravel 13 skeleton plus `scripts/start-mysql.ps1`. Models in `app/Models` (Trip, Member, Room, Bed, VaultFile, AuditLog, ErrorLog); migrations in `database/migrations` (2026_11_11_00000N_*). No controllers/routes/services yet.

## Decisions log (append only)
- 2026-11-11: Name is "The Lobby". Repo name `thelobby`.
- 2026-11-11: Real MySQL 8.4 (not XAMPP/MariaDB), same family as AWS RDS MySQL.
- 2026-11-11: PHP via winget zip build (thread-safe, no installer), php.ini made from php.ini-development with needed extensions enabled. Composer via checksum-verified composer.phar.
- 2026-11-11: MySQL runs as a hidden background process bound to 127.0.0.1 instead of a Windows service (service install needs admin).
- 2026-11-11: `beds.taken_by_member_id` is UNIQUE so the database itself enforces one bed per person. Members are soft-removed (`removed_at`), never deleted.
- 2026-11-11: `audit_logs.trip_id` uses restrictOnDelete and the AuditLog model throws on update/delete (append-only). `error_logs` has no foreign keys on purpose so logging an error can never fail.
- 2026-11-11: Models use `$fillable` (mass-assignment protection); `Member::$hidden` includes token_hash.
