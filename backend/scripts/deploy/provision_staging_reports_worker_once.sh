#!/usr/bin/env bash

set -Eeuo pipefail

deploy_path="${DEPLOY_PATH:-}"
supervisorctl_path='/usr/bin/supervisorctl'
config_root='/etc/supervisor'
config_dir='/etc/supervisor/conf.d'
config_path="${config_dir}/fap-queue-reports.conf"

fail() {
  printf 'STAGING_REPORTS_PROVISION_FAILED:%s\n' "$1" >&2
  exit 1
}

[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_DEPLOY_PATH
[[ "$deploy_path" != *'..'* ]] || fail INVALID_DEPLOY_PATH
[[ -x /usr/bin/php ]] || fail PHP_UNAVAILABLE
sudo -n true >/dev/null 2>&1 || fail SUDO_UNAVAILABLE

supervisor_installed=false
if [[ ! -x "$supervisorctl_path" ]]; then
  [[ -x /usr/bin/apt-get ]] || fail SUPERVISOR_INSTALL_UNAVAILABLE
  sudo -n /usr/bin/apt-get update -qq || fail SUPERVISOR_APT_UPDATE
  sudo -n env DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y -qq --no-install-recommends supervisor \
    >/dev/null || fail SUPERVISOR_APT_INSTALL
  supervisor_installed=true
fi
[[ -x "$supervisorctl_path" ]] || fail SUPERVISORCTL_UNAVAILABLE
[[ -x /usr/bin/systemctl ]] || fail SYSTEMCTL_UNAVAILABLE
sudo -n /usr/bin/systemctl enable --now supervisor >/dev/null || fail SUPERVISOR_SERVICE_START

deploy_root="$(readlink -f "$deploy_path")"
current_backend="$(readlink -f "$deploy_root/current/backend")"
[[ "$current_backend" == "$deploy_root"/releases/*/backend ]] || fail CURRENT_BACKEND_SCOPE
[[ -r "$current_backend/artisan" ]] || fail ARTISAN_UNREADABLE
shared_log_dir="$deploy_root/shared/backend/storage/logs"
[[ -d "$shared_log_dir" ]] || fail SHARED_LOG_MISSING
sudo -n -u www-data -- test -w "$shared_log_dir" || fail SHARED_LOG_UNWRITABLE
[[ -d "$config_dir" ]] || fail SUPERVISOR_CONFIG_DIR_MISSING

candidate="$(mktemp "${TMPDIR:-/tmp}/fap-queue-reports.XXXXXX")"
trap 'rm -f -- "$candidate"' EXIT
chmod 600 "$candidate"

cat > "$candidate" <<EOF
[program:fap-queue-reports]
directory=${deploy_root}/current/backend
command=/usr/bin/php artisan queue:work database_reports --queue=reports --sleep=1 --tries=3 --timeout=180 --max-time=3600
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
startsecs=1
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=${shared_log_dir}/fap-queue-reports.log
stopwaitsecs=210
EOF

mapfile -t existing_program_files < <(
  sudo -n grep -R -l -F -x --include='*.conf' '[program:fap-queue-reports]' "$config_root" 2>/dev/null || true
)
if (( ${#existing_program_files[@]} > 1 )); then
  fail DUPLICATE_PROGRAM
fi
if (( ${#existing_program_files[@]} == 1 )) && [[ "${existing_program_files[0]}" != "$config_path" ]]; then
  fail PROGRAM_OUTSIDE_MANAGED_PATH
fi

installed=false
if sudo -n test -e "$config_path"; then
  sudo -n cmp -s "$candidate" "$config_path" || fail CONFIG_DRIFT
else
  sudo -n install -o root -g root -m 0644 "$candidate" "$config_path"
  installed=true
fi

queue_probe() {
  local mode="$1"
  local started_at="$2"

  sudo -n -u www-data -- /usr/bin/php -d display_errors=0 -- \
    "$current_backend" "$mode" "$started_at" <<'PHP'
<?php

declare(strict_types=1);

try {
    $backendRoot = $argv[1] ?? null;
    $mode = $argv[2] ?? null;
    $startedAt = $argv[3] ?? null;
    if (! is_string($backendRoot) || $backendRoot === ''
        || ! in_array($mode, ['before', 'after'], true)
        || ! is_string($startedAt) || ! ctype_digit($startedAt)) {
        throw new RuntimeException('invalid probe arguments');
    }

    require $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $reports = config('queue.connections.database_reports');
    if (! is_array($reports)
        || (string) ($reports['driver'] ?? '') !== 'database'
        || (string) ($reports['queue'] ?? '') !== 'reports') {
        throw new RuntimeException('reports queue drift');
    }

    $pending = (int) Illuminate\Support\Facades\DB::table('report_snapshots')
        ->where('status', 'pending')
        ->count();
    $depth = Illuminate\Support\Facades\Queue::connection('database_reports')->size('reports');
    if (! is_int($depth) && ! ctype_digit((string) $depth)) {
        throw new RuntimeException('invalid reports depth');
    }

    if ($mode === 'before') {
        echo $pending, ' ', (int) $depth, PHP_EOL;
        exit(0);
    }

    $readySince = (int) Illuminate\Support\Facades\DB::table('report_snapshots')
        ->where('status', 'ready')
        ->where('updated_at', '>=', date('Y-m-d H:i:s', (int) $startedAt))
        ->count();
    echo $pending, ' ', $readySince, ' ', (int) $depth, PHP_EOL;
} catch (Throwable) {
    exit(1);
}
PHP
}

read -r pending_before reports_before < <(queue_probe before 0) || fail BACKLOG_PROBE
[[ "$pending_before" =~ ^[0-9]+$ && "$reports_before" =~ ^[0-9]+$ ]] || fail BACKLOG_PROBE

started_at="$(date -u +%s)"
sudo -n "$supervisorctl_path" reread >/dev/null || fail SUPERVISOR_REREAD
sudo -n "$supervisorctl_path" update >/dev/null || fail SUPERVISOR_UPDATE
sudo -n "$supervisorctl_path" restart 'fap-queue-reports:*' >/dev/null || fail SUPERVISOR_RESTART

status_lines="$(sudo -n "$supervisorctl_path" status 2>/dev/null || true)"
member_count="$(awk '$1 ~ /^fap-queue-reports(:|$)/ {count++} END {print count+0}' <<<"$status_lines")"
running_count="$(awk '$1 ~ /^fap-queue-reports(:|$)/ && $2 == "RUNNING" {count++} END {print count+0}' <<<"$status_lines")"
[[ "$member_count" == 1 && "$running_count" == 1 ]] || fail REPORTS_WORKER_NOT_RUNNING

deadline=$((SECONDS + 90))
converged=false
pending_after=0
ready_since=0
reports_after=0
while (( SECONDS <= deadline )); do
  if read -r pending_after ready_since reports_after < <(queue_probe after "$started_at"); then
    if [[ "$pending_after" =~ ^[0-9]+$ \
      && "$ready_since" =~ ^[0-9]+$ \
      && "$reports_after" =~ ^[0-9]+$ ]] \
      && (( pending_after == 0 )) \
      && (( reports_after <= 3 )) \
      && (( ready_since >= pending_before )); then
      converged=true
      break
    fi
  fi
  sleep 3
done

[[ "$converged" == true ]] || fail BACKLOG_DID_NOT_CONVERGE
printf 'staging_reports_provision=pass supervisor_installed=%s program_installed=%s workers_running=1 pending_before=%s pending_after=0 ready_transitions=%s reports_depth_before=%s reports_depth_after=%s\n' \
  "$supervisor_installed" "$installed" "$pending_before" "$ready_since" "$reports_before" "$reports_after"
