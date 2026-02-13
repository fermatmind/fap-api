#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

REPO_NAME="$(basename "${REPO_DIR}")"

# Pack path can be overridden via PACK_REL; default is mainline pack.
PACK_REL="${PACK_REL:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST}"
PACK_DIR="${REPO_DIR}/content_packages/${PACK_REL}"

echo "[GUARD] repo=${REPO_NAME}"
echo "[GUARD] backend=backend"
echo "[GUARD] pack_rel=${PACK_REL}"

# -------------------------------
# 1) API controllers must exist and be non-empty
# -------------------------------
test -d "${BACKEND_DIR}/app/Http/Controllers/API"
CTRL_COUNT="$(find "${BACKEND_DIR}/app/Http/Controllers/API" -type f -name '*.php' | wc -l | tr -d ' ')"
echo "[GUARD] controllers php count=${CTRL_COUNT}"
test "${CTRL_COUNT}" -gt 0

# -------------------------------
# 2) Content pack must exist and key JSON must be valid
# -------------------------------
for f in manifest.json questions.json scoring_spec.json version.json; do
  test -s "${PACK_DIR}/${f}"
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "${PACK_DIR}/${f}"
done
echo "[GUARD] content pack json OK"

# -------------------------------
# 3) route:list must run (controllers resolvable by router)
# -------------------------------
cd "${BACKEND_DIR}"
php artisan route:clear >/dev/null 2>&1 || true
php artisan route:list >/dev/null
echo "[GUARD] artisan route:list OK"

# -------------------------------
# 3.5) critical v0.3 class/trait must be autoloadable
# -------------------------------
php -r '
require "vendor/autoload.php";
$classes = [
  "App\\Http\\Controllers\\API\\V0_3\\Webhooks\\PaymentWebhookController",
];
$traits = [
  "App\\Http\\Controllers\\API\\V0_3\\Concerns\\ResolvesAttemptOwnership",
];
foreach ($classes as $c) {
  if (!class_exists($c)) {
    fwrite(STDERR, "[GUARD][FAIL] missing loadable class: {$c}\n");
    exit(1);
  }
}
foreach ($traits as $t) {
  if (!trait_exists($t)) {
    fwrite(STDERR, "[GUARD][FAIL] missing loadable trait: {$t}\n");
    exit(1);
  }
}
'
echo "[GUARD] v0.3 critical class/trait load OK"

# -------------------------------
# 4) git archive must include critical files (when git available)
# -------------------------------
cd "${REPO_DIR}"
if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "[GUARD] archive check begin"

  ARCHIVE_LIST="$(git archive HEAD | tar -tf -)"
  for required in \
    "backend/routes/api.php" \
    "backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php" \
    "backend/app/Http/Controllers/API/V0_3/Concerns/ResolvesAttemptOwnership.php" \
    "content_packages/${PACK_REL}/manifest.json"
  do
    echo "$ARCHIVE_LIST" | grep -Fx "$required" >/dev/null || {
      echo "[GUARD][FAIL] archive missing required file: $required" >&2
      exit 1
    }
  done

  echo "[GUARD] archive required-files check OK"
fi

echo "[GUARD] ALL OK"
