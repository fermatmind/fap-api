#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$BACKEND_DIR"

if [[ ! -f ".env" ]]; then
  cp .env.example .env
fi

export APP_ENV="${APP_ENV:-ci}"
export DB_CONNECTION="${DB_CONNECTION:-mysql}"
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_DATABASE="${DB_DATABASE:-fap_ci}"
export DB_USERNAME="${DB_USERNAME:-root}"
export DB_PASSWORD="${DB_PASSWORD:-root}"

php artisan key:generate --force
php artisan migrate:fresh --force --no-interaction
php artisan db:seed --class=CiScalesRegistrySeeder --force --no-interaction

if php artisan fap:sync-slugs --no-interaction; then
  :
else
  php artisan fap:scales:sync-slugs --no-interaction
fi

echo "PASS: prepare_mysql"
