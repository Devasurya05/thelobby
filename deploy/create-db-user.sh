#!/usr/bin/env bash
# Creates the app's own database and a LIMITED database user on RDS, and writes its password into .env.
#   bash create-db-user.sh <rds-endpoint>
#
# Why: the RDS "master" account can do anything (drop every database). The app should never run with it.
# The app user may only touch the `thelobby` database. Its password is generated here, written straight
# into .env, and never printed. You only type the master password (hidden), once, and it is not saved.
set -euo pipefail

HOST="${1:?Usage: bash create-db-user.sh <rds-endpoint>}"
APP_DIR=/var/www/thelobby
CA=/etc/ssl/rds/global-bundle.pem

read -r -p "RDS master username (usually admin): " MASTER
read -r -s -p "RDS master password (hidden): " MASTER_PW
echo

# Letters and digits only: nothing here needs escaping in SQL or in .env.
APP_PW="$(openssl rand -hex 24)"

# The SQL goes in through stdin, so the new password never appears in the process list or command history.
MYSQL_PWD="$MASTER_PW" mysql --host="$HOST" --user="$MASTER" --ssl-ca="$CA" --ssl-mode=VERIFY_IDENTITY <<SQL
CREATE DATABASE IF NOT EXISTS thelobby CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'thelobby'@'%' IDENTIFIED BY '${APP_PW}';
ALTER USER 'thelobby'@'%' IDENTIFIED BY '${APP_PW}';
GRANT ALL PRIVILEGES ON thelobby.* TO 'thelobby'@'%';
FLUSH PRIVILEGES;
SQL
unset MASTER_PW

cd "$APP_DIR"
setenv() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
setenv DB_HOST "$HOST"
setenv DB_PORT 3306
setenv DB_DATABASE thelobby
setenv DB_USERNAME thelobby
setenv DB_PASSWORD "$APP_PW"
unset APP_PW

# sed -i swaps .env for a brand-new file owned by this user, which would lock the web server
# (user www-data) out of its own settings. Give the web group read access back.
sudo chgrp www-data .env
chmod 640 .env

echo "Done. Database 'thelobby' and limited user 'thelobby' exist; .env updated (password not shown)."
