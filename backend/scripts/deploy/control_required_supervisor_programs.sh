#!/usr/bin/env bash

set -Eeuo pipefail

mode="${MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_active_revision="${EXPECTED_ACTIVE_REVISION:-}"
expected_state_sha256="${EXPECTED_STATE_SHA256:-}"
supervisorctl_path="${SUPERVISORCTL_PATH:-/usr/bin/supervisorctl}"
php_path="${PHP_PATH:-/usr/bin/php}"
sudo_path="${SUDO_PATH:-/usr/bin/sudo}"
zero_sha256="$(printf '0%.0s' {1..64})"
programs=(fap-queue-default-high fap-queue-reports)

fail() {
  printf 'REQUIRED_QUEUE_CONTROL_FAILED:%s\n' "$1" >&2
  exit 1
}

[[ "$mode" =~ ^(preflight|apply)$ ]] || fail INVALID_MODE
[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_DEPLOY_PATH
[[ "$deploy_path" != *".."* ]] || fail INVALID_DEPLOY_PATH
[[ "$expected_active_revision" =~ ^[0-9a-f]{40}$ ]] || fail INVALID_ACTIVE_REVISION
[[ "$supervisorctl_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_SUPERVISORCTL_PATH
[[ "$php_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_PHP_PATH
[[ "$sudo_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail INVALID_SUDO_PATH
[[ "$supervisorctl_path" != *".."* ]] || fail INVALID_SUPERVISORCTL_PATH
[[ "$php_path" != *".."* ]] || fail INVALID_PHP_PATH
[[ "$sudo_path" != *".."* ]] || fail INVALID_SUDO_PATH
if [[ "$mode" == "apply" ]]; then
  [[ "$expected_state_sha256" =~ ^[0-9a-f]{64}$ ]] || fail INVALID_STATE_SHA
else
  [[ -z "$expected_state_sha256" ]] || fail UNEXPECTED_STATE_SHA
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
[[ "$deploy_process_count" == "0" ]] || fail DEPLOY_PROCESS_PRESENT

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

queue_pending_counts() {
  "$sudo_path" -n -u www-data /usr/bin/env \
    CURRENT_RELEASE_BACKEND="$current_release/backend" \
    "$php_path" -d display_errors=0 2>/dev/null <<'PHP'
<?php

try {
    $base = getenv('CURRENT_RELEASE_BACKEND');
    if (! is_string($base) || $base === '') {
        throw new RuntimeException('invalid base');
    }

    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $queueSizes = static function (): array {
        $targets = [
            'high' => ['redis', 'high'],
            'default' => ['redis', 'default'],
            'reports' => ['database_reports', 'reports'],
        ];
        $sizes = [];
        foreach ($targets as $key => [$connection, $queue]) {
            $size = Illuminate\Support\Facades\Queue::connection($connection)->size($queue);
            if (! is_int($size) && ! ctype_digit((string) $size)) {
                throw new RuntimeException('invalid size');
            }
            $sizes[$key] = (int) $size;
        }

        return $sizes;
    };

    $classify = static function (string $payload): string {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return 'UNKNOWN';
        }
        $candidates = [
            $decoded['displayName'] ?? null,
            $decoded['data']['commandName'] ?? null,
            $decoded['job'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate)
                && preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\.]*\z/', $candidate) === 1) {
                return $candidate;
            }
        }

        return 'UNKNOWN';
    };

    $before = $queueSizes();
    $payloads = ['high' => [], 'default' => [], 'reports' => []];
    $createdAt = ['high' => [], 'default' => [], 'reports' => []];
    $snapshotMaterial = [];

    $redisConnection = (string) config('queue.connections.redis.connection', 'default');
    if (preg_match('/\A[A-Za-z0-9_.-]+\z/', $redisConnection) !== 1) {
        throw new RuntimeException('invalid redis connection');
    }
    $redis = Illuminate\Support\Facades\Redis::connection($redisConnection);
    foreach (['high', 'default'] as $queue) {
        foreach (['ready' => '', 'delayed' => ':delayed', 'reserved' => ':reserved'] as $bucket => $suffix) {
            $key = 'queues:'.$queue.$suffix;
            $items = $bucket === 'ready'
                ? $redis->lrange($key, 0, -1)
                : $redis->zrange($key, 0, -1);
            if (! is_array($items)) {
                throw new RuntimeException('invalid redis payload set');
            }
            foreach ($items as $payload) {
                if (! is_string($payload)) {
                    throw new RuntimeException('invalid redis payload');
                }
                $payloads[$queue][] = $payload;
                $decoded = json_decode($payload, true);
                $payloadCreatedAt = is_array($decoded) ? ($decoded['createdAt'] ?? null) : null;
                if (is_int($payloadCreatedAt) || ctype_digit((string) $payloadCreatedAt)) {
                    $createdAt[$queue][] = (int) $payloadCreatedAt;
                }
                $snapshotMaterial[] = $queue.'|'.$bucket.'|'.hash('sha256', $payload);
            }
        }
    }

    $reportsConfig = config('queue.connections.database_reports');
    if (! is_array($reportsConfig)) {
        throw new RuntimeException('invalid reports config');
    }
    $databaseConnection = $reportsConfig['connection'] ?? null;
    $reportsTable = (string) ($reportsConfig['table'] ?? '');
    $reportsQueue = (string) ($reportsConfig['queue'] ?? 'reports');
    if (($databaseConnection !== null
            && (! is_string($databaseConnection)
                || preg_match('/\A[A-Za-z0-9_.-]+\z/', $databaseConnection) !== 1))
        || preg_match('/\A[A-Za-z0-9_]+\z/', $reportsTable) !== 1
        || preg_match('/\A[A-Za-z0-9_.-]+\z/', $reportsQueue) !== 1) {
        throw new RuntimeException('invalid reports database config');
    }

    $database = Illuminate\Support\Facades\DB::connection($databaseConnection);
    $database->listen(static function ($query): void {
        $sql = ltrim((string) $query->sql);
        if (preg_match('/\Aselect\b/i', $sql) !== 1) {
            throw new RuntimeException('non-read query');
        }
    });
    $rows = $database->table($reportsTable)
        ->where('queue', $reportsQueue)
        ->orderBy('id')
        ->get(['payload', 'created_at']);
    foreach ($rows as $row) {
        $payload = $row->payload ?? null;
        if (! is_string($payload)) {
            throw new RuntimeException('invalid database payload');
        }
        $payloads['reports'][] = $payload;
        $timestamp = $row->created_at ?? null;
        if (is_int($timestamp) || ctype_digit((string) $timestamp)) {
            $createdAt['reports'][] = (int) $timestamp;
        }
        $snapshotMaterial[] = 'reports|ready|'.hash('sha256', $payload);
    }

    $after = $queueSizes();
    if ($before !== $after) {
        throw new RuntimeException('queue size drift');
    }
    foreach ($before as $queue => $count) {
        if (count($payloads[$queue]) !== $count) {
            throw new RuntimeException('payload count drift');
        }
    }

    $classCounts = ['high' => [], 'default' => [], 'reports' => []];
    $unknownClassCount = 0;
    foreach ($payloads as $queue => $items) {
        foreach ($items as $payload) {
            try {
                $class = $classify($payload);
            } catch (Throwable) {
                $class = 'UNKNOWN';
            }
            $classCounts[$queue][$class] = ($classCounts[$queue][$class] ?? 0) + 1;
            if ($class === 'UNKNOWN') {
                $unknownClassCount++;
            }
        }
        ksort($classCounts[$queue], SORT_STRING);
    }

    $timestamps = array_merge(...array_values($createdAt));
    $oldestPendingSeconds = 0;
    if ($timestamps !== []) {
        $oldestPendingSeconds = max(0, time() - min($timestamps));
    }
    sort($snapshotMaterial, SORT_STRING);
    $snapshotSha256 = hash('sha256', implode("\n", $snapshotMaterial));
    $classification = json_encode(
        $classCounts,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );

    echo implode("\t", [
        $before['high'],
        $before['default'],
        $before['reports'],
        $unknownClassCount,
        $oldestPendingSeconds,
        $snapshotSha256,
        base64_encode($classification),
    ]), PHP_EOL;
} catch (Throwable) {
    echo 'PROBE_FAILED', PHP_EOL;
    exit(1);
}
PHP
}

snapshot() {
  local status_lines="$1"
  local state_material=""
  local program=""
  local matches=""
  local member_count=""
  local running_count=""
  local status_sha256=""
  local state=""
  local normalized_matches=""

  for program in "${programs[@]}"; do
    matches="$(awk -v pattern="^${program}(:|$)" '$1 ~ pattern {print}' <<<"$status_lines")"
    [[ -n "$matches" ]] || fail "PROGRAM_MISSING_${program^^}"
    member_count="$(awk 'NF {count++} END {print count+0}' <<<"$matches")"
    running_count="$(awk '$2 == "RUNNING" {count++} END {print count+0}' <<<"$matches")"
    [[ "$member_count" -gt 0 ]] || fail INVALID_MEMBER_COUNT
    if [[ "$running_count" -eq "$member_count" ]]; then
      state="RUNNING"
    else
      state="NOT_RUNNING"
    fi
    normalized_matches="$(
      awk '{
        pid=""
        for (i=3; i<=NF; i++) {
          if ($i == "pid") {
            pid=$(i+1)
            gsub(/,/, "", pid)
          }
        }
        print $1 "|" $2 "|" pid
      }' <<<"$matches"
    )"
    status_sha256="$(printf '%s\n' "$normalized_matches" | sha256sum | awk '{print $1}')"
    state_material+="${program}|${state}|${member_count}|${running_count}|${status_sha256}"$'\n'
  done

  printf '%s' "$state_material"
}

foreign_fingerprint() {
  local status_lines="$1"

  awk '
    $1 !~ /^fap-queue-default-high(:|$)/ && $1 !~ /^fap-queue-reports(:|$)/ {
      pid=""
      for (i=3; i<=NF; i++) {
        if ($i == "pid") {
          pid=$(i+1)
          gsub(/,/, "", pid)
        }
      }
      print $1 "|" $2 "|" pid
    }
  ' <<<"$status_lines" | sort | sha256sum | awk '{print $1}'
}

status_before="$(read_status)"
state_material_before="$(snapshot "$status_before")"
foreign_before="$(foreign_fingerprint "$status_before")"
pending_counts="$(queue_pending_counts)"
IFS=$'\t' read -r high_pending default_pending reports_pending unknown_class_count oldest_pending_seconds backlog_snapshot_sha256 job_class_counts_b64 <<<"$pending_counts"
[[ "$high_pending" =~ ^[0-9]+$ ]] || fail QUEUE_PROBE
[[ "$default_pending" =~ ^[0-9]+$ ]] || fail QUEUE_PROBE
[[ "$reports_pending" =~ ^[0-9]+$ ]] || fail QUEUE_PROBE
[[ "$unknown_class_count" =~ ^[0-9]+$ ]] || fail QUEUE_PROBE
[[ "$oldest_pending_seconds" =~ ^[0-9]+$ ]] || fail QUEUE_PROBE
[[ "$backlog_snapshot_sha256" =~ ^[0-9a-f]{64}$ ]] || fail QUEUE_PROBE
[[ "$job_class_counts_b64" =~ ^[A-Za-z0-9+/=]+$ ]] || fail QUEUE_PROBE
pending_total=$((high_pending + default_pending + reports_pending))
[[ "$pending_total" =~ ^[0-9]+$ ]] || fail QUEUE_PROBE
(( unknown_class_count <= pending_total )) || fail QUEUE_PROBE
if [[ "$mode" == "apply" && "$pending_total" != "0" ]]; then
  fail QUEUE_BACKLOG_PRESENT
fi
target_set_sha256="$(printf '%s\n' "${programs[@]}" | sha256sum | awk '{print $1}')"
state_sha256="$(
  printf '%s\n%s\n%s\n%s\n%s\n%s\n%s\n%s\n%s\n' \
    "$active_revision" "$target_set_sha256" "$high_pending" "$default_pending" \
    "$reports_pending" "$unknown_class_count" "$backlog_snapshot_sha256" \
    "$foreign_before" "$state_material_before" \
    | sha256sum | awk '{print $1}'
)"

default_state="$(awk -F '|' '$1 == "fap-queue-default-high" {print $2}' <<<"$state_material_before")"
reports_state="$(awk -F '|' '$1 == "fap-queue-reports" {print $2}' <<<"$state_material_before")"
[[ "$default_state" =~ ^(RUNNING|NOT_RUNNING)$ ]] || fail DEFAULT_STATE
[[ "$reports_state" =~ ^(RUNNING|NOT_RUNNING)$ ]] || fail REPORTS_STATE

if [[ "$mode" == "preflight" ]]; then
  convergence_required=false
  if [[ "$default_state" != "RUNNING" || "$reports_state" != "RUNNING" ]]; then
    convergence_required=true
  fi
  apply_supported=false
  if [[ "$convergence_required" == true && "$pending_total" == "0" ]]; then
    apply_supported=true
  fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$active_revision" "$target_set_sha256" "$state_sha256" "$default_state" \
    "$reports_state" "$pending_total" "$high_pending" "$default_pending" \
    "$reports_pending" "$convergence_required" "$apply_supported" "$foreign_before" \
    "$unknown_class_count" "$oldest_pending_seconds" "$backlog_snapshot_sha256" \
    "$job_class_counts_b64"
  exit 0
fi

[[ "$state_sha256" == "$expected_state_sha256" ]] || fail STATE_DRIFT
[[ "$default_state" != "RUNNING" || "$reports_state" != "RUNNING" ]] || fail CONVERGENCE_NOT_REQUIRED

restart_program() {
  local program="$1"
  local attempt=""
  local target=""
  local target_kind=""
  local status=""
  local rc=0

  for attempt in 1 2 3; do
    status="$(read_status)"
    if awk -v prefix="${program}:" 'index($1, prefix) == 1 {found=1} END {exit !found}' <<<"$status"; then
      target="${program}:*"
      target_kind=group
    elif awk -v expected="$program" '$1 == expected {found=1} END {exit !found}' <<<"$status"; then
      target="$program"
      target_kind=single
    else
      fail "PROGRAM_DISAPPEARED_${program^^}"
    fi

    set +e
    "$sudo_path" -n "$supervisorctl_path" restart "$target" >/dev/null 2>&1
    rc=$?
    set -e
    if [[ "$rc" -eq 0 ]]; then
      status="$(read_status)"
      if [[ "$target_kind" == group ]] \
        && awk -v prefix="${program}:" '
          index($1, prefix) == 1 {found=1; if ($2 != "RUNNING") bad=1}
          END {exit !(found && !bad)}
        ' <<<"$status"; then
        return 0
      fi
      if [[ "$target_kind" == single ]] \
        && awk -v expected="$program" '
          $1 == expected {found=1; if ($2 != "RUNNING") bad=1}
          END {exit !(found && !bad)}
        ' <<<"$status"; then
        return 0
      fi
    fi
    [[ "$attempt" -eq 3 ]] || sleep 2
  done

  fail "PROGRAM_RESTART_FAILED_${program^^}"
}

if [[ "$default_state" != "RUNNING" ]]; then
  restart_program fap-queue-default-high
fi
if [[ "$reports_state" != "RUNNING" ]]; then
  restart_program fap-queue-reports
fi

status_after="$(read_status)"
state_material_after="$(snapshot "$status_after")"
foreign_after="$(foreign_fingerprint "$status_after")"
[[ "$foreign_after" == "$foreign_before" ]] || fail FOREIGN_RUNTIME_DRIFT
awk -F '|' '$2 != "RUNNING" {bad=1} END {exit bad}' <<<"$state_material_after" \
  || fail TARGET_NOT_RUNNING
[[ "$(tr -d '\r\n' < "$deploy_root/current/REVISION")" == "$active_revision" ]] \
  || fail ACTIVE_REVISION_CHANGED
test ! -e "$deploy_root/.dep/deploy.lock" || fail DEPLOY_LOCK_CHANGED

after_state_sha256="$(
  printf '%s\n%s\n%s\n%s\n%s\n' \
    "$active_revision" "$target_set_sha256" "$pending_total" "$foreign_after" "$state_material_after" \
    | sha256sum | awk '{print $1}'
)"
printf '%s\t%s\t%s\t%s\n' \
  "$active_revision" "$target_set_sha256" "$state_sha256" "$after_state_sha256"
