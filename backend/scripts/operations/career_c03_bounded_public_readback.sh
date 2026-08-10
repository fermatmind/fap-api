#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() {
  printf '%s\n' "$1" >&2
  exit 2
}

test "$#" -eq 2 || fail 'CAREER_C03_READBACK_ARGUMENTS_INVALID'

inspection="$1"
output="$2"

test -f "$inspection" || fail 'CAREER_C03_READBACK_INSPECTION_INVALID'
test ! -L "$inspection" || fail 'CAREER_C03_READBACK_INSPECTION_INVALID'
test -d "$(dirname "$output")" || fail 'CAREER_C03_READBACK_OUTPUT_INVALID'
test ! -L "$output" || fail 'CAREER_C03_READBACK_OUTPUT_INVALID'

urls_text="$(jq -er '
  .expected_urls
  | select(type == "array" and length > 0 and all(.[]; type == "string" and length > 0))
  | .[]
' "$inspection")" || fail 'CAREER_C03_READBACK_TARGETS_INVALID'

urls=()
while IFS= read -r url; do
  urls[${#urls[@]}]="$url"
done <<<"$urls_text"
test "${#urls[@]}" -gt 0 || fail 'CAREER_C03_READBACK_TARGETS_INVALID'

unique_count="$(printf '%s\n' "${urls[@]}" | LC_ALL=C sort -u | wc -l | tr -d ' ')"
test "$unique_count" -eq "${#urls[@]}" || fail 'CAREER_C03_READBACK_TARGETS_DUPLICATE'

for url in "${urls[@]}"; do
  [[ "$url" =~ ^https://fermatmind\.com/(en|zh)/career/jobs/[a-z0-9]+(-[a-z0-9]+)*$ ]] \
    || fail 'CAREER_C03_READBACK_TARGET_INVALID'
done

: > "$output"

read_one() {
  local url="$1"
  local code rc first_rc attempt_count

  set +e
  code="$(curl --http1.1 --silent --proto '=https' --request GET --max-redirs 0 \
    --connect-timeout 5 --max-time 20 --output /dev/null --write-out '%{http_code}' \
    -H 'Cache-Control: no-cache' -- "$url")"
  rc=$?
  set -e

  first_rc=$rc
  attempt_count=1
  if [ "$rc" -ne 0 ]; then
    sleep 1
    set +e
    code="$(curl --http1.1 --silent --proto '=https' --request GET --max-redirs 0 \
      --connect-timeout 5 --max-time 20 --output /dev/null --write-out '%{http_code}' \
      -H 'Cache-Control: no-cache' -- "$url")"
    rc=$?
    set -e
    attempt_count=2
  fi

  [[ "${code:-}" =~ ^[0-9]{3}$ ]] || code=000
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$READBACK_ROUND" "$url" "$code" "$rc" "$attempt_count" "$first_rc" \
    >> "$READBACK_OUTPUT"
}

export -f read_one
export READBACK_OUTPUT="$output"

for round in 1 2; do
  export READBACK_ROUND="$round"
  # The child shell resolves the positional parameter.
  # shellcheck disable=SC2016
  printf '%s\0' "${urls[@]}" | xargs -0 -P 2 -n 1 bash -c 'read_one "$1"' _
done

expected_lines=$((${#urls[@]} * 2))
actual_lines="$(wc -l < "$output" | tr -d ' ')"
test "$actual_lines" -eq "$expected_lines" || fail 'CAREER_C03_READBACK_RESULT_INCOMPLETE'
