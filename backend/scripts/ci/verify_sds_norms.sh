#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$BACKEND_DIR"

php artisan norms:import --scale=SDS_20 --csv=resources/norms/sds/sds_norm_stats_seed.csv --activate=1
php artisan norms:sds:drift-check --from=2026Q1_seed --to=2026Q1_seed --group_id=zh-CN_all_18-60
php artisan test --filter SdsNorms
