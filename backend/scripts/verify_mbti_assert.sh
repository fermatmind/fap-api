#!/usr/bin/env bash
set -euo pipefail

# -----------------------------
# Small assertion helpers for CI/E2E scripts
# -----------------------------

_red()   { printf "\033[31m%s\033[0m" "$*"; }
_green() { printf "\033[32m%s\033[0m" "$*"; }
_yellow(){ printf "\033[33m%s\033[0m" "$*"; }

fail() {
  local msg="${1:-failed}"
  echo "$(_red "FAIL") $msg" >&2
  exit 1
}

note() {
  echo "$(_yellow "==>") $*" >&2
}

ok() {
  echo "$(_green "OK") $*" >&2
}

require_cmd() {
  local cmd="$1"
  command -v "$cmd" >/dev/null 2>&1 || fail "missing command: $cmd"
}

# -----------------------------
# Text assertions
# -----------------------------
assert_contains() {
  local hay="$1" needle="$2" ctx="${3:-}"
  grep -Fq -- "$needle" "$hay" || fail "${ctx:+$ctx: }expected file to contain: $needle (file=$hay)"
}

assert_not_contains() {
  local hay="$1" needle="$2" ctx="${3:-}"
  grep -Fq -- "$needle" "$hay" && fail "${ctx:+$ctx: }expected file NOT to contain: $needle (file=$hay)"
}

# -----------------------------
# JSON helpers (jq required)
# -----------------------------
json_has_path() {
  local file="$1" jq_path="$2"
  jq -e "$jq_path | . != null" "$file" >/dev/null 2>&1
}

json_get_raw() {
  local file="$1" jq_path="$2"
  jq -r "$jq_path" "$file"
}

# Pick first existing JSON path from a list, print it, return 0; else return 1.
json_pick_first_path() {
  local file="$1"; shift
  local p
  for p in "$@"; do
    if json_has_path "$file" "$p"; then
      echo "$p"
      return 0
    fi
  done
  return 1
}

assert_json_exists() {
  local file="$1" jq_path="$2" ctx="${3:-}"
  json_has_path "$file" "$jq_path" || fail "${ctx:+$ctx: }missing json path: $jq_path (file=$file)"
}

assert_json_eq() {
  local file="$1" jq_path="$2" expected="$3" ctx="${4:-}"
  local got
  got="$(json_get_raw "$file" "$jq_path")"
  [[ "$got" == "$expected" ]] || fail "${ctx:+$ctx: }json mismatch at $jq_path: expected='$expected' got='$got' (file=$file)"
}

assert_json_len_between() {
  local file="$1" jq_path="$2" min="$3" max="$4" ctx="${5:-}"
  local n
  n="$(jq -r "$jq_path | length" "$file" 2>/dev/null || echo "null")"
  [[ "$n" =~ ^[0-9]+$ ]] || fail "${ctx:+$ctx: }cannot read array length at $jq_path (file=$file)"
  (( n >= min && n <= max )) || fail "${ctx:+$ctx: }length at $jq_path out of range: got=$n expected=[$min,$max]"
}

assert_json_array_includes() {
  local file="$1" jq_path="$2" want="$3" ctx="${4:-}"
  jq -e "$jq_path | index(\"$want\") != null" "$file" >/dev/null 2>&1 \
    || fail "${ctx:+$ctx: }expected array $jq_path to include '$want' (file=$file)"
}

assert_json_array_not_includes() {
  local file="$1" jq_path="$2" bad="$3" ctx="${4:-}"
  jq -e "$jq_path | index(\"$bad\") == null" "$file" >/dev/null 2>&1 \
    || fail "${ctx:+$ctx: }expected array $jq_path NOT to include '$bad' (file=$file)"
}

assert_file_exists() {
  local f="$1" ctx="${2:-}"
  [[ -f "$f" ]] || fail "${ctx:+$ctx: }file not found: $f"
}