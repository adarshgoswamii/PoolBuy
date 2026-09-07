#!/usr/bin/env bash
# Railway entrypoint for the OpenCart PoolBuy app.
#
# Railway provides:
#   - a MySQL service with MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD / MYSQLDATABASE
#     (we also accept the standard MYSQL_* names as a fallback)
#   - $PORT for the web server to listen on
#   - RAILWAY_PUBLIC_DOMAIN for the public URL
#
# This script:
#   1. Points Apache at $PORT.
#   2. Writes config.php + admin/config.php from the environment.
#   3. Waits for MySQL, runs the OpenCart CLI install once (guarded by a lock row),
#      installs + seeds the PoolBuy extension.
#   4. Starts Apache in the foreground.
set -uo pipefail
# NOTE: intentionally NOT using `set -e`. The web server must always start so the
# platform health check passes; DB/install/seed steps are best-effort and must not
# abort the container if they transiently fail (e.g. MySQL not linked yet).

OC=/var/www/html

# ---- Resolve DB settings (support both Railway MYSQL* and generic names) ----
DB_HOST="${MYSQLHOST:-${DB_HOSTNAME:-mysql}}"
DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_USER="${MYSQLUSER:-${DB_USERNAME:-root}}"
DB_PASS="${MYSQLPASSWORD:-${DB_PASSWORD:-opencart}}"
DB_NAME="${MYSQLDATABASE:-${DB_DATABASE:-railway}}"

# ---- Public URL ----
DOMAIN="${RAILWAY_PUBLIC_DOMAIN:-localhost}"
BASE_URL="https://${DOMAIN}/"
if [ "$DOMAIN" = "localhost" ]; then BASE_URL="http://localhost/"; fi

PORT="${PORT:-80}"

echo "[entrypoint] DB=${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}  BASE=${BASE_URL}  PORT=${PORT}"

# ---- Apache: listen on $PORT (Railway assigns the port; must match exactly) ----
echo "Listen ${PORT}" > /etc/apache2/ports.conf
sed -i "s#<VirtualHost \*:[0-9]*>#<VirtualHost *:${PORT}>#" /etc/apache2/sites-available/000-default.conf
# Silence the "could not determine FQDN" warning
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true
# Ensure exactly ONE MPM is loaded. mod_php needs prefork; a stray event/worker MPM
# (pulled in by some apt deps) makes Apache refuse to start with
# "More than one MPM loaded". Force prefork only.
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

# ---- Write catalog config.php ----
cat > "${OC}/config.php" <<PHP
<?php
define('APPLICATION', 'Catalog');
define('HTTP_SERVER', '${BASE_URL}');
define('DIR_OPENCART', '${OC}/');
define('DIR_APPLICATION', DIR_OPENCART . 'catalog/');
define('DIR_SYSTEM', DIR_OPENCART . 'system/');
define('DIR_EXTENSION', DIR_OPENCART . 'extension/');
define('DIR_IMAGE', DIR_OPENCART . 'image/');
define('DIR_STORAGE', DIR_SYSTEM . 'storage/');
define('DIR_LANGUAGE', DIR_APPLICATION . 'language/');
define('DIR_TEMPLATE', DIR_APPLICATION . 'view/template/');
define('DIR_CONFIG', DIR_SYSTEM . 'config/');
define('DIR_CACHE', DIR_STORAGE . 'cache/');
define('DIR_DOWNLOAD', DIR_STORAGE . 'download/');
define('DIR_LOGS', DIR_STORAGE . 'logs/');
define('DIR_SESSION', DIR_STORAGE . 'session/');
define('DIR_UPLOAD', DIR_STORAGE . 'upload/');
define('DB_DRIVER', 'mysqli');
define('DB_HOSTNAME', '${DB_HOST}');
define('DB_USERNAME', '${DB_USER}');
define('DB_PASSWORD', '${DB_PASS}');
define('DB_DATABASE', '${DB_NAME}');
define('DB_PREFIX', 'oc_');
define('DB_PORT', '${DB_PORT}');
define('CACHE_ENGINE', 'file');
PHP

