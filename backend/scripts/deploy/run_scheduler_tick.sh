#!/usr/bin/env bash

set -euo pipefail

php_bin="/usr/bin/php"
backend_path=""
flock_bin="/usr/bin/flock"
timeout_bin="/usr/bin/timeout"

for argument in "$@"; do
  case "$argument" in
    --php-bin=*) php_bin="${argument#*=}" ;;
    --backend-path=*) backend_path="${argument#*=}" ;;
    --flock-bin=*) flock_bin="${argument#*=}" ;;
    --timeout-bin=*) timeout_bin="${argument#*=}" ;;
    *) printf 'scheduler_tick_invalid_argument\n' >&2; exit 2 ;;
  esac
done

fail() {
  printf 'scheduler_tick_failed reason=%s\n' "$1" >&2
  exit 1
}

for path in "$php_bin" "$backend_path" "$flock_bin" "$timeout_bin"; do
  [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail invalid_path
  [[ "$path" != *".."* ]] || fail invalid_path
done
[[ -x "$php_bin" ]] || fail php_unavailable
[[ -x "$flock_bin" ]] || fail flock_unavailable
[[ -x "$timeout_bin" ]] || fail timeout_unavailable
[[ -f "$backend_path/artisan" ]] || fail artisan_unavailable
mkdir -p "$backend_path/storage/app/ops"

exec 9>"$backend_path/storage/app/ops/scheduler-tick.lock"
overlap_path="$backend_path/storage/app/ops/scheduler-tick.overlap"
if ! "$flock_bin" -n 9; then
  # Never bootstrap Laravel on contention: a blocked dependency would create
  # another stuck PHP process every minute even though the tick is locked.
  : > "$overlap_path"
  printf 'scheduler_tick_failed reason=overlap\n' >&2
  exit 75
fi

cd "$backend_path"
rm -f "$overlap_path"
record_heartbeat() {
  "$timeout_bin" --signal=TERM --kill-after=5s 10s "$php_bin" -d memory_limit=256M \
    artisan ops:scheduler-heartbeat-record "$@" --json --no-interaction --no-ansi >/dev/null
}
record_heartbeat --status=started
set +e
"$php_bin" artisan schedule:run --no-interaction --no-ansi
schedule_rc=$?
set -e
if [[ -f "$overlap_path" ]]; then
  record_heartbeat --status=overlap
fi
record_heartbeat --status=completed --exit-code="$schedule_rc"
exit "$schedule_rc"
