#!/usr/bin/env bash

set -euo pipefail

fail() {
  echo "career_current_publisher_resource_guard_invalid" >&2
  exit 64
}

resolve_command() {
  local name="$1"
  local resolved

  resolved="$(command -v "$name" 2>/dev/null || true)"
  [[ "$resolved" == /* && -x "$resolved" ]] || fail
  printf '%s' "$resolved"
}

timeout_bin="$(resolve_command timeout)"
nice_bin="$(resolve_command nice)"
ionice_bin="$(resolve_command ionice)"
php_bin="$(resolve_command php)"

readonly timeout_seconds=900
readonly kill_after_seconds=30
readonly nice_adjustment=15
readonly ionice_class=2
readonly ionice_priority=7
readonly memory_limit_mb=1024

export CAREER_CURRENT_PUBLISH_RESOURCE_GUARD_SCHEMA="career.current_authority_publish.resource_guard.v1"
export CAREER_CURRENT_PUBLISH_TIMEOUT_SECONDS="$timeout_seconds"
export CAREER_CURRENT_PUBLISH_NICE_ADJUSTMENT="$nice_adjustment"
export CAREER_CURRENT_PUBLISH_IONICE_CLASS="$ionice_class"
export CAREER_CURRENT_PUBLISH_IONICE_PRIORITY="$ionice_priority"
export CAREER_CURRENT_PUBLISH_MEMORY_LIMIT_MB="$memory_limit_mb"

exec "$timeout_bin" \
  --foreground \
  --signal=TERM \
  --kill-after="${kill_after_seconds}s" \
  "${timeout_seconds}s" \
  "$nice_bin" -n "$nice_adjustment" \
  "$ionice_bin" -c "$ionice_class" -n "$ionice_priority" \
  "$php_bin" -d "memory_limit=${memory_limit_mb}M"
