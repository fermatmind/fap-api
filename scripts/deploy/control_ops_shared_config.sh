#!/usr/bin/env bash

set -euo pipefail

failure_gate=INITIALIZE

on_error() {
  local exit_code=$?
  trap - ERR
  printf 'OPS_SHARED_MIGRATION_GATE_FAILED:%s\n' "$failure_gate" >&2
  exit "$exit_code"
}

trap on_error ERR

zero_sha256="$(printf '0%.0s' {1..64})"
target_path=/etc/supervisor/conf.d/fap-queue-ops.conf

failure_gate=INPUTS
[[ "${MODE:-}" =~ ^(preflight|apply)$ ]]
[[ "${DEPLOY_PATH:-}" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$DEPLOY_PATH" != *".."* ]]
[[ "${EXPECTED_ACTIVE_REVISION:-}" =~ ^[0-9a-f]{40}$ ]]
[[ "${OPS_CANDIDATE_B64:-}" =~ ^[A-Za-z0-9+/=]+$ ]]
[[ "${OPS_PROJECTOR_B64:-}" =~ ^[A-Za-z0-9+/=]+$ ]]
for hash_value in \
  "${EVIDENCE_CONFIG_PATH_SHA256:-}" \
  "${EVIDENCE_CURRENT_CONFIG_SHA256:-}" \
  "${EVIDENCE_MANAGED_TARGET_PATH_SHA256:-}" \
  "${EVIDENCE_MANAGED_TARGET_CURRENT_SHA256:-}" \
  "${EVIDENCE_STRIPPED_SOURCE_SHA256:-}" \
  "${EVIDENCE_RENDERED_OPS_SHA256:-}" \
  "${EVIDENCE_FOREIGN_RUNTIME_FINGERPRINT_SHA256:-}"; do
  [[ "$hash_value" =~ ^[0-9a-f]{64}$ ]]
done
[[ "${EVIDENCE_CONFIG_EXACT_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
[[ "${EVIDENCE_CONFIG_TOTAL_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
[[ "${EVIDENCE_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
[[ "${EVIDENCE_STRIPPED_EXACT_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
[[ "${EVIDENCE_STRIPPED_TOTAL_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
[[ "${EVIDENCE_STRIPPED_PROGRAM_SECTION_COUNT:-}" =~ ^[0-9]+$ ]]
if [ "$MODE" = apply ]; then
  [[ "${CONTROL_RUN_ID:-}" =~ ^[0-9]+$ ]]
  for hash_value in \
    "${EXPECTED_SOURCE_PATH_SHA256:-}" \
    "${EXPECTED_SOURCE_CONFIG_SHA256:-}" \
    "${EXPECTED_STRIPPED_SOURCE_SHA256:-}" \
    "${EXPECTED_TARGET_PATH_SHA256:-}" \
    "${EXPECTED_TARGET_CURRENT_SHA256:-}" \
    "${EXPECTED_RENDERED_OPS_SHA256:-}" \
    "${EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256:-}"; do
    [[ "$hash_value" =~ ^[0-9a-f]{64}$ ]]
  done
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

failure_gate=SUPERVISOR_PATHS
supervisorctl_path="$(command -v supervisorctl)"
python3_path="$(command -v python3)"
grep_path="$(command -v grep)"

validate_supervisor_config() {
  local config_path="$1"

  sudo -n "$python3_path" - "$config_path" >/dev/null 2>&1 <<'PY'
import sys

from supervisor.options import ServerOptions

if len(sys.argv) != 2:
    raise SystemExit(2)

ServerOptions().realize(args=["-c", sys.argv[1]])
PY
}

foreign_runtime_fingerprint() {
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
  ' | sort | sha256sum | awk '{print $1}'
}

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
test "$worker_cwd" = "$(readlink -f "$current")"
runtime_cwd_current=true
failure_gate=WORKER_ARGV
expected_argv_sha256="$(
  printf '%s\0' \
    /usr/bin/php artisan queue:work database --queue=ops --sleep=1 --tries=3 \
    --timeout=120 --max-time=3600 \
    | sha256sum | awk '{print $1}'
)"
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
source_path="${config_candidates[0]}"
[[ "$source_path" =~ ^/(etc/supervisor|opt/1panel)(/[A-Za-z0-9._-]+)+\.(conf|ini)$ ]]
[[ "$source_path" != *".."* ]]
test "$source_path" != "$target_path"
source_path_sha256="$(printf '%s' "$source_path" | sha256sum | awk '{print $1}')"
source_config_sha256="$(sudo -n sha256sum "$source_path" | awk '{print $1}')"
target_path_sha256="$(printf '%s' "$target_path" | sha256sum | awk '{print $1}')"
target_current_sha256="$zero_sha256"
sudo -n test ! -e "$target_path"

failure_gate=CONFIG_LAYOUT
config_exact_program_section_count="$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$source_path")"
config_total_section_count="$(sudo -n "$grep_path" -Ec '^\[[^]]+\][[:space:]]*$' "$source_path")"
config_program_section_count="$(sudo -n "$grep_path" -Ec '^\[program:[^]]+\][[:space:]]*$' "$source_path")"
config_foreign_program_section_count=$((config_program_section_count - config_exact_program_section_count))
test "$config_exact_program_section_count" -eq 1
test "$config_total_section_count" -eq 3
test "$config_program_section_count" -eq 3
test "$config_foreign_program_section_count" -eq 2

project_source() {
  local output_path="${1:-}"

  printf '%s' "$OPS_PROJECTOR_B64" \
    | base64 -d \
    | sudo -n php -- "$source_path" "$OPS_CANDIDATE_B64" "$output_path"
}

failure_gate=CONFIG_PROJECTION
projection_result="$(project_source 2>/dev/null)"
IFS=$'\t' read -r stripped_source_sha256 rendered_ops_sha256 <<<"$projection_result"
[[ "$stripped_source_sha256" =~ ^[0-9a-f]{64}$ ]]
[[ "$rendered_ops_sha256" =~ ^[0-9a-f]{64}$ ]]
stripped_exact_program_section_count=0
stripped_total_section_count=2
stripped_program_section_count=2

failure_gate=RUNTIME_CONFIG
config_epoch="$(sudo -n stat -c %Y "$source_path")"
boot_epoch="$(awk '$1 == "btime" {print $2}' /proc/stat)"
start_ticks="$(awk '{print $22}' "/proc/$worker_pid/stat")"
clock_ticks="$(getconf CLK_TCK)"
process_epoch=$((boot_epoch + start_ticks / clock_ticks))
test "$process_epoch" -ge "$config_epoch"
runtime_config_current=true

failure_gate=QUEUE_PROBE
queue_probe_php='try { $base=$argv[1]; require $base."/vendor/autoload.php"; $app=require $base."/bootstrap/app.php"; $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); $conn=Illuminate\Support\Facades\DB::connection(); $conn->listen(function($query){$sql=ltrim(strtolower((string)$query->sql)); if(!str_starts_with($sql,"select")&&!str_starts_with($sql,"show")&&!str_starts_with($sql,"describe")&&!str_starts_with($sql,"explain")&&!str_starts_with($sql,"pragma")){throw new RuntimeException("write query refused");}}); echo (clone $conn->table("jobs")->where("queue","ops"))->count(),PHP_EOL; } catch (Throwable $e) { echo "PROBE_FAILED",PHP_EOL; exit(1); }'
ops_pending_total="$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)"
test "$ops_pending_total" = 0

failure_gate=V5_EVIDENCE_BINDING
test "$source_path_sha256" = "$EVIDENCE_CONFIG_PATH_SHA256"
test "$source_config_sha256" = "$EVIDENCE_CURRENT_CONFIG_SHA256"
test "$target_path_sha256" = "$EVIDENCE_MANAGED_TARGET_PATH_SHA256"
test "$target_current_sha256" = "$EVIDENCE_MANAGED_TARGET_CURRENT_SHA256"
test "$stripped_source_sha256" = "$EVIDENCE_STRIPPED_SOURCE_SHA256"
test "$rendered_ops_sha256" = "$EVIDENCE_RENDERED_OPS_SHA256"
test "$foreign_runtime_fingerprint_sha256" = "$EVIDENCE_FOREIGN_RUNTIME_FINGERPRINT_SHA256"
test "$config_exact_program_section_count" = "$EVIDENCE_CONFIG_EXACT_PROGRAM_SECTION_COUNT"
test "$config_total_section_count" = "$EVIDENCE_CONFIG_TOTAL_SECTION_COUNT"
test "$config_foreign_program_section_count" = "$EVIDENCE_CONFIG_FOREIGN_PROGRAM_SECTION_COUNT"
test "$stripped_exact_program_section_count" = "$EVIDENCE_STRIPPED_EXACT_PROGRAM_SECTION_COUNT"
test "$stripped_total_section_count" = "$EVIDENCE_STRIPPED_TOTAL_SECTION_COUNT"
test "$stripped_program_section_count" = "$EVIDENCE_STRIPPED_PROGRAM_SECTION_COUNT"

backup_sha256="$zero_sha256"
post_source_sha256="$source_config_sha256"
post_target_sha256="$target_current_sha256"
post_foreign_runtime_fingerprint_sha256="$foreign_runtime_fingerprint_sha256"

if [ "$MODE" = apply ]; then
  failure_gate=APPLY_RECEIPT_BINDING
  test "$source_path_sha256" = "$EXPECTED_SOURCE_PATH_SHA256"
  test "$source_config_sha256" = "$EXPECTED_SOURCE_CONFIG_SHA256"
  test "$stripped_source_sha256" = "$EXPECTED_STRIPPED_SOURCE_SHA256"
  test "$target_path_sha256" = "$EXPECTED_TARGET_PATH_SHA256"
  test "$target_current_sha256" = "$EXPECTED_TARGET_CURRENT_SHA256"
  test "$rendered_ops_sha256" = "$EXPECTED_RENDERED_OPS_SHA256"
  test "$foreign_runtime_fingerprint_sha256" = "$EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256"

  backup_dir="$DEPLOY_PATH/shared/ops-supervisor-migration-backups"
  backup_path="$backup_dir/shared-source-${CONTROL_RUN_ID}.conf"
  stripped_candidate="/tmp/fap-ops-shared-migration-${CONTROL_RUN_ID}.source"
  target_candidate="/tmp/fap-ops-shared-migration-${CONTROL_RUN_ID}.target"
  validation_root="/tmp/fap-ops-shared-migration-${CONTROL_RUN_ID}.supervisord.conf"
  validation_log="/tmp/fap-ops-shared-migration-${CONTROL_RUN_ID}.log"
  validation_pid="/tmp/fap-ops-shared-migration-${CONTROL_RUN_ID}.pid"
  [[ "$backup_path" =~ ^/[A-Za-z0-9._/-]+/shared/ops-supervisor-migration-backups/shared-source-[0-9]+\.conf$ ]]
  for temporary_path in \
    "$stripped_candidate" "$target_candidate" "$validation_root" \
    "$validation_log" "$validation_pid"; do
    [[ "$temporary_path" =~ ^/tmp/fap-ops-shared-migration-[0-9]+\.[A-Za-z0-9.]+$ ]]
    sudo -n test ! -e "$temporary_path"
  done

  failure_gate=BACKUP
  sudo -n install -d -o root -g root -m 0700 "$backup_dir"
  sudo -n test ! -e "$backup_path"
  sudo -n install -o root -g root -m 0600 "$source_path" "$backup_path"
  backup_sha256="$(sudo -n sha256sum "$backup_path" | awk '{print $1}')"
  test "$backup_sha256" = "$source_config_sha256"

  failure_gate=CANDIDATE_RENDER
  project_source "$stripped_candidate" >/dev/null 2>&1
  printf '%s' "$OPS_CANDIDATE_B64" | base64 -d | sudo -n tee "$target_candidate" >/dev/null
  sudo -n chmod 0600 "$stripped_candidate" "$target_candidate"
  test "$(sudo -n sha256sum "$stripped_candidate" | awk '{print $1}')" = "$stripped_source_sha256"
  test "$(sudo -n sha256sum "$target_candidate" | awk '{print $1}')" = "$rendered_ops_sha256"

  failure_gate=CANDIDATE_SET_VALIDATE
  printf '%s\n' \
    '[supervisord]' \
    "logfile=$validation_log" \
    "pidfile=$validation_pid" \
    'childlogdir=/tmp' \
    'nodaemon=true' \
    '[include]' \
    "files=$stripped_candidate $target_candidate" \
    | sudo -n tee "$validation_root" >/dev/null
  sudo -n chmod 0600 "$validation_root"
  validate_supervisor_config "$validation_root"

  failure_gate=SOURCE_INSTALL
  sudo -n install -o root -g root -m 0644 "$stripped_candidate" "$source_path"
  failure_gate=TARGET_INSTALL
  sudo -n install -o root -g root -m 0644 "$target_candidate" "$target_path"
  failure_gate=LIVE_CONFIG_VALIDATE
  validate_supervisor_config /etc/supervisor/supervisord.conf
  failure_gate=SUPERVISOR_REREAD
  sudo -n "$supervisorctl_path" reread >/dev/null
  failure_gate=SUPERVISOR_UPDATE
  sudo -n "$supervisorctl_path" update fap-queue-ops >/dev/null

  failure_gate=POST_CONFIG_READBACK
  post_source_sha256="$(sudo -n sha256sum "$source_path" | awk '{print $1}')"
  post_target_sha256="$(sudo -n sha256sum "$target_path" | awk '{print $1}')"
  test "$post_source_sha256" = "$stripped_source_sha256"
  test "$post_target_sha256" = "$rendered_ops_sha256"
  test "$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$source_path")" -eq 0
  test "$(sudo -n "$grep_path" -Ec '^\[[^]]+\][[:space:]]*$' "$source_path")" -eq 2
  test "$(sudo -n "$grep_path" -Ec '^\[program:[^]]+\][[:space:]]*$' "$source_path")" -eq 2
  test "$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$target_path")" -eq 1
  test "$(sudo -n "$grep_path" -Ec '^\[[^]]+\][[:space:]]*$' "$target_path")" -eq 1

  failure_gate=POST_SUPERVISOR_READBACK
  status_after="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
  post_foreign_runtime_fingerprint_sha256="$(foreign_runtime_fingerprint <<<"$status_after")"
  test "$post_foreign_runtime_fingerprint_sha256" = "$foreign_runtime_fingerprint_sha256"
  test "$(awk '$1 ~ /^fap-queue-ops(:|$)/ {count++} END {print count+0}' <<<"$status_after")" -eq 1
  awk '$1 ~ /^fap-queue-ops(:|$)/ {found=1; if ($2 != "RUNNING") bad=1} END {exit !(found && !bad)}' <<<"$status_after"

  failure_gate=POST_WORKER_READBACK
  readback_pid="$(sudo -n "$supervisorctl_path" pid fap-queue-ops:fap-queue-ops_00 2>/dev/null)"
  [[ "$readback_pid" =~ ^[1-9][0-9]*$ ]]
  test "$readback_pid" != "$worker_pid"
  test "$(ps -o user= -p "$readback_pid" | awk '{$1=$1; print}')" = www-data
  test "$(sudo -n -u www-data readlink -f "/proc/$readback_pid/cwd")" = "$(readlink -f "$current")"
  test "$(sha256sum "/proc/$readback_pid/cmdline" | awk '{print $1}')" = "$expected_argv_sha256"
  test "$(tr -d '\r\n' < "$DEPLOY_PATH/current/REVISION")" = "$EXPECTED_ACTIVE_REVISION"
  test ! -e "$DEPLOY_PATH/.dep/deploy.lock"
  test "$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)" = 0

  sudo -n rm -f \
    "$stripped_candidate" "$target_candidate" "$validation_root" \
    "$validation_log" "$validation_pid"
fi

trap - ERR
printf 'active_revision=%s\n' "$active_revision"
printf 'source_path_sha256=%s\n' "$source_path_sha256"
printf 'source_config_sha256=%s\n' "$source_config_sha256"
printf 'stripped_source_sha256=%s\n' "$stripped_source_sha256"
printf 'target_path_sha256=%s\n' "$target_path_sha256"
printf 'target_current_sha256=%s\n' "$target_current_sha256"
printf 'rendered_ops_sha256=%s\n' "$rendered_ops_sha256"
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
printf 'backup_sha256=%s\n' "$backup_sha256"
printf 'post_source_sha256=%s\n' "$post_source_sha256"
printf 'post_target_sha256=%s\n' "$post_target_sha256"
printf 'post_foreign_runtime_fingerprint_sha256=%s\n' "$post_foreign_runtime_fingerprint_sha256"
if [ "$MODE" = apply ]; then
  printf 'status=PASS_APPLY\n'
  printf 'production_write_execution=true\n'
  printf 'source_config_write_count=1\n'
  printf 'target_config_write_count=1\n'
  printf 'backup_write_count=1\n'
  printf 'worker_restart_count=1\n'
  printf 'migration_count=1\n'
else
  printf 'status=PASS_PREFLIGHT\n'
  printf 'production_write_execution=false\n'
  printf 'source_config_write_count=0\n'
  printf 'target_config_write_count=0\n'
  printf 'backup_write_count=0\n'
  printf 'worker_restart_count=0\n'
  printf 'migration_count=0\n'
fi
printf 'automatic_rollback_count=0\n'
