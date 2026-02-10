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
# JSON helpers (php -r)
# -----------------------------
json_has_path() {
  local file="$1" path="$2"
  php -r '
$file=getenv("FILE");
$path=getenv("PATH");
$data=json_decode(file_get_contents($file), true);
if (!is_array($data)) { exit(1); }
$cur=$data;
foreach (explode(".", $path) as $seg) {
  if ($seg === "") continue;
  if (is_array($cur) && array_key_exists($seg, $cur)) {
    $cur=$cur[$seg];
    continue;
  }
  if (is_array($cur) && ctype_digit($seg)) {
    $idx=(int)$seg;
    if (array_key_exists($idx, $cur)) { $cur=$cur[$idx]; continue; }
  }
  exit(1);
}
exit(0);
' FILE="$file" PATH="$path"
}

json_get_raw() {
  local file="$1" path="$2"
  php -r '
$file=getenv("FILE");
$path=getenv("PATH");
$data=json_decode(file_get_contents($file), true);
if (!is_array($data)) { exit(1); }
$cur=$data;
foreach (explode(".", $path) as $seg) {
  if ($seg === "") continue;
  if (is_array($cur) && array_key_exists($seg, $cur)) {
    $cur=$cur[$seg];
    continue;
  }
  if (is_array($cur) && ctype_digit($seg)) {
    $idx=(int)$seg;
    if (array_key_exists($idx, $cur)) { $cur=$cur[$idx]; continue; }
  }
  exit(2);
}
if (is_array($cur)) { echo json_encode($cur, JSON_UNESCAPED_UNICODE); exit(0); }
if (is_bool($cur)) { echo $cur ? "true" : "false"; exit(0); }
if ($cur === null) { echo "null"; exit(0); }
echo (string)$cur;
exit(0);
' FILE="$file" PATH="$path"
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
  local file="$1" path="$2" ctx="${3:-}"
  json_has_path "$file" "$path" || fail "${ctx:+$ctx: }missing json path: $path (file=$file)"
}

assert_json_eq() {
  local file="$1" path="$2" expected="$3" ctx="${4:-}"
  local got
  got="$(json_get_raw "$file" "$path" || true)"
  [[ "$got" == "$expected" ]] || fail "${ctx:+$ctx: }json mismatch at $path: expected='$expected' got='$got' (file=$file)"
}

assert_json_len_between() {
  local file="$1" path="$2" min="$3" max="$4" ctx="${5:-}"
  local n
  n="$(php -r '
$file=getenv("FILE");
$path=getenv("PATH");
$min=(int)getenv("MIN");
$max=(int)getenv("MAX");
$data=json_decode(file_get_contents($file), true);
if (!is_array($data)) { exit(2); }
$cur=$data;
foreach (explode(".", $path) as $seg) {
  if ($seg === "") continue;
  if (is_array($cur) && array_key_exists($seg, $cur)) { $cur=$cur[$seg]; continue; }
  if (is_array($cur) && ctype_digit($seg)) { $idx=(int)$seg; if (array_key_exists($idx, $cur)) { $cur=$cur[$idx]; continue; } }
  exit(3);
}
if (!is_array($cur)) { exit(4); }
$n=count($cur);
echo $n;
exit(($n >= $min && $n <= $max) ? 0 : 5);
' FILE="$file" PATH="$path" MIN="$min" MAX="$max" 2>/dev/null || true)"
  [[ "$n" =~ ^[0-9]+$ ]] || fail "${ctx:+$ctx: }cannot read array length at $path (file=$file)"
  (( n >= min && n <= max )) || fail "${ctx:+$ctx: }length at $path out of range: got=$n expected=[$min,$max]"
}

assert_json_array_includes() {
  local file="$1" path="$2" want="$3" ctx="${4:-}"
  php -r '
$file=getenv("FILE");
$path=getenv("PATH");
$want=getenv("WANT");
$data=json_decode(file_get_contents($file), true);
if (!is_array($data)) { exit(1); }
$cur=$data;
foreach (explode(".", $path) as $seg) {
  if ($seg === "") continue;
  if (is_array($cur) && array_key_exists($seg, $cur)) { $cur=$cur[$seg]; continue; }
  if (is_array($cur) && ctype_digit($seg)) { $idx=(int)$seg; if (array_key_exists($idx, $cur)) { $cur=$cur[$idx]; continue; } }
  exit(2);
}
if (!is_array($cur)) { exit(3); }
exit(in_array($want, $cur, true) ? 0 : 4);
' FILE="$file" PATH="$path" WANT="$want" >/dev/null 2>&1 \
    || fail "${ctx:+$ctx: }expected array $path to include '$want' (file=$file)"
}

assert_json_array_not_includes() {
  local file="$1" path="$2" bad="$3" ctx="${4:-}"
  php -r '
$file=getenv("FILE");
$path=getenv("PATH");
$bad=getenv("BAD");
$data=json_decode(file_get_contents($file), true);
if (!is_array($data)) { exit(1); }
$cur=$data;
foreach (explode(".", $path) as $seg) {
  if ($seg === "") continue;
  if (is_array($cur) && array_key_exists($seg, $cur)) { $cur=$cur[$seg]; continue; }
  if (is_array($cur) && ctype_digit($seg)) { $idx=(int)$seg; if (array_key_exists($idx, $cur)) { $cur=$cur[$idx]; continue; } }
  exit(2);
}
if (!is_array($cur)) { exit(3); }
exit(in_array($bad, $cur, true) ? 4 : 0);
' FILE="$file" PATH="$path" BAD="$bad" >/dev/null 2>&1 \
    || fail "${ctx:+$ctx: }expected array $path NOT to include '$bad' (file=$file)"
}

assert_file_exists() {
  local f="$1" ctx="${2:-}"
  [[ -f "$f" ]] || fail "${ctx:+$ctx: }file not found: $f"
}
