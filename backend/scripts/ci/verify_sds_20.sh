#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "$BACKEND_DIR"

php artisan content:lint --pack=SDS_20 --pack-version=v1
php artisan content:compile --pack=SDS_20 --pack-version=v1
php artisan test --testsuite=Feature --list-tests | rg -q "Sds20" || { echo "[FAIL] Sds20 tests not discovered"; exit 32; }
php artisan test --testsuite=Feature --filter Sds20GoldenCasesTest
php artisan test --testsuite=Feature --filter Sds20
