#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

MODE="repo"
TARGET="$REPO_DIR"

usage() {
  cat <<'USAGE'
Usage:
  assert_artifact_clean.sh [--mode repo|artifact] [--target <path>]
USAGE
}

fail_count=0
fail() {
  echo "[SEC-001][FAIL] $*" >&2
  fail_count=$((fail_count + 1))
}

rel() {
  local p="$1"
  p="${p#${TARGET}/}"
  echo "$p"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --mode)
      MODE="${2:-}"
      shift 2
      ;;
    --target)
      TARGET="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[SEC-001][FAIL] unknown arg: $1" >&2
      usage
      exit 2
      ;;
  esac
done

if [[ "$MODE" != "repo" && "$MODE" != "artifact" ]]; then
  echo "[SEC-001][FAIL] --mode must be repo|artifact" >&2
  exit 2
fi

TARGET="$(cd "$TARGET" && pwd)"

# 1) .git only forbidden in artifact mode
if [[ "$MODE" == "artifact" && -e "$TARGET/.git" ]]; then
  fail "forbidden path exists: .git ; fix: remove git metadata from delivery package."
fi

# 2) env files
while IFS= read -r -d '' f; do
  base="$(basename "$f")"
  if [[ "$MODE" == "repo" && "$base" == ".env.example" ]]; then
    continue
  fi
  fail "forbidden env file: $(rel "$f") ; fix: remove real env files from package/workspace."
done < <(find "$TARGET" -type f \( -name '.env' -o -name '.env.*' \) -print0)

# 3) explicit denylist dirs
for p in node_modules backend/node_modules vendor backend/vendor; do
  if [[ -e "$TARGET/$p" ]]; then
    fail "forbidden path exists: $p ; fix: delete dependencies output before delivery."
  fi
done

# 4) runtime dirs: only .gitkeep files allowed
check_only_gitkeep_files() {
  local dir="$1"
  local abs="$TARGET/$dir"
  if [[ ! -d "$abs" ]]; then
    return 0
  fi
  while IFS= read -r -d '' f; do
    fail "forbidden runtime artifact: $(rel "$f") ; fix: keep only .gitkeep in $dir."
  done < <(find "$abs" -type f ! -name '.gitkeep' -print0)
}

check_only_gitkeep_files "backend/storage/logs"
check_only_gitkeep_files "backend/storage/framework"
check_only_gitkeep_files "backend/storage/app/private/reports"

# 5) required placeholders
for p in \
  backend/storage/app/private/.gitkeep \
  backend/storage/app/private/reports/.gitkeep \
  backend/storage/logs/.gitkeep \
  backend/storage/framework/.gitkeep
do
  if [[ ! -f "$TARGET/$p" ]]; then
    fail "missing placeholder: $p ; fix: create .gitkeep."
  fi
done

if [[ $fail_count -gt 0 ]]; then
  echo "[SEC-001] artifact hygiene check failed with ${fail_count} issue(s)." >&2
  exit 1
fi

echo "[SEC-001] artifact hygiene check PASS (mode=$MODE target=$TARGET)"