# ---- Write admin config.php ----
cat > "${OC}/admin/config.php" <<PHP
<?php
define('APPLICATION', 'Admin');
define('HTTP_SERVER', '${BASE_URL}admin/');
define('HTTP_CATALOG', '${BASE_URL}');
define('DIR_OPENCART', '${OC}/');
define('DIR_APPLICATION', DIR_OPENCART . 'admin/');
define('DIR_SYSTEM', DIR_OPENCART . 'system/');
define('DIR_EXTENSION', DIR_OPENCART . 'extension/');
define('DIR_IMAGE', DIR_OPENCART . 'image/');
define('DIR_STORAGE', DIR_SYSTEM . 'storage/');
define('DIR_CATALOG', DIR_OPENCART . 'catalog/');
define('DIR_LANGUAGE', DIR_APPLICATION . 'language/');
define('DIR_TEMPLATE', DIR_APPLICATION . 'view/template/');
define('DIR_CONFIG', DIR_SYSTEM . 'config/');
define('DIR_CACHE', DIR_STORAGE . 'cache/');
define('DIR_DOWNLOAD', DIR_STORAGE . 'download/');
define('DIR_LOGS', DIR_STORAGE . 'logs/');
define('DIR_SESSION', DIR_STORAGE . 'session/');
define('DIR_UPLOAD', DIR_STORAGE . 'upload/');
define('DB_DRIVER', 'mysqli');
define('DB_HOSTNAME', '${DB_HOST}');
define('DB_USERNAME', '${DB_USER}');
define('DB_PASSWORD', '${DB_PASS}');
define('DB_DATABASE', '${DB_NAME}');
define('DB_PREFIX', 'oc_');
define('DB_PORT', '${DB_PORT}');
define('CACHE_ENGINE', 'file');
define('OPENCART_SERVER', 'https://www.opencart.com/');
PHP

# ---- Wait for MySQL ----
echo "[entrypoint] waiting for MySQL..."
for i in $(seq 1 60); do
  if H="$DB_HOST" U="$DB_USER" P="$DB_PASS" PT="$DB_PORT" php -r '$c=@mysqli_connect(getenv("H"),getenv("U"),getenv("P"),"",intval(getenv("PT")));exit($c?0:1);' 2>/dev/null; then
    echo "[entrypoint] MySQL is up"; break
  fi
  sleep 3
done

# ---- One-time install (guarded) ----
if [ -f "${OC}/install/cli_install.php" ]; then
  ALREADY=$(H="$DB_HOST" U="$DB_USER" P="$DB_PASS" D="$DB_NAME" PT="$DB_PORT" php -r '$c=@mysqli_connect(getenv("H"),getenv("U"),getenv("P"),getenv("D"),intval(getenv("PT")));if(!$c){exit(0);}$r=@mysqli_query($c,"SELECT 1 FROM oc_setting LIMIT 1");echo $r?"yes":"no";' 2>/dev/null || echo "no")
  if [ "$ALREADY" != "yes" ]; then
    echo "[entrypoint] running OpenCart CLI install..."
    php "${OC}/install/cli_install.php" install \
      --username admin --password admin --email admin@poolbuy.test \
      --http_server "${BASE_URL}" \
      --db_driver mysqli --db_hostname "${DB_HOST}" --db_username "${DB_USER}" \
      --db_password "${DB_PASS}" --db_database "${DB_NAME}" --db_port "${DB_PORT}" --db_prefix oc_ || echo "[entrypoint] install returned non-zero (may already exist)"
  else
    echo "[entrypoint] OpenCart already installed; skipping."
  fi
fi

echo "[entrypoint] starting Apache on ${PORT}"

# Seed PoolBuy in the background once Apache is serving (the seeder calls the
# admin HTTP API on localhost, so the web server must be up first).
(
  for i in $(seq 1 30); do
    if curl -sf "http://127.0.0.1:${PORT}/admin/" >/dev/null 2>&1; then break; fi
    sleep 2
  done
  echo "[entrypoint] running PoolBuy seed..."
  php "${OC}/railway_seed.php" || echo "[entrypoint] seed returned non-zero"
) &

exec apache2-foreground
