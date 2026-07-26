#!/usr/bin/env bash

set -euo pipefail

failure_gate=INITIALIZE

on_error() {
  local exit_code=$?
  trap - ERR
  printf 'OPS_SHARED_RESIDUE_RECOVERY_GATE_FAILED:%s\n' "$failure_gate" >&2
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
[[ "${FAILED_RUN_ID:-}" =~ ^[1-9][0-9]*$ ]]
for hash_value in \
  "${EVIDENCE_SOURCE_PATH_SHA256:-}" \
  "${EVIDENCE_SOURCE_ORIGINAL_CONFIG_SHA256:-}" \
  "${EVIDENCE_SOURCE_CURRENT_CONFIG_SHA256:-}" \
  "${EVIDENCE_TARGET_PATH_SHA256:-}" \
  "${EVIDENCE_TARGET_CURRENT_SHA256:-}"; do
  [[ "$hash_value" =~ ^[0-9a-f]{64}$ ]]
done
if [ "$MODE" = apply ]; then
  [[ "${EXPECTED_BACKUP_SHA256:-}" =~ ^[0-9a-f]{64}$ ]]
  [[ "${EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256:-}" =~ ^[0-9a-f]{64}$ ]]
  [[ "${EXPECTED_RESIDUE_SET_SHA256:-}" =~ ^[0-9a-f]{64}$ ]]
  [[ "${EXPECTED_RESIDUE_FILE_COUNT:-}" =~ ^[1-5]$ ]]
  test "${EXPECTED_VALIDATION_PROCESS_STATE:-}" = absent
  test "${EXPECTED_VALIDATION_PROCESS_FINGERPRINT_SHA256:-}" = "$zero_sha256"
  test "${EXPECTED_MIGRATION_PROCESS_STATE:-}" = absent
  test "${EXPECTED_MIGRATION_PROCESS_FINGERPRINT_SHA256:-}" = "$zero_sha256"
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

failure_gate=SUPERVISOR_STATE
supervisorctl_path="$(command -v supervisorctl)"
grep_path="$(command -v grep)"
status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
test "$(awk '$1 ~ /^fap-queue-ops(:|$)/ {count++} END {print count+0}' <<<"$status_lines")" -eq 1
awk '$1 ~ /^fap-queue-ops(:|$)/ {found=1; if ($2 != "RUNNING") bad=1} END {exit !(found && !bad)}' <<<"$status_lines"
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
[[ "$foreign_runtime_fingerprint_sha256" =~ ^[0-9a-f]{64}$ ]]
if [ "$MODE" = apply ]; then
  test "$foreign_runtime_fingerprint_sha256" = "$EXPECTED_FOREIGN_RUNTIME_FINGERPRINT_SHA256"
fi

failure_gate=WORKER_STATE
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
test "$(sha256sum "/proc/$worker_pid/cmdline" | awk '{print $1}')" = "$expected_argv_sha256"
ops_worker_process_count="$(
  ps -eo pid= \
    | awk '{$1=$1; if ($1 ~ /^[1-9][0-9]*$/) print $1}' \
    | while IFS= read -r process_pid; do
        if [ -r "/proc/$process_pid/cmdline" ] \
          && [ "$(sha256sum "/proc/$process_pid/cmdline" 2>/dev/null | awk '{print $1}')" = "$expected_argv_sha256" ]; then
          printf '.'
        fi
      done \
    | wc -c
)"
test "$ops_worker_process_count" = 1
ops_worker_pid_sha256="$(printf '%s' "$worker_pid" | sha256sum | awk '{print $1}')"

