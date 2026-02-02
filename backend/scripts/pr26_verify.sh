#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REPO_NAME="$(basename "${REPO_DIR}")"
ART_DIR="${REPO_DIR}/backend/artifacts/pr26"
mkdir -p "${ART_DIR}"

{
  echo "[PR26_VERIFY] repo=${REPO_NAME}"
  echo "[PR26_VERIFY] start=$(date -u +%FT%TZ)"
  echo "[PR26_VERIFY] git_sha=$(git rev-parse --short HEAD 2>/dev/null || true)"
} > "${ART_DIR}/verify.log"

# 门禁脚本（同时做 archive 校验）
( cd "${REPO_DIR}" && bash backend/scripts/guard_release_integrity.sh ) \
  >> "${ART_DIR}/verify.log" 2>&1

# CI / deploy 文件存在性与关键内容检查
cd "${REPO_DIR}"
test -f deploy.php
grep -n "fap:seed_shared_content_packages" deploy.php >> "${ART_DIR}/verify.log"
grep -n "fap:preflight" deploy.php >> "${ART_DIR}/verify.log"

SELFCHK_REL="$(find . -maxdepth 4 -path '*/.github/workflows/*' -name 'selfcheck.yml' | head -n 1)"
test -n "${SELFCHK_REL}"
grep -n "Guard release integrity (controllers + content pack + archive)" "${SELFCHK_REL}" >> "${ART_DIR}/verify.log"

echo "[PR26_VERIFY] OK ✅" >> "${ART_DIR}/verify.log"
