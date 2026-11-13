# The Lobby: runbook (how to run it, check it, change it, stop it, and tear it down)

Placeholders: `<site>` = the site's address (the free DuckDNS name, `https://...duckdns.org`), `<elastic-ip>` = the server's fixed IP,
`<key>` = the server key file (`.pem`, kept in `~/.ssh`, never in the repo), `<code>` = a trip's 8-character join code.

## 0. The one thing to remember
**The live site runs on the AWS server, not on your laptop.** Closing windows, closing the browser, shutting the laptop: none of it switches the site off.
Only AWS actions can (stopping the server, deleting the database, teardown). **Never stop the server** while it is needed: its address can change when it is stopped and started.

## 1. Is the live site healthy? (60 seconds)
1. Open `<site>` in a browser: home page, padlock.
2. Full check from the laptop (about 40 seconds, makes a throw-away trip):
   `php scripts\smoke-test.php <site>`  (add `--quick` to skip the 30-second wait). The script waits and retries by itself if the site says "slow down" (join and create are limited to 10 a minute per visitor address), so a run right after another just takes longer.
   It must end with `ALL PASSED` (about 46 checks, including the booking flows).

## 2. Log in to the server and log out
- Log in (PowerShell on the laptop): `ssh -i "$env:USERPROFILE\.ssh\<key>.pem" ubuntu@<elastic-ip>`
  A prompt like `ubuntu@ip-...$` means every command now runs on the server.
- Log out: type `exit`. (Closing the window also works. Do not use Ctrl+C: nothing is running in the foreground.)
- SSH only works from the IP address saved in the `thelobby-web` security group. On a different network (new Wi-Fi, hotspot) edit that rule in the AWS console (EC2, Security groups, `thelobby-web`, Inbound rules, SSH, source "My IP"). The website itself is not affected.

## 3. Put new code live
1. Laptop: `git add -A`, `git commit -m "..."`, `git push` (check `git status` first: no `.env`, no `.pem`, no `CLAUDE.md`).
2. Server: `bash /var/www/thelobby/deploy/release.sh` (pulls, installs, migrates the database, rebuilds caches).

## 4. Change a server setting (never edit `.env` by hand)
`bash /var/www/thelobby/deploy/set-env.sh KEY VALUE`, for example `HOLD_SECONDS 30`.
- Demo with friends: `HOLD_SECONDS 30`. Normal use: `HOLD_SECONDS 120`.
- Why not edit by hand: replacing `.env` with the wrong group makes the web server unable to read it, and Laravel then silently falls back to a wrong database (see BUILD_LOG error 17).
- Show the admin key when you need the admin page: `grep ADMIN_KEY /var/www/thelobby/.env` (then open `<site>/admin/login`).

## 5. Something is wrong: where to look
| Symptom | Check |
|---|---|
| Site will not load at all | AWS console, EC2, Instances: is `thelobby-web` *Running* with 2/2 checks? Then on the server: `systemctl is-active nginx php8.4-fpm cron`. Restart: `sudo systemctl restart nginx php8.4-fpm` |
| "Something went wrong" page | `<site>/admin/errors` (needs the admin key) or the newest log: `sudo tail -50 /var/www/thelobby/storage/logs/laravel-$(date +%F).log` |
| Pages load but data errors | Database reachable? `sudo -u www-data php /var/www/thelobby/artisan migrate:status` (RDS, EC2 console: `thelobby-db` must be *Available*) |
| Vault upload/open fails | The server role: `sudo -u www-data php /var/www/thelobby/artisan tinker --execute='dump(Storage::disk("s3")->exists("trips/none"));'` should print `false` (not an error). Bucket name in `.env` (`AWS_BUCKET`)? |
| Web server complaint | `sudo tail -30 /var/log/nginx/error.log` |
| Holds do not expire / purge does not run | `journalctl -u cron --since "5 min ago" --no-pager | tail`; `cd /var/www/thelobby && sudo -u www-data php artisan schedule:list`; run by hand: `sudo -u www-data php artisan vault:purge` |
| Padlock expired or warning | `sudo certbot renew --dry-run`; `sudo certbot renew`; `sudo systemctl reload nginx` |
| Name does not resolve | DuckDNS page: the name's IP must be `<elastic-ip>`. The site also works at `http://<elastic-ip>` as a last resort (no padlock). |

Race demo on the live server (throw-away trip only): `cd /var/www/thelobby && sudo -u www-data php artisan demo:race <code> --racers=5 --allow-production`

