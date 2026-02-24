#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "$BACKEND_DIR"

php artisan content:lint --pack=EQ_60 --pack-version=v1
php artisan content:compile --pack=EQ_60 --pack-version=v1
if command -v rg >/dev/null 2>&1; then
  php artisan test --list-tests | rg -q "Eq60" || { echo "[FAIL] Eq60 tests not discovered"; exit 32; }
else
  php artisan test --list-tests | grep -q "Eq60" || { echo "[FAIL] Eq60 tests not discovered"; exit 32; }
fi
php artisan test --filter Eq60
php artisan test --filter Eq60DriverScoringTest
