#!/usr/bin/env bash

set -euo pipefail

failure_gate=INITIALIZE

on_error() {
  local exit_code=$?
  trap - ERR
  printf 'OPS_SHARED_VALIDATION_PROCESS_GATE_FAILED:%s\n' "$failure_gate" >&2
  exit "$exit_code"
}

trap on_error ERR

zero_sha256="$(printf '0%.0s' {1..64})"
target_path=/etc/supervisor/conf.d/fap-queue-ops.conf

sha256_text() {
  printf '%s' "$1" | sha256sum | awk '{print $1}'
}

process_start_ticks() {
  local process_pid="$1"
  sudo -n cat "/proc/$process_pid/stat" \
    | awk '{line=$0; sub(/^.*\) /, "", line); split(line, fields, " "); print fields[20]}'
}

process_cmdline_sha256() {
  local process_pid="$1"
  sudo -n sha256sum "/proc/$process_pid/cmdline" | awk '{print $1}'
}

process_cwd_sha256() {
  local process_pid="$1"
  local cwd
  cwd="$(sudo -n readlink -f "/proc/$process_pid/cwd")"
  sha256_text "$cwd"
}

process_identity_line() {
  local process_pid="$1"
  local process_user process_state
  read -r process_user process_state < <(
    ps -o user=,stat= -p "$process_pid" | awk '{$1=$1; print $1, $2}'
  )
  [[ "$process_user" =~ ^[A-Za-z_][A-Za-z0-9_-]{0,31}$ ]]
  [[ "$process_state" =~ ^[^Z] ]]
  printf '%s\t%s\t%s\t%s\t%s\n' \
    "$process_pid" \
    "$(process_start_ticks "$process_pid")" \
    "$(process_cmdline_sha256 "$process_pid")" \
    "$process_user" \
    "$(process_cwd_sha256 "$process_pid")"
}

descendant_pids() {
  local root_pid="$1"
  local process_table frontier next_frontier parent_pid process_pid
  process_table="$(ps -eo pid=,ppid=,stat= | awk '$3 !~ /^Z/ {print $1, $2}')"
  frontier="$root_pid"
  while [ -n "$frontier" ]; do
    next_frontier=''
    while IFS= read -r parent_pid; do
      [ -n "$parent_pid" ] || continue
      while IFS= read -r process_pid; do
        [ -n "$process_pid" ] || continue
        printf '%s\n' "$process_pid"
        next_frontier+="$process_pid"
        next_frontier+=$'\n'
      done < <(awk -v parent="$parent_pid" '$2 == parent {print $1}' <<<"$process_table")
    done <<<"$frontier"
    frontier="${next_frontier%$'\n'}"
  done
}

