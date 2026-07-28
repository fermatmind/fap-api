#!/usr/bin/env bash

set -Eeuo pipefail

mode="${MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_active_revision="${EXPECTED_ACTIVE_REVISION:-}"
expected_target_set_sha256="${EXPECTED_TARGET_SET_SHA256:-}"
expected_state_sha256="${EXPECTED_STATE_SHA256:-}"
expected_config_path_sha256="${EXPECTED_CONFIG_PATH_SHA256:-}"
expected_config_sha256="${EXPECTED_CONFIG_SHA256:-}"
expected_foreign_runtime_sha256="${EXPECTED_FOREIGN_RUNTIME_SHA256:-}"
supervisorctl_path="${SUPERVISORCTL_PATH:-/usr/bin/supervisorctl}"
php_path="${PHP_PATH:-/usr/bin/php}"
sudo_path="${SUDO_PATH:-/usr/bin/sudo}"
program="fap-queue-ops"

fail() {
  printf 'OPS_RUNTIME_CONTROL_FAILED:%s\n' "$1" >&2
  exit 1
}

[[ "$mode" =~ ^(preflight|apply)$ ]] || fail INVALID_MODE
[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_DEPLOY_PATH
[[ "$deploy_path" != *".."* ]] || fail INVALID_DEPLOY_PATH
[[ "$expected_active_revision" =~ ^[0-9a-f]{40}$ ]] || fail INVALID_ACTIVE_REVISION
[[ "$supervisorctl_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_SUPERVISORCTL_PATH
[[ "$php_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_PHP_PATH
[[ "$sudo_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_SUDO_PATH
if [[ "$mode" == apply ]]; then
  [[ "$expected_target_set_sha256" =~ ^[0-9a-f]{64}$ ]] || fail INVALID_TARGET_SET_SHA
  [[ "$expected_state_sha256" =~ ^[0-9a-f]{64}$ ]] || fail INVALID_STATE_SHA
  [[ "$expected_config_path_sha256" =~ ^[0-9a-f]{64}$ ]] || fail INVALID_CONFIG_PATH_SHA
  [[ "$expected_config_sha256" =~ ^[0-9a-f]{64}$ ]] || fail INVALID_CONFIG_SHA
  [[ "$expected_foreign_runtime_sha256" =~ ^[0-9a-f]{64}$ ]] || fail INVALID_FOREIGN_RUNTIME_SHA
else
  [[ -z "$expected_target_set_sha256" ]] || fail UNEXPECTED_TARGET_SET_SHA
  [[ -z "$expected_state_sha256" ]] || fail UNEXPECTED_STATE_SHA
  [[ -z "$expected_config_path_sha256" ]] || fail UNEXPECTED_CONFIG_PATH_SHA
  [[ -z "$expected_config_sha256" ]] || fail UNEXPECTED_CONFIG_SHA
  [[ -z "$expected_foreign_runtime_sha256" ]] || fail UNEXPECTED_FOREIGN_RUNTIME_SHA
fi
[[ -x "$supervisorctl_path" ]] || fail SUPERVISORCTL_UNAVAILABLE

deploy_root="$(readlink -f "$deploy_path")"
current_release="$(readlink -f "$deploy_root/current")"
[[ "$current_release" == "$deploy_root"/releases/* ]] || fail ACTIVE_RELEASE_SCOPE
test -f "$current_release/REVISION" || fail ACTIVE_REVISION_MISSING
active_revision="$(tr -d '\r\n' < "$current_release/REVISION")"
[[ "$active_revision" == "$expected_active_revision" ]] || fail ACTIVE_REVISION_DRIFT
test ! -e "$deploy_root/.dep/deploy.lock" || fail DEPLOY_LOCK_PRESENT
deploy_process_count="$(
  ps -eo comm=,args= | awk '
    $1 == "php" && ($0 ~ /dep\.phar/ || $0 ~ /artisan migrate/ || $0 ~ /queue:reload-workers/) { count++ }
    $1 == "composer" && $0 ~ /install/ { count++ }
    END { print count + 0 }
  '
)"
[[ "$deploy_process_count" == 0 ]] || fail DEPLOY_PROCESS_PRESENT

read_status() {
  local output=""
  local rc=0
  set +e
  output="$("$sudo_path" -n "$supervisorctl_path" status 2>/dev/null)"
  rc=$?
  set -e
  [[ "$rc" -eq 0 || "$rc" -eq 3 ]] || fail SUPERVISOR_STATUS
  printf '%s\n' "$output"
}

discover_config() {
  local grep_path=""
  local candidates=""
  local candidate_count=""
  grep_path="$(command -v grep)"
  candidates="$(
    "$sudo_path" -n find /etc/supervisor /opt/1panel -type f -size -256k \
      \( -name '*.conf' -o -name '*.ini' \) \
      -exec "$grep_path" -lFx '[program:fap-queue-ops]' {} + 2>/dev/null \
      | sort -u
  )"
  candidate_count="$(awk 'NF { count++ } END { print count + 0 }' <<<"$candidates")"
  [[ "$candidate_count" -eq 1 ]] || fail CONFIG_IDENTITY_COUNT
  printf '%s\n' "$candidates"
}

ops_pending_count() {
  "$sudo_path" -n -u www-data \
    "$php_path" -d display_errors=0 -- "$current_release/backend" 2>/dev/null <<'PHP'
<?php
try {
    $base = $argv[1] ?? null;
    if (! is_string($base) || $base === '') {
        throw new RuntimeException('invalid base');
    }
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $connection = Illuminate\Support\Facades\DB::connection();
    $connection->listen(static function ($query): void {
        if (preg_match('/\Aselect\b/i', ltrim((string) $query->sql)) !== 1) {
            throw new RuntimeException('write query refused');
        }
    });
    echo $connection->table('jobs')->where('queue', 'ops')->count(), PHP_EOL;
} catch (Throwable) {
    echo 'PROBE_FAILED', PHP_EOL;
    exit(1);
}
PHP
}

status_before="$(read_status)"
matches_before="$(awk -v prefix="${program}:" '
  index($1, prefix) == 1 || $1 == substr(prefix, 1, length(prefix) - 1) { print }
' <<<"$status_before")"
[[ -n "$matches_before" ]] || fail PROGRAM_MISSING
member_count="$(awk 'NF { count++ } END { print count + 0 }' <<<"$matches_before")"
[[ "$member_count" -eq 1 ]] || fail PROGRAM_MEMBER_COUNT
running_count="$(awk '$2 == "RUNNING" { count++ } END { print count + 0 }' <<<"$matches_before")"
program_state=NOT_RUNNING
[[ "$running_count" -eq "$member_count" ]] && program_state=RUNNING
normalized_target_before="$(awk '{
  pid=""
  for (i=3; i<=NF; i++) {
    if ($i == "pid") {
      pid=$(i+1)
      gsub(/,/, "", pid)
    }
  }
  print $1 "|" $2 "|" pid
}' <<<"$matches_before")"
foreign_runtime_sha256="$(
  awk -v prefix="${program}:" '
    index($1, prefix) != 1 && $1 != substr(prefix, 1, length(prefix) - 1) {
      pid=""
      for (i=3; i<=NF; i++) {
        if ($i == "pid") {
          pid=$(i+1)
          gsub(/,/, "", pid)
        }
      }
      print $1 "|" $2 "|" pid
    }
  ' <<<"$status_before" | sort | sha256sum | awk '{print $1}'
)"

config_path="$(discover_config)"
[[ "$config_path" =~ ^/(etc/supervisor|opt/1panel)(/[A-Za-z0-9._-]+)+\.(conf|ini)$ ]] \
  || fail CONFIG_PATH_SCOPE
[[ "$config_path" != *".."* ]] || fail CONFIG_PATH_SCOPE
config_path_sha256="$(printf '%s' "$config_path" | sha256sum | awk '{print $1}')"
config_sha256="$("$sudo_path" -n sha256sum "$config_path" | awk '{print $1}')"
[[ "$config_sha256" =~ ^[0-9a-f]{64}$ ]] || fail CONFIG_HASH
exact_section_count="$("$sudo_path" -n grep -Fxc '[program:fap-queue-ops]' "$config_path")"
[[ "$exact_section_count" -eq 1 ]] || fail CONFIG_PROGRAM_IDENTITY
total_section_count="$("$sudo_path" -n grep -Ec '^\[[^]]+\][[:space:]]*$' "$config_path")"
[[ "$total_section_count" =~ ^[1-9][0-9]*$ ]] || fail CONFIG_SECTION_COUNT
config_layout=DEDICATED
[[ "$total_section_count" -gt 1 ]] && config_layout=SHARED

set +e
ops_pending_total="$(ops_pending_count)"
probe_rc=$?
set -e
[[ "$probe_rc" -eq 0 && "$ops_pending_total" =~ ^[0-9]+$ ]] || fail QUEUE_PROBE

target_set_sha256="$(printf '%s\n' "$program" | sha256sum | awk '{print $1}')"
state_sha256="$(
  printf '%s\n%s\n%s\n%s\n%s\n%s\n%s\n' \
    "$active_revision" "$target_set_sha256" "$program_state" "$normalized_target_before" \
    "$config_path_sha256" "$config_sha256" "$foreign_runtime_sha256" \
    | sha256sum | awk '{print $1}'
)"

if [[ "$mode" == preflight ]]; then
  recovery_required=false
  [[ "$program_state" != RUNNING ]] && recovery_required=true
  apply_supported=false
  [[ "$recovery_required" == true && "$ops_pending_total" == 0 ]] && apply_supported=true
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$active_revision" "$target_set_sha256" "$state_sha256" "$program_state" \
    "$ops_pending_total" "$config_path_sha256" "$config_sha256" "$config_layout" \
    "$foreign_runtime_sha256" "$recovery_required" "$apply_supported"
  exit 0
fi

[[ "$target_set_sha256" == "$expected_target_set_sha256" ]] || fail TARGET_SET_DRIFT
[[ "$state_sha256" == "$expected_state_sha256" ]] || fail STATE_DRIFT
[[ "$config_path_sha256" == "$expected_config_path_sha256" ]] || fail CONFIG_PATH_DRIFT
[[ "$config_sha256" == "$expected_config_sha256" ]] || fail CONFIG_DRIFT
[[ "$foreign_runtime_sha256" == "$expected_foreign_runtime_sha256" ]] || fail FOREIGN_RUNTIME_DRIFT
[[ "$program_state" == NOT_RUNNING ]] || fail RECOVERY_NOT_REQUIRED
[[ "$ops_pending_total" == 0 ]] || fail OPS_BACKLOG_PRESENT

target="$program"
if awk -v prefix="${program}:" 'index($1, prefix) == 1 { found=1 } END { exit !found }' \
  <<<"$status_before"; then
  target="${program}:*"
fi

restart_succeeded=false
for attempt in 1 2 3; do
  set +e
  "$sudo_path" -n "$supervisorctl_path" restart "$target" >/dev/null 2>&1
  restart_rc=$?
  set -e
  if [[ "$restart_rc" -eq 0 ]]; then
    status_after="$(read_status)"
    if awk -v prefix="${program}:" -v single="$program" '
      (index($1, prefix) == 1 || $1 == single) {
        found=1
        if ($2 != "RUNNING") bad=1
      }
      END { exit !(found && !bad) }
    ' <<<"$status_after"; then
      restart_succeeded=true
      break
    fi
  fi
  [[ "$attempt" -eq 3 ]] || sleep 2
done
[[ "$restart_succeeded" == true ]] || fail PROGRAM_RESTART_FAILED

status_after="$(read_status)"
foreign_after_sha256="$(
  awk -v prefix="${program}:" '
    index($1, prefix) != 1 && $1 != substr(prefix, 1, length(prefix) - 1) {
      pid=""
      for (i=3; i<=NF; i++) {
        if ($i == "pid") {
          pid=$(i+1)
          gsub(/,/, "", pid)
        }
      }
      print $1 "|" $2 "|" pid
    }
  ' <<<"$status_after" | sort | sha256sum | awk '{print $1}'
)"
[[ "$foreign_after_sha256" == "$foreign_runtime_sha256" ]] || fail FOREIGN_RUNTIME_CHANGED
[[ "$(printf '%s' "$(discover_config)" | sha256sum | awk '{print $1}')" == "$config_path_sha256" ]] \
  || fail CONFIG_PATH_CHANGED
[[ "$("$sudo_path" -n sha256sum "$config_path" | awk '{print $1}')" == "$config_sha256" ]] \
  || fail CONFIG_CHANGED
[[ "$(tr -d '\r\n' < "$deploy_root/current/REVISION")" == "$active_revision" ]] \
  || fail ACTIVE_REVISION_CHANGED
test ! -e "$deploy_root/.dep/deploy.lock" || fail DEPLOY_LOCK_CHANGED
[[ "$(ops_pending_count)" == 0 ]] || fail OPS_BACKLOG_CHANGED

after_state_sha256="$(
  printf '%s\n%s\n%s\n%s\n%s\n' \
    "$active_revision" "$target_set_sha256" RUNNING "$config_sha256" "$foreign_after_sha256" \
    | sha256sum | awk '{print $1}'
)"
printf '%s\t%s\t%s\t%s\n' \
  "$active_revision" "$target_set_sha256" "$state_sha256" "$after_state_sha256"
