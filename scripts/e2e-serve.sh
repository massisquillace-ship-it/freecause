#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

PHP=php
if [ -x /opt/homebrew/opt/php/bin/php ]; then PHP=/opt/homebrew/opt/php/bin/php; fi

DB="${E2E_DB:-$(mktemp -d)/e2e.sqlite}"
mkdir -p "$(dirname "$DB")"
rm -f "$DB"
touch "$DB"

export APP_ENV=e2e APP_DEBUG=false APP_URL=http://127.0.0.1:8099
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export APP_CONFIG_CACHE="$(dirname "$DB")/config.php" APP_ROUTES_CACHE="$(dirname "$DB")/routes.php"
export DB_CONNECTION=sqlite DB_DATABASE="$DB" DB_URL=
export CACHE_STORE=array SESSION_DRIVER=file QUEUE_CONNECTION=sync MAIL_MAILER=array
export SENTRY_LARAVEL_DSN= LOG_CHANNEL=stderr ENFORCE_MIN_PHP=false
# Consent text is synthetic/test-only in this isolated database.
export ORGANIZATIONS_CONSENT_ENABLED=true
export PHP_CLI_SERVER_WORKERS=4

[ "$DB_CONNECTION" = sqlite ]
: > .env.e2e
trap 'rm -f .env.e2e' EXIT
trap 'kill "${PID:-0}" 2>/dev/null; exit 143' INT TERM
"$PHP" artisan migrate --force --seed --seeder='Database\Seeders\E2eSeeder' >/dev/null
# Uploaded covers are served from /storage; a fresh checkout (CI) has no link yet.
# The visual job runs in Docker on the host checkout, so it must not rewrite the host's link.
if [ -z "${E2E_VISUAL:-}" ]; then
    [ -e public/storage ] || { rm -f public/storage; "$PHP" artisan storage:link >/dev/null; }
fi
"$PHP" artisan serve --no-reload --host=127.0.0.1 --port=8099 &
PID=$!
wait "$PID"
