#!/usr/bin/env bash

set -euo pipefail

supervisorctl_bin="/usr/bin/supervisorctl"
sudo_bin="/usr/bin/sudo"
timeout_bin="/usr/bin/timeout"
program=""
attempts="3"
delay_seconds="2"
required="true"
restart_timeout_seconds="390"
heartbeat_seconds="20"

for argument in "$@"; do
  case "$argument" in
    --supervisorctl=*)
      supervisorctl_bin="${argument#*=}"
      ;;
    --sudo=*)
      sudo_bin="${argument#*=}"
      ;;
    --timeout-bin=*)
      timeout_bin="${argument#*=}"
      ;;
    --program=*)
      program="${argument#*=}"
      ;;
    --attempts=*)
      attempts="${argument#*=}"
      ;;
    --delay-seconds=*)
      delay_seconds="${argument#*=}"
      ;;
    --required=*)
      required="${argument#*=}"
      ;;
    --restart-timeout-seconds=*)
      restart_timeout_seconds="${argument#*=}"
      ;;
    --heartbeat-seconds=*)
      heartbeat_seconds="${argument#*=}"
      ;;
    *)
      echo "supervisor_program_restart_invalid_argument" >&2
      exit 2
      ;;
  esac
done

if [[ ! "$supervisorctl_bin" =~ ^/[A-Za-z0-9._/-]+$ ]] \
  || [[ "$supervisorctl_bin" == *".."* ]] \
  || [[ ! "$sudo_bin" =~ ^/[A-Za-z0-9._/-]+$ ]] \
  || [[ "$sudo_bin" == *".."* ]] \
  || [[ ! "$timeout_bin" =~ ^/[A-Za-z0-9._/-]+$ ]] \
  || [[ "$timeout_bin" == *".."* ]] \
  || [[ ! "$program" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] \
  || [[ ! "$attempts" =~ ^[1-9][0-9]?$ ]] \
  || [[ ! "$delay_seconds" =~ ^[0-9]$ ]] \
  || [[ ! "$required" =~ ^(true|false)$ ]] \
  || [[ ! "$restart_timeout_seconds" =~ ^[1-9][0-9]{1,2}$ ]] \
  || [[ "$restart_timeout_seconds" -lt 30 ]] \
  || [[ "$restart_timeout_seconds" -gt 900 ]] \
  || [[ ! "$heartbeat_seconds" =~ ^[1-9][0-9]?$ ]] \
  || [[ "$heartbeat_seconds" -gt 60 ]]; then
  echo "supervisor_program_restart_invalid_contract" >&2
  exit 2
fi

status_output=""
active_restart_pid=""
active_restart_pgid=""
active_heartbeat_pid=""

stop_heartbeat() {
  if [[ -n "$active_heartbeat_pid" ]] && kill -0 "$active_heartbeat_pid" 2>/dev/null; then
    kill -TERM "$active_heartbeat_pid" 2>/dev/null || true
    wait "$active_heartbeat_pid" 2>/dev/null || true
  fi

  active_heartbeat_pid=""
}

# shellcheck disable=SC2329 # Invoked by the signal trap below.
cleanup_restart() {
  if [[ -n "$active_restart_pid" ]] && kill -0 "$active_restart_pid" 2>/dev/null; then
    kill -TERM -- "-$active_restart_pgid" 2>/dev/null || true

    for _ in {1..50}; do
      if ! kill -0 "$active_restart_pid" 2>/dev/null; then
        break
      fi
      sleep 0.1
    done

    if kill -0 "$active_restart_pid" 2>/dev/null; then
      kill -KILL -- "-$active_restart_pgid" 2>/dev/null || true
    fi

    wait "$active_restart_pid" 2>/dev/null || true
  fi

  active_restart_pid=""
  active_restart_pgid=""
  stop_heartbeat
}

trap 'cleanup_restart; exit 143' HUP INT TERM

emit_restart_heartbeat() {
  local attempt="$1"
  local sleep_pid=""

  # shellcheck disable=SC2329 # Invoked by the subshell signal trap below.
  cleanup_heartbeat() {
    if [[ -n "$sleep_pid" ]] && kill -0 "$sleep_pid" 2>/dev/null; then
      kill -TERM "$sleep_pid" 2>/dev/null || true
      wait "$sleep_pid" 2>/dev/null || true
    fi

    exit 0
  }

  trap cleanup_heartbeat HUP INT TERM

  while kill -0 "$active_restart_pid" 2>/dev/null; do
    sleep "$heartbeat_seconds" &
    sleep_pid=$!
    wait "$sleep_pid" 2>/dev/null || true
    sleep_pid=""

    if kill -0 "$active_restart_pid" 2>/dev/null; then
      printf 'supervisor_program_restart_heartbeat program=%s attempt=%s\n' "$program" "$attempt"
    fi
  done
}

restart_target() {
  local target="$1"
  local attempt="$2"
  local restart_rc=0

  set +e
  set -m
  "$timeout_bin" \
    --signal=TERM \
    --kill-after=5s \
    "${restart_timeout_seconds}s" \
    "$sudo_bin" -n "$supervisorctl_bin" restart "$target" \
    >/dev/null 2>&1 &
  active_restart_pid=$!
  active_restart_pgid=$active_restart_pid
  set +m
  set -e

  emit_restart_heartbeat "$attempt" &
  active_heartbeat_pid=$!

  set +e
  # Bash job control may otherwise print the killed job's PID and full command
  # line when timeout escalates to KILL. The bounded status is captured below.
  wait "$active_restart_pid" 2>/dev/null
  restart_rc=$?
  set -e
  active_restart_pid=""
  active_restart_pgid=""
  stop_heartbeat

  if [[ "$restart_rc" -eq 124 ]] || [[ "$restart_rc" -eq 137 ]]; then
    printf 'supervisor_program_restart_timeout program=%s attempt=%s\n' "$program" "$attempt" >&2
  fi

  return "$restart_rc"
}

target_exists() {
  local candidate="$1"
  local target_kind="$2"

  set +e
  status_output="$("$sudo_bin" -n "$supervisorctl_bin" status "$candidate" 2>&1)"
  set -e

  if [[ "$target_kind" == "group" ]]; then
    printf '%s\n' "$status_output" | awk -v prefix="${program}:" '
      index($1, prefix) == 1 && $2 ~ /^(RUNNING|STOPPED|STARTING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN)$/ {
        found = 1
      }
      END { exit(found ? 0 : 1) }
    '

    return
  fi

  printf '%s\n' "$status_output" | awk -v expected="$program" '
    $1 == expected && $2 ~ /^(RUNNING|STOPPED|STARTING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN)$/ {
      found = 1
    }
    END { exit(found ? 0 : 1) }
  '
}

target_is_running() {
  local candidate="$1"
  local target_kind="$2"

  set +e
  status_output="$("$sudo_bin" -n "$supervisorctl_bin" status "$candidate" 2>&1)"
  set -e

  if [[ "$target_kind" == "group" ]]; then
    printf '%s\n' "$status_output" | awk -v prefix="${program}:" '
      index($1, prefix) == 1 {
        found = 1
        if ($2 != "RUNNING") {
          bad = 1
        }
      }
      END { exit(found && !bad ? 0 : 1) }
    '

    return
  fi

  printf '%s\n' "$status_output" | awk -v expected="$program" '
    $1 == expected {
      found = 1
      if ($2 != "RUNNING") {
        bad = 1
      }
    }
    END { exit(found && !bad ? 0 : 1) }
  '
}

for ((attempt = 1; attempt <= attempts; attempt++)); do
  target=""
  target_kind=""

  if target_exists "${program}:*" "group"; then
    target="${program}:*"
    target_kind="group"
  elif target_exists "$program" "single"; then
    target="$program"
    target_kind="single"
  fi

  if [[ -z "$target" ]] && [[ "$required" == "false" ]]; then
    printf 'supervisor_program_restart_optional_skip program=%s\n' "$program"
    exit 0
  fi

  if [[ -n "$target" ]]; then
    if restart_target "$target" "$attempt"; then
      restart_rc=0
    else
      restart_rc=$?
    fi

    if [[ "$restart_rc" -eq 0 ]] && target_is_running "$target" "$target_kind"; then
      printf 'supervisor_program_restart_pass program=%s attempts=%s\n' "$program" "$attempt"
      exit 0
    fi
  fi

  if [[ "$attempt" -lt "$attempts" ]] && [[ "$delay_seconds" -gt 0 ]]; then
    sleep "$delay_seconds"
  fi
done

if [[ "$required" == "false" ]]; then
  printf 'supervisor_program_restart_optional_skip program=%s\n' "$program"
  exit 0
fi

printf 'supervisor_program_restart_failed program=%s attempts=%s\n' "$program" "$attempts" >&2
exit 1