## 5b. The pretend booking sites and the booking connection
- Pretend sites: `<site>/demo` (DemoStay hotels, DemoFly flights). Not real, free, rate limited, capped at 500 stored bookings. Switch them off completely with `bash /var/www/thelobby/deploy/set-env.sh DEMO_PARTNERS false` (then `/demo` is a 404); on again with `true`.
- Demo flow, fastest: on a pretend site book, then press "Create a new trip" and type a name: a finished trip (rooms, dates, you as organiser) opens. A booking already in a live trip never makes a second trip.
- Demo flow, trip first: on the home page choose "They will come from a booking" when creating (names only), then on a pretend site use "Already made a trip? Put its code here": the trip fills in at once (the first booking needs no approval). Keep that code private until then.
- Demo flow, existing trip with typed rooms: send with the trip's code, the organiser presses "Add to trip" on the trip's Plan page. Later changes and cancellations on the pretend site follow by themselves. Beds that are held or confirmed are never removed: the booking shows "Needs attention" instead.
- Show the real webhook over HTTP (on the server): `cd /var/www/thelobby && sudo -u www-data php artisan demo:webhook <trip-code> <booking-reference>` (add `--url=https://<site>` if needed). Expect `HTTP 202 {"result":"staged"}` the first time, `HTTP 200 {"result":"duplicate"}` when repeated.
- No secret to manage: signing secrets are derived from `APP_KEY`. Do NOT change `APP_KEY` on the live server (it would also log everybody out).
- If a booking does not arrive: `<site>/admin/errors` and `sudo grep "Webhook refused" /var/www/thelobby/storage/logs/laravel-$(date +%F).log` (a refused signature is logged without its body).

## 6. Monday-morning checklist (10 minutes)
1. Open `<site>` on the laptop and on a phone using mobile data (not the venue Wi-Fi).
2. `php scripts\smoke-test.php <site> --quick`: `ALL PASSED`.
3. AWS console, Billing: spend is small and as expected.
4. Make a fresh demo trip with a few rooms; keep its invite link ready.
5. Plan B if the venue network blocks the site: the app also runs fully on the laptop (section 7) with the real S3 bucket; keep a short screen recording of the live demo too.

## 7. Run it on the laptop (Windows PowerShell)
Start (three windows/tabs, in the project folder):
1. Database (needed after every reboot): `powershell -File scripts\start-mysql.ps1`
2. The app: `php artisan serve` (for other devices on the same Wi-Fi: `php artisan serve --host=0.0.0.0`, and the firewall rule below, then open `http://<laptop-ip>:8000`)
3. Background jobs (holds, purge): `php artisan schedule:work`

Stop: **Ctrl+C** in the `serve` and `schedule:work` windows. Stop the database: `powershell -File scripts\stop-mysql.ps1`.
Firewall rule (only for phone tests on the same Wi-Fi; admin PowerShell): add `New-NetFirewallRule -DisplayName "The Lobby dev (port 8000)" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow -Profile Private`; remove `Remove-NetFirewallRule -DisplayName "The Lobby dev (port 8000)"`.
Tests: `php artisan test` (needs the local database running; expect all green). Formatting: `php vendor\bin\pint app tests config routes`.
Local `.env` has `HOLD_SECONDS` for quick demos (30) or 120 for normal.

## 8. AWS console
- Sign in as the IAM admin user (`lobby-admin`) with the password and the 6-digit code from the phone authenticator. Do not use the root user day to day.
- Region must be **Mumbai (ap-south-1)** (top right). A wrong region shows an empty console, not your resources.
- Everything created stays there whether or not you are logged in. The things that cost credits while on: the EC2 instance, the RDS database, the Elastic IP (about $1 a day together, by estimate; check Billing).
- Never click "Upgrade plan", never join AWS Organizations (both silently leave the Free plan).

## 9. Teardown after the demo (do it, or the credits keep burning)
In this order (names as created):
1. EC2: terminate instance `thelobby-web` (wait until *terminated*).
2. EC2, Elastic IPs: **release** the address (an unattached Elastic IP is still billed).
3. RDS: delete `thelobby-db` (untick "create final snapshot" and tick "delete automated backups" if the data is not needed).
4. S3: empty the bucket, then delete it.
5. EC2, Security groups: delete `thelobby-db` then `thelobby-web`. Key pairs: delete `thelobby-key`; delete the `.pem` file from the laptop.
6. IAM: delete the access key of `lobby-local-dev` and the user; delete role `thelobby-ec2-role`; delete policy `thelobby-vault-access`. Remove the key from the local `.env`.
7. DuckDNS: delete the domain. (The certificate dies with the server.)
8. Billing: delete the budget, cancel any saved payment mandate/auto-pay, and, if finished with AWS, close the account.
9. Laptop: remove the firewall rule; the repo can stay as it is.
After this the only costs are none: verify in Billing the next day that no service is listed as running.
