#!/usr/bin/env bash
# One-time setup of The Lobby on a fresh Ubuntu 24.04 server (EC2).
#
# Run as the normal login user (NOT root):
#   bash setup-server.sh <git-repo-url> <s3-bucket-name> <app-url>
# Example:
#   bash setup-server.sh https://github.com/OWNER/thelobby.git my-bucket-name http://203.0.113.10
#
# What it does: installs Nginx + PHP 8.4 + Composer (checksum verified), downloads the app, writes a
# production .env (no AWS keys: the server gets its S3 access from its IAM role), sets file permissions,
# turns on the Nginx site and the once-a-minute scheduler.
# Safe to run again: every step checks before it changes anything.
set -euo pipefail

REPO_URL="${1:?Usage: bash setup-server.sh <git-repo-url> <s3-bucket-name> <app-url>}"
BUCKET="${2:?missing S3 bucket name}"
APP_URL="${3:?missing app url, e.g. http://1.2.3.4}"
APP_DIR=/var/www/thelobby
PHP_V=8.4
MIN_PHP=8.4.27   # earlier 8.4 releases lack the PHP2Shell security fix

echo "== 1/8 system packages =="
# Never ask questions (Ubuntu would otherwise pop up a "restart services?" dialog and freeze the script).
APT="sudo env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get -y"
$APT update
$APT upgrade
$APT install software-properties-common ca-certificates curl unzip git mysql-client nginx
# Ubuntu's own PHP is older than our minimum, so use the well-known maintained PHP repository.
sudo add-apt-repository -y ppa:ondrej/php
$APT update
$APT install "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-mysql" "php${PHP_V}-mbstring" \
    "php${PHP_V}-xml" "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-intl" "php${PHP_V}-gd" "php${PHP_V}-bcmath"

if ! php -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$MIN_PHP"; then
    echo "STOP: PHP $(php -r 'echo PHP_VERSION;') is older than $MIN_PHP (security fix). Not continuing."
    exit 1
fi
echo "PHP version OK: $(php -r 'echo PHP_VERSION;')"

echo "== 2/8 PHP settings =="
sudo tee "/etc/php/${PHP_V}/fpm/conf.d/99-thelobby.ini" >/dev/null <<'EOF'
; vault uploads are limited to 10 MB in the app; these must be a little higher
upload_max_filesize = 11M
post_max_size = 12M
; never show PHP errors to visitors
display_errors = Off
expose_php = Off
EOF

echo "== 3/8 Composer (installer checksum verified) =="
if ! command -v composer >/dev/null; then
    EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    if [ "$EXPECTED" != "$ACTUAL" ]; then
        rm -f /tmp/composer-setup.php
        echo "STOP: Composer installer checksum does not match."
        exit 1
    fi
    sudo php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

echo "== 4/8 AWS database certificate bundle =="
# Lets the app check it is really talking to our RDS database, over an encrypted connection.
sudo mkdir -p /etc/ssl/rds
sudo curl -fsSL -o /etc/ssl/rds/global-bundle.pem https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem

echo "== 5/8 app code =="
sudo mkdir -p "$APP_DIR"
sudo chown "$USER":"$USER" "$APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
    git clone "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"
# --no-scripts: Composer's own scripts would run as this user; we run them as the web user below.
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

echo "== 6/8 production .env =="
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi
setenv() { # setenv KEY VALUE : replace the line if it exists, else add it
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
setenv APP_ENV production
setenv APP_DEBUG false
setenv APP_URL "$APP_URL"
setenv LOG_LEVEL warning
setenv AWS_DEFAULT_REGION ap-south-1
setenv AWS_BUCKET "$BUCKET"
setenv MYSQL_ATTR_SSL_CA /etc/ssl/rds/global-bundle.pem
# Admin key: random, only ever stored here. Show it later with: grep ADMIN_KEY .env
if ! grep -qE '^ADMIN_KEY=.{16,}' .env; then setenv ADMIN_KEY "$(openssl rand -hex 16)"; fi
# NO AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY here on purpose: the server's IAM role provides access.
sudo chgrp www-data .env
chmod 640 .env

echo "== 7/8 permissions =="
# Only the web server user may write, and only to storage/ and bootstrap/cache/.
# (mkdir -p: harmless if they exist; Git does not keep empty folders.)
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo -u www-data php artisan package:discover --ansi

echo "== 8/8 Nginx and scheduler =="
sudo cp deploy/nginx-thelobby.conf /etc/nginx/sites-available/thelobby
sudo ln -sf /etc/nginx/sites-available/thelobby /etc/nginx/sites-enabled/thelobby
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl enable --now "php${PHP_V}-fpm" nginx
sudo systemctl reload "php${PHP_V}-fpm"
sudo systemctl reload nginx

# Once a minute: expire bed holds and purge finished trips' vault files. Runs as the web user.
CRON_LINE="* * * * * cd $APP_DIR && /usr/bin/php artisan schedule:run >> /dev/null 2>&1 # thelobby scheduler"
( sudo crontab -u www-data -l 2>/dev/null | grep -v 'thelobby scheduler' || true; echo "$CRON_LINE" ) | sudo crontab -u www-data -

echo
echo "Server is set up. NEXT: create the database user, then release:"
echo "  bash $APP_DIR/deploy/create-db-user.sh <rds-endpoint>"
echo "  bash $APP_DIR/deploy/release.sh"
