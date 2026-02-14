#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
AUDIT_ROOT_REL="./_audit/fap-api-0212-5"
AUDIT_ROOT_ABS="${ROOT}/_audit/fap-api-0212-5"

usage() {
  cat <<'USAGE'
Usage:
  bash scripts/audit_smoke.sh dist/fap-api-release.zip
  bash scripts/audit_smoke.sh ./_audit/fap-api-0212-5/
USAGE
}

to_audit_rel() {
  local abs="$1"
  local rel="${abs#${AUDIT_ROOT_ABS}/}"
  if [[ "$abs" == "$AUDIT_ROOT_ABS" ]]; then
    echo "${AUDIT_ROOT_REL}/"
  else
    echo "${AUDIT_ROOT_REL}/${rel}"
  fi
}

prepare_from_zip() {
  local zip_path="$1"
  [[ -f "$zip_path" ]] || { echo "[FAIL] zip not found: $zip_path" >&2; exit 1; }

  rm -rf "$AUDIT_ROOT_ABS"
  mkdir -p "$AUDIT_ROOT_ABS"
  unzip -q "$zip_path" -d "$AUDIT_ROOT_ABS"
}

prepare_from_dir() {
  local src_dir="$1"
  [[ -d "$src_dir" ]] || { echo "[FAIL] dir not found: $src_dir" >&2; exit 1; }

  local src_abs
  src_abs="$(cd "$src_dir" && pwd)"

  if [[ "$src_abs" == "$AUDIT_ROOT_ABS" ]]; then
    return
  fi

  rm -rf "$AUDIT_ROOT_ABS"
  mkdir -p "$AUDIT_ROOT_ABS"
  cp -a "${src_abs}/." "$AUDIT_ROOT_ABS/"
}

INPUT="${1:-}"
[[ -n "$INPUT" ]] || { usage; exit 2; }

if [[ -f "$INPUT" ]]; then
  prepare_from_zip "$INPUT"
elif [[ -d "$INPUT" ]]; then
  prepare_from_dir "$INPUT"
else
  echo "[FAIL] input not found: $INPUT" >&2
  usage
  exit 2
fi

echo "========== AUDIT SMOKE REPORT =========="
echo "[AUDIT] root=${AUDIT_ROOT_REL}/"

echo "========== EVIDENCE-1: REQUIRED ENTRY =========="
if [[ -f "${AUDIT_ROOT_ABS}/backend/routes/api.php" ]]; then
  echo "[EVIDENCE-1] EXISTS path=${AUDIT_ROOT_REL}/backend/routes/api.php"
else
  echo "[EVIDENCE-1] MISSING path=${AUDIT_ROOT_REL}/backend/routes/api.php"
fi

echo "========== EVIDENCE-2: REQUIRED STRUCTURE =========="
for p in backend/app backend/routes backend/config backend/database/migrations; do
  if [[ -e "${AUDIT_ROOT_ABS}/${p}" ]]; then
    echo "[EVIDENCE-2] EXISTS path=${AUDIT_ROOT_REL}/${p}"
  else
    echo "[EVIDENCE-2] MISSING path=${AUDIT_ROOT_REL}/${p}"
  fi
done

echo "========== EVIDENCE-3: CONTAMINATION HITS =========="
HITS_FILE="$(mktemp)"
trap 'rm -f "$HITS_FILE"' EXIT

while IFS= read -r hit; do
  [[ -n "$hit" ]] && echo "$hit" >> "$HITS_FILE"
done < <(find "$AUDIT_ROOT_ABS" -type f \( -name '.env' -o -name '.env.*' \) ! -name '.env.example' -print)

while IFS= read -r hit; do
  [[ -n "$hit" ]] && echo "$hit" >> "$HITS_FILE"
done < <(find "$AUDIT_ROOT_ABS" -type d -name '.git' -print)

for p in \
  "${AUDIT_ROOT_ABS}/vendor" \
  "${AUDIT_ROOT_ABS}/backend/vendor" \
  "${AUDIT_ROOT_ABS}/node_modules" \
  "${AUDIT_ROOT_ABS}/backend/node_modules" \
  "${AUDIT_ROOT_ABS}/backend/storage/logs" \
  "${AUDIT_ROOT_ABS}/storage/logs" \
  "${AUDIT_ROOT_ABS}/backend/artifacts" \
  "${AUDIT_ROOT_ABS}/artifacts"
do
  [[ -e "$p" ]] && echo "$p" >> "$HITS_FILE"
done

while IFS= read -r hit; do
  [[ -n "$hit" ]] && echo "$hit" >> "$HITS_FILE"
done < <(find "$AUDIT_ROOT_ABS" -type f -name '*.sqlite*' -print)

while IFS= read -r hit; do
  [[ -n "$hit" ]] && echo "$hit" >> "$HITS_FILE"
done < <(find "$AUDIT_ROOT_ABS" -type d -name '__MACOSX' -print)

while IFS= read -r hit; do
  [[ -n "$hit" ]] && echo "$hit" >> "$HITS_FILE"
done < <(find "$AUDIT_ROOT_ABS" -type f -name '.DS_Store' -print)

if [[ -s "$HITS_FILE" ]]; then
  sort -u "$HITS_FILE" -o "$HITS_FILE"
  COUNT="$(wc -l < "$HITS_FILE" | tr -d ' ')"
  echo "[EVIDENCE-3] HIT_COUNT=${COUNT}"
  while IFS= read -r hit; do
    echo "[EVIDENCE-3] HIT path=$(to_audit_rel "$hit")"
  done < "$HITS_FILE"
else
  echo "[EVIDENCE-3] HIT_COUNT=0"
fi
