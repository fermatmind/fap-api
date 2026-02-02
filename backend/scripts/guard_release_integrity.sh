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

# 主内容包路径可由外部传入；未传入使用当前主链默认目录
PACK_REL="${PACK_REL:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST}"
PACK_DIR="${REPO_DIR}/content_packages/${PACK_REL}"

echo "[GUARD] repo=${REPO_NAME}"
echo "[GUARD] backend=backend"
echo "[GUARD] pack_rel=${PACK_REL}"

# -------------------------------
# 1) API Controllers 必须存在且非空
# -------------------------------
test -d "${BACKEND_DIR}/app/Http/Controllers/API"
CTRL_COUNT="$(find "${BACKEND_DIR}/app/Http/Controllers/API" -type f -name '*.php' | wc -l | tr -d ' ')"
echo "[GUARD] controllers php count=${CTRL_COUNT}"
test "${CTRL_COUNT}" -gt 0

# -------------------------------
# 2) 主内容包必须存在 + 关键文件必须为合法 JSON
# -------------------------------
for f in manifest.json questions.json scoring_spec.json version.json; do
  test -s "${PACK_DIR}/${f}"
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "${PACK_DIR}/${f}"
done
echo "[GUARD] content pack json OK"

# -------------------------------
# 3) route:list 必须可跑通（控制器可实例化）
# -------------------------------
cd "${BACKEND_DIR}"
php artisan route:clear >/dev/null 2>&1 || true
php artisan route:list >/dev/null
echo "[GUARD] artisan route:list OK"

# -------------------------------
# 4) git archive 产物必须包含关键文件（本地/CI 有 git 时执行）
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

echo "[GUARD] ALL OK ✅"
