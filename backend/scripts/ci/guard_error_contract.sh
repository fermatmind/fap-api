#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
cd "${REPO_DIR}"

echo "[GUARD] error contract (no hand-written controller error field)"

TARGET_FILES=(
  "backend/app/Http/Controllers/API/V0_3/CommerceController.php"
  "backend/app/Http/Controllers/API/V0_3/AttemptReadController.php"
  "backend/app/Http/Controllers/API/V0_3/AttemptWriteController.php"
  "backend/app/Http/Controllers/API/V0_3/ShareController.php"
  "backend/app/Http/Controllers/API/V0_3/ClaimController.php"
)

# Keep a narrow exception list for unavoidable legacy cases.
ALLOWLIST=(
)

is_allowlisted() {
  local candidate="$1"
  if [[ "${#ALLOWLIST[@]}" -eq 0 ]]; then
    return 1
  fi
  for allow in "${ALLOWLIST[@]}"; do
    if [[ "$candidate" == "$allow" ]]; then
      return 0
    fi
  done
  return 1
}

hits=()

if [[ "${#TARGET_FILES[@]}" -eq 0 ]]; then
  echo "[GUARD] PASS (no controller files found)"
  exit 0
fi

for file in "${TARGET_FILES[@]}"; do
  if [[ ! -f "${file}" ]]; then
    continue
  fi

  if is_allowlisted "${file}"; then
    continue
  fi

  if perl -0777 -ne '
      while (/response\(\)->json\(\s*\[(.*?)\]\s*(?:,\s*[0-9]+\s*)?\)/sg) {
          if ($1 =~ /["\047]error["\047]\s*=>/s) {
              exit 10;
          }
      }
      exit 0;
  ' "${file}"; then
    :
  else
    hits+=("${file}")
  fi
done

if [[ "${#hits[@]}" -gt 0 ]]; then
  echo "[GUARD][FAIL] found controller responses using legacy 'error' field:"
  for hit in "${hits[@]}"; do
    echo " - ${hit}"
    rg -n "response\\(\\)->json\\(\\[|['\\\"]error['\\\"]\\s*=>" "${hit}" -S || true
  done
  exit 1
fi

echo "[GUARD] PASS"
