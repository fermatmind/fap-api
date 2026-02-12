#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
DIST_DIR="${REPO_DIR}/dist"
OUT_DIR="${DIST_DIR}/fap-api"
OUT_ZIP="${DIST_DIR}/fap-api.zip"

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[SEC-001][FAIL] missing command: $1" >&2
    exit 2
  }
}

need_cmd rsync
need_cmd zip
need_cmd bash

cd "$REPO_DIR"

# Pre-check workspace cleanliness
bash "${REPO_DIR}/scripts/security/assert_artifact_clean.sh" --mode repo --target "$REPO_DIR"

rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR"

rsync -a --delete \
  --exclude='.git/' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='**/.env' \
  --exclude='**/.env.*' \
  --exclude='node_modules/' \
  --exclude='backend/node_modules/' \
  --exclude='vendor/' \
  --exclude='backend/vendor/' \
  --exclude='backend/storage/logs/' \
  --exclude='backend/storage/framework/' \
  --exclude='backend/storage/app/private/reports/' \
  --exclude='dist/' \
  "${REPO_DIR}/" "${OUT_DIR}/"

mkdir -p \
  "${OUT_DIR}/backend/storage/app/private/reports" \
  "${OUT_DIR}/backend/storage/logs" \
  "${OUT_DIR}/backend/storage/framework"

: > "${OUT_DIR}/backend/storage/app/private/.gitkeep"
: > "${OUT_DIR}/backend/storage/app/private/reports/.gitkeep"
: > "${OUT_DIR}/backend/storage/logs/.gitkeep"
: > "${OUT_DIR}/backend/storage/framework/.gitkeep"

# Post-check exported tree
bash "${REPO_DIR}/scripts/security/assert_artifact_clean.sh" --mode artifact --target "$OUT_DIR"

rm -f "$OUT_ZIP"
(
  cd "$DIST_DIR"
  zip -qr "$(basename "$OUT_ZIP")" "fap-api"
)
rm -rf "$OUT_DIR"

echo "[SEC-001] clean source exported: $OUT_ZIP"
