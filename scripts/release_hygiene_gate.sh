#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_RAW="${1:-./_audit/fap-api-0212-5/}"
[[ -d "$TARGET_RAW" ]] || { echo "[FAIL] reason=target_not_found path=${TARGET_RAW}" >&2; exit 1; }

TARGET="$(cd "$TARGET_RAW" && pwd)"
FAIL_COUNT=0

fail() {
  local reason="$1"
  local path="$2"
  echo "[FAIL] reason=${reason} path=${path}"
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

to_rel() {
  local abs="$1"
  local rel="${abs#${TARGET}/}"
  if [[ "$abs" == "$TARGET" ]]; then
    echo "./"
  else
    echo "./${rel}"
  fi
}

echo "[PRECHECK] release audit prerequisites (non-blocking)"
for p in \
  backend/routes/api.php \
  backend/app \
  backend/config \
  backend/database/migrations \
  backend/composer.json \
  backend/composer.lock
do
  if [[ -e "${TARGET}/${p}" ]]; then
    echo "[PRECHECK] EXISTS path=./${p}"
  else
    echo "[PRECHECK] MISSING path=./${p}"
  fi
done

# 必须存在
[[ -f "${TARGET}/backend/routes/api.php" ]] || fail "missing_required" "./backend/routes/api.php"
[[ -d "${TARGET}/backend/app" ]] || fail "missing_required" "./backend/app"
[[ -d "${TARGET}/backend/config" ]] || fail "missing_required" "./backend/config"
[[ -d "${TARGET}/backend/database/migrations" ]] || fail "missing_required" "./backend/database/migrations"
[[ -f "${TARGET}/backend/composer.json" ]] || fail "missing_required" "./backend/composer.json"
[[ -f "${TARGET}/backend/composer.lock" ]] || fail "missing_required" "./backend/composer.lock"

# 根结构严格唯一：backend/ + scripts/ + docs/ + README_DEPLOY.md
for req in backend scripts docs; do
  [[ -d "${TARGET}/${req}" ]] || fail "missing_required_root_dir" "./${req}"
done
[[ -f "${TARGET}/README_DEPLOY.md" ]] || fail "missing_required_root_file" "./README_DEPLOY.md"

while IFS= read -r entry; do
  base="$(basename "$entry")"
  case "$base" in
    backend|scripts|docs|README_DEPLOY.md) ;;
    *) fail "unexpected_root_entry" "./${base}" ;;
  esac
done < <(find "$TARGET" -mindepth 1 -maxdepth 1 -print)

# 必须不存在：.env / .env.*（允许 .env.example）
while IFS= read -r -d '' hit; do
  fail "forbidden_env_file" "$(to_rel "$hit")"
done < <(find "$TARGET" -type f \( -name '.env' -o -name '.env.*' \) ! -name '.env.example' -print0)

# 必须不存在：.git
while IFS= read -r -d '' hit; do
  fail "forbidden_git_dir" "$(to_rel "$hit")"
done < <(find "$TARGET" -type d -name '.git' -print0)

# 必须不存在：vendor / node_modules
for p in \
  "${TARGET}/vendor" \
  "${TARGET}/backend/vendor" \
  "${TARGET}/node_modules" \
  "${TARGET}/backend/node_modules"
do
  [[ -e "$p" ]] && fail "forbidden_dependency_dir" "$(to_rel "$p")"
done

# 必须不存在：runtime/产物目录
for p in \
  "${TARGET}/backend/storage/logs" \
  "${TARGET}/backend/artifacts" \
  "${TARGET}/backend/storage/app/private/reports" \
  "${TARGET}/backend/storage/app/archives"
do
  [[ -e "$p" ]] && fail "forbidden_runtime_dir" "$(to_rel "$p")"
done

# 必须不存在：sqlite
while IFS= read -r -d '' hit; do
  fail "forbidden_sqlite_file" "$(to_rel "$hit")"
done < <(find "$TARGET" -type f -name '*.sqlite*' -print0)

# 必须不存在：macOS 污染
while IFS= read -r -d '' hit; do
  fail "forbidden_macosx_dir" "$(to_rel "$hit")"
done < <(find "$TARGET" -type d -name '__MACOSX' -print0)

while IFS= read -r -d '' hit; do
  fail "forbidden_ds_store" "$(to_rel "$hit")"
done < <(find "$TARGET" -type f -name '.DS_Store' -print0)

if ! bash "${SCRIPT_DIR}/supply_chain_gate.sh" "${TARGET}"; then
  fail "supply_chain_gate_failed" "./backend/composer.lock"
fi

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  exit 1
fi

echo "[OK] release hygiene gate passed"
