#!/usr/bin/env bash

set -euo pipefail

supervisorctl_bin="/usr/bin/supervisorctl"
sudo_bin="/usr/bin/sudo"
timeout_bin="/usr/bin/timeout"
crontab_bin="/usr/bin/crontab"
php_bin="/usr/bin/php"
deploy_path=""
proc_root="/proc"
system_cron_file="/etc/cron.d/fap-api-scheduler"
required="true"

for argument in "$@"; do
  case "$argument" in
    --supervisorctl=*) supervisorctl_bin="${argument#*=}" ;;
    --sudo=*) sudo_bin="${argument#*=}" ;;
    --timeout-bin=*) timeout_bin="${argument#*=}" ;;
    --crontab=*) crontab_bin="${argument#*=}" ;;
    --php-bin=*) php_bin="${argument#*=}" ;;
    --deploy-path=*) deploy_path="${argument#*=}" ;;
    --proc-root=*) proc_root="${argument#*=}" ;;
    --system-cron-file=*) system_cron_file="${argument#*=}" ;;
    --required=*) required="${argument#*=}" ;;
    --restart-script=*) : ;;
    *) printf 'scheduler_refresh_invalid_argument\n' >&2; exit 2 ;;
  esac
done

fail() {
  printf 'scheduler_refresh_failed reason=%s\n' "$1" >&2
  exit 1
}

