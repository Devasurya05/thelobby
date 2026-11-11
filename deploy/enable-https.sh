#!/usr/bin/env bash
# Turns on HTTPS (the padlock) with a free Let's Encrypt certificate. Run on the server:
#   bash /var/www/thelobby/deploy/enable-https.sh <domain-name> <your-email>
# Example:
#   bash enable-https.sh thelobby-demo.duckdns.org me@example.com
#
# Needs: the name already points at this server (checked below), and ports 80 and 443 open (security group).
# Safe to run again. The certificate renews itself (a timer installed by certbot).
set -euo pipefail

DOMAIN="${1:?Usage: bash enable-https.sh <domain-name> <your-email>}"
EMAIL="${2:?missing email (Let's Encrypt sends expiry warnings there)}"
APP_DIR=/var/www/thelobby

echo "== 1/5 does the name point at this server? =="
# The certificate authority will connect to this name over the internet. If it points elsewhere, the check fails.
MY_IP="$(curl -fsS -m 10 https://checkip.amazonaws.com | tr -d '[:space:]')"
DNS_IP="$(getent ahostsv4 "$DOMAIN" | awk '{print $1; exit}')"
if [ "$MY_IP" != "$DNS_IP" ]; then
    echo "STOP: $DOMAIN points to '${DNS_IP:-nothing}' but this server is $MY_IP."
    echo "Fix the name at your DNS provider, wait a minute, and run this again."
    exit 1
fi
echo "OK: $DOMAIN -> $MY_IP"

echo
echo "Let's Encrypt asks you to accept its Terms of Service: https://letsencrypt.org/repository/"
read -r -p "Type yes to accept and continue: " ANSWER
if [ "$ANSWER" != "yes" ]; then
    echo "Not accepted. Nothing was changed."
    exit 1
fi

echo "== 2/5 certbot =="
sudo env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get -y install certbot python3-certbot-nginx

echo "== 3/5 tell Nginx the site's name =="
sudo sed -i "s/^\([[:space:]]*server_name[[:space:]]\+\)[^;]*;/\1${DOMAIN};/" /etc/nginx/sites-available/thelobby
sudo nginx -t
sudo systemctl reload nginx

echo "== 4/5 get the certificate and switch Nginx to HTTPS (plain HTTP gets redirected) =="
sudo certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email --redirect --non-interactive

echo "== 5/5 tell the app it now lives at https =="
cd "$APP_DIR"
setenv() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
setenv APP_URL "https://${DOMAIN}"
# Cookies may now be marked Secure: browsers only send them over HTTPS.
setenv SESSION_SECURE_COOKIE true
# sed -i replaced the file, so give the web server's group read access back (otherwise the app cannot read .env).
sudo chgrp www-data .env
chmod 640 .env
sudo -u www-data php artisan config:cache
sudo systemctl reload php8.4-fpm

echo
echo "Done. Open https://${DOMAIN}"
echo "Check auto-renewal works with:  sudo certbot renew --dry-run"
