#!/usr/bin/env bash
set -euo pipefail

echo "[ci] prepare sqlite db: /tmp/fap-ci.sqlite"

rm -f /tmp/fap-ci.sqlite
touch /tmp/fap-ci.sqlite
chmod 666 /tmp/fap-ci.sqlite

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/fap-ci.sqlite

# 让 Laravel 读取最新 env（CI 里最稳的顺序）
php artisan config:clear

# 建表
php artisan migrate --force --no-interaction

# 写入 scales_registry 基础数据
php artisan db:seed --class="Database\\Seeders\\CiScalesRegistrySeeder" --force --no-interaction

echo "[ci] sqlite ready."
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
echo "scales_registry MBTI rows: ".DB::table("scales_registry")->where("org_id",0)->where("code","MBTI")->count().PHP_EOL;
'