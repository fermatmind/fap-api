#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TARGET_RAW="${1:-${REPO_ROOT}}"

if [[ ! -d "$TARGET_RAW" ]]; then
  echo "[FAIL] target_not_found path=${TARGET_RAW}" >&2
  exit 1
fi

TARGET="$(cd "$TARGET_RAW" && pwd)"
COMPOSER_JSON="${TARGET}/backend/composer.json"
COMPOSER_LOCK="${TARGET}/backend/composer.lock"

fail() {
  echo "[FAIL] $1" >&2
  exit 1
}

command -v jq >/dev/null 2>&1 || fail "missing command: jq"

[[ -f "$COMPOSER_JSON" ]] || fail "missing backend/composer.json"
[[ -f "$COMPOSER_LOCK" ]] || fail "missing backend/composer.lock"

PACKAGES_LEN="$(jq -r '(.packages // []) | length' "$COMPOSER_LOCK" 2>/dev/null)" || fail "invalid backend/composer.lock"

if [[ "$PACKAGES_LEN" == "0" ]]; then
  echo "[FAIL] composer.lock packages empty" >&2
  exit 1
fi

jq -e 'any((.packages // [])[].name; . == "laravel/framework")' "$COMPOSER_LOCK" >/dev/null 2>&1 || fail "laravel/framework missing in backend/composer.lock"

echo "[OK] supply chain gate passed path=${TARGET}/backend/composer.lock"
