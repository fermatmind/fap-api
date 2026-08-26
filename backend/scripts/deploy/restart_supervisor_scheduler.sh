#!/usr/bin/env bash

set -euo pipefail

supervisorctl_bin="/usr/bin/supervisorctl"
sudo_bin="/usr/bin/sudo"
timeout_bin="/usr/bin/timeout"
restart_script=""
deploy_path=""
proc_root="/proc"
required="true"

for argument in "$@"; do
  case "$argument" in
    --supervisorctl=*) supervisorctl_bin="${argument#*=}" ;;
    --sudo=*) sudo_bin="${argument#*=}" ;;
    --timeout-bin=*) timeout_bin="${argument#*=}" ;;
    --restart-script=*) restart_script="${argument#*=}" ;;
    --deploy-path=*) deploy_path="${argument#*=}" ;;
    --proc-root=*) proc_root="${argument#*=}" ;;
    --required=*) required="${argument#*=}" ;;
    *) printf 'scheduler_refresh_invalid_argument\n' >&2; exit 2 ;;
  esac
done

fail() {
  printf 'scheduler_refresh_failed reason=%s\n' "$1" >&2
  exit 1
}

for path in "$supervisorctl_bin" "$sudo_bin" "$timeout_bin" "$restart_script" "$deploy_path" "$proc_root"; do
  [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail invalid_path
  [[ "$path" != *".."* ]] || fail invalid_path
done
[[ "$required" =~ ^(true|false)$ ]] || fail invalid_required_mode
[[ -x "$supervisorctl_bin" ]] || fail supervisorctl_unavailable
[[ -x "$sudo_bin" ]] || fail sudo_unavailable
[[ -x "$timeout_bin" ]] || fail timeout_unavailable
[[ -x "$restart_script" ]] || fail restart_helper_unavailable
[[ -d "$proc_root" ]] || fail proc_root_unavailable

deploy_root="$(readlink -f "$deploy_path")"
current_release="$(readlink -f "$deploy_root/current")"
[[ "$current_release" == "$deploy_root"/releases/* ]] || fail active_release_scope
[[ -f "$current_release/REVISION" ]] || fail active_revision_missing
active_revision="$(tr -d '\r\n' < "$current_release/REVISION")"
[[ "$active_revision" =~ ^[0-9a-f]{40}$ ]] || fail active_revision_invalid
current_backend="$(readlink -f "$current_release/backend")"

read_status() {
  local output=""
  local rc=0

  set +e
  output="$("$sudo_bin" -n "$supervisorctl_bin" status 2>/dev/null)"
  rc=$?
  set -e
  [[ "$rc" -eq 0 || "$rc" -eq 3 ]] || fail supervisor_status_unavailable
  printf '%s\n' "$output"
}

discover_scheduler() {
  local status="$1"
  local name=""
  local state=""
  local pid_label=""
  local pid=""
  local command_line=""
  local program=""
  local programs=""
  local cmdline_path=""
  local scheduler_pid=""

  while read -r name state pid_label pid _; do
    [[ "$state" == RUNNING && "$pid_label" == pid ]] || continue
    pid="${pid%,}"
    [[ "$pid" =~ ^[1-9][0-9]*$ ]] || continue
    [[ "$name" =~ ^[A-Za-z0-9][A-Za-z0-9._:-]{0,255}$ ]] || fail invalid_supervisor_identity
    program="${name%%:*}"
    [[ "$program" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || fail invalid_scheduler_identity
    if program_name_is_scheduler "$program"; then
      programs+="$program"$'\n'
    fi

    for cmdline_path in "$proc_root"/[0-9]*/cmdline; do
      [[ -r "$cmdline_path" ]] || continue
      scheduler_pid="${cmdline_path%/cmdline}"
      scheduler_pid="${scheduler_pid##*/}"
      [[ "$scheduler_pid" =~ ^[1-9][0-9]*$ ]] || continue
      command_line="$(tr '\0' ' ' < "$cmdline_path")"
      [[ "$command_line" =~ (^|[[:space:]])([^[:space:]]*/)?artisan[[:space:]]+schedule:work([[:space:]]|$) ]] || continue
      if process_descends_from "$scheduler_pid" "$pid"; then
        programs+="$program"$'\n'
      fi
    done
  done <<< "$status"

  printf '%s' "$programs" | awk 'NF { seen[$0]=1 } END { for (item in seen) print item }' | sort
}

program_name_is_scheduler() {
  local program=""

  program="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
  [[ "$program" =~ (^|[._-])(scheduler|schedule)([._-]|$) ]]
}

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

status_before="$(read_status)"
programs_before="$(discover_scheduler "$status_before")"
program_count="$(awk 'NF { count++ } END { print count + 0 }' <<< "$programs_before")"
if [[ "$program_count" -eq 0 && "$required" == false ]]; then
  printf 'scheduler_refresh_optional_skip reason=not_managed\n'
  exit 0
fi
[[ "$program_count" -eq 1 ]] || fail scheduler_identity_count
program="$(awk 'NF { print; exit }' <<< "$programs_before")"

bash "$restart_script" \
  "--supervisorctl=$supervisorctl_bin" \
  "--sudo=$sudo_bin" \
  "--timeout-bin=$timeout_bin" \
  "--program=$program" \
  --attempts=3 \
  --delay-seconds=2 \
  --restart-timeout-seconds=390 \
  --heartbeat-seconds=20 \
  --required=true

status_after="$(read_status)"
programs_after="$(discover_scheduler "$status_after")"
[[ "$programs_after" == "$program" ]] || fail scheduler_identity_drift

member_count=0
verification="active_release"
while read -r name state pid_label pid _; do
  [[ "$state" == RUNNING && "$pid_label" == pid ]] || continue
  pid="${pid%,}"
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || continue
  [[ "${name%%:*}" == "$program" ]] || continue
  for cmdline_path in "$proc_root"/[0-9]*/cmdline; do
    [[ -r "$cmdline_path" ]] || continue
    scheduler_pid="${cmdline_path%/cmdline}"
    scheduler_pid="${scheduler_pid##*/}"
    [[ "$scheduler_pid" =~ ^[1-9][0-9]*$ ]] || continue
    command_line="$(tr '\0' ' ' < "$cmdline_path")"
    [[ "$command_line" =~ (^|[[:space:]])([^[:space:]]*/)?artisan[[:space:]]+schedule:work([[:space:]]|$) ]] || continue
    process_descends_from "$scheduler_pid" "$pid" || continue
    process_cwd="$(readlink -f "$proc_root/$scheduler_pid/cwd")"
    [[ "$process_cwd" == "$current_backend" ]] || fail scheduler_release_drift
    member_count=$((member_count + 1))
  done
done <<< "$status_after"
if [[ "$member_count" -eq 0 ]]; then
  program_name_is_scheduler "$program" || fail scheduler_member_missing
  verification="supervisor_restart"
fi

program_hash="$(printf '%s' "$program" | sha256sum | awk '{print $1}')"
printf 'scheduler_refresh_pass revision=%s program_hash=%s members=%s verification=%s\n' \
  "$active_revision" "$program_hash" "$member_count" "$verification"
