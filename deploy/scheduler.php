<?php

namespace Deployer;

task('scheduler:install-managed-cron', function () {
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
deadline_epoch="$((started_epoch + 90))"
while [[ "$(date -u +%s)" -le "$deadline_epoch" ]]; do
  set +e
  heartbeat="$({{bin/php}} artisan ops:scheduler-heartbeat-check --max-age-seconds=180 --json --no-interaction --no-ansi 2>/dev/null)"
  heartbeat_rc=$?
  set -e
  if [[ "$heartbeat_rc" -eq 0 ]] && printf '%s' "$heartbeat" | ACTIVATION_EPOCH="$activation_epoch" {{bin/php}} -r '
    $payload = json_decode(stream_get_contents(STDIN), true);
    $observed = is_array($payload) ? strtotime((string) ($payload["observed_at"] ?? "")) : false;
    exit(($payload["ok"] ?? false) === true && is_int($observed) && $observed >= (int) getenv("ACTIVATION_EPOCH") ? 0 : 1);
  '; then
    printf 'scheduler_heartbeat_gate_pass\n'
    exit 0
  fi
  sleep 3
done
printf 'scheduler_heartbeat_gate_failed reason=natural_tick_timeout\n' >&2
exit 1
BASH, timeout: 95);
    });
});

after('queue:reload-workers', 'scheduler:install-managed-cron');
after('scheduler:install-managed-cron', 'scheduler:wait-natural-heartbeat');