failure_gate=QUEUE_PROBE
queue_probe_php='try { $base=$argv[1]; require $base."/vendor/autoload.php"; $app=require $base."/bootstrap/app.php"; $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); $conn=Illuminate\Support\Facades\DB::connection(); $conn->listen(function($query){$sql=ltrim(strtolower((string)$query->sql)); if(!str_starts_with($sql,"select")&&!str_starts_with($sql,"show")&&!str_starts_with($sql,"describe")&&!str_starts_with($sql,"explain")&&!str_starts_with($sql,"pragma")){throw new RuntimeException("write query refused");}}); echo (clone $conn->table("jobs")->where("queue","ops"))->count(),PHP_EOL; } catch (Throwable $e) { echo "PROBE_FAILED",PHP_EOL; exit(1); }'
ops_pending_total="$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)"
test "$ops_pending_total" = 0

failure_gate=CONFIG_DISCOVERY
mapfile -t config_candidates < <(
  sudo -n find /etc/supervisor /opt/1panel -type f \
    \( -name '*.conf' -o -name '*.ini' \) -size -128k \
    -exec "$grep_path" -lFx '[program:fap-queue-ops]' {} + 2>/dev/null \
    | sort -u
)
test "${#config_candidates[@]}" -eq 1
target_current_path="${config_candidates[0]}"
test "$target_current_path" = "$target_path"
target_path_sha256="$(printf '%s' "$target_path" | sha256sum | awk '{print $1}')"
target_current_sha256="$(sudo -n sha256sum "$target_path" | awk '{print $1}')"
test "$target_path_sha256" = "$EVIDENCE_TARGET_PATH_SHA256"
test "$target_current_sha256" = "$EVIDENCE_TARGET_CURRENT_SHA256"
test "$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$target_path")" -eq 1
test "$(sudo -n "$grep_path" -Ec '^\[[^]]+\][[:space:]]*$' "$target_path")" -eq 1
test "$(sudo -n "$grep_path" -Ec '^\[program:[^]]+\][[:space:]]*$' "$target_path")" -eq 1

mapfile -t source_candidates < <(
  while IFS= read -r candidate; do
    if [ "$candidate" != "$target_path" ] \
      && [ "$(sudo -n sha256sum "$candidate" | awk '{print $1}')" = "$EVIDENCE_SOURCE_CURRENT_CONFIG_SHA256" ]; then
      printf '%s\n' "$candidate"
    fi
  done < <(
    sudo -n find /etc/supervisor /opt/1panel -type f \
      \( -name '*.conf' -o -name '*.ini' \) -size -128k -print 2>/dev/null \
      | sort -u
  )
)
test "${#source_candidates[@]}" -eq 1
source_path="${source_candidates[0]}"
[[ "$source_path" =~ ^/(etc/supervisor|opt/1panel)(/[A-Za-z0-9._-]+)+\.(conf|ini)$ ]]
source_path_sha256="$(printf '%s' "$source_path" | sha256sum | awk '{print $1}')"
source_original_config_sha256="$EVIDENCE_SOURCE_ORIGINAL_CONFIG_SHA256"
source_current_config_sha256="$(sudo -n sha256sum "$source_path" | awk '{print $1}')"
test "$source_path_sha256" = "$EVIDENCE_SOURCE_PATH_SHA256"
test "$source_current_config_sha256" = "$EVIDENCE_SOURCE_CURRENT_CONFIG_SHA256"
test "$(sudo -n "$grep_path" -Fxc '[program:fap-queue-ops]' "$source_path")" -eq 0

failure_gate=BACKUP
backup_path="$DEPLOY_PATH/shared/ops-supervisor-migration-backups/shared-source-${FAILED_RUN_ID}.conf"
[[ "$backup_path" =~ ^/[A-Za-z0-9._/-]+/shared/ops-supervisor-migration-backups/shared-source-[0-9]+\.conf$ ]]
sudo -n test -f "$backup_path"
backup_sha256="$(sudo -n sha256sum "$backup_path" | awk '{print $1}')"
test "$backup_sha256" = "$source_original_config_sha256"

