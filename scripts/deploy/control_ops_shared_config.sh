#!/usr/bin/env bash

set -euo pipefail

failure_gate=INITIALIZE
config_installed=false
backup_path=''
config_path=''
supervisorctl_path=''
supervisord_path=''

fail() {
  failure_gate="$1"
  return 1
}

rollback_on_error() {
  local exit_code=$?
  trap - ERR

  if [ "$config_installed" = true ]; then
    rollback_gate=ROLLBACK_INSTALL
    sudo -n install -o root -g root -m 0644 "$backup_path" "$config_path" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_VALIDATE
    sudo -n "$supervisord_path" -t >/dev/null 2>&1 || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_REREAD
    sudo -n "$supervisorctl_path" reread >/dev/null || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_UPDATE
    sudo -n "$supervisorctl_path" update fap-queue-ops >/dev/null || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_HASH
    test "$(sudo -n sha256sum "$config_path" | awk '{print $1}')" = "$EXPECTED_CURRENT_CONFIG_SHA256" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_STATUS
    rollback_status="$(sudo -n "$supervisorctl_path" status 2>/dev/null)" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    awk '$1 ~ /^fap-queue-ops(:|$)/ {found=1; if ($2 != "RUNNING") bad=1} END {exit !(found && !bad)}' <<<"$rollback_status" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    test "$(awk '$1 !~ /^fap-queue-ops(:|$)/' <<<"$rollback_status" | sha256sum | awk '{print $1}')" = "$foreign_status_sha256" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_QUEUE
    test "$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)" = 0 || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
  fi

  printf 'OPS_SHARED_CONFIG_GATE_FAILED:%s\n' "$failure_gate" >&2
  exit "$exit_code"
}

trap rollback_on_error ERR

