# The Lobby: build log (what we did, what broke, how it was fixed)

A plain-language record of the whole build, written so every decision and every error can be explained later.
Placeholders like `<bucket>`, `<elastic-ip>` and `<rds-endpoint>` stand for real values that are deliberately not stored in the repo.

## Part 1: The order things were built in

| Step | What | Why this order |
|---|---|---|
| 1 | Environment: PHP 8.4, Composer, MySQL 8.4, Laravel 13 | Nothing else can start without it. Real MySQL (not SQLite/MariaDB) because the booking logic depends on real row locks and RDS is MySQL. |
| 2 | Database tables and models | The shape of the data decides everything else. Constraints (unique, foreign keys) are the last line of defence. |
| 3 | Create trip, join trip, token login | Trips need people before beds make sense. |
| 4 | The live lobby: holds, confirm, release, expiry, polling screen | The core feature. |
| 5 | `demo:race`, audit gaps, error logging, invite link, Members and Audit pages | Prove the lobby cannot double-book; make everything traceable. |
| 6 | AWS: account, MFA, budget, IAM, private S3 bucket | Cloud storage needed before the vault. |
| 7 | The vault: private uploads, 60-second links, roles, End trip, purge job | Second core feature. |
| 8 | Deploy: security groups, RDS, IAM role, EC2, Nginx/PHP-FPM, cron | Make it a real, always-on website. |

## Part 2: How the main ideas work

**Booking without double booking.** Every bed action runs in one database transaction. The person's row is locked first, then the bed rows (`SELECT ... FOR UPDATE`). Two people tapping the same bed at the same instant are forced into a queue by MySQL: the first wins, the second sees "taken". A UNIQUE index on `beds.taken_by_member_id` makes it physically impossible for one person to hold two confirmed beds, even if the PHP had a bug. Services return result objects instead of throwing exceptions, because an exception would roll back the transaction and erase the "rejected" audit row we want to keep.

**Login without passwords.** Creating or joining a trip gives the browser a random 40-character token in an encrypted, HttpOnly, SameSite=Lax cookie that is only sent to that trip's pages. The database stores only the SHA-256 of the token, so a database leak does not leak logins. A middleware turns the cookie into "this member of this trip" on every request, and a removed member gets 403 immediately.

**The vault.** Files go to a private S3 bucket under a random key. Nobody ever gets a permanent link. When a member taps a file, our app checks who they are, writes an audit row, and redirects them to a pre-signed link that stops working after 60 seconds. "End trip now" and a scheduled job delete every file; the audit log stays.

