#!/usr/bin/env bash
set -euo pipefail

FS_DIR=/var/www/html/facturas
VF_DIR=/verifactu

# --- Ensure FS-writable directories exist with correct ownership ---------------
# Named volumes (fs_myfiles) mount empty and inherit root ownership, so we
# fix that here on every boot. Idempotent.
mkdir -p \
  "${FS_DIR}/MyFiles/Tmp/FileCache" \
  "${FS_DIR}/MyFiles/Public" \
  "${FS_DIR}/Dinamic/View" \
  "${FS_DIR}/Dinamic/Model" \
  "${FS_DIR}/Dinamic/Controller" \
  "${FS_DIR}/Dinamic/Lib"
chown -R www-data:www-data "${FS_DIR}/MyFiles" "${FS_DIR}/Dinamic"
chmod -R 775 "${FS_DIR}/MyFiles" "${FS_DIR}/Dinamic"

echo "[entrypoint] Waiting for MariaDB at ${DB_HOST}:${DB_PORT}..."
for i in $(seq 1 60); do
    if mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" -e "SELECT 1" "${DB_NAME}" >/dev/null 2>&1; then
        echo "[entrypoint] MariaDB ready."
        break
    fi
    sleep 1
done

# --- Generate FS config.php from env (always overwrite, so changes propagate) --
echo "[entrypoint] Writing ${FS_DIR}/config.php"
cat > "${FS_DIR}/config.php" <<EOF
<?php
define('FS_COOKIES_EXPIRE',     31536000);
define('FS_DB_FOREIGN_KEYS',    true);
define('FS_DB_TYPE_CHECK',      true);
define('FS_DB_TYPE',            'mysql');
define('FS_DB_HOST',            '${DB_HOST}');
define('FS_DB_PORT',            '${DB_PORT}');
define('FS_DB_NAME',            '${DB_NAME}');
define('FS_DB_USER',            '${DB_USER}');
define('FS_DB_PASS',            '${DB_PASS}');
define('FS_DB_SSL',             false);
define('FS_DB_HISTORY',         true);
define('FS_LANG',               '${FS_LANG:-es_ES}');
define('FS_TIMEZONE',           '${FS_TIMEZONE:-Europe/Madrid}');
define('FS_ROUTE',              '');
define('FS_DEBUG',              false);
define('FS_DISABLE_ADD_PLUGINS', false);
define('FS_DISABLE_RM_PLUGINS', false);
define('FS_HIDDEN_PLUGINS',     '');
EOF
chown www-data:www-data "${FS_DIR}/config.php"

# --- Bootstrap verifactu workspace --------------------------------------------
if [ -d "${VF_DIR}" ]; then
    chown -R www-data:www-data "${VF_DIR}" 2>/dev/null || true

    # Install our scripts' composer deps if missing
    if [ -f "${VF_DIR}/composer.json" ] && [ ! -d "${VF_DIR}/vendor" ]; then
        echo "[entrypoint] Installing verifactu PHP dependencies..."
        (cd "${VF_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction) || true
    fi

    # Apply the sidecar schema (idempotent: CREATE TABLE IF NOT EXISTS)
    if [ -f "${VF_DIR}/setup-sidecar.sql" ]; then
        mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
            < "${VF_DIR}/setup-sidecar.sql" 2>/dev/null || true
    fi
fi

echo "[entrypoint] Handing off to Apache..."
exec "$@"
