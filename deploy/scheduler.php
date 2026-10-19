<?php

namespace Deployer;

task('scheduler:install-managed-cron', function () {
    if (currentHost()->getAlias() === 'staging') {
        // Staging must renew the ten-minute sitemap cache without running the
        // production scheduler's mail, payment and publication commands.
        $script = deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/install_staging_sitemap_cron.sh');
        $sudoPrefix = deployCanSudoWwwData() ? 'sudo -n -u www-data --' : '';
        run(
            'php_bin="$(command -v {{bin/php}})"; test -n "$php_bin"; '.$sudoPrefix
                .' /usr/bin/timeout --signal=TERM --kill-after=5s 30s bash '.$script
                .' --php-bin="$php_bin"'
                .' --deploy-path='.deployPlaceholderPathArg('{{deploy_path}}'),
            timeout: 35,
        );

        return;
    }
    if (deployUsesCareerContentPackage()) {
        writeln('<comment>Skip scheduler installation for Career body-only release.</comment>');

        return;
    }
    if (currentHost()->getAlias() !== 'production') {
        writeln('<comment>Skip managed scheduler installation outside production</comment>');

        return;
    }

    $supervisorctl = trim((string) get('queue_supervisorctl', '/usr/bin/supervisorctl'));
    $resolvedSupervisorctl = trim((string) run(
        'if [ -x '.escapeshellarg($supervisorctl).' ]; then echo '.escapeshellarg($supervisorctl).'; else command -v supervisorctl; fi'
    ));
    if ($resolvedSupervisorctl === '') {
        throw new \RuntimeException('scheduler cron installation requires supervisor status capability');
    }

    $schedulerScript = deployPlaceholderPathArg(
        '{{release_path}}',
        'backend/scripts/deploy/restart_supervisor_scheduler.sh',
    );
    run(
        'php_bin="$(command -v {{bin/php}})"; test -n "$php_bin"; /usr/bin/timeout --signal=TERM --kill-after=5s 90s bash '.$schedulerScript
            .' --supervisorctl='.escapeshellarg($resolvedSupervisorctl)
            .' --sudo=/usr/bin/sudo'
            .' --timeout-bin=/usr/bin/timeout'
            .' --crontab=/usr/bin/crontab'
            .' --php-bin="$php_bin"'
            .' --deploy-path='.deployPlaceholderPathArg('{{deploy_path}}')
            .' --proc-root=/proc'
            .' --required=true',
        timeout: 90,
    );
});

task('scheduler:wait-natural-heartbeat', function () {
    if (deployUsesCareerContentPackage()) {
        writeln('<comment>Skip scheduler heartbeat wait for Career body-only release.</comment>');

        return;
    }
    if (currentHost()->getAlias() !== 'production') {
        writeln('<comment>Skip scheduler heartbeat gate outside production</comment>');

        return;
    }

    within('{{current_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
started_epoch="$(date -u +%s)"
[[ -L "{{current_path}}" ]]
# Queue reload can finish after the current release's natural tick starts.
# Require an observation after atomic activation, not after this later wait.
activation_epoch="$(stat -c %Y "{{current_path}}")"
[[ "$activation_epoch" =~ ^[0-9]+$ && "$activation_epoch" -le "$started_epoch" ]]
expected_sha="$(tr -d '\r\n' < "{{current_path}}/REVISION")"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]]
deadline_epoch="$((started_epoch + 90))"
heartbeat='{}'
while [[ "$(date -u +%s)" -lt "$((deadline_epoch - 2))" ]]; do
  remaining="$((deadline_epoch - $(date -u +%s) - 2))"
  check_timeout=5
  if [[ "$remaining" -lt "$check_timeout" ]]; then check_timeout="$remaining"; fi
  [[ "$check_timeout" -ge 1 ]] || break
  set +e
  heartbeat="$(timeout --signal=TERM --kill-after=1s "${check_timeout}s" {{bin/php}} artisan ops:scheduler-heartbeat-check --max-age-seconds=180 --json --no-interaction --no-ansi 2>/dev/null)"
  heartbeat_rc=$?
  set -e
  if [[ "$heartbeat_rc" -eq 124 || "$heartbeat_rc" -eq 137 ]]; then heartbeat='{"reason":"checker_timeout"}'; fi
  if [[ -z "$heartbeat" ]]; then heartbeat='{"reason":"checker_failed"}'; fi
  if [[ "$heartbeat_rc" -eq 0 ]] && printf '%s' "$heartbeat" | ACTIVATION_EPOCH="$activation_epoch" EXPECTED_SHA="$expected_sha" {{bin/php}} -r '
    $payload = json_decode(stream_get_contents(STDIN), true);
    $observed = is_array($payload) ? strtotime((string) ($payload["last_completed_at"] ?? "")) : false;
    exit(($payload["ok"] ?? false) === true && ($payload["release_sha"] ?? null) === getenv("EXPECTED_SHA") && ($payload["completed_release_sha"] ?? null) === getenv("EXPECTED_SHA") && is_string($payload["completed_tick_id"] ?? null) && is_int($observed) && $observed >= (int) getenv("ACTIVATION_EPOCH") ? 0 : 1);
  '; then
    printf 'scheduler_heartbeat_gate_pass\n'
    exit 0
  fi
  if [[ "$((deadline_epoch - $(date -u +%s)))" -gt 5 ]]; then sleep 3; fi
done
printf '%s' "$heartbeat" | {{bin/php}} -r '
    $p = json_decode(stream_get_contents(STDIN), true);
    $safe = array_intersect_key(is_array($p) ? $p : [], array_flip(["reason", "status", "release_sha", "completed_release_sha", "tick_id", "completed_tick_id", "last_completed_at", "age_seconds"]));
    fwrite(STDERR, "scheduler_last_check=".json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE)."\n");
';
printf 'scheduler_heartbeat_gate_failed reason=natural_tick_timeout\n' >&2
exit 1
BASH, timeout: 95);
    });
});

after('queue:reload-workers', 'scheduler:install-managed-cron');
after('scheduler:install-managed-cron', 'scheduler:wait-natural-heartbeat');
