#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"

export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-/tmp/fap-ci.sqlite}"

rm -f "$DB_DATABASE" || true
touch "$DB_DATABASE"
chmod 666 "$DB_DATABASE" || true

echo "[prepare_sqlite] DB_CONNECTION=$DB_CONNECTION"
echo "[prepare_sqlite] DB_DATABASE=$DB_DATABASE"

cd "$BACKEND_DIR"

php artisan config:clear || true
php artisan migrate --force --no-interaction
php artisan db:seed --class="Database\\Seeders\\CiScalesRegistrySeeder" --force --no-interaction || true

# sync slugs for lookup tests
php artisan fap:scales:sync-slugs || true

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$codes = ["MBTI","DEMO_ANSWERS","SIMPLE_SCORE_DEMO","IQ_RAVEN"];
foreach ($codes as $c) {
  $n = DB::table("scales_registry")->where("org_id",0)->where("code",$c)->count();
  echo "scales_registry {$c} rows: {$n}".PHP_EOL;
}
' || true
