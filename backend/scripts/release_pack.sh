#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT="$(cd "${BACKEND_DIR}/.." && pwd)"

STAGING_DIR="${ROOT}/.release_staging"
DIST_DIR="${ROOT}/dist"
OUT_ZIP="${DIST_DIR}/fap-api-release.zip"

fail() {
  echo "[FAIL] $*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"
}

need_cmd git
need_cmd zip
need_cmd unzip
need_cmd tar
need_cmd find

cd "${ROOT}"

WHITELIST_PATHS=(
  backend
  README_DEPLOY.md
  scripts
  docs
)

REQUIRED_HEAD_FILES=(
  backend
  backend/artisan
  backend/bootstrap/app.php
  backend/bootstrap/providers.php
  backend/routes/api.php
  backend/composer.json
  backend/composer.lock
  README_DEPLOY.md
)

for required in "${REQUIRED_HEAD_FILES[@]}"; do
  git -C "${ROOT}" cat-file -e "HEAD:${required}" 2>/dev/null || fail "required source missing in HEAD: ${required}"
done

rm -rf "${STAGING_DIR}"
mkdir -p "${STAGING_DIR}" "${DIST_DIR}"

# Export only tracked files from whitelist paths.
git -C "${ROOT}" archive --format=tar HEAD "${WHITELIST_PATHS[@]}" | tar -xf - -C "${STAGING_DIR}"

# Blacklist cleanup.
while IFS= read -r -d '' f; do
  base="$(basename "$f")"
  [[ "$base" == ".env.example" ]] && continue
  rm -f "$f"
done < <(find "${STAGING_DIR}" -type f \( -name '.env' -o -name '.env.*' \) -print0)

rm -rf "${STAGING_DIR}/.git" \
       "${STAGING_DIR}/vendor" \
       "${STAGING_DIR}/node_modules" \
       "${STAGING_DIR}/backend/vendor" \
       "${STAGING_DIR}/backend/node_modules" \
       "${STAGING_DIR}/backend/storage/logs" \
       "${STAGING_DIR}/backend/artifacts" \
       "${STAGING_DIR}/backend/storage/app/private/reports"

find "${STAGING_DIR}" -type f -name '*.sqlite*' -delete

# Blacklist hard gate.
HITS_FILE="$(mktemp)"
trap 'rm -f "$HITS_FILE"' EXIT

find "${STAGING_DIR}" -type f \( -name '.env' -o -name '.env.*' \) ! -name '.env.example' -print >> "$HITS_FILE"
find "${STAGING_DIR}" -type d -name '.git' -print >> "$HITS_FILE"
find "${STAGING_DIR}" -type d -name 'vendor' -print >> "$HITS_FILE"
find "${STAGING_DIR}" -type d -name 'node_modules' -print >> "$HITS_FILE"
find "${STAGING_DIR}" -type d -path '*/storage/logs' -print >> "$HITS_FILE"
find "${STAGING_DIR}" -type d -name 'artifacts' -print >> "$HITS_FILE"
find "${STAGING_DIR}" -type f -name '*.sqlite*' -print >> "$HITS_FILE"
find "${STAGING_DIR}" -type d -path '*/storage/app/private/reports' -print >> "$HITS_FILE"

if [[ -s "$HITS_FILE" ]]; then
  echo "[FAIL] blacklist violations found:" >&2
  sort -u "$HITS_FILE" >&2
  exit 1
fi

PACKAGE_REQUIRED_FILES=(
  backend/artisan
  backend/bootstrap/app.php
  backend/bootstrap/providers.php
  backend/routes/api.php
)

for required in "${PACKAGE_REQUIRED_FILES[@]}"; do
  [[ -f "${STAGING_DIR}/${required}" ]] || fail "required file missing in package staging: ${required}"
done

rm -f "${OUT_ZIP}"
(
  cd "${STAGING_DIR}"
  zip -qr "${OUT_ZIP}" .
)

rm -rf "${STAGING_DIR}"

echo "[OK] release package generated: ${OUT_ZIP}"
