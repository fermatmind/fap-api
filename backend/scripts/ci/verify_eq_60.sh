#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "$BACKEND_DIR"

php artisan content:lint --pack=EQ_60 --pack-version=v1
php artisan content:compile --pack=EQ_60 --pack-version=v1
php artisan test --filter Eq60GoldenCasesTest
php artisan test --filter Eq60
