#!/usr/bin/env bash

set -euo pipefail

supervisorctl_bin="/usr/bin/supervisorctl"
sudo_bin="/usr/bin/sudo"
program=""
attempts="3"
delay_seconds="2"
required="true"

for argument in "$@"; do
  case "$argument" in
    --supervisorctl=*)
      supervisorctl_bin="${argument#*=}"
      ;;
    --sudo=*)
      sudo_bin="${argument#*=}"
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
  || [[ ! "$program" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] \
  || [[ ! "$attempts" =~ ^[1-9][0-9]?$ ]] \
  || [[ ! "$delay_seconds" =~ ^[0-9]$ ]] \
  || [[ ! "$required" =~ ^(true|false)$ ]]; then
  echo "supervisor_program_restart_invalid_contract" >&2
  exit 2
fi

status_output=""

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
    set +e
    "$sudo_bin" -n "$supervisorctl_bin" restart "$target" >/dev/null 2>&1
    restart_rc=$?
    set -e

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
