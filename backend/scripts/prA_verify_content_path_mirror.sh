#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ART_DIR="$ROOT_DIR/artifacts/prA_content_path_mirror"
mkdir -p "$ART_DIR"

cd "$ROOT_DIR"

php artisan migrate --force >/dev/null
php artisan fap:scales:seed-default >/dev/null

SUFFIX="script_$(date +%s)"
BACKEND_OLD="content_packs/TEST_MIRROR_SRC_${SUFFIX}"
BACKEND_NEW="content_packs/TEST_MIRROR_DST_${SUFFIX}"
CONTENT_OLD="default/CN_MAINLAND/zh-CN/TEST_MIRROR_SRC_${SUFFIX}"
CONTENT_NEW="default/CN_MAINLAND/zh-CN/TEST_MIRROR_DST_${SUFFIX}"

BACKEND_OLD_ABS="$ROOT_DIR/$BACKEND_OLD"
BACKEND_NEW_ABS="$ROOT_DIR/$BACKEND_NEW"
CONTENT_ROOT="$(php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); echo rtrim((string) config("content_packs.root", base_path("../content_packages")), "/\\");')"
CONTENT_OLD_ABS="$CONTENT_ROOT/$CONTENT_OLD"
CONTENT_NEW_ABS="$CONTENT_ROOT/$CONTENT_NEW"

cleanup() {
  rm -rf "$BACKEND_OLD_ABS" "$BACKEND_NEW_ABS" "$CONTENT_OLD_ABS" "$CONTENT_NEW_ABS" || true
  php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
\Illuminate\Support\Facades\DB::table("content_path_aliases")
    ->whereIn("old_path", [$argv[1], $argv[2]])
    ->delete();
' "$BACKEND_OLD" "$CONTENT_OLD" >/dev/null 2>&1 || true
}
trap cleanup EXIT

mkdir -p "$BACKEND_OLD_ABS/v1/compiled" "$CONTENT_OLD_ABS/compiled"
cat > "$BACKEND_OLD_ABS/v1/manifest.json" <<'JSON'
{"pack":"backend","kind":"manifest"}
JSON
cat > "$BACKEND_OLD_ABS/v1/questions.json" <<'JSON'
[{"id":"B1"}]
JSON
cat > "$BACKEND_OLD_ABS/v1/compiled/manifest.json" <<'JSON'
{"compiled":true}
JSON
cat > "$BACKEND_OLD_ABS/v1/compiled/questions.compiled.json" <<'JSON'
{"question_index":[{"id":"B1"}]}
JSON
cat > "$CONTENT_OLD_ABS/manifest.json" <<'JSON'
{"pack":"content","kind":"manifest"}
JSON
cat > "$CONTENT_OLD_ABS/questions.json" <<'JSON'
[{"id":"C1"}]
JSON
cat > "$CONTENT_OLD_ABS/compiled/manifest.json" <<'JSON'
{"compiled":true}
JSON
cat > "$CONTENT_OLD_ABS/compiled/questions.compiled.json" <<'JSON'
{"question_index":[{"id":"C1"}]}
JSON

php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$now = now();
\Illuminate\Support\Facades\DB::table("content_path_aliases")->updateOrInsert(
    ["scope" => "backend_content_packs", "old_path" => $argv[1]],
    ["new_path" => $argv[2], "scale_uid" => null, "is_active" => true, "updated_at" => $now, "created_at" => $now]
);
\Illuminate\Support\Facades\DB::table("content_path_aliases")->updateOrInsert(
    ["scope" => "content_packages", "old_path" => $argv[3]],
    ["new_path" => $argv[4], "scale_uid" => null, "is_active" => true, "updated_at" => $now, "created_at" => $now]
);
' "$BACKEND_OLD" "$BACKEND_NEW" "$CONTENT_OLD" "$CONTENT_NEW"

run1="$(php artisan ops:content-path-mirror \
  --sync \
  --verify-hash \
  --old-path="$BACKEND_OLD" \
  --old-path="$CONTENT_OLD")"
printf '%s\n' "$run1" > "$ART_DIR/run1.txt"

if ! grep -Eq 'verify_mismatch_files=0' <<<"$run1"; then
  echo "[FAIL] run1 verify mismatch not zero" >&2
  exit 1
fi

run2="$(php artisan ops:content-path-mirror \
  --sync \
  --verify-hash \
  --old-path="$BACKEND_OLD" \
  --old-path="$CONTENT_OLD")"
printf '%s\n' "$run2" > "$ART_DIR/run2.txt"

if ! grep -Eq 'sync_copied_files=0' <<<"$run2"; then
  echo "[FAIL] run2 copied files should be zero" >&2
  exit 1
fi
if ! grep -Eq 'sync_updated_files=0' <<<"$run2"; then
  echo "[FAIL] run2 updated files should be zero" >&2
  exit 1
fi
if ! grep -Eq 'verify_mismatch_files=0' <<<"$run2"; then
  echo "[FAIL] run2 verify mismatch not zero" >&2
  exit 1
fi

cat > "$ART_DIR/summary.txt" <<TXT
PR-A content path mirror verification summary

Checks:
- migrate --force: OK
- fap:scales:seed-default: OK
- ops:content-path-mirror run1 sync+verify: verify_mismatch_files=0
- ops:content-path-mirror run2 idempotence: sync_copied_files=0, sync_updated_files=0, verify_mismatch_files=0

Artifacts:
- backend/artifacts/prA_content_path_mirror/run1.txt
- backend/artifacts/prA_content_path_mirror/run2.txt
- backend/artifacts/prA_content_path_mirror/summary.txt
TXT

echo "[OK] content path mirror verification complete."

