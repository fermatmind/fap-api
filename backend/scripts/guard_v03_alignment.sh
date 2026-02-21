#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${REPO_DIR}"

MODE="changed-only"
BASE_REF="origin/main"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --changed-only)
      MODE="changed-only"
      shift
      ;;
    --full-scan)
      MODE="full-scan"
      shift
      ;;
    --base)
      BASE_REF="${2:-origin/main}"
      shift 2
      ;;
    *)
      echo "[GUARD_V03_ALIGNMENT][FAIL] unknown argument: $1" >&2
      exit 2
      ;;
  esac
done

PATTERN='MBTI\.cn-mainland\.zh-CN\.v0\.2|MBTI-CN-v0\.2|/api/v0\.2|\bV0_2\b|\bv0_2\b|\bv0\.2(\.[0-9A-Za-z-]+)?\b'

ALLOW_EXACT=(
  "backend/routes/api.php"
  "backend/tests/Feature/DeprecatedApiVersionContractTest.php"
  "backend/tests/Unit/Security/SecurityGuardrailsTest.php"
  "docs/PROJECT_OVERVIEW.md"
  "backend/scripts/guard_v03_alignment.sh"
)

ALLOW_PREFIX=(
  "docs/verify/"
  "docs/content/evidence/"
  "content_packages/_deprecated/"
)

is_allowed_path() {
  local path="$1"

  for allow in "${ALLOW_EXACT[@]}"; do
    if [[ "$path" == "$allow" ]]; then
      return 0
    fi
  done

  for prefix in "${ALLOW_PREFIX[@]}"; do
    if [[ "$path" == "$prefix"* ]]; then
      return 0
    fi
  done

  return 1
}

collect_targets() {
  if [[ "$MODE" == "full-scan" ]]; then
    rg --files
    return 0
  fi

  if ! git rev-parse --verify --quiet "${BASE_REF}" >/dev/null; then
    git fetch --no-tags --prune --depth=200 origin main >/dev/null 2>&1 || true
  fi

  if ! git rev-parse --verify --quiet "${BASE_REF}" >/dev/null; then
    echo "[GUARD_V03_ALIGNMENT] base ref ${BASE_REF} not found; fallback to HEAD~1" >&2
    git diff --name-only --diff-filter=ACMR HEAD~1..HEAD
    return 0
  fi

  git diff --name-only --diff-filter=ACMR "${BASE_REF}...HEAD"
}

TARGETS=()
while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  TARGETS+=("$path")
done < <(collect_targets | sed '/^$/d' | sort -u)

if [[ "${#TARGETS[@]}" -eq 0 ]]; then
  echo "[GUARD_V03_ALIGNMENT] no target files to scan (${MODE})"
  exit 0
fi

violations=()
for path in "${TARGETS[@]}"; do
  [[ -f "$path" ]] || continue
  is_allowed_path "$path" && continue

  if ! grep -Iq . "$path"; then
    continue
  fi

  if hit="$(rg -n -S "${PATTERN}" "$path" 2>/dev/null)"; then
    violations+=("${hit}")
  fi
done

if [[ "${#violations[@]}" -gt 0 ]]; then
  echo "[GUARD_V03_ALIGNMENT][FAIL] v0.2 residue found outside allowlist:"
  printf '%s\n' "${violations[@]}"
  exit 1
fi

echo "[GUARD_V03_ALIGNMENT] PASS (${MODE})"
