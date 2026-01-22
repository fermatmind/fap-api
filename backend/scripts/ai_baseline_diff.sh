#!/usr/bin/env bash

set -u
set -o pipefail

AI_BASELINE_ENABLED="${AI_BASELINE_ENABLED:-0}"
if [ "$AI_BASELINE_ENABLED" != "1" ]; then
  echo "AI baseline diff disabled (AI_BASELINE_ENABLED=${AI_BASELINE_ENABLED}). skip."
  exit 0
fi

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
REPO_ROOT=$(cd "${SCRIPT_DIR}/../.." && pwd)
DEFAULT_OLD_DIR="${REPO_ROOT}/content_baselines_prev"
DEFAULT_NEW_DIR="${REPO_ROOT}/content_baselines"

OLD_DIR="${BASELINE_OLD_DIR:-$DEFAULT_OLD_DIR}"
NEW_DIR="${BASELINE_NEW_DIR:-$DEFAULT_NEW_DIR}"
if [ "$#" -ge 1 ]; then
  OLD_DIR="$1"
  if [ "$#" -ge 2 ]; then
    NEW_DIR="$2"
  else
    echo "FAIL: NEW_DIR missing."
    echo "Usage: $0 OLD_DIR NEW_DIR"
    exit 1
  fi
fi

if [ ! -d "$OLD_DIR" ]; then
  echo "FAIL: baseline OLD_DIR not found at ${OLD_DIR}."
  echo "Prepare it by copying the previous baseline, e.g.:"
  echo "  cp -R content_baselines content_baselines_prev"
  exit 1
fi
if [ ! -d "$NEW_DIR" ]; then
  echo "FAIL: baseline NEW_DIR not found at ${NEW_DIR}."
  echo "Prepare it by generating current baseline at content_baselines."
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "FAIL: jq not found."
  exit 1
fi

TMPDIR=$(mktemp -d 2>/dev/null || mktemp -d -t ai_baseline_diff)
cleanup() {
  rm -rf "$TMPDIR"
}
trap cleanup EXIT

OLD_INDEX="$TMPDIR/old.tsv"
NEW_INDEX="$TMPDIR/new.tsv"
OLD_INVALID="$TMPDIR/old_invalid.txt"
NEW_INVALID="$TMPDIR/new_invalid.txt"
OLD_SORTED="$TMPDIR/old.sorted.tsv"
NEW_SORTED="$TMPDIR/new.sorted.tsv"
MISSING_IDS="$TMPDIR/missing.tsv"
ADDED_IDS="$TMPDIR/added.tsv"
BOTH_IDS="$TMPDIR/both.tsv"
SCHEMA_MISMATCH="$TMPDIR/schema_mismatch.txt"
EXPLODED="$TMPDIR/exploded.txt"
INVALID_JSON="$TMPDIR/invalid_json.txt"
DUP_OLD="$TMPDIR/dup_old.txt"
DUP_NEW="$TMPDIR/dup_new.txt"
RATIO_LOG="$TMPDIR/ratio.txt"

build_index() {
  local dir="$1"
  local out="$2"
  local invalid="$3"

  : > "$out"
  : > "$invalid"

  while IFS= read -r file; do
    local id
    id=$(jq -r '.id // empty' "$file" 2>/dev/null || true)
    if [ -z "$id" ]; then
      echo "$file" >> "$invalid"
      continue
    fi
    printf "%s\t%s\n" "$id" "$file" >> "$out"
  done < <(find "$dir" -type f -name "*.json" | sort)
}

build_index "$OLD_DIR" "$OLD_INDEX" "$OLD_INVALID"
build_index "$NEW_DIR" "$NEW_INDEX" "$NEW_INVALID"

if [ ! -s "$OLD_INDEX" ]; then
  echo "FAIL: no json files found in ${OLD_DIR}."
  exit 1
fi
if [ ! -s "$NEW_INDEX" ]; then
  echo "FAIL: no json files found in ${NEW_DIR}."
  exit 1
fi

LC_ALL=C sort -t $'\t' -k1,1 "$OLD_INDEX" > "$OLD_SORTED"
LC_ALL=C sort -t $'\t' -k1,1 "$NEW_INDEX" > "$NEW_SORTED"

awk -F'\t' 'seen[$1]++==1 {print $1}' "$OLD_SORTED" > "$DUP_OLD"
awk -F'\t' 'seen[$1]++==1 {print $1}' "$NEW_SORTED" > "$DUP_NEW"

join -t $'\t' -v 1 "$OLD_SORTED" "$NEW_SORTED" > "$MISSING_IDS"
join -t $'\t' -v 2 "$OLD_SORTED" "$NEW_SORTED" > "$ADDED_IDS"
join -t $'\t' "$OLD_SORTED" "$NEW_SORTED" > "$BOTH_IDS"

