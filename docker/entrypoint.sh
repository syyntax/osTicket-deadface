#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/var/www/html/include/ost-config.php"
PERSIST_DIR="/var/www/html/data/config"
PERSIST_FILE="${PERSIST_DIR}/ost-config.php"
COOKIE_JAR="$(mktemp)"
INSTALL_LOG="$(mktemp)"

require_env() {
    local name="$1"
    if [ -z "${!name:-}" ]; then
        echo "entrypoint: required environment variable ${name} is not set" >&2
        exit 1
    fi
}

for var in DB_HOST DB_NAME DB_USER DB_PASSWORD \
           OSTICKET_SITE_NAME OSTICKET_DEFAULT_EMAIL \
           OSTICKET_ADMIN_FNAME OSTICKET_ADMIN_LNAME OSTICKET_ADMIN_EMAIL \
           OSTICKET_ADMIN_USERNAME OSTICKET_ADMIN_PASSWORD; do
    require_env "$var"
done

DB_TABLE_PREFIX="${DB_TABLE_PREFIX:-ost_}"
OSTICKET_LANG="${OSTICKET_LANG:-en_US}"
OSTICKET_TIMEZONE="${OSTICKET_TIMEZONE:-UTC}"

# $CONFIG_FILE (include/ost-config.php) must live at that exact path for
# osTicket's own code to find it, but we want the *generated* config to
# survive container recreation without persisting all of include/ (which
# is core app code, not user data). So the named volume is mounted at
# data/config instead, and include/ost-config.php is a symlink into it -
# seeded from the fork's sample config the first time the volume is empty.
mkdir -p "$PERSIST_DIR"
if [ ! -f "$PERSIST_FILE" ]; then
    echo "entrypoint: seeding persisted config from ost-sampleconfig.php"
    cp /var/www/html/include/ost-sampleconfig.php "$PERSIST_FILE"
fi
ln -sf "$PERSIST_FILE" "$CONFIG_FILE"
chown -R www-data:www-data "$PERSIST_DIR"

echo "entrypoint: waiting for database at ${DB_HOST}..."
for i in $(seq 1 60); do
    # --skip-ssl: the mariadb-client on this base image defaults to
    # requiring TLS, but the db container doesn't terminate TLS - only
    # affects this readiness probe, not the app's PHP mysqli connection.
    if mysqladmin ping -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" --skip-ssl --silent 2>/dev/null; then
        echo "entrypoint: database is reachable"
        break
    fi
    if [ "$i" -eq 60 ]; then
        echo "entrypoint: database never became reachable, giving up" >&2
        exit 1
    fi
    sleep 2
done

if grep -q "define('OSTINSTALLED',TRUE);" "$CONFIG_FILE" 2>/dev/null; then
    echo "entrypoint: osTicket already installed (config file already provisioned), skipping installer"
else
    echo "entrypoint: running first-time osTicket install via the setup wizard endpoint"

    apache2-foreground &
    APACHE_PID=$!

    for i in $(seq 1 60); do
        if curl -sf -o /dev/null "http://127.0.0.1/setup/install.php"; then
            break
        fi
        if [ "$i" -eq 60 ]; then
            echo "entrypoint: apache never became reachable for install, giving up" >&2
            kill "$APACHE_PID" 2>/dev/null || true
            exit 1
        fi
        sleep 1
    done

    # Drive the same three-step POST flow the browser-based installer uses
    # (setup/install.php, state machine keyed off $_SESSION['ost_installer']['s']).
    # No CSRF token is required by this endpoint.
    curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null \
        "http://127.0.0.1/setup/install.php"

    curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null \
        --data-urlencode "s=prereq" \
        "http://127.0.0.1/setup/install.php"

    curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null \
        --data-urlencode "s=config" \
        "http://127.0.0.1/setup/install.php"

    curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o "$INSTALL_LOG" \
        --data-urlencode "s=install" \
        --data-urlencode "name=${OSTICKET_SITE_NAME}" \
        --data-urlencode "email=${OSTICKET_DEFAULT_EMAIL}" \
        --data-urlencode "lang_id=${OSTICKET_LANG}" \
        --data-urlencode "fname=${OSTICKET_ADMIN_FNAME}" \
        --data-urlencode "lname=${OSTICKET_ADMIN_LNAME}" \
        --data-urlencode "admin_email=${OSTICKET_ADMIN_EMAIL}" \
        --data-urlencode "username=${OSTICKET_ADMIN_USERNAME}" \
        --data-urlencode "passwd=${OSTICKET_ADMIN_PASSWORD}" \
        --data-urlencode "passwd2=${OSTICKET_ADMIN_PASSWORD}" \
        --data-urlencode "prefix=${DB_TABLE_PREFIX}" \
        --data-urlencode "dbhost=${DB_HOST}" \
        --data-urlencode "dbname=${DB_NAME}" \
        --data-urlencode "dbuser=${DB_USER}" \
        --data-urlencode "dbpass=${DB_PASSWORD}" \
        --data-urlencode "timezone=${OSTICKET_TIMEZONE}" \
        "http://127.0.0.1/setup/install.php"

    if grep -q "define('OSTINSTALLED',TRUE);" "$CONFIG_FILE" 2>/dev/null; then
        echo "entrypoint: osTicket install completed successfully"
    else
        echo "entrypoint: osTicket install did NOT complete - config file was not finalized." >&2
        echo "entrypoint: installer response body follows for debugging:" >&2
        cat "$INSTALL_LOG" >&2
        kill "$APACHE_PID" 2>/dev/null || true
        exit 1
    fi

    kill "$APACHE_PID"
    wait "$APACHE_PID" 2>/dev/null || true
fi

rm -f "$COOKIE_JAR" "$INSTALL_LOG"

echo "entrypoint: handing off to: $*"
exec "$@"