**AWS layout.**
- *S3 bucket* (private, encrypted, no public access, HTTPS only, 30-day safety-net deletion rule): the files.
- *IAM*: who may do what. A human admin user (console, MFA); a code-only user for the laptop whose policy only allows Put/Get/Delete under `trips/*` in one bucket; on the server an *IAM role* instead of keys.
- *RDS MySQL* (private, only reachable from the web server's security group, TLS with the AWS certificate bundle): the data.
- *EC2* (Ubuntu 24.04, Nginx + PHP-FPM 8.4, cron every minute): runs the app. *Elastic IP*: an address that does not change.
- *Security groups* (firewalls): web = SSH from the owner's IP only, HTTP and HTTPS from anywhere; database = port 3306 from the web group only.

## Part 3: Every error we hit, and what it taught

### On the laptop (Windows)

| # | Symptom | Cause | Fix | Lesson |
|---|---|---|---|---|
| 1 | The `demo:race` test asked a confirmation question during teardown | The test faked `production`; Laravel asks "are you sure?" before wiping a production DB | Reset the environment to `testing` in a `finally` block | Tests that fake the environment must always restore it. |
| 2 | Web errors were recorded as "artisan" | `runningInConsole()` is true inside PHPUnit | Treat a request as CLI only if it has no matched route | A "where am I running" check can lie in tests. |
| 3 | Pint reformatted generated files | It was run on the whole project, including `bootstrap/cache` | Run it only on `app tests config routes` | Run tools on code you wrote, not generated files. |
| 4 | A view called `$errors` showed the wrong thing | Laravel already has a validation `$errors` variable | Renamed ours to `$logs` | Do not reuse framework variable names. |
| 5 | Error pages crashed when there was no session | They extended the main layout | A standalone `errors/friendly` view | An error page must not depend on what failed. |
| 6 | Tests: removed-member checks failed, "anonymous" requests were logged in | `getJson` sends no cookie unless `->withCredentials()`; `withCookie()` sticks to the test client | Use `withCredentials()`, do anonymous checks first | Test clients carry state between calls. |
| 7 | `assertSame` on audit details failed | JSON key order differed | `assertEquals` | Compare data, not byte order. |
| 8 | Clipboard copy did nothing in the embedded browser | Browsers only allow the clipboard on secure pages (HTTPS or localhost) | A visible fallback message; HTTPS on the live site | Some browser features need HTTPS. |
| 9 | `composer require pkg "^3.0"` installed 3.0.0 | `composer.bat` swallows the `^` | Edited `composer.json` by hand, then `composer update` | Shells and wrappers eat special characters. |
| 10 | `cURL error 60: unable to get local issuer certificate` on the first real S3 call | Windows PHP has no list of trusted certificate authorities | Downloaded `cacert.pem` from curl.se, verified its SHA-256 against the published value, set `curl.cainfo` and `openssl.cafile` in `php.ini` | The "denied" checks in that first test passed for the wrong reason (the connection itself failed): always check a failure is the *expected* failure. |
| 11 | Photos would be rejected | PHP's default upload limit is 2 MB | `upload_max_filesize=11M`, `post_max_size=12M` (and the same on the server, plus Nginx `client_max_body_size`) | Three layers each have an upload limit: PHP, the web server, the app. |

### In AWS and on the server

| # | Symptom | Cause | Fix | Lesson |
|---|---|---|---|---|
| 12 | RDS offered "Aurora" next to MySQL | Aurora is a different, costlier product | Chose plain MySQL, Full configuration (Easy create hides network options) | Read the engine tile, and prefer the screen that exposes the security options. |
| 13 | EC2 launch dialog: "security group will be overridden, volume deleted" | Changing the AMI resets dependent settings | Confirmed, then re-set the security group (existing group), storage (16 GiB) and the advanced settings | Pick the image first, then everything else. |
| 14 | The AMI list showed Ubuntu 26.04 | Newest is not always the right choice | Chose 24.04 LTS | Deploy scripts are written and tested for one OS version. |
| 15 | `add-apt-repository ppa:ondrej/php` crashed with `TimeoutError: Connection timed out` | From this EC2 instance every Launchpad host (api.launchpad.net, ppa.launchpadcontent.net, keyserver.ubuntu.com) times out, while GitHub, Composer, the Ubuntu mirrors and packages.sury.org work | Diagnosed with a `curl` loop over the hosts and their exit codes; added the PHP maintainer's own repository (packages.sury.org) by hand (key downloaded to `/usr/share/keyrings`, `signed-by`). It offers PHP 8.4.27, which meets the security minimum; Ubuntu's own PHP is 8.3.6 and does not | Test which network paths exist before blaming your own code. Never skip a security minimum to get past an error. |
| 16 | The setup script risked freezing on a "restart services?" dialog | Ubuntu's `needrestart` asks questions | Ran apt with `DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a` | Unattended scripts must never wait for a human. |
| 17 | `migrate` failed with "Database file .../database.sqlite does not exist" | `create-db-user.sh` edited `.env` with `sed -i`, which replaces the file with a new one owned by the login user's group; the web user (`www-data`) could no longer read `.env`, so Laravel silently used its default (SQLite) | `sudo chgrp www-data .env; chmod 640 .env`; the script now restores the group at the end | An unreadable `.env` does not give a permission error: it looks like a wrong-database error. When config seems "ignored", check who can read the file. |

| 18 | `enable-https.sh` died with `MY_IP: unbound variable`, on a line that looked correct | An apostrophe in the usage message (`${2:?... Let's Encrypt ...}`) confused bash about where the quoted text ended, so it swallowed the next few lines of the script (including the one that sets `MY_IP`). `bash -n` (syntax check) said the file was fine, because it was valid, just not what was meant | Ran the script with `bash -x`, which prints every command as it executes: the trace showed `EMAIL` holding junk (`...gmail.com')`) and no `MY_IP` assignment. Removed the apostrophe, and tested the argument parsing on its own | `bash -x` is the first tool to reach for. A syntax check proves a script *parses*, not that it *does what you meant*. Keep apostrophes out of `${var:?message}`. |

## Part 4: Every instruction we gave, in order (deploy)

1. Security groups: `thelobby-web` (SSH from my IP only, 80, 443) and `thelobby-db` (3306 from `thelobby-web` only).
2. RDS: MySQL 8.4, Free-plan single-AZ `db.t3.micro`, 20 GiB gp3, autoscaling off, public access **No**, group `thelobby-db`, self-managed master password (not pasted anywhere), 1-day backups, no extra monitoring, deletion protection off.
3. IAM role `thelobby-ec2-role` with the same restricted S3 policy the laptop user has.
4. EC2: Ubuntu 24.04 x86, `t3.micro`, key pair (.pem stored outside the repo, `*.pem` is gitignored), group `thelobby-web`, 16 GiB gp3, role attached, metadata version "V2 only".
5. Elastic IP allocated and associated (the Free plan allows it).
6. Key file permissions on Windows: `icacls <key> /inheritance:r` then `icacls <key> /grant:r "<user>:(R)"`.
7. `ssh -i <key> ubuntu@<elastic-ip>` (answer `yes` to the fingerprint question the first time).
8. `curl -fsSLO .../deploy/setup-server.sh` then `bash setup-server.sh <repo-url> <bucket> http://<elastic-ip>`.
9. `bash /var/www/thelobby/deploy/create-db-user.sh <rds-endpoint>` (master username and hidden password; creates a limited `thelobby` user with a generated password written only into the server's `.env`).
10. `bash /var/www/thelobby/deploy/release.sh` (git pull, composer, migrate, cache config/routes/views).

## Part 5: HTTPS and the free name

1. DuckDNS (free dynamic DNS): sign in (GitHub), pass the CAPTCHA (a human step), add a sub-domain, set its IP to the Elastic IP. The account token is never shared or stored; it is only needed to *change* the record, and an Elastic IP does not change.
2. Check the name from the laptop: `nslookup <name>.duckdns.org` must show the Elastic IP.
3. `bash deploy/release.sh` (pulls the new script), then `bash deploy/enable-https.sh <name>.duckdns.org <email>`. It checks the name points at the server, asks you to accept the Let's Encrypt terms by typing `yes`, installs certbot, tells Nginx the site's name, gets the certificate (HTTP-01 challenge over port 80, which is why port 80 stays open), redirects HTTP to HTTPS, sets `APP_URL=https://...` and `SESSION_SECURE_COOKIE=true`.
4. Checked from outside: HTTP answers `301` to HTTPS; the certificate is trusted (issuer Let's Encrypt, about 90 days, renewed automatically by a systemd timer); cookies carry `secure`, `HttpOnly`, `SameSite=Lax`; `/.env`, `/.git/config`, `/vendor/...`, `/artisan`, logs and a stray `.php` file are not reachable (403/404); `/admin/errors` redirects strangers to the login.

## Part 6: Operating the live server

- Put new code live: `git push` on the laptop, then `bash /var/www/thelobby/deploy/release.sh` on the server.
- Change one setting safely: `bash /var/www/thelobby/deploy/set-env.sh KEY VALUE` (never edit `.env` with `sed -i` by hand: see error 17).
- Race demo on the live server (throw-away trip only): `cd /var/www/thelobby && sudo -u www-data php artisan demo:race <CODE> --racers=5 --allow-production`. Without the flag it refuses in production (a guard against creating fake members on a real trip); the racers are removed afterwards, their audit rows stay.
