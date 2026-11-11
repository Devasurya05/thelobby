#!/usr/bin/env bash
# Put the latest pushed code live. Run on the server after every `git push` you want deployed:
#   bash /var/www/thelobby/deploy/release.sh
set -euo pipefail

cd /var/www/thelobby

git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Everything below runs as the web user (the one that owns storage/ and bootstrap/cache/).
ARTISAN="sudo -u www-data php artisan"
$ARTISAN package:discover --ansi
# --force: Laravel asks "are you sure?" before changing a production database; this answers yes on purpose.
$ARTISAN migrate --force
# Cache config, routes and views so each request starts faster. (Re-run on every release.)
$ARTISAN config:cache
$ARTISAN route:cache
$ARTISAN view:cache

sudo systemctl reload php8.4-fpm
echo "Released commit $(git rev-parse --short HEAD)"
