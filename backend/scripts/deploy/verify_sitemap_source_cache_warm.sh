#!/usr/bin/env bash
set -euo pipefail

php_bin="${SITEMAP_SOURCE_WARM_PHP_BIN:-}"
artisan="${SITEMAP_SOURCE_WARM_ARTISAN:-}"
timeout_seconds="${SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS:-180}"
kill_after_seconds="${SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS:-30}"
strict="${SITEMAP_SOURCE_WARM_STRICT:-false}"

fail_config() {
  printf '%s\n' "sitemap_source_cache_warm_status=configuration_error" >&2
  exit 2
}

is_bounded_integer() {
  local value="$1"
  local minimum="$2"
  local maximum="$3"

  [[ "$value" =~ ^[0-9]+$ ]] \
    && [ "$value" -ge "$minimum" ] \
    && [ "$value" -le "$maximum" ]
}

[[ "$php_bin" == /* && -x "$php_bin" ]] || fail_config
[[ "$artisan" == /* && -r "$artisan" ]] || fail_config
is_bounded_integer "$timeout_seconds" 120 600 || fail_config
is_bounded_integer "$kill_after_seconds" 5 60 || fail_config
[[ "$strict" == "true" || "$strict" == "false" ]] || fail_config
command -v jq >/dev/null 2>&1 || fail_config
command -v timeout >/dev/null 2>&1 || fail_config

set +e
command_output="$(
  timeout \
    --kill-after="${kill_after_seconds}s" \
    "${timeout_seconds}s" \
    "$php_bin" \
    "$artisan" \
    seo:warm-sitemap-source-cache \
    --json \
    --no-interaction \
    --no-ansi 2>/dev/null
)"
command_status=$?
set -e

reason=""
result_status=""
result_count=""

if [ "$command_status" -eq 0 ]; then
  result_json="$(printf '%s\n' "$command_output" | awk 'NF { line=$0 } END { print line }')"
  if ! printf '%s' "$result_json" | jq -e 'type == "object"' >/dev/null 2>&1; then
    reason="invalid_json"
  else
    result_status="$(printf '%s' "$result_json" | jq -r '.status // ""')"
    result_count="$(printf '%s' "$result_json" | jq -r '.count // ""')"

    if [[ "$result_count" =~ ^[0-9]+$ ]] \
      && [ "$result_count" -ge 1 ] \
      && { [ "$result_status" = "warmed" ] || [ "$result_status" = "fallback_warmed" ]; }
    then
      printf 'sitemap_source_cache_warm_status=%s\n' "$result_status"
      printf 'sitemap_source_cache_warm_count=%s\n' "$result_count"
      printf 'sitemap_source_cache_warm_strict=%s\n' "$strict"
      exit 0
    fi

    if [ "$result_status" = "locked" ]; then
      reason="locked"
    elif [[ "$result_count" =~ ^[0-9]+$ ]] && [ "$result_count" -lt 1 ]; then
      reason="empty"
    else
      reason="invalid_result"
    fi
  fi
elif [ "$command_status" -eq 124 ] || [ "$command_status" -eq 137 ]; then
  reason="timeout"
else
  reason="command_failed"
fi

printf '%s\n' "sitemap_source_cache_warm_status=degraded"
printf 'sitemap_source_cache_warm_reason=%s\n' "$reason"
printf 'sitemap_source_cache_warm_strict=%s\n' "$strict"

if [ "$strict" = "true" ]; then
  exit 1
fi

exit 0