failure_gate=INPUTS
[[ "${MODE:-}" =~ ^(preflight|apply)$ ]]
[[ "${DEPLOY_PATH:-}" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$DEPLOY_PATH" != *".."* ]]
[[ "${EXPECTED_ACTIVE_REVISION:-}" =~ ^[0-9a-f]{40}$ ]]
[[ "${FAILED_RUN_ID:-}" =~ ^[1-9][0-9]*$ ]]
for hash_value in \
  "${EVIDENCE_SOURCE_PATH_SHA256:-}" \
  "${EVIDENCE_SOURCE_CONFIG_SHA256:-}" \
  "${EVIDENCE_TARGET_PATH_SHA256:-}" \
  "${EVIDENCE_TARGET_CURRENT_SHA256:-}" \
  "${EVIDENCE_FOREIGN_RUNTIME_FINGERPRINT_SHA256:-}"; do
  [[ "$hash_value" =~ ^[0-9a-f]{64}$ ]]
done
if [ "$MODE" = apply ]; then
  for hash_value in \
    "${EXPECTED_MAIN_RUNTIME_FINGERPRINT_SHA256:-}" \
    "${EXPECTED_OPS_WORKER_PID_SHA256:-}" \
    "${EXPECTED_VALIDATION_CONFIG_SHA256:-}" \
    "${EXPECTED_VALIDATION_PID_FILE_SHA256:-}" \
    "${EXPECTED_VALIDATION_PROCESS_FINGERPRINT_SHA256:-}" \
    "${EXPECTED_VALIDATION_TREE_SHA256:-}"; do
    [[ "$hash_value" =~ ^[0-9a-f]{64}$ ]]
  done
  [[ "${EXPECTED_VALIDATION_DESCENDANT_COUNT:-}" =~ ^([1-9]|10)$ ]]
  test "${EXPECTED_VALIDATION_DUPLICATE_OPS_COUNT:-}" = 1
  test "${EXPECTED_SYSTEM_OPS_WORKER_COUNT:-}" = 2
fi

failure_gate=DEPLOY_ROOT
deploy_root="$(readlink -f "$DEPLOY_PATH")"
current_release="$(readlink -f "$DEPLOY_PATH/current")"
current="$current_release/backend"
case "$current_release" in
  "$deploy_root"/releases/*) ;;
  *) exit 1 ;;
esac

failure_gate=ACTIVE_REVISION
active_revision="$(tr -d '\r\n' < "$DEPLOY_PATH/current/REVISION")"
test "$active_revision" = "$EXPECTED_ACTIVE_REVISION"
test ! -e "$DEPLOY_PATH/.dep/deploy.lock"
deploy_like_process_count="$(
  ps -eo comm=,args= \
    | awk '
      $1=="php" && ($0 ~ /dep\.phar/ || $0 ~ /artisan migrate/ || $0 ~ /queue:reload-workers/) {count++}
      $1=="composer" && $0 ~ /install/ {count++}
      END {print count+0}
    '
)"
test "$deploy_like_process_count" = 0

failure_gate=MAIN_SUPERVISOR_STATE
supervisorctl_path="$(command -v supervisorctl)"
grep_path="$(command -v grep)"
status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
test "$(awk '$1 ~ /^fap-queue-ops(:|$)/ {count++} END {print count+0}' <<<"$status_lines")" -eq 1
awk '$1 ~ /^fap-queue-ops(:|$)/ {found=1; if ($2 != "RUNNING") bad=1} END {exit !(found && !bad)}' <<<"$status_lines"
main_runtime_manifest="$(
  awk '
    {
      pid=""
      for (i=3; i<=NF; i++) {
        if ($i == "pid") {
          pid=$(i+1)
          sub(/,$/, "", pid)
        }
      }
      print $1 "\t" $2 "\t" pid
    }
  ' <<<"$status_lines" | sort
)"
main_runtime_fingerprint_sha256="$(printf '%s\n' "$main_runtime_manifest" | sha256sum | awk '{print $1}')"
foreign_runtime_fingerprint_sha256="$(
  awk '
    $1 !~ /^fap-queue-ops(:|$)/ {
      pid=""
      for (i=3; i<=NF; i++) {
        if ($i == "pid") {
          pid=$(i+1)
          sub(/,$/, "", pid)
        }
      }
      print $1 "\t" $2 "\t" pid
    }
  ' <<<"$status_lines" \
    | sort \
    | sha256sum \
    | awk '{print $1}'
)"
test "$foreign_runtime_fingerprint_sha256" = "$EVIDENCE_FOREIGN_RUNTIME_FINGERPRINT_SHA256"
mapfile -t main_runtime_pids < <(awk -F'\t' '$3 ~ /^[1-9][0-9]*$/ {print $3}' <<<"$main_runtime_manifest")
test "${#main_runtime_pids[@]}" -ge 1

failure_gate=PRIMARY_OPS_WORKER
worker_pid="$(sudo -n "$supervisorctl_path" pid fap-queue-ops:fap-queue-ops_00 2>/dev/null)"
[[ "$worker_pid" =~ ^[1-9][0-9]*$ ]]
test "$(ps -o user= -p "$worker_pid" | awk '{$1=$1; print}')" = www-data
test "$(sudo -n -u www-data readlink -f "/proc/$worker_pid/cwd")" = "$(readlink -f "$current")"
expected_argv_sha256="$(
  printf '%s\0' \
    /usr/bin/php artisan queue:work database --queue=ops --sleep=1 --tries=3 \
    --timeout=120 --max-time=3600 \
    | sha256sum | awk '{print $1}'
)"
test "$(process_cmdline_sha256 "$worker_pid")" = "$expected_argv_sha256"
ops_worker_pid_sha256="$(sha256_text "$worker_pid")"

failure_gate=CONFIG_STATE
mapfile -t config_candidates < <(
  sudo -n find /etc/supervisor /opt/1panel -type f \
    \( -name '*.conf' -o -name '*.ini' \) -size -128k \
    -exec "$grep_path" -lFx '[program:fap-queue-ops]' {} + 2>/dev/null \
    | sort -u
)
test "${#config_candidates[@]}" -eq 1
source_path="${config_candidates[0]}"
test "$source_path" != "$target_path"
source_path_sha256="$(sha256_text "$source_path")"
source_config_sha256="$(sudo -n sha256sum "$source_path" | awk '{print $1}')"
target_path_sha256="$(sha256_text "$target_path")"
target_current_sha256="$zero_sha256"
sudo -n test ! -e "$target_path"
test "$source_path_sha256" = "$EVIDENCE_SOURCE_PATH_SHA256"
test "$source_config_sha256" = "$EVIDENCE_SOURCE_CONFIG_SHA256"
test "$target_path_sha256" = "$EVIDENCE_TARGET_PATH_SHA256"
test "$target_current_sha256" = "$EVIDENCE_TARGET_CURRENT_SHA256"
test "$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$source_path")" -eq 1
test "$(sudo -n "$grep_path" -Ec '^\[[^]]+\][[:space:]]*$' "$source_path")" -eq 3
test "$(sudo -n "$grep_path" -Ec '^\[program:[^]]+\][[:space:]]*$' "$source_path")" -eq 3

failure_gate=VALIDATION_FILES
residue_prefix="/tmp/fap-ops-shared-migration-${FAILED_RUN_ID}"
validation_root="${residue_prefix}.supervisord.conf"
validation_pid_path="${residue_prefix}.pid"
sudo -n test -f "$validation_root"
sudo -n test -f "$validation_pid_path"
validation_config_sha256="$(sudo -n sha256sum "$validation_root" | awk '{print $1}')"
validation_pid_file_sha256="$(sudo -n sha256sum "$validation_pid_path" | awk '{print $1}')"
validation_pid="$(sudo -n cat "$validation_pid_path" | tr -d '\r\n')"
[[ "$validation_pid" =~ ^[1-9][0-9]*$ ]]

failure_gate=VALIDATION_PROCESS
sudo -n test -d "/proc/$validation_pid"
test "$(ps -o user= -p "$validation_pid" | awk '{$1=$1; print}')" = root
validation_cmdline_lines="$(sudo -n cat "/proc/$validation_pid/cmdline" | tr '\0' '\n')"
test "$(grep -Fxc "$validation_root" <<<"$validation_cmdline_lines")" -eq 1
test "$(grep -Fxc -- '-c' <<<"$validation_cmdline_lines")" -eq 1
test "$(grep -Fxc -- '-t' <<<"$validation_cmdline_lines")" -eq 1
test "$(grep -Ec '(^|/)supervisord$' <<<"$validation_cmdline_lines")" -eq 1
validation_start_ticks="$(process_start_ticks "$validation_pid")"
[[ "$validation_start_ticks" =~ ^[1-9][0-9]*$ ]]
validation_cmdline_sha256="$(process_cmdline_sha256 "$validation_pid")"
validation_process_fingerprint_sha256="$(
  printf '%s\t%s\t%s\t%s\n' \
    "$validation_pid" \
    "$validation_start_ticks" \
    "$validation_cmdline_sha256" \
    "$(process_cwd_sha256 "$validation_pid")" \
    | sha256sum | awk '{print $1}'
)"

failure_gate=VALIDATION_TREE
mapfile -t validation_descendant_pids < <(descendant_pids "$validation_pid" | sort -n -u)
validation_descendant_count="${#validation_descendant_pids[@]}"
test "$validation_descendant_count" -ge 1
test "$validation_descendant_count" -le 10
validation_tree_manifest=''
validation_duplicate_ops_count=0
for descendant_pid in "${validation_descendant_pids[@]}"; do
  for main_pid in "${main_runtime_pids[@]}"; do
    test "$descendant_pid" != "$main_pid"
  done
  identity_line="$(process_identity_line "$descendant_pid")"
  validation_tree_manifest+="$identity_line"
  validation_tree_manifest+=$'\n'
  if [ "$(process_cmdline_sha256 "$descendant_pid")" = "$expected_argv_sha256" ]; then
    validation_duplicate_ops_count=$((validation_duplicate_ops_count + 1))
  fi
done
test "$validation_duplicate_ops_count" = 1
validation_tree_sha256="$(printf '%s' "$validation_tree_manifest" | sha256sum | awk '{print $1}')"

failure_gate=SYSTEM_OPS_COUNT
system_ops_worker_count=0
for process_path in /proc/[1-9]*/cmdline; do
  [ -r "$process_path" ] || continue
  if [ "$(sha256sum "$process_path" 2>/dev/null | awk '{print $1}')" = "$expected_argv_sha256" ]; then
    system_ops_worker_count=$((system_ops_worker_count + 1))
  fi
done
test "$system_ops_worker_count" = 2

failure_gate=QUEUE_PROBE
queue_probe_php='try { $base=$argv[1]; require $base."/vendor/autoload.php"; $app=require $base."/bootstrap/app.php"; $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); $conn=Illuminate\Support\Facades\DB::connection(); $conn->listen(function($query){$sql=ltrim(strtolower((string)$query->sql)); if(!str_starts_with($sql,"select")&&!str_starts_with($sql,"show")&&!str_starts_with($sql,"describe")&&!str_starts_with($sql,"explain")&&!str_starts_with($sql,"pragma")){throw new RuntimeException("write query refused");}}); echo (clone $conn->table("jobs")->where("queue","ops"))->count(),PHP_EOL; } catch (Throwable $e) { echo "PROBE_FAILED",PHP_EOL; exit(1); }'
ops_pending_total="$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)"
test "$ops_pending_total" = 0

validation_process_stop_count=0
if [ "$MODE" = apply ]; then
  failure_gate=APPLY_RECEIPT_BINDING
  test "$main_runtime_fingerprint_sha256" = "$EXPECTED_MAIN_RUNTIME_FINGERPRINT_SHA256"
  test "$ops_worker_pid_sha256" = "$EXPECTED_OPS_WORKER_PID_SHA256"
  test "$validation_config_sha256" = "$EXPECTED_VALIDATION_CONFIG_SHA256"
  test "$validation_pid_file_sha256" = "$EXPECTED_VALIDATION_PID_FILE_SHA256"
  test "$validation_process_fingerprint_sha256" = "$EXPECTED_VALIDATION_PROCESS_FINGERPRINT_SHA256"
  test "$validation_tree_sha256" = "$EXPECTED_VALIDATION_TREE_SHA256"
  test "$validation_descendant_count" = "$EXPECTED_VALIDATION_DESCENDANT_COUNT"
  test "$validation_duplicate_ops_count" = "$EXPECTED_VALIDATION_DUPLICATE_OPS_COUNT"
  test "$system_ops_worker_count" = "$EXPECTED_SYSTEM_OPS_WORKER_COUNT"

  failure_gate=TERM_EXACT_VALIDATION_PROCESS
  test "$(process_start_ticks "$validation_pid")" = "$validation_start_ticks"
  test "$(process_cmdline_sha256 "$validation_pid")" = "$validation_cmdline_sha256"
  sudo -n kill -TERM "$validation_pid"
  validation_process_stop_count=1

  failure_gate=WAIT_FOR_VALIDATION_TREE_EXIT
  for _wait_iteration in {1..30}; do
    if ! sudo -n test -d "/proc/$validation_pid"; then
      break
    fi
    sleep 1
  done
  sudo -n test ! -d "/proc/$validation_pid"
  for descendant_pid in "${validation_descendant_pids[@]}"; do
    sudo -n test ! -d "/proc/$descendant_pid"
  done

  failure_gate=POST_STOP_READBACK
  test "$(tr -d '\r\n' < "$DEPLOY_PATH/current/REVISION")" = "$EXPECTED_ACTIVE_REVISION"
  test "$(sudo -n sha256sum "$source_path" | awk '{print $1}')" = "$source_config_sha256"
  sudo -n test ! -e "$target_path"
  post_status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
  post_main_runtime_manifest="$(
    awk '
      {
        pid=""
        for (i=3; i<=NF; i++) {
          if ($i == "pid") {
            pid=$(i+1)
            sub(/,$/, "", pid)
          }
        }
        print $1 "\t" $2 "\t" pid
      }
    ' <<<"$post_status_lines" | sort
  )"
  test "$(printf '%s\n' "$post_main_runtime_manifest" | sha256sum | awk '{print $1}')" = "$main_runtime_fingerprint_sha256"
  test "$(sudo -n "$supervisorctl_path" pid fap-queue-ops:fap-queue-ops_00 2>/dev/null)" = "$worker_pid"
  post_system_ops_worker_count=0
  for process_path in /proc/[1-9]*/cmdline; do
    [ -r "$process_path" ] || continue
    if [ "$(sha256sum "$process_path" 2>/dev/null | awk '{print $1}')" = "$expected_argv_sha256" ]; then
      post_system_ops_worker_count=$((post_system_ops_worker_count + 1))
    fi
  done
  test "$post_system_ops_worker_count" = 1
  test "$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)" = 0
fi

trap - ERR
printf 'active_revision=%s\n' "$active_revision"
printf 'failed_run_id=%s\n' "$FAILED_RUN_ID"
printf 'source_path_sha256=%s\n' "$source_path_sha256"
printf 'source_config_sha256=%s\n' "$source_config_sha256"
printf 'target_path_sha256=%s\n' "$target_path_sha256"
printf 'target_current_sha256=%s\n' "$target_current_sha256"
printf 'foreign_runtime_fingerprint_sha256=%s\n' "$foreign_runtime_fingerprint_sha256"
printf 'main_runtime_fingerprint_sha256=%s\n' "$main_runtime_fingerprint_sha256"
printf 'ops_worker_pid_sha256=%s\n' "$ops_worker_pid_sha256"
printf 'validation_config_sha256=%s\n' "$validation_config_sha256"
printf 'validation_pid_file_sha256=%s\n' "$validation_pid_file_sha256"
printf 'validation_process_state=present\n'
printf 'validation_process_fingerprint_sha256=%s\n' "$validation_process_fingerprint_sha256"
printf 'validation_descendant_count=%s\n' "$validation_descendant_count"
printf 'validation_tree_sha256=%s\n' "$validation_tree_sha256"
printf 'validation_duplicate_ops_count=%s\n' "$validation_duplicate_ops_count"
printf 'system_ops_worker_count=%s\n' "$system_ops_worker_count"
printf 'ops_pending_total=%s\n' "$ops_pending_total"
printf 'validation_process_stop_count=%s\n' "$validation_process_stop_count"
if [ "$MODE" = apply ]; then
  printf 'status=PASS_APPLY\n'
  printf 'production_write_execution=true\n'
else
  printf 'status=PASS_PREFLIGHT\n'
  printf 'production_write_execution=false\n'
fi
printf 'residue_delete_count=0\n'
printf 'source_config_write_count=0\n'
printf 'target_config_write_count=0\n'
printf 'backup_write_count=0\n'
printf 'worker_restart_count=0\n'
printf 'application_deploy_count=0\n'
printf 'symlink_write_count=0\n'
printf 'application_migration_count=0\n'
printf 'cms_or_database_authority_write_count=0\n'
printf 'publication_or_discoverability_write_count=0\n'
