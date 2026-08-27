#!/usr/bin/env bash

set -euo pipefail

php_bin="/usr/bin/php"
backend_path=""
flock_bin="/usr/bin/flock"

for argument in "$@"; do
  case "$argument" in
    --php-bin=*) php_bin="${argument#*=}" ;;
    --backend-path=*) backend_path="${argument#*=}" ;;
    --flock-bin=*) flock_bin="${argument#*=}" ;;
    *) printf 'scheduler_tick_invalid_argument\n' >&2; exit 2 ;;
  esac
done

fail() {
  printf 'scheduler_tick_failed reason=%s\n' "$1" >&2
  exit 1
}

for path in "$php_bin" "$backend_path" "$flock_bin"; do
  [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail invalid_path
  [[ "$path" != *".."* ]] || fail invalid_path
done
[[ -x "$php_bin" ]] || fail php_unavailable
[[ -x "$flock_bin" ]] || fail flock_unavailable
[[ -f "$backend_path/artisan" ]] || fail artisan_unavailable
mkdir -p "$backend_path/storage/app/ops"

exec 9>"$backend_path/storage/app/ops/scheduler-tick.lock"
if ! "$flock_bin" -n 9; then
  cd "$backend_path"
  "$php_bin" artisan ops:scheduler-heartbeat-record --status=overlap --json --no-interaction --no-ansi >/dev/null || true
  printf 'scheduler_tick_failed reason=overlap\n' >&2
  exit 75
fi

cd "$backend_path"
"$php_bin" artisan ops:scheduler-heartbeat-record --status=started --json --no-interaction --no-ansi >/dev/null
set +e
"$php_bin" artisan schedule:run --no-interaction --no-ansi
schedule_rc=$?
set -e
"$php_bin" artisan ops:scheduler-heartbeat-record --status=completed --exit-code="$schedule_rc" --json --no-interaction --no-ansi >/dev/null
exit "$schedule_rc"
