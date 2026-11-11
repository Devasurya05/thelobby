#!/usr/bin/env bash
# Safely change one setting in the server's .env, then reload the app's cached config.
#   bash /var/www/thelobby/deploy/set-env.sh HOLD_SECONDS 30
#
# Why a script: editing .env with sed -i swaps the file for a new one with the wrong group, which locks the
# web server out of its own settings (we hit exactly that once). This always puts the permissions back.
set -euo pipefail

KEY="${1:?Usage: bash set-env.sh KEY VALUE}"
VALUE="${2?Usage: bash set-env.sh KEY VALUE}"
cd /var/www/thelobby

# Only plain setting names and simple values: nothing that could break out of the sed expression.
if ! [[ "$KEY" =~ ^[A-Z][A-Z0-9_]*$ ]]; then echo "Bad setting name: $KEY"; exit 1; fi
if ! [[ "$VALUE" =~ ^[A-Za-z0-9._:/@-]*$ ]]; then echo "Value may only contain letters, digits and . _ : / @ -"; exit 1; fi

if grep -q "^${KEY}=" .env; then
    sed -i "s|^${KEY}=.*|${KEY}=${VALUE}|" .env
else
    echo "${KEY}=${VALUE}" >> .env
fi

sudo chgrp www-data .env
chmod 640 .env
sudo -u www-data php artisan config:cache
sudo systemctl reload php8.4-fpm
echo "Set ${KEY} (value not shown for safety). App config reloaded."
