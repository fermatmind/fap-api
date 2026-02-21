#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export PAGER=cat
export GIT_PAGER=cat
export TERM=dumb
export XDEBUG_MODE=off
export LANG=en_US.UTF-8

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr61"
SERVE_PORT="${SERVE_PORT:-1861}"
DB_PATH="/tmp/pr61.sqlite"

mkdir -p "${ART_DIR}"

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  local pid_list
  pid_list="$(lsof -ti tcp:"${port}" || true)"
  if [[ -n "${pid_list}" ]]; then
    echo "${pid_list}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

cleanup() {
  cleanup_port "${SERVE_PORT}"
  cleanup_port 18000
  rm -f "${DB_PATH}"
}
trap cleanup EXIT

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

export APP_ENV=testing
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"
export FAP_PACKS_DRIVER=local
export FAP_PACKS_ROOT="${REPO_DIR}/content_packages"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.3}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.3}"

rm -f "${DB_PATH}"
touch "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
if ($packId === "" || $dirVersion === "") { fwrite(STDERR, "missing_content_pack_defaults\n"); exit(1); }
if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) { fwrite(STDERR, "missing_scales_registry_table\n"); exit(1); }
$row = Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id", 0)->where("code", "MBTI")->first();
if (!$row) { fwrite(STDERR, "missing_scales_registry_mbti\n"); exit(1); }
if ((string) ($row->default_pack_id ?? "") !== $packId) { fwrite(STDERR, "default_pack_id_mismatch\n"); exit(1); }
$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($packId, $dirVersion);
if (!($found["ok"] ?? false)) { fwrite(STDERR, "pack_not_found\n"); exit(1); }
$item = $found["item"] ?? [];
$manifestPath = (string) ($item["manifest_path"] ?? "");
$questionsPath = (string) ($item["questions_path"] ?? "");
if ($manifestPath === "" || !is_file($manifestPath)) { fwrite(STDERR, "manifest_missing\n"); exit(1); }
if ($questionsPath === "" || !is_file($questionsPath)) { fwrite(STDERR, "questions_missing\n"); exit(1); }
echo "config_default_pack_id=".$packId.PHP_EOL;
echo "config_default_dir_version=".$dirVersion.PHP_EOL;
echo "manifest=".$manifestPath.PHP_EOL;
echo "questions=".$questionsPath.PHP_EOL;
' > "${ART_DIR}/pack_seed_config.txt"

cd "${REPO_DIR}"
ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr61_verify.sh"

{
  echo "PR61 Acceptance Summary"
  echo "- pass_items:"
  echo "  - migrate_fresh_sqlite: PASS"
  echo "  - scales_seed_and_slug_sync: PASS"
  echo "  - pack_seed_config_consistency: PASS"
  echo "  - unit_testsuite: PASS"
  echo "- key_outputs:"
  echo "  - serve_port: ${SERVE_PORT}"
  echo "  - php_version: $(tr -d '\n' < "${ART_DIR}/php_version.txt")"
  echo "  - artifacts_dir: ${ART_DIR}"
  echo "- changed_files:"
  git --no-pager diff --name-only | sed "s/^/  - /"
  git ls-files --others --exclude-standard | sed "s/^/  - /"
} > "${ART_DIR}/summary.txt"

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 61

bash -n "${BACKEND_DIR}/scripts/pr61_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr61_verify.sh"

echo "[PR61][ACCEPT] pass"
