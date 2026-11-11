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

## Part 7: The smoke test (proof from the outside)

`php scripts/smoke-test.php <url>` behaves like real people over real HTTPS: creates a throw-away trip, joins five people, fires five simultaneous grabs at one bed (exactly one 200 and four 409s), confirms a bed, checks a confirmed bed cannot be taken, lets a 30-second hold expire, uploads a PDF, opens it through the signed link (60 seconds, not cached, exact bytes, unsigned URL refused, stranger refused), ends the trip and checks the old link is dead. On the live site: all 24 checks passed. It also proves the server's IAM role reaches S3 with no access key on the machine.

## Part 8: Giving up a confirmed bed, and the visual redesign (2026-11-11 evening)

### Give up a confirmed bed
- Before: a confirmed bed was final on purpose (`release()` only works on a hold). Now `BedService::giveUp()` lets the person who owns a confirmed bed hand it back.
- How it works: same recipe as every other bed action. One transaction, lock the member row first, then the bed row. Checks: not removed, not a viewer, trip not ended (these all go through `cannotPlay`). The bed must be `taken` and `taken_by_member_id` must be you, otherwise it is refused and an audit row `give_up_rejected` is written. On success status becomes `free` and `taken_by_member_id` is cleared (that column carries the UNIQUE "one bed per person" rule, so it has to be cleared or the person could never pick again). Audit row `bed_given_up`.
- Route `POST /t/{code}/beds/{bedId}/giveup` (inside the same `lobby-actions` rate limit group), button "Give up bed" on your own confirmed bed, and the browser asks "Give up your bed?" first (`window.confirm`). The server does not rely on that popup.
- 4 new tests (frees the bed and lets you pick again, cannot give up someone else's bed or a bed you only hold, refused after the trip ended, HTTP endpoint returns fresh state). Suite: 84 passed.

### The redesign (what changed and why)
- Look ("paper and ink"): warm paper background `#f3efe6` (never pure white), one ink colour, one green accent, borders instead of shadows, 3px corners, no gradients, no icons, no animation. Fonts: Newsreader (headings) and Source Sans 3 (body) from Google Fonts, with Georgia and Segoe UI as fallback if the fonts cannot load.
- Bed states are shown as words in small square labels (Free, Held, Taken) as well as colour, so they are readable without colour vision. Your own bed has a thick ink border. There are no coloured left stripes any more.
- Layout: one header with the clickable logo and one menu. On a trip the menu is Rooms, Vault, People, Activity (the last two for the organiser only); outside a trip it is How it works, Create a trip, Join a trip. On phones it collapses behind a "Menu" button (`public/js/menu.js`; without JavaScript the menu simply stays visible). The layout reads the trip and member from the request (set by the `trip.member` middleware), so no page has to pass them in.
- Home page: headline and two buttons, then a working practice lobby (made-up beds in `home.js`, nothing saved, the timer only rewrites its text so a tap is never lost), then "How it works" as a numbered list, then Create and Join side by side (stacked on a phone). "Fill in an example" fills the rooms box.
- Messages: one place in the layout shows success and error messages for every page (session `status`, session `problem`, validation errors). Pages with inline field errors show one line "Some details need fixing" plus the marked fields.
- Housekeeping from the checklist: page titles use "Page | The Lobby", every page has a meta description, trip and admin pages are `noindex`, favicon (SVG plus the existing ICO), footer with the current year and working links, custom error pages restyled (404, 403, 419, 429, 500, 503), skip-to-content link, visible keyboard focus, no horizontal scroll (checked on all 10 page types at 375px wide), link crawl found nothing broken. Not applicable: image compression (no images), phone and email links (none exist).

### Errors this evening
19. Symptom: one test failed after the redesign (`test_audit_page_shows_friendly_text_hides_other_trips_and_pages_back`, "0 is identical to 50"). Cause: the test counted rows by searching for the exact text `class="card audit-row"`, so it broke when the markup changed from cards to rows. Fix: the rows now carry the class `row audit-row` and the test counts that. Lesson: tests that count HTML by exact class text are fragile; count a stable marker, and re-run the whole suite after any markup change.
20. Symptom: my "is MySQL running?" check (`Get-NetTCPConnection -LocalPort 3306`) said down while `start-mysql.ps1` said "already running". Cause: the check was the wrong tool (the database listens on a different port or address). Lesson: ask the real thing (run the tests, or the project's own start script) instead of guessing from a port.

## Part 9: The Plan page, calendar files and map links (2026-11-11 night)

### What it is
A new "Plan" tab on every trip: where to be and when (a stay, a flight, tickets, a meal). Everyone in the trip can read it. Only the organiser adds or removes items. Each item has: type, title, start date, optional start time, optional end date and time, optional place name, address and notes.
- No time given = an all-day event. Times have NO time zone on purpose: "14:00" means 14:00 at the place, and a calendar shows exactly what was typed.
- Table `itinerary_items` (migration `2026_11_11_000008`), model `ItineraryItem`, `ItineraryService` (add and delete), `StoreItineraryItemRequest` (validation), `ItineraryController`, view `plan.blade.php`. Limits: 60 items per trip, title 120, address 200, notes 500, dates between 2000 and 2100.
- Audit rows: `plan_item_added`, `plan_item_deleted`; a refused attempt writes `action_refused` (attempted `plan_add` or `plan_delete`). The service looks the member up fresh, so a just-removed organiser is stopped. Nothing can be added once the trip has ended.

### Calendar (no sign-ups, no API keys)
- `GET /t/{code}/calendar.ics` downloads one file with the trip's own dates (an all-day event, so it works even if the plan is empty) plus every item. `GET /t/{code}/plan/{id}.ics` downloads one item. Only people logged in to the trip can download; an id from another trip is a 404; the response is `Cache-Control: no-store`.
- The file is built by `App\Support\IcsBuilder` following the iCalendar rules (RFC 5545): CRLF line ends, lines folded at 75 bytes without cutting a multi-byte character, and `\ ; , newline` escaped in text. The escaping also stops injection: a title with line breaks cannot create a fake second event (tested).
- All-day events end the day AFTER the last day (the format means "up to but not including"). A timed item with no end defaults to one hour; with an end date but no end time it uses the same clock time.
- "Google Calendar" buttons are plain links to Google's event-template URL (`PlanLinks::googleCalendar`); nothing is sent until a person taps.

### Maps
- "Open in maps" is a plain link: `https://www.google.com/maps/search/?api=1&query=<place, address>` (`PlanLinks::map`). On a phone it opens the maps app. It shows only when an item has a place or address. No map is embedded, so nothing third-party loads on the page and nothing breaks on a bad venue network.

### Errors
21. Symptom: one of my new tests failed ("2 is identical to 1") for the injection check. Cause: the test counted the text `BEGIN:VEVENT` anywhere in the file, but the escaped title legitimately contains those characters inside the SUMMARY line. Fix: the test now checks that exactly one LINE is a real `BEGIN:VEVENT` (and one `END:VEVENT`). Lesson: when testing for injection, look at structure (whole lines), not at substrings.
- Suite: 103 passed. Checked in a browser at phone width: no overflow; the real downloaded file has the right dates, escaping and headers.

## Part 10: Lobby as the trip's main page, quieter invite, separate Create and Join (2026-11-11 late night)

### Lobby page ("Rooms") now shows the trip at a glance
- Top of the page: "You have joined this trip", the trip name, your name and role, then three lines: When (trip dates, with "Add to calendar" and "Google Calendar"), Where (the place and address of the FIRST STAY on the Plan, with "Open in maps"), Plan (how many items, with "See the full plan"). The bed lobby itself is unchanged below it.
- Why the place comes from the Plan: there is no separate "place" field on a trip. The Plan is the one source of truth, so nothing can get out of step, and the booking connection will fill the same place later. If there is no stay yet the organiser sees "Add the place on the plan"; members see "The organiser has not added the place yet".
- Bottom of the page, in this order: a Vault block (medium size), then Invite people (deliberately smaller and quieter, with a hairline above it), then a last row of links (Plan, Vault, and People and Activity for the organiser). The Vault link stays in the top menu too.
- Code: `LobbyController::show` looks up the stay and builds the links (`PlanLinks`); view `lobby.blade.php`; CSS `.facts`, `.invite`, `.end-links`. 3 new tests (106 in total): shows the stay's place not an earlier flight's airport; empty state wording per role; order vault, invite, end links.

### Home page: Create and Join are separate
- Before: "Create a trip" scrolled to an area with Create and Join side by side, and "Join" opened a different page. Now they are two separate sections, one under the other, each with its own anchor (`#create`, `#join`). The two big buttons, the menu and the footer all point to their own section. Checked at phone width: Create lands at its heading, Join lands on its form, nothing sits side by side. `/join/CODE` (invite links) still opens the standalone join page.

## Part 11: Booking sites, translators and the signed webhook (2026-11-11 night)

### What it is, in one paragraph
Two PRETEND booking sites live inside the app under `/demo`: DemoStay (hotels) and DemoFly (flights). A booking made there can be sent to a trip, and the trip fills itself in: rooms and beds for a hotel, plan items (and so the calendar, map and the lobby's "Where" line) for hotels and flights. If the booking is changed or cancelled on the pretend site, the trip follows. They are clearly labelled "Demo site, not a real booking site" and stand in for real sites, which cannot be connected to.

### The pieces (and why each exists)
- **Standard format** (`App\Integrations\BookingData`): the one shape The Lobby understands: reference, version, cancelled, title, rooms (name + bed labels), items (plan lines). `BookingData::make()` checks and cleans everything (types, lengths, dates, counts, control characters) because the values come from another company's system.
- **Translators** (`DemoStayProvider`, `DemoFlyProvider`, interface `BookingProvider`, list in `Providers`): each turns one site's OWN format into the standard one. The two sites deliberately use different field names (`booking_ref/check_in/rooms` against `pnr/rev/segments`). Adding a site means one new class and one line in `Providers::CLASSES`; nothing else changes. A sample message per site is saved in the tests (`BookingFixtures`): when a partner changes its format, that test fails first. A time such as `18:30+05:30` is kept as 18:30 (the clock at the place).
- **Two ways in, one set of rules** (`BookingService`):
  - PULL: the organiser types a reference on the Plan page ("Import a booking"); applied at once, because the organiser is the one asking.
  - PUSH: the site sends a signed webhook. The trip code is known to every member, so a first message is only STAGED ("Waiting for you") until the organiser presses "Add to trip". After that, later versions of the same booking apply by themselves. A dismissed booking is ignored forever.
- **THE BED RULE**: a change from a booking site may add rooms and beds and change dates and places, but it never removes a bed that somebody holds or has confirmed (an expired hold counts as free). That room or bed is kept, the booking shows "Needs attention" with the reason, and the audit log records it. Rooms and plan items typed by hand are never touched (each row remembers the booking that created it, `booking_id`).
- **Idempotent**: unique index on `bookings (trip, provider, reference)` plus the partner's version number. Same or older version = "duplicate", nothing happens. The trip row is locked while a booking is applied, so two identical messages at the same instant cannot both create rows.
- **Webhook** `POST /webhooks/{provider}` (`WebhookController`, `WebhookReceiver`): checks run in this order and nothing in the body is read before the signature is verified: known partner, body size (100 KB), secret configured, timestamp fresh (5 minutes: stops replays), HMAC-SHA256 of `timestamp.body` matches (compared in constant time). Then JSON, trip lookup, translation, `BookingService::receive`. Replies carry only a short word (`staged`, `applied`, `duplicate`, `ignored`, or an error word). Refusals are logged (partner, reason, IP, never the body). CSRF is switched off for `webhooks/*` only (a server is calling, not a browser form): the signature is the lock.
- **Secrets**: for our own pretend sites the signing secret is derived from `APP_KEY` (nothing extra to create or leak); `PARTNER_<NAME>_SECRET` in `.env` overrides it for a real partner. Never change `APP_KEY` on the live server.
- **How the pretend sites call it**: `PartnerWebhookSender` signs and hands the message straight to `WebhookReceiver` (same code, no network hop), because the laptop's single-threaded test server cannot answer its own request. `php artisan demo:webhook <trip> <reference>` sends the same message as a real HTTP request.
- **Demo safety**: `/demo` is public, so it is rate limited (60 a minute; booking 10 a minute; sending 10 a minute), capped at 500 stored bookings, all inputs go through Form Requests, and it can be switched off (`DEMO_PARTNERS=false`).
- New tables: `partner_bookings` (the pretend sites' own records), `bookings` (The Lobby's side), and a nullable `booking_id` on `rooms` and `itinerary_items`.

### Errors and lessons tonight
22. Symptom: the first translator test crashed with "Cannot indirectly modify readonly property BookingData::$items". Cause: `end($this->items)` takes a reference to the array, which a read-only property forbids. Fix: `$this->items[array_key_last($this->items)]`. Lesson: tests found it before anyone saw the page; PHP's `end()` and `reset()` need a variable they can modify.
23. Symptom: "Session is missing expected key [status]" in a test that accepted two bookings. Cause: my test helper `stage()` always returned the trip's FIRST booking, so the "second" booking was the same row. Fix: look the booking up by its reference. Lesson: a helper that returns "the" row hides bugs in the test itself.
24. Symptom: an old, correctly signed message got the answer `stale_or_missing_timestamp`, but my test expected `bad_signature`. Cause: the test was wrong: the receiver checks freshness before the signature on purpose and says which one failed. Fix: the test now expects the precise word.
25. Symptom: a test that blanked `app.key` died with "No application encryption key has been specified". Cause: Laravel's own cookie encryption runs before our code, so no HTTP request can work without a key. Fix: that case is tested by calling `WebhookReceiver` directly. Lesson: test a rule at the layer where it lives.
26. Symptom: a test that made 11 booking requests got a 429 on the 11th, while the limit said 10. REAL FINDING: unnamed rate limits (`throttle:10,1`) share one counter per visitor, and a route with BOTH a group limit and its own limit counted every request twice (so "10 a minute" was really 5). Fix: every limit gets its own counter name as a third value (`throttle:10,1,demo-book`, `throttle:60,1,demo`, `throttle:120,1,webhook`, `throttle:60,1,plan-read`). Known and left as it was: `trips`, `join` and `admin/login` still share one counter per visitor (a visitor who made 5 trips and joins cannot log in to the owner page for a minute); harmless.
27. Test limitation to remember: Laravel switches the CSRF check off while tests run, so no unit test can prove the webhook works WITHOUT a CSRF token. It was proved on a running server instead: `php artisan demo:webhook ... --url=http://127.0.0.1:8000` returned `applied` then `duplicate`, and forged and unsigned requests returned 401.
28. Symptom: the heredoc that appended these notes failed to parse in the shell, and nothing was written. Fix: write the text to a file with the file tool, then append the file. Lesson: for long text, do not paste it inside a shell command.

### Proof
- Suite: 205 tests passed (new: translators 29, import and bed rule 32, webhook 20, pretend sites 17), Pint clean.
- `scripts/smoke-test.php` now also covers the booking flow (book, send, waiting, member refused, approve, change follows, forged and unsigned webhooks refused, cancel follows). Run against the local copy with real S3: ALL PASSED (36 checks).
- Left out on purpose: importing rooms from a booking on the create-trip page; claimable flight seats; real partners.
