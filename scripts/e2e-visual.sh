#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(node -p "require('@playwright/test/package.json').version")
# Preserve the checkout path so the host-created public/storage symlink also resolves inside Docker.
exec docker run --rm --platform linux/amd64 -v "$PWD":"$PWD" -w "$PWD" -e E2E_VISUAL=1 \
    "mcr.microsoft.com/playwright:v${VERSION}-noble" bash -c "
        apt-get update -qq >/dev/null &&
        apt-get install -y -qq php-cli php-sqlite3 php-mbstring php-xml php-curl php-gd php-zip php-bcmath php-intl >/dev/null &&
        npx playwright test visual --output=test-results $*; status=\$?; chown -R $(id -u):$(id -g) storage bootstrap/cache test-results tests/e2e/visual.spec.js-snapshots; exit \$status"