failure_gate=RESIDUE_SET
residue_prefix="/tmp/fap-ops-shared-migration-${FAILED_RUN_ID}"
allowed_residue_paths=(
  "${residue_prefix}.source"
  "${residue_prefix}.target"
  "${residue_prefix}.supervisord.conf"
  "${residue_prefix}.log"
  "${residue_prefix}.pid"
)
mapfile -t discovered_residue_paths < <(
  sudo -n find /tmp -maxdepth 1 -type f \
    -name "fap-ops-shared-migration-${FAILED_RUN_ID}.*" -print \
    | sort
)
test "${#discovered_residue_paths[@]}" -ge 1
test "${#discovered_residue_paths[@]}" -le 5
residue_manifest=''
for discovered_path in "${discovered_residue_paths[@]}"; do
  allowed=false
  for allowed_path in "${allowed_residue_paths[@]}"; do
    if [ "$discovered_path" = "$allowed_path" ]; then
      allowed=true
      break
    fi
  done
  test "$allowed" = true
  sudo -n test -f "$discovered_path"
  residue_manifest+="$(basename "$discovered_path")"
  residue_manifest+=$'\t'
  residue_manifest+="$(sudo -n sha256sum "$discovered_path" | awk '{print $1}')"
  residue_manifest+=$'\n'
done
residue_file_count="${#discovered_residue_paths[@]}"
residue_set_sha256="$(printf '%s' "$residue_manifest" | sha256sum | awk '{print $1}')"

failure_gate=VALIDATION_PROCESS_ABSENT
validation_root="${residue_prefix}.supervisord.conf"
validation_process_count="$(
  ps -eo comm=,args= \
    | awk -v validation_root="$validation_root" '
      ($1 == "supervisord" || $1 ~ /^python([0-9.]*)?$/) \
        && index($0, validation_root) > 0 {count++}
      END {print count+0}
    '
)"
test "$validation_process_count" = 0
validation_process_state=absent
validation_process_fingerprint_sha256="$zero_sha256"

failure_gate=MIGRATION_PROCESS_ABSENT
migration_process_count="$(
  ps -eo comm=,args= \
    | awk -v residue_prefix="$residue_prefix" '
      ($1 == "bash" || $1 == "sh" || $1 == "supervisord" || $1 ~ /^python([0-9.]*)?$/) \
        && index($0, residue_prefix) > 0 {count++}
      END {print count+0}
    '
)"
test "$migration_process_count" = 0
migration_process_state=absent
migration_process_fingerprint_sha256="$zero_sha256"