failure_gate=INPUTS
[[ "${MODE:-}" =~ ^(preflight|apply)$ ]]
[[ "${DEPLOY_PATH:-}" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$DEPLOY_PATH" != *".."* ]]
[[ "${EXPECTED_ACTIVE_REVISION:-}" =~ ^[0-9a-f]{40}$ ]]
[[ "${OPS_CANDIDATE_B64:-}" =~ ^[A-Za-z0-9+/=]+$ ]]
[[ "${OPS_PROJECTOR_B64:-}" =~ ^[A-Za-z0-9+/=]+$ ]]
if [ "$MODE" = apply ]; then
  [[ "${CONTROL_RUN_ID:-}" =~ ^[0-9]+$ ]]
  for hash_value in \
    "${EXPECTED_CONFIG_PATH_SHA256:-}" \
    "${EXPECTED_CURRENT_CONFIG_SHA256:-}" \
    "${EXPECTED_CURRENT_OPS_SECTION_SHA256:-}" \
    "${EXPECTED_FOREIGN_PROJECTION_SHA256:-}" \
    "${EXPECTED_FOREIGN_STATUS_SHA256:-}" \
    "${EXPECTED_RENDERED_OPS_SECTION_SHA256:-}" \
    "${EXPECTED_PATCHED_CONFIG_SHA256:-}"; do
    [[ "$hash_value" =~ ^[0-9a-f]{64}$ ]]
  done
  [[ "${EXPECTED_CONFIG_EXACT_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_CONFIG_TOTAL_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_RUNTIME_CWD_CURRENT:-}" =~ ^(true|false)$ ]]
  [[ "${EXPECTED_RUNTIME_CONFIG_CURRENT:-}" =~ ^(true|false)$ ]]
fi

failure_gate=DEPLOY_ROOT
deploy_root="$(readlink -f "$DEPLOY_PATH")"
current_release="$(readlink -f "$DEPLOY_PATH/current")"
current="$current_release/backend"
failure_gate=ACTIVE_REVISION
active_revision="$(tr -d '\r\n' < "$DEPLOY_PATH/current/REVISION")"
test "$active_revision" = "$EXPECTED_ACTIVE_REVISION"
lock_present=false
[ -e "$DEPLOY_PATH/.dep/deploy.lock" ] && lock_present=true
test "$lock_present" = false
deploy_like_process_count="$(ps -eo comm=,args= | awk '$1=="php" && ($0 ~ /dep\.phar/ || $0 ~ /artisan migrate/ || $0 ~ /queue:reload-workers/) {count++} $1=="composer" && $0 ~ /install/ {count++} END {print count+0}')"
test "$deploy_like_process_count" = 0

failure_gate=SUPERVISOR_PATHS
supervisorctl_path="$(command -v supervisorctl)"
supervisord_path="$(command -v supervisord)"
grep_path="$(command -v grep)"
failure_gate=SUPERVISOR_STATUS
status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
test "$(awk '$1 ~ /^fap-queue-ops(:|$)/ {count++} END {print count+0}' <<<"$status_lines")" -eq 1
awk '$1 ~ /^fap-queue-ops(:|$)/ {found=1; if ($2 != "RUNNING") bad=1} END {exit !(found && !bad)}' <<<"$status_lines"
foreign_status_sha256="$(awk '$1 !~ /^fap-queue-ops(:|$)/' <<<"$status_lines" | sha256sum | awk '{print $1}')"

failure_gate=WORKER_PID
worker_pid="$(sudo -n "$supervisorctl_path" pid fap-queue-ops:fap-queue-ops_00 2>/dev/null)"
[[ "$worker_pid" =~ ^[1-9][0-9]*$ ]]
failure_gate=WORKER_USER
test "$(ps -o user= -p "$worker_pid" | awk '{$1=$1; print}')" = www-data
failure_gate=WORKER_CWD
worker_cwd="$(sudo -n -u www-data readlink -f "/proc/$worker_pid/cwd")"
runtime_cwd_current=false
if [ "$worker_cwd" = "$(readlink -f "$current")" ]; then
  runtime_cwd_current=true
else
  case "$worker_cwd" in
    "$deploy_root"/releases/*/backend) ;;
    *) exit 1 ;;
  esac
  stale_release="${worker_cwd%/backend}"
  test "$(tr -d '\r\n' < "$stale_release/REVISION")" = "$active_revision"
fi
failure_gate=WORKER_ARGV
expected_argv_sha256="$(printf '%s\0' \
  /usr/bin/php artisan queue:work database --queue=ops --sleep=1 --tries=3 \
  --timeout=120 --max-time=3600 | sha256sum | awk '{print $1}')"
actual_argv_sha256="$(sha256sum "/proc/$worker_pid/cmdline" | awk '{print $1}')"
test "$actual_argv_sha256" = "$expected_argv_sha256"

failure_gate=CONFIG_DISCOVERY
mapfile -t config_candidates < <(
  sudo -n find /etc/supervisor /opt/1panel -type f \
    \( -name '*.conf' -o -name '*.ini' \) -size -128k \
    -exec "$grep_path" -lFx '[program:fap-queue-ops]' {} + 2>/dev/null \
    | sort -u
)
test "${#config_candidates[@]}" -eq 1
config_path="${config_candidates[0]}"
[[ "$config_path" =~ ^/(etc/supervisor|opt/1panel)(/[A-Za-z0-9._-]+)+\.(conf|ini)$ ]]
[[ "$config_path" != *".."* ]]
config_path_sha256="$(printf '%s' "$config_path" | sha256sum | awk '{print $1}')"
current_config_sha256="$(sudo -n sha256sum "$config_path" | awk '{print $1}')"

failure_gate=CONFIG_LAYOUT
config_exact_program_section_count="$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$config_path")"
config_total_section_count="$(sudo -n "$grep_path" -Ec '^\[[^]]+\][[:space:]]*$' "$config_path")"
config_program_section_count="$(sudo -n "$grep_path" -Ec '^\[program:[^]]+\][[:space:]]*$' "$config_path")"
test "$config_exact_program_section_count" -eq 1
test "$config_total_section_count" -ge 2
test "$config_total_section_count" -eq "$config_program_section_count"
config_foreign_program_section_count=$((config_program_section_count - config_exact_program_section_count))
test "$config_foreign_program_section_count" -ge 1

project_config() {
  local output_path="${1:-}"

  printf '%s' "$OPS_PROJECTOR_B64" \
    | base64 -d \
    | sudo -n php -- "$config_path" "$OPS_CANDIDATE_B64" "$output_path"
}

failure_gate=CONFIG_PROJECTIONS
projection_result="$(project_config 2>/dev/null)"
IFS=$'\t' read -r current_ops_section_sha256 foreign_projection_sha256 rendered_ops_section_sha256 patched_config_sha256 <<<"$projection_result"
for projection_hash in \
  "$current_ops_section_sha256" \
  "$foreign_projection_sha256" \
  "$rendered_ops_section_sha256" \
  "$patched_config_sha256"; do
  [[ "$projection_hash" =~ ^[0-9a-f]{64}$ ]]
done

failure_gate=RUNTIME_CONFIG
config_epoch="$(sudo -n stat -c %Y "$config_path")"
boot_epoch="$(awk '$1 == "btime" {print $2}' /proc/stat)"
start_ticks="$(awk '{print $22}' "/proc/$worker_pid/stat")"
clock_ticks="$(getconf CLK_TCK)"
runtime_config_current=false
process_epoch=$((boot_epoch + start_ticks / clock_ticks))
[ "$process_epoch" -ge "$config_epoch" ] && runtime_config_current=true

failure_gate=QUEUE_PROBE
queue_probe_php='try { $base=$argv[1]; require $base."/vendor/autoload.php"; $app=require $base."/bootstrap/app.php"; $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); $conn=Illuminate\Support\Facades\DB::connection(); $conn->listen(function($query){$sql=ltrim(strtolower((string)$query->sql)); if(!str_starts_with($sql,"select")&&!str_starts_with($sql,"show")&&!str_starts_with($sql,"describe")&&!str_starts_with($sql,"explain")&&!str_starts_with($sql,"pragma")){throw new RuntimeException("write query refused");}}); echo (clone $conn->table("jobs")->where("queue","ops"))->count(),PHP_EOL; } catch (Throwable $e) { echo "PROBE_FAILED",PHP_EOL; exit(1); }'
ops_pending_total="$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)"
test "$ops_pending_total" = 0

if [ "$MODE" = apply ]; then
  failure_gate=EXPECTED_BINDING
  test "$config_path_sha256" = "$EXPECTED_CONFIG_PATH_SHA256"
  test "$current_config_sha256" = "$EXPECTED_CURRENT_CONFIG_SHA256"
  test "$current_ops_section_sha256" = "$EXPECTED_CURRENT_OPS_SECTION_SHA256"
  test "$foreign_projection_sha256" = "$EXPECTED_FOREIGN_PROJECTION_SHA256"
  test "$foreign_status_sha256" = "$EXPECTED_FOREIGN_STATUS_SHA256"
  test "$rendered_ops_section_sha256" = "$EXPECTED_RENDERED_OPS_SECTION_SHA256"
  test "$patched_config_sha256" = "$EXPECTED_PATCHED_CONFIG_SHA256"
  test "$config_exact_program_section_count" = "$EXPECTED_CONFIG_EXACT_PROGRAM_SECTION_COUNT"
  test "$config_total_section_count" = "$EXPECTED_CONFIG_TOTAL_SECTION_COUNT"
  test "$config_foreign_program_section_count" = "$EXPECTED_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT"
  test "$runtime_cwd_current" = "$EXPECTED_RUNTIME_CWD_CURRENT"
  test "$runtime_config_current" = "$EXPECTED_RUNTIME_CONFIG_CURRENT"

  backup_path="/tmp/fap-queue-ops-shared-${CONTROL_RUN_ID}.backup"
  candidate_path="/tmp/fap-queue-ops-shared-${CONTROL_RUN_ID}.candidate"
  [[ "$backup_path" =~ ^/tmp/fap-queue-ops-shared-[0-9]+\.backup$ ]]
  [[ "$candidate_path" =~ ^/tmp/fap-queue-ops-shared-[0-9]+\.candidate$ ]]
  failure_gate=TEMP_ABSENT
  sudo -n test ! -e "$backup_path"
  sudo -n test ! -e "$candidate_path"
  failure_gate=BACKUP
  sudo -n install -o root -g root -m 0600 "$config_path" "$backup_path"
  test "$(sudo -n sha256sum "$backup_path" | awk '{print $1}')" = "$current_config_sha256"
  failure_gate=PATCH_RENDER
  project_config "$candidate_path" >/dev/null 2>&1
  test "$(sudo -n sha256sum "$candidate_path" | awk '{print $1}')" = "$patched_config_sha256"
  failure_gate=CONFIG_INSTALL
  config_installed=true
  sudo -n install -o root -g root -m 0644 "$candidate_path" "$config_path"
  failure_gate=SUPERVISOR_VALIDATE
  sudo -n "$supervisord_path" -t >/dev/null 2>&1
  failure_gate=SUPERVISOR_REREAD
  sudo -n "$supervisorctl_path" reread >/dev/null
  failure_gate=SUPERVISOR_UPDATE
  sudo -n "$supervisorctl_path" update fap-queue-ops >/dev/null

  failure_gate=READBACK_CONFIG
  test "$(sudo -n sha256sum "$config_path" | awk '{print $1}')" = "$patched_config_sha256"
  readback_projection="$(project_config 2>/dev/null)"
  IFS=$'\t' read -r readback_ops_sha readback_foreign_sha readback_rendered_sha readback_patched_sha <<<"$readback_projection"
  test "$readback_ops_sha" = "$rendered_ops_section_sha256"
  test "$readback_foreign_sha" = "$foreign_projection_sha256"
  test "$readback_rendered_sha" = "$rendered_ops_section_sha256"
  test "$readback_patched_sha" = "$patched_config_sha256"
  failure_gate=READBACK_FOREIGN_STATUS
  status_after="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
  test "$(awk '$1 !~ /^fap-queue-ops(:|$)/' <<<"$status_after" | sha256sum | awk '{print $1}')" = "$foreign_status_sha256"
  awk '$1 ~ /^fap-queue-ops(:|$)/ {found=1; if ($2 != "RUNNING") bad=1} END {exit !(found && !bad)}' <<<"$status_after"
  failure_gate=READBACK_WORKER
  readback_pid="$(sudo -n "$supervisorctl_path" pid fap-queue-ops:fap-queue-ops_00 2>/dev/null)"
  [[ "$readback_pid" =~ ^[1-9][0-9]*$ ]]
  test "$readback_pid" != "$worker_pid"
  test "$(ps -o user= -p "$readback_pid" | awk '{$1=$1; print}')" = www-data
  test "$(sudo -n -u www-data readlink -f "/proc/$readback_pid/cwd")" = "$(readlink -f "$current")"
  test "$(sha256sum "/proc/$readback_pid/cmdline" | awk '{print $1}')" = "$expected_argv_sha256"
  failure_gate=READBACK_QUEUE
  test "$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)" = 0

  config_installed=false
  sudo -n rm -f "$candidate_path" "$backup_path"
fi

trap - ERR
printf 'active_revision=%s\n' "$active_revision"
printf 'config_path_sha256=%s\n' "$config_path_sha256"
printf 'current_config_sha256=%s\n' "$current_config_sha256"
printf 'current_ops_section_sha256=%s\n' "$current_ops_section_sha256"
printf 'foreign_projection_sha256=%s\n' "$foreign_projection_sha256"
printf 'foreign_status_sha256=%s\n' "$foreign_status_sha256"
printf 'rendered_ops_section_sha256=%s\n' "$rendered_ops_section_sha256"
printf 'patched_config_sha256=%s\n' "$patched_config_sha256"
printf 'config_exact_program_section_count=%s\n' "$config_exact_program_section_count"
printf 'config_total_section_count=%s\n' "$config_total_section_count"
printf 'config_foreign_program_section_count=%s\n' "$config_foreign_program_section_count"
printf 'runtime_cwd_current=%s\n' "$runtime_cwd_current"
printf 'runtime_config_current=%s\n' "$runtime_config_current"
printf 'ops_pending_total=%s\n' "$ops_pending_total"
printf 'live_process_verified=true\n'
if [ "$MODE" = apply ]; then
  printf 'status=PASS_APPLY\n'
  printf 'production_write_execution=true\n'
  printf 'config_write_count=1\n'
  printf 'worker_restart_count=1\n'
else
  printf 'status=PASS_PREFLIGHT\n'
  printf 'production_write_execution=false\n'
  printf 'config_write_count=0\n'
  printf 'worker_restart_count=0\n'
fi
printf 'rollback_execution_count=0\n'
