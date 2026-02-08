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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr64}"

mkdir -p "${ART_DIR}"

fail() {
  echo "[PR64][VERIFY][FAIL] $*" >&2
  exit 1
}

cd "${BACKEND_DIR}"

php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfgPack = trim((string) config("content_packs.default_pack_id", ""));
$cfgDir = trim((string) config("content_packs.default_dir_version", ""));
$cfgScalePackRaw = trim((string) config("scales_registry.default_pack_id", ""));
$cfgScaleDirRaw = trim((string) config("scales_registry.default_dir_version", ""));

$cfgScalePack = $cfgScalePackRaw !== "" ? $cfgScalePackRaw : $cfgPack;
$cfgScaleDir = $cfgScaleDirRaw !== "" ? $cfgScaleDirRaw : $cfgDir;

if ($cfgPack === "" || $cfgDir === "") {
    fwrite(STDERR, "content_packs_defaults_missing\n");
    exit(1);
}
if ($cfgScalePack === "" || $cfgScaleDir === "") {
    fwrite(STDERR, "scales_registry_defaults_missing\n");
    exit(1);
}
if ($cfgPack !== $cfgScalePack || $cfgDir !== $cfgScaleDir) {
    fwrite(STDERR, "config_pack_mismatch\n");
    exit(1);
}

$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($cfgPack, $cfgDir);
if (!($found["ok"] ?? false)) {
    fwrite(STDERR, "pack_not_found\n");
    exit(1);
}
$item = $found["item"] ?? [];
$manifestPath = (string) ($item["manifest_path"] ?? "");
$questionsPath = (string) ($item["questions_path"] ?? "");
$packDir = $manifestPath !== "" ? dirname($manifestPath) : "";
$versionPath = $packDir !== "" ? $packDir . DIRECTORY_SEPARATOR . "version.json" : "";

foreach ([$manifestPath, $questionsPath, $versionPath] as $path) {
    if ($path === "" || !is_file($path)) {
        fwrite(STDERR, "pack_file_missing:" . $path . "\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "pack_json_invalid:" . $path . "\n");
        exit(1);
    }
}

if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) {
    fwrite(STDERR, "missing_scales_registry_table\n");
    exit(1);
}
$row = Illuminate\Support\Facades\DB::table("scales_registry")
    ->where("org_id", 0)
    ->where("code", "MBTI")
    ->first();
if (!$row) {
    fwrite(STDERR, "missing_scales_registry_mbti\n");
    exit(1);
}
$rowPack = trim((string) ($row->default_pack_id ?? ""));
$rowDir = trim((string) ($row->default_dir_version ?? ""));
if ($rowPack !== $cfgPack || $rowDir !== $cfgDir) {
    fwrite(STDERR, "seed_pack_config_mismatch\n");
    exit(1);
}

echo "config_content_packs_default_pack_id=" . $cfgPack . PHP_EOL;
echo "config_content_packs_default_dir_version=" . $cfgDir . PHP_EOL;
echo "config_scales_registry_default_pack_id_raw=" . $cfgScalePackRaw . PHP_EOL;
echo "config_scales_registry_default_dir_version_raw=" . $cfgScaleDirRaw . PHP_EOL;
echo "config_scales_registry_default_pack_id_effective=" . $cfgScalePack . PHP_EOL;
echo "config_scales_registry_default_dir_version_effective=" . $cfgScaleDir . PHP_EOL;
echo "seed_row_default_pack_id=" . $rowPack . PHP_EOL;
echo "seed_row_default_dir_version=" . $rowDir . PHP_EOL;
echo "manifest_path=" . $manifestPath . PHP_EOL;
echo "questions_path=" . $questionsPath . PHP_EOL;
echo "version_path=" . $versionPath . PHP_EOL;
' > "${ART_DIR}/pack_seed_config.log" 2>&1 || {
  cat "${ART_DIR}/pack_seed_config.log" >&2 || true
  fail "pack/seed/config consistency check failed"
}

php artisan test --filter AttemptOwnershipAnd404Test 2>&1 | tee "${ART_DIR}/phpunit.log"

if ! grep -E "OK \\(|PASS" "${ART_DIR}/phpunit.log" >/dev/null; then
  fail "phpunit log missing success marker"
fi

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