deleted_residue_file_count=0
if [ "$MODE" = apply ]; then
  failure_gate=APPLY_RECEIPT_BINDING
  test "$backup_sha256" = "$EXPECTED_BACKUP_SHA256"
  test "$residue_set_sha256" = "$EXPECTED_RESIDUE_SET_SHA256"
  test "$residue_file_count" = "$EXPECTED_RESIDUE_FILE_COUNT"
  test "$validation_process_state" = "$EXPECTED_VALIDATION_PROCESS_STATE"
  test "$validation_process_fingerprint_sha256" = "$EXPECTED_VALIDATION_PROCESS_FINGERPRINT_SHA256"
  test "$migration_process_state" = "$EXPECTED_MIGRATION_PROCESS_STATE"
  test "$migration_process_fingerprint_sha256" = "$EXPECTED_MIGRATION_PROCESS_FINGERPRINT_SHA256"

  failure_gate=DELETE_EXACT_RESIDUE
  for discovered_path in "${discovered_residue_paths[@]}"; do
    sudo -n rm -f -- "$discovered_path"
    deleted_residue_file_count=$((deleted_residue_file_count + 1))
  done

  failure_gate=POST_DELETE_READBACK
  test "$deleted_residue_file_count" = "$EXPECTED_RESIDUE_FILE_COUNT"
  test -z "$(
    sudo -n find /tmp -maxdepth 1 -type f \
      -name "fap-ops-shared-migration-${FAILED_RUN_ID}.*" -print -quit
  )"
  test "$(
    ps -eo comm=,args= \
      | awk -v validation_root="$validation_root" '
        ($1 == "supervisord" || $1 ~ /^python([0-9.]*)?$/) \
          && index($0, validation_root) > 0 {count++}
        END {print count+0}
      '
  )" = 0
  test "$(
    ps -eo comm=,args= \
      | awk -v residue_prefix="$residue_prefix" '
        ($1 == "bash" || $1 == "sh" || $1 == "supervisord" || $1 ~ /^python([0-9.]*)?$/) \
          && index($0, residue_prefix) > 0 {count++}
        END {print count+0}
      '
  )" = 0
  test "$(tr -d '\r\n' < "$DEPLOY_PATH/current/REVISION")" = "$EXPECTED_ACTIVE_REVISION"
  test "$(sudo -n sha256sum "$source_path" | awk '{print $1}')" = "$source_current_config_sha256"
  test "$(sudo -n sha256sum "$target_path" | awk '{print $1}')" = "$target_current_sha256"
  test "$(sudo -n sha256sum "$backup_path" | awk '{print $1}')" = "$backup_sha256"
  post_status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null)"
  test "$(
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
    ' <<<"$post_status_lines" \
      | sort \
      | sha256sum \
      | awk '{print $1}'
  )" = "$foreign_runtime_fingerprint_sha256"
  post_worker_pid="$(sudo -n "$supervisorctl_path" pid fap-queue-ops:fap-queue-ops_00 2>/dev/null)"
  test "$post_worker_pid" = "$worker_pid"
  test "$(sudo -n -u www-data php -d display_errors=0 -r "$queue_probe_php" "$current" 2>/dev/null)" = 0
fi

trap - ERR
printf 'active_revision=%s\n' "$active_revision"
printf 'failed_run_id=%s\n' "$FAILED_RUN_ID"
printf 'source_path_sha256=%s\n' "$source_path_sha256"
printf 'source_original_config_sha256=%s\n' "$source_original_config_sha256"
printf 'source_current_config_sha256=%s\n' "$source_current_config_sha256"
printf 'target_path_sha256=%s\n' "$target_path_sha256"
printf 'target_current_sha256=%s\n' "$target_current_sha256"
printf 'backup_sha256=%s\n' "$backup_sha256"
printf 'foreign_runtime_fingerprint_sha256=%s\n' "$foreign_runtime_fingerprint_sha256"
printf 'ops_worker_pid_sha256=%s\n' "$ops_worker_pid_sha256"
printf 'ops_worker_process_count=%s\n' "$ops_worker_process_count"
printf 'ops_pending_total=%s\n' "$ops_pending_total"
printf 'residue_file_count=%s\n' "$residue_file_count"
printf 'residue_set_sha256=%s\n' "$residue_set_sha256"
printf 'validation_process_state=%s\n' "$validation_process_state"
printf 'validation_process_fingerprint_sha256=%s\n' "$validation_process_fingerprint_sha256"
printf 'migration_process_state=%s\n' "$migration_process_state"
printf 'migration_process_fingerprint_sha256=%s\n' "$migration_process_fingerprint_sha256"
printf 'deleted_residue_file_count=%s\n' "$deleted_residue_file_count"
if [ "$MODE" = apply ]; then
  printf 'status=PASS_APPLY\n'
  printf 'production_write_execution=true\n'
else
  printf 'status=PASS_PREFLIGHT\n'
  printf 'production_write_execution=false\n'
fi
printf 'source_config_write_count=0\n'
printf 'target_config_write_count=0\n'
printf 'backup_write_count=0\n'
printf 'worker_restart_count=0\n'
printf 'validation_process_stop_count=0\n'
printf 'application_deploy_count=0\n'
printf 'symlink_write_count=0\n'
printf 'application_migration_count=0\n'
printf 'cms_or_database_authority_write_count=0\n'
printf 'publication_or_discoverability_write_count=0\n'