for path in "$supervisorctl_bin" "$sudo_bin" "$timeout_bin" "$crontab_bin" "$php_bin" "$deploy_path" "$proc_root" "$system_cron_file"; do
  [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail invalid_path
  [[ "$path" != *".."* ]] || fail invalid_path
done
[[ "$required" =~ ^(true|false)$ ]] || fail invalid_required_mode
[[ -x "$supervisorctl_bin" ]] || fail supervisorctl_unavailable
[[ -x "$sudo_bin" ]] || fail sudo_unavailable
[[ -x "$timeout_bin" ]] || fail timeout_unavailable
[[ -d "$proc_root" ]] || fail proc_root_unavailable

deploy_root="$(readlink -f "$deploy_path")"
current_release="$(readlink -f "$deploy_root/current")"
[[ "$current_release" == "$deploy_root"/releases/* ]] || fail active_release_scope
[[ -f "$current_release/REVISION" ]] || fail active_revision_missing
active_revision="$(tr -d '\r\n' < "$current_release/REVISION")"
[[ "$active_revision" =~ ^[0-9a-f]{40}$ ]] || fail active_revision_invalid
current_backend="$(readlink -f "$current_release/backend")"
tick_wrapper="$current_backend/scripts/deploy/run_scheduler_tick.sh"
[[ -x "$php_bin" ]] || fail php_unavailable
[[ -x "$tick_wrapper" ]] || fail tick_wrapper_unavailable
[[ -f "$current_backend/artisan" ]] || fail artisan_unavailable
(
  cd "$current_backend"
  "$php_bin" artisan schedule:list --json --no-ansi 2>/dev/null
) | grep -Fq 'seo:runtime-probe-scheduled' || fail scheduler_registration_missing

process_descends_from() {
  local current_pid="$1"
  local ancestor_pid="$2"
  local stat_line=""
  local stat_tail=""
  local state=""
  local parent_pid=""
  local depth=0

  while [[ "$current_pid" =~ ^[1-9][0-9]*$ && "$depth" -lt 64 ]]; do
    [[ "$current_pid" == "$ancestor_pid" ]] && return 0
    [[ -r "$proc_root/$current_pid/stat" ]] || return 1
    stat_line="$(< "$proc_root/$current_pid/stat")"
    [[ "$stat_line" == *') '* ]] || return 1
    stat_tail="${stat_line##*) }"
    read -r state parent_pid _ <<< "$stat_tail"
    [[ "$parent_pid" =~ ^[0-9]+$ ]] || return 1
    [[ "$parent_pid" != "$current_pid" && "$parent_pid" != 0 ]] || return 1
    current_pid="$parent_pid"
    depth=$((depth + 1))
  done
  return 1
}

set +e
supervisor_status="$("$sudo_bin" -n "$supervisorctl_bin" status 2>/dev/null)"
supervisor_rc=$?
set -e
[[ "$supervisor_rc" -eq 0 || "$supervisor_rc" -eq 3 ]] || fail supervisor_status_unavailable

legacy_pids=""
legacy_programs=""
for cmdline_path in "$proc_root"/[0-9]*/cmdline; do
  [[ -r "$cmdline_path" ]] || continue
  pid="${cmdline_path%/cmdline}"
  pid="${pid##*/}"
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || continue
  command_line="$(tr '\0' ' ' < "$cmdline_path")"
  [[ "$command_line" =~ (^|[[:space:]])([^[:space:]]*/)?artisan[[:space:]]+schedule:work([[:space:]]|$) ]] || continue
  process_cwd="$(readlink -f "$proc_root/$pid/cwd" 2>/dev/null || true)"
  case "$process_cwd" in
    "$deploy_root"/releases/*/backend|"$current_backend") ;;
    *) fail unknown_schedule_work ;;
  esac
  legacy_pids+="$pid"$'\n'

  while read -r name state pid_label supervisor_pid _; do
    [[ "$state" == RUNNING && "$pid_label" == pid ]] || continue
    supervisor_pid="${supervisor_pid%,}"
    [[ "$supervisor_pid" =~ ^[1-9][0-9]*$ ]] || continue
    if process_descends_from "$pid" "$supervisor_pid"; then
      legacy_programs+="${name%%:*}"$'\n'
    fi
  done <<< "$supervisor_status"
done

while read -r name state _; do
  [[ "$state" == RUNNING ]] || continue
  program="${name%%:*}"
  normalized="$(printf '%s' "$program" | tr '[:upper:]' '[:lower:]')"
  if [[ "$normalized" =~ (^|[._-])(scheduler|schedule)([._-]|$) ]] \
    && ! grep -Fxq "$program" <<< "$legacy_programs"; then
    fail unknown_scheduler
  fi
done <<< "$supervisor_status"

legacy_programs="$(printf '%s' "$legacy_programs" | awk 'NF { seen[$0]=1 } END { for (item in seen) print item }' | sort)"
while IFS= read -r program; do
  [[ -n "$program" ]] || continue
  "$timeout_bin" --signal=TERM --kill-after=5s 30s "$sudo_bin" -n "$supervisorctl_bin" stop "$program" >/dev/null 2>&1 \
    || fail legacy_supervisor_stop_failed
  set +e
  stopped_status="$("$sudo_bin" -n "$supervisorctl_bin" status "$program" 2>/dev/null)"
  stopped_rc=$?
  set -e
  [[ "$stopped_rc" -eq 0 || "$stopped_rc" -eq 3 || "$stopped_rc" -eq 4 ]] || fail legacy_supervisor_status_failed
  if awk -v expected="$program" '($1 == expected || index($1, expected ":") == 1) && $2 == "RUNNING" { running=1 } END { exit(running ? 0 : 1) }' <<< "$stopped_status"; then
    fail legacy_supervisor_still_running
  fi
done <<< "$legacy_programs"

while IFS= read -r pid; do
  [[ -n "$pid" ]] || continue
  owned_by_supervisor=false
  while read -r name state pid_label supervisor_pid _; do
    [[ "$state" == RUNNING && "$pid_label" == pid ]] || continue
    supervisor_pid="${supervisor_pid%,}"
    if [[ "$supervisor_pid" =~ ^[1-9][0-9]*$ ]] && process_descends_from "$pid" "$supervisor_pid"; then
      owned_by_supervisor=true
      break
    fi
  done <<< "$supervisor_status"
  if [[ "$owned_by_supervisor" == false ]]; then
    "$sudo_bin" -n /bin/kill -TERM "$pid" >/dev/null 2>&1 || fail legacy_process_stop_failed
  fi
done <<< "$legacy_pids"

if [[ "$required" == false ]]; then
  printf 'scheduler_refresh_optional_skip reason=cron_not_required\n'
  exit 0
fi

[[ -x "$crontab_bin" ]] || fail crontab_unavailable
legacy_cron_helper="$current_backend/scripts/deploy/retire_legacy_scheduler_cron.py"
[[ -f "$legacy_cron_helper" ]] || fail legacy_cron_helper_unavailable
legacy_cron_command=("$sudo_bin" -n /usr/bin/python3 "$legacy_cron_helper" --deploy-root "$deploy_root" --cron-file "$system_cron_file")
"${legacy_cron_command[@]}" --check || fail legacy_system_cron_preflight_failed
begin_marker="# BEGIN fap-api managed scheduler"
end_marker="# END fap-api managed scheduler"
canonical_line="* * * * * $tick_wrapper --php-bin=$php_bin --backend-path=$deploy_root/current/backend >> /dev/null 2>&1"
crontab_error="$(mktemp)"
candidate="$(mktemp)"
trap 'rm -f "$crontab_error" "$candidate" "$candidate.raw"' EXIT
set +e
current_crontab="$(LC_ALL=C "$crontab_bin" -l 2>"$crontab_error")"
crontab_rc=$?
set -e
if [[ "$crontab_rc" -eq 1 ]] && grep -Eq '^no crontab for ' "$crontab_error"; then
  current_crontab=""
elif [[ "$crontab_rc" -ne 0 ]]; then
  fail crontab_read_failed
fi

begin_count="$(grep -Fxc "$begin_marker" <<< "$current_crontab" || true)"
end_count="$(grep -Fxc "$end_marker" <<< "$current_crontab" || true)"
[[ "$begin_count" -eq "$end_count" && "$begin_count" -le 1 ]] || fail cron_managed_block_invalid
marker_layout="$(awk -v begin="$begin_marker" -v end="$end_marker" '
  $0 == begin { if (managed || seen_begin) invalid=1; managed=1; seen_begin=1; next }
  $0 == end { if (!managed || seen_end) invalid=1; managed=0; seen_end=1; next }
  END { print (!invalid && !managed && seen_begin == seen_end) ? "valid" : "invalid" }
' <<< "$current_crontab")"
[[ "$marker_layout" == valid ]] || fail cron_managed_block_invalid

printf '%s\n' "$current_crontab" | awk -v begin="$begin_marker" -v end="$end_marker" '
  $0 == begin { managed=1; next }
  $0 == end { managed=0; next }
  !managed { print }
' > "$candidate.raw"

: > "$candidate"
while IFS= read -r line; do
  if [[ "$line" =~ ^[[:space:]]*# ]] || [[ -z "$line" ]]; then
    printf '%s\n' "$line" >> "$candidate"
    continue
  fi
  if [[ "$line" == *"artisan schedule:run"* || "$line" == *"artisan schedule:work"* || "$line" == *"run_scheduler_tick.sh"* ]]; then
    [[ "$line" == *"$deploy_root/"* || "$line" == *"$deploy_path/"* ]] || fail unknown_cron_scheduler
    continue
  fi
  printf '%s\n' "$line" >> "$candidate"
done < "$candidate.raw"
rm -f "$candidate.raw"

printf '%s\n%s\n%s\n' "$begin_marker" "$canonical_line" "$end_marker" >> "$candidate"
"$crontab_bin" "$candidate" || fail crontab_install_failed
installed="$("$crontab_bin" -l 2>/dev/null)" || fail crontab_verify_failed
[[ "$(grep -Fxc "$begin_marker" <<< "$installed" || true)" -eq 1 ]] || fail cron_managed_block_verify
[[ "$(grep -Fxc "$end_marker" <<< "$installed" || true)" -eq 1 ]] || fail cron_managed_block_verify
[[ "$(grep -Fxc "$canonical_line" <<< "$installed" || true)" -eq 1 ]] || fail cron_current_release_verify
[[ "$(grep -c 'run_scheduler_tick.sh' <<< "$installed" || true)" -eq 1 ]] || fail cron_scheduler_identity_count
[[ "$(grep -c 'artisan schedule:\(run\|work\)' <<< "$installed" || true)" -eq 0 ]] || fail cron_scheduler_identity_count

# Install and verify the replacement first, then atomically comment only the
# exact legacy system entry. Retain its original text for incident rollback.
"${legacy_cron_command[@]}" || fail legacy_system_cron_retirement_failed

printf 'scheduler_refresh_pass revision=%s mode=cron_schedule_run\n' "$active_revision"
