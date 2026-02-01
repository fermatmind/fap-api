#!/usr/bin/env bash
set -euo pipefail

DB="${DB_DATABASE:-/tmp/fap-ci.sqlite}"

echo "[ci] prepare sqlite db: ${DB}"

rm -f "${DB}"
mkdir -p "$(dirname "${DB}")" || true
touch "${DB}"
chmod 666 "${DB}"

export APP_ENV="${APP_ENV:-ci}"
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"

# 让 Laravel 读取最新 env
php artisan config:clear || true

# 建表
php artisan migrate --force --no-interaction

# 写入 CI 必要的 scales_registry（含 MBTI + demo scales）
php artisan db:seed --class="Database\\Seeders\\CiScalesRegistrySeeder" --force --no-interaction

echo "[ci] sqlite ready."
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
echo "scales_registry rows: ".DB::table("scales_registry")->count().PHP_EOL;
echo "MBTI rows: ".DB::table("scales_registry")->where("org_id",0)->where("code","MBTI")->count().PHP_EOL;
'
