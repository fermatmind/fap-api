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
# 3) route:list must run (controllers instantiable)
# -------------------------------
cd "${BACKEND_DIR}"
php artisan route:clear >/dev/null 2>&1 || true
php artisan route:list >/dev/null
echo "[GUARD] artisan route:list OK"

# -------------------------------
# 4) git archive must include key files (when git available)
# -------------------------------
cd "${REPO_DIR}"
if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "[GUARD] archive check begin"

  git archive HEAD backend/app/Http/Controllers/API \
    | tar -tf - \
    | grep -E '^backend/app/Http/Controllers/API/.*\.php$' >/dev/null

  git archive HEAD "content_packages/${PACK_REL}/manifest.json" \
    | tar -tf - \
    | grep -F "content_packages/${PACK_REL}/manifest.json" >/dev/null

  echo "[GUARD] archive check OK"
fi

echo "[GUARD] ALL OK"
