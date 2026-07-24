#!/usr/bin/env bash

set -euo pipefail

failure_gate=INITIALIZE
migration_started=false
source_backup=''
source_candidate=''
target_candidate=''
source_config=''
managed_target=/etc/supervisor/conf.d/fap-queue-ops.conf
supervisorctl_path=''
supervisord_path=''

foreign_runtime_fingerprint() {
  awk '
    $1 !~ /^fap-queue-ops(:|$)/ {
      pid=""
      for (i=3; i<=NF; i++) {
        if ($i == "pid") {
          pid=$(i+1)
          gsub(/,/, "", pid)
        }
      }
      print $1 "\t" $2 "\t" pid
    }
  ' | sort | sha256sum | awk '{print $1}'
}

rollback_on_error() {
  local exit_code=$?
  trap - ERR

  if [ "$migration_started" = true ]; then
    rollback_gate=ROLLBACK_REMOVE_TARGET
    sudo -n rm -f "$managed_target" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_RESTORE_SOURCE
    sudo -n install -o root -g root -m 0644 "$source_backup" "$source_config" || {
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
    rollback_gate=ROLLBACK_SOURCE_HASH
    test "$(sudo -n sha256sum "$source_config" | awk '{print $1}')" = "$EXPECTED_CURRENT_CONFIG_SHA256" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_TARGET_ABSENT
    sudo -n test ! -e "$managed_target" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_status="$(sudo -n "$supervisorctl_path" status 2>/dev/null)" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:ROLLBACK_STATUS\n' >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_OPS_RUNNING
    awk '$1 ~ /^fap-queue-ops(:|$)/ {found=1; if ($2 != "RUNNING") bad=1} END {exit !(found && !bad)}' <<<"$rollback_status" || {
      printf 'OPS_SHARED_CONFIG_ROLLBACK_FAILED:%s\n' "$rollback_gate" >&2
      exit "$exit_code"
    }
    rollback_gate=ROLLBACK_FOREIGN_RUNTIME
    test "$(foreign_runtime_fingerprint <<<"$rollback_status")" = "$foreign_runtime_fingerprint_sha256" || {
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
    "${EXPECTED_STRIPPED_SOURCE_SHA256:-}" \
    "${EXPECTED_MANAGED_TARGET_PATH_SHA256:-}" \
    "${EXPECTED_MANAGED_TARGET_CURRENT_SHA256:-}" \
    "${EXPECTED_RENDERED_OPS_CONFIG_SHA256:-}" \
    "${EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256:-}"; do
    [[ "$hash_value" =~ ^[0-9a-f]{64}$ ]]
  done
  [[ "${EXPECTED_CONFIG_EXACT_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_CONFIG_TOTAL_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_STRIPPED_EXACT_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_STRIPPED_TOTAL_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
  [[ "${EXPECTED_STRIPPED_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
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
test ! -e "$DEPLOY_PATH/.dep/deploy.lock"
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
foreign_runtime_fingerprint_sha256="$(foreign_runtime_fingerprint <<<"$status_lines")"

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
  stale_release_name="$(basename "$stale_release")"
  [[ "$stale_release_name" =~ ^[A-Za-z0-9._-]{1,128}$ ]]
  test "$(readlink -f "$deploy_root/releases/$stale_release_name/backend")" = "$worker_cwd"
  test "$(tr -d '\r\n' < "$stale_release/REVISION")" = "$active_revision"
fi
failure_gate=WORKER_ARGV
expected_argv_sha256="$(printf '%s\0' \
  /usr/bin/php artisan queue:work database --queue=ops --sleep=1 --tries=3 \
  --timeout=120 --max-time=3600 | sha256sum | awk '{print $1}')"
test "$(sha256sum "/proc/$worker_pid/cmdline" | awk '{print $1}')" = "$expected_argv_sha256"

failure_gate=CONFIG_DISCOVERY
mapfile -t config_candidates < <(
  sudo -n find /etc/supervisor /opt/1panel -type f -size -256k \
    \( -name '*.conf' -o -name '*.ini' \) \
    -exec "$grep_path" -lFx '[program:fap-queue-ops]' {} + 2>/dev/null \
    | sort -u
)
test "${#config_candidates[@]}" -eq 1
source_config="${config_candidates[0]}"
[[ "$source_config" =~ ^/(etc/supervisor|opt/1panel)(/[A-Za-z0-9._-]+)+\.(conf|ini)$ ]]
[[ "$source_config" != *".."* ]]
test "$source_config" != "$managed_target"
config_path_sha256="$(printf '%s' "$source_config" | sha256sum | awk '{print $1}')"
managed_target_path_sha256="$(printf '%s' "$managed_target" | sha256sum | awk '{print $1}')"
zero_sha256="$(printf '0%.0s' {1..64})"
managed_target_current_sha256="$zero_sha256"
if sudo -n test -e "$managed_target"; then
  managed_target_current_sha256="$(sudo -n sha256sum "$managed_target" | awk '{print $1}')"
fi
current_config_sha256="$(sudo -n sha256sum "$source_config" | awk '{print $1}')"

failure_gate=CONFIG_LAYOUT
config_exact_program_section_count="$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$source_config")"
config_total_section_count="$(sudo -n "$grep_path" -Ec '^\[[^]]+\][[:space:]]*$' "$source_config")"
config_program_section_count="$(sudo -n "$grep_path" -Ec '^\[program:[^]]+\][[:space:]]*$' "$source_config")"
config_foreign_program_section_count=$((config_program_section_count - config_exact_program_section_count))
test "$config_exact_program_section_count" -eq 1
test "$config_total_section_count" -eq 3
test "$config_program_section_count" -eq 3
test "$config_foreign_program_section_count" -eq 2
test "$managed_target_current_sha256" = "$zero_sha256"

project_config() {
  local stripped_path="${1:-}"
  local target_path="${2:-}"
  printf '%s' "$OPS_PROJECTOR_B64" \
    | base64 -d \
    | sudo -n php -- "$source_config" "$OPS_CANDIDATE_B64" "$stripped_path" "$target_path"
}

failure_gate=CONFIG_PROJECTIONS
projection_result="$(project_config 2>/dev/null)"
IFS=$'\t' read -r current_ops_section_sha256 stripped_source_sha256 rendered_ops_config_sha256 <<<"$projection_result"
for projection_hash in \
  "$current_ops_section_sha256" \
  "$stripped_source_sha256" \
  "$rendered_ops_config_sha256"; do
  [[ "$projection_hash" =~ ^[0-9a-f]{64}$ ]]
done
stripped_exact_program_section_count=0
stripped_total_section_count=$((config_total_section_count - 1))
stripped_program_section_count=$((config_program_section_count - 1))
test "$stripped_total_section_count" -eq 2
test "$stripped_program_section_count" -eq 2

failure_gate=RUNTIME_CONFIG
config_epoch="$(sudo -n stat -c %Y "$source_config")"
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
  test "$stripped_source_sha256" = "$EXPECTED_STRIPPED_SOURCE_SHA256"
  test "$managed_target_path_sha256" = "$EXPECTED_MANAGED_TARGET_PATH_SHA256"
  test "$managed_target_current_sha256" = "$EXPECTED_MANAGED_TARGET_CURRENT_SHA256"
  test "$rendered_ops_config_sha256" = "$EXPECTED_RENDERED_OPS_CONFIG_SHA256"
  test "$foreign_runtime_fingerprint_sha256" = "$EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256"
  test "$config_exact_program_section_count" = "$EXPECTED_CONFIG_EXACT_PROGRAM_SECTION_COUNT"
  test "$config_total_section_count" = "$EXPECTED_CONFIG_TOTAL_SECTION_COUNT"
  test "$config_foreign_program_section_count" = "$EXPECTED_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT"
  test "$stripped_exact_program_section_count" = "$EXPECTED_STRIPPED_EXACT_PROGRAM_SECTION_COUNT"
  test "$stripped_total_section_count" = "$EXPECTED_STRIPPED_TOTAL_SECTION_COUNT"
  test "$stripped_program_section_count" = "$EXPECTED_STRIPPED_PROGRAM_SECTION_COUNT"
  test "$runtime_cwd_current" = "$EXPECTED_RUNTIME_CWD_CURRENT"
  test "$runtime_config_current" = "$EXPECTED_RUNTIME_CONFIG_CURRENT"

  source_backup="/tmp/fap-queue-ops-split-${CONTROL_RUN_ID}.backup"
  source_candidate="/tmp/fap-queue-ops-split-${CONTROL_RUN_ID}.source"
  target_candidate="/tmp/fap-queue-ops-split-${CONTROL_RUN_ID}.target"
  [[ "$source_backup" =~ ^/tmp/fap-queue-ops-split-[0-9]+\.backup$ ]]
  [[ "$source_candidate" =~ ^/tmp/fap-queue-ops-split-[0-9]+\.source$ ]]
  [[ "$target_candidate" =~ ^/tmp/fap-queue-ops-split-[0-9]+\.target$ ]]
  failure_gate=TEMP_ABSENT
  sudo -n test ! -e "$source_backup"
  sudo -n test ! -e "$source_candidate"
  sudo -n test ! -e "$target_candidate"
  failure_gate=BACKUP
  sudo -n install -o root -g root -m 0600 "$source_config" "$source_backup"
  test "$(sudo -n sha256sum "$source_backup" | awk '{print $1}')" = "$current_config_sha256"
  failure_gate=SPLIT_RENDER
  project_config "$source_candidate" "$target_candidate" >/dev/null 2>&1
  test "$(sudo -n sha256sum "$source_candidate" | awk '{print $1}')" = "$stripped_source_sha256"
  test "$(sudo -n sha256sum "$target_candidate" | awk '{print $1}')" = "$rendered_ops_config_sha256"
  migration_started=true
  failure_gate=SOURCE_INSTALL
  sudo -n install -o root -g root -m 0644 "$source_candidate" "$source_config"
  failure_gate=TARGET_INSTALL
  sudo -n install -o root -g root -m 0644 "$target_candidate" "$managed_target"
  failure_gate=SUPERVISOR_VALIDATE
  sudo -n "$supervisord_path" -t >/dev/null 2>&1
  failure_gate=SUPERVISOR_REREAD
  sudo -n "$supervisorctl_path" reread >/dev/null
  failure_gate=SUPERVISOR_UPDATE
  sudo -n "$supervisorctl_path" update fap-queue-ops >/dev/null

  failure_gate=READBACK_SOURCE
  test "$(sudo -n sha256sum "$source_config" | awk '{print $1}')" = "$stripped_source_sha256"
  failure_gate=READBACK_TARGET
  test "$(sudo -n sha256sum "$managed_target" | awk '{print $1}')" = "$rendered_ops_config_sha256"
  failure_gate=READBACK_STATUS
  status_after="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
  test "$(foreign_runtime_fingerprint <<<"$status_after")" = "$foreign_runtime_fingerprint_sha256"
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

  sudo -n rm -f "$source_backup" "$source_candidate" "$target_candidate"
  migration_started=false
fi

trap - ERR
printf 'active_revision=%s\n' "$active_revision"
printf 'config_path_sha256=%s\n' "$config_path_sha256"
printf 'current_config_sha256=%s\n' "$current_config_sha256"
printf 'current_ops_section_sha256=%s\n' "$current_ops_section_sha256"
printf 'stripped_source_sha256=%s\n' "$stripped_source_sha256"
printf 'managed_target_path_sha256=%s\n' "$managed_target_path_sha256"
printf 'managed_target_current_sha256=%s\n' "$managed_target_current_sha256"
printf 'rendered_ops_config_sha256=%s\n' "$rendered_ops_config_sha256"
printf 'foreign_runtime_fingerprint_sha256=%s\n' "$foreign_runtime_fingerprint_sha256"
printf 'config_exact_program_section_count=%s\n' "$config_exact_program_section_count"
printf 'config_total_section_count=%s\n' "$config_total_section_count"
printf 'config_foreign_program_section_count=%s\n' "$config_foreign_program_section_count"
printf 'stripped_exact_program_section_count=%s\n' "$stripped_exact_program_section_count"
printf 'stripped_total_section_count=%s\n' "$stripped_total_section_count"
printf 'stripped_program_section_count=%s\n' "$stripped_program_section_count"
printf 'runtime_cwd_current=%s\n' "$runtime_cwd_current"
printf 'runtime_config_current=%s\n' "$runtime_config_current"
printf 'ops_pending_total=%s\n' "$ops_pending_total"
printf 'live_process_verified=true\n'
printf 'migration_supported=true\n'
if [ "$MODE" = apply ]; then
  printf 'status=PASS_APPLY\n'
  printf 'production_write_execution=true\n'
  printf 'source_config_write_count=1\n'
  printf 'managed_target_write_count=1\n'
  printf 'worker_restart_count=1\n'
else
  printf 'status=PASS_PREFLIGHT\n'
  printf 'production_write_execution=false\n'
  printf 'source_config_write_count=0\n'
  printf 'managed_target_write_count=0\n'
  printf 'worker_restart_count=0\n'
fi
printf 'rollback_execution_count=0\n'
