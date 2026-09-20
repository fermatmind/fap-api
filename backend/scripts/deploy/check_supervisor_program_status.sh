#!/usr/bin/env bash
set -euo pipefail

supervisorctl=""
sudo_bin="/usr/bin/sudo"
program=""
retries=5
delay_seconds=2

for argument in "$@"; do
  case "$argument" in
    --supervisorctl=*) supervisorctl="${argument#*=}" ;;
    --sudo=*) sudo_bin="${argument#*=}" ;;
    --program=*) program="${argument#*=}" ;;
    --retries=*) retries="${argument#*=}" ;;
    --delay-seconds=*) delay_seconds="${argument#*=}" ;;
    *) printf 'supervisor_program_preflight status=invalid_argument\n' >&2; exit 64 ;;
  esac
done

if [[ -z "$supervisorctl" || -z "$program" ]] \
  || [[ ! "$program" =~ ^[A-Za-z0-9._-]+$ ]] \
  || [[ ! "$retries" =~ ^[0-9]+$ ]] \
  || [[ ! "$delay_seconds" =~ ^[0-9]+$ ]]; then
  printf 'supervisor_program_preflight status=invalid_configuration\n' >&2
  exit 64
fi

attempt=0
while (( attempt <= retries )); do
  output="$($sudo_bin -n "$supervisorctl" status 2>/dev/null || true)"
  states="$({ printf '%s\n' "$output" || true; } | awk -v prefix="$program" '
    $1 == prefix || index($1, prefix ":") == 1 { print $2 }
  ')"

  if [[ -z "$states" ]]; then
    printf 'supervisor_program_preflight program=%s status=missing\n' "$program" >&2
    exit 20
  fi

  classification="recoverable"
  while IFS= read -r state; do
    case "$state" in
      RUNNING|STOPPED) ;;
      STARTING|STOPPING) classification="transient" ;;
      BACKOFF|FATAL|EXITED|UNKNOWN) classification="failed"; break ;;
      *) classification="failed"; break ;;
    esac
  done <<< "$states"

  if [[ "$classification" == "recoverable" ]]; then
    printf 'supervisor_program_preflight program=%s status=recoverable attempts=%d\n' "$program" "$((attempt + 1))"
    exit 0
  fi

  if [[ "$classification" == "failed" ]]; then
    printf 'supervisor_program_preflight program=%s status=failed\n' "$program" >&2
    exit 21
  fi

  if (( attempt == retries )); then
    printf 'supervisor_program_preflight program=%s status=transient_timeout attempts=%d\n' "$program" "$((attempt + 1))" >&2
    exit 22
  fi

  sleep "$delay_seconds"
  attempt=$((attempt + 1))
done
