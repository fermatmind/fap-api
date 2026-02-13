#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

MODE="repo"
TARGET="$REPO_DIR"

usage() {
  cat <<'USAGE'
Usage:
  assert_artifact_clean.sh [repo|artifact]
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

contains_path() {
  local pattern="$1"
  local input="$2"
  printf '%s\n' "$input" | grep -E "$pattern" || true
}

check_repo_mode() {
  if ! git -C "$TARGET" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    fail "target is not a git repository: $TARGET"
    return
  fi

  local tracked
  tracked="$(git -C "$TARGET" ls-files)"

  local env_hits
  env_hits="$(contains_path '(^\.env$|^\.env\..+|^backend/\.env$|^backend/\.env\..+)' "$tracked" \
    | grep -Ev '(^\.env\.example$|^backend/\.env\.example$)' || true)"
  if [[ -n "$env_hits" ]]; then
    while IFS= read -r f; do
      [[ -n "$f" ]] || continue
      fail "forbidden tracked env file: $f ; fix: remove real env files from repository."
    done <<< "$env_hits"
  fi

  local p hits
  for p in node_modules backend/node_modules vendor backend/vendor; do
    hits="$(contains_path "^${p}(/|$)" "$tracked")"
    if [[ -n "$hits" ]]; then
      fail "forbidden tracked path exists: $p ; fix: remove dependencies output from repository."
    fi
  done

  hits="$(contains_path '^backend/storage/logs/' "$tracked" | grep -Ev '/\.gitkeep$' || true)"
  if [[ -n "$hits" ]]; then
    while IFS= read -r f; do
      [[ -n "$f" ]] || continue
      fail "forbidden tracked runtime artifact: $f ; fix: keep only .gitkeep in backend/storage/logs."
    done <<< "$hits"
  fi

  hits="$(contains_path '^backend/storage/framework/' "$tracked" | grep -Ev '/\.gitkeep$' || true)"
  if [[ -n "$hits" ]]; then
    while IFS= read -r f; do
      [[ -n "$f" ]] || continue
      fail "forbidden tracked runtime artifact: $f ; fix: keep only .gitkeep in backend/storage/framework."
    done <<< "$hits"
  fi

  hits="$(contains_path '^backend/storage/app/private/reports/' "$tracked" | grep -Ev '/\.gitkeep$' || true)"
  if [[ -n "$hits" ]]; then
    while IFS= read -r f; do
      [[ -n "$f" ]] || continue
      fail "forbidden tracked runtime artifact: $f ; fix: keep only .gitkeep in backend/storage/app/private/reports."
    done <<< "$hits"
  fi

  for p in \
    backend/storage/app/private/.gitkeep \
    backend/storage/app/private/reports/.gitkeep \
    backend/storage/logs/.gitkeep \
    backend/storage/framework/.gitkeep
  do
    if ! git -C "$TARGET" ls-files --error-unmatch "$p" >/dev/null 2>&1; then
      fail "missing tracked placeholder: $p ; fix: create and track .gitkeep."
    fi
  done
}

check_artifact_mode() {
  # 1) .git only forbidden in artifact mode
  if [[ -e "$TARGET/.git" ]]; then
    fail "forbidden path exists: .git ; fix: remove git metadata from delivery package."
  fi

  # 2) env files
  while IFS= read -r -d '' f; do
    base="$(basename "$f")"
    if [[ "$base" == ".env.example" ]]; then
      continue
    fi
    fail "forbidden env file: $(rel "$f") ; fix: remove real env files from package/workspace."
  done < <(find "$TARGET" -type f \( -name '.env' -o -name '.env.*' \) -print0)

  # 3) explicit denylist dirs
  local p
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

  # 5) required placeholders (only when parent directory is included in artifact)
  for p in \
    backend/storage/app/private/.gitkeep \
    backend/storage/app/private/reports/.gitkeep \
    backend/storage/logs/.gitkeep \
    backend/storage/framework/.gitkeep
  do
    parent_dir="$(dirname "$TARGET/$p")"
    if [[ -d "$parent_dir" && ! -f "$TARGET/$p" ]]; then
      fail "missing placeholder: $p ; fix: create .gitkeep."
    fi
  done
}

if [[ $# -gt 0 ]]; then
  case "$1" in
    repo|artifact)
      MODE="$1"
      shift
      ;;
  esac
fi

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
if [[ "$MODE" == "repo" ]]; then
  check_repo_mode
else
  check_artifact_mode
fi

if [[ $fail_count -gt 0 ]]; then
  echo "[SEC-001] artifact hygiene check failed with ${fail_count} issue(s)." >&2
  exit 1
fi

echo "[SEC-001] artifact hygiene check PASS (mode=$MODE target=$TARGET)"