: > "$SCHEMA_MISMATCH"
: > "$EXPLODED"
: > "$INVALID_JSON"
: > "$RATIO_LOG"

JQ_TEXT_LEN='def collect_strings: if type=="string" then . elif type=="array" then map(collect_strings) | join("") elif type=="object" then to_entries | map(.value | collect_strings) | join("") else "" end; ((.title|collect_strings) + (.body|collect_strings)) | length'

while IFS=$'\t' read -r id old_file new_file; do
  if ! jq -e . "$old_file" >/dev/null 2>&1; then
    echo "old\t${id}\t${old_file}" >> "$INVALID_JSON"
    continue
  fi
  if ! jq -e . "$new_file" >/dev/null 2>&1; then
    echo "new\t${id}\t${new_file}" >> "$INVALID_JSON"
    continue
  fi

  old_type=$(jq -r '.type // empty' "$old_file")
  old_slug=$(jq -r '.slug // empty' "$old_file")
  old_status=$(jq -r '.status // empty' "$old_file")
  new_type=$(jq -r '.type // empty' "$new_file")
  new_slug=$(jq -r '.slug // empty' "$new_file")
  new_status=$(jq -r '.status // empty' "$new_file")

  if [ "$old_type" != "$new_type" ] || [ "$old_slug" != "$new_slug" ] || [ "$old_status" != "$new_status" ]; then
    echo "${id}\told(type=${old_type},slug=${old_slug},status=${old_status})\tnew(type=${new_type},slug=${new_slug},status=${new_status})" >> "$SCHEMA_MISMATCH"
  fi

  old_len=$(jq -r "$JQ_TEXT_LEN" "$old_file" 2>/dev/null || echo "")
  new_len=$(jq -r "$JQ_TEXT_LEN" "$new_file" 2>/dev/null || echo "")
  if [ -z "$old_len" ] || [ -z "$new_len" ]; then
    echo "len\t${id}\t${old_file}\t${new_file}" >> "$INVALID_JSON"
    continue
  fi

  ratio=$(awk -v o="$old_len" -v n="$new_len" 'BEGIN{d=o-n; if(d<0)d=-d; m=(o>n?o:n); if(m==0){printf "0.0000";} else {printf "%.4f", d/m;}}')
  echo "${id}\t${ratio}\told=${old_len}\tnew=${new_len}" >> "$RATIO_LOG"

  if awk -v r="$ratio" 'BEGIN{exit (r>0.3)?0:1}'; then
    echo "${id}\t${ratio}\told=${old_len}\tnew=${new_len}" >> "$EXPLODED"
  fi

done < "$BOTH_IDS"

fail=0

echo "AI baseline diff summary"

if [ -s "$OLD_INVALID" ]; then
  echo "FAIL: missing/empty .id in OLD files:"
  cat "$OLD_INVALID"
  fail=1
fi
if [ -s "$NEW_INVALID" ]; then
  echo "FAIL: missing/empty .id in NEW files:"
  cat "$NEW_INVALID"
  fail=1
fi
if [ -s "$DUP_OLD" ]; then
  echo "FAIL: duplicate ids in OLD baseline:"
  cat "$DUP_OLD"
  fail=1
fi
if [ -s "$DUP_NEW" ]; then
  echo "FAIL: duplicate ids in NEW baseline:"
  cat "$DUP_NEW"
  fail=1
fi
if [ -s "$MISSING_IDS" ]; then
  echo "FAIL: missing ids in NEW baseline:"
  cat "$MISSING_IDS"
  fail=1
fi
if [ -s "$ADDED_IDS" ]; then
  echo "FAIL: new ids added in NEW baseline:"
  cat "$ADDED_IDS"
  fail=1
fi
if [ -s "$SCHEMA_MISMATCH" ]; then
  echo "FAIL: schema mismatches (type/slug/status):"
  cat "$SCHEMA_MISMATCH"
  fail=1
fi
if [ -s "$EXPLODED" ]; then
  echo "FAIL: text change ratio > 30%:"
  cat "$EXPLODED"
  fail=1
fi
if [ -s "$INVALID_JSON" ]; then
  echo "FAIL: invalid JSON encountered:"
  cat "$INVALID_JSON"
  fail=1
fi

if [ -s "$RATIO_LOG" ]; then
  echo "Changed ratios (id, ratio, old_len, new_len):"
  cat "$RATIO_LOG"
fi

if [ "$fail" -ne 0 ]; then
  exit 1
fi

exit 0
