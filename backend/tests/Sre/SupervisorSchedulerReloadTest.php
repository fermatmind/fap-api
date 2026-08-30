<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SupervisorSchedulerReloadTest extends TestCase
{
    private string $temporaryDirectory;

    private string $revision = '2117895ad725735ce8f88aad90077e54920a511c';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/fap-scheduler-reload-'.bin2hex(random_bytes(8));
        $backend = $this->temporaryDirectory.'/deploy/releases/'.$this->revision.'/backend';
        mkdir($backend.'/scripts/deploy', 0700, true);
        mkdir($backend.'/storage/app/ops', 0700, true);
        mkdir($this->temporaryDirectory.'/proc', 0700, true);
        symlink('releases/'.$this->revision, $this->temporaryDirectory.'/deploy/current');
        file_put_contents(dirname($backend).'/REVISION', $this->revision."\n");
        file_put_contents($backend.'/artisan', "#!/usr/bin/env php\n");
        copy(dirname(__DIR__, 2).'/scripts/deploy/run_scheduler_tick.sh', $backend.'/scripts/deploy/run_scheduler_tick.sh');
        chmod($backend.'/scripts/deploy/run_scheduler_tick.sh', 0700);

        $this->writeExecutable('sudo', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == "-n" ]] && shift
exec "$@"
BASH);
        $this->writeExecutable('timeout', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
while [[ "${1:-}" == --signal=* || "${1:-}" == --kill-after=* ]]; do shift; done
[[ "${1:-}" =~ ^[0-9]+s$ ]]
shift
exec "$@"
BASH);
        $this->writeExecutable('crontab', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == -l ]]; then
  if [[ "${FAKE_CRONTAB_READ_ERROR:-false}" == true ]]; then printf 'permission denied\n' >&2; exit 1; fi
  if [[ ! -f "$FAKE_CRONTAB_FILE" ]]; then printf 'no crontab for test\n' >&2; exit 1; fi
  cat "$FAKE_CRONTAB_FILE"
  exit 0
fi
[[ $# -eq 1 && -f "$1" ]]
cp "$1" "$FAKE_CRONTAB_FILE"
BASH);
        $this->writeExecutable('php', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "${FAKE_SCHEDULE_REGISTRATION_MISSING:-false}" != true ]] || exit 1
printf '[{"command":"seo:runtime-probe-scheduled --trigger=scheduled --json"}]\n'
BASH);
        $this->writeExecutable('supervisorctl', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
command="${1:-}"
target="${2:-}"
case "$command" in
  status)
    if [[ "${FAKE_SUPERVISOR_SCHEDULER:-false}" == true ]]; then
      if [[ -f "$FAKE_STOP_LOG" ]]; then
        printf 'fap-scheduler STOPPED Not started\n'
      else
        printf 'fap-scheduler RUNNING pid 301, uptime 0:01:00\n'
      fi
    else
      printf 'fap-queue RUNNING pid 999, uptime 0:01:00\n'
    fi
    ;;
  stop)
    [[ "$target" == fap-scheduler ]]
    printf '%s\n' "$target" >> "$FAKE_STOP_LOG"
    ;;
  *) exit 2 ;;
esac
BASH);
    }

    protected function tearDown(): void
    {
        (new Process(['find', $this->temporaryDirectory, '-depth', '-delete']))->mustRun();
        parent::tearDown();
    }

    #[Test]
    public function production_installs_one_managed_foreground_tick(): void
    {
        $process = $this->runScript();
        $crontab = (string) file_get_contents($this->temporaryDirectory.'/crontab-state');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame(1, substr_count($crontab, '# BEGIN fap-api managed scheduler'));
        $this->assertSame(1, substr_count($crontab, 'run_scheduler_tick.sh'));
        $this->assertStringNotContainsString('artisan schedule:run', $crontab);
        $this->assertStringNotContainsString('artisan schedule:work', $crontab);
        $this->assertStringContainsString('mode=cron_schedule_run', $process->getOutput());
    }

    #[Test]
    public function exact_repository_supervisor_schedule_work_is_stopped_before_cron_install(): void
    {
        $this->addSupervisorScheduleWork(true);
        $process = $this->runScript(['FAKE_SUPERVISOR_SCHEDULER' => 'true']);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("fap-scheduler\n", file_get_contents($this->temporaryDirectory.'/stop-log'));
        $this->assertFileExists($this->temporaryDirectory.'/crontab-state');
    }

    #[Test]
    public function unknown_schedule_work_and_unknown_supervisor_scheduler_fail_closed(): void
    {
        $this->addSupervisorScheduleWork(false);
        $unknownProcess = $this->runScript(['FAKE_SUPERVISOR_SCHEDULER' => 'true']);
        $this->assertFalse($unknownProcess->isSuccessful());
        $this->assertStringContainsString('reason=unknown_schedule_work', $unknownProcess->getErrorOutput());
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/crontab-state');

        $this->removeProc();
        $unknownSupervisor = $this->runScript(['FAKE_SUPERVISOR_SCHEDULER' => 'true']);
        $this->assertFalse($unknownSupervisor->isSuccessful());
        $this->assertStringContainsString('reason=unknown_scheduler', $unknownSupervisor->getErrorOutput());
    }

    #[Test]
    public function duplicate_or_foreign_cron_scheduler_fails_before_mutation(): void
    {
        $original = "* * * * * cd /one && php artisan schedule:run\n* * * * * cd /two && php artisan schedule:run\n";
        file_put_contents($this->temporaryDirectory.'/crontab-state', $original);
        $process = $this->runScript();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=unknown_cron_scheduler', $process->getErrorOutput());
        $this->assertSame($original, file_get_contents($this->temporaryDirectory.'/crontab-state'));
    }

    #[Test]
    public function one_exact_legacy_cron_is_replaced_and_unrelated_entries_are_preserved(): void
    {
        file_put_contents(
            $this->temporaryDirectory.'/crontab-state',
            "MAILTO=ops@example.test\n* * * * * cd {$this->temporaryDirectory}/deploy/current/backend && php artisan schedule:run\n",
        );
        $process = $this->runScript();
        $crontab = (string) file_get_contents($this->temporaryDirectory.'/crontab-state');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('MAILTO=ops@example.test', $crontab);
        $this->assertSame(1, substr_count($crontab, 'run_scheduler_tick.sh'));
        $this->assertStringNotContainsString('artisan schedule:run', $crontab);
    }

    #[Test]
    public function malformed_managed_block_and_unreadable_crontab_fail_closed(): void
    {
        $original = "# END fap-api managed scheduler\n# BEGIN fap-api managed scheduler\n";
        file_put_contents($this->temporaryDirectory.'/crontab-state', $original);
        $malformed = $this->runScript();
        $this->assertFalse($malformed->isSuccessful());
        $this->assertStringContainsString('reason=cron_managed_block_invalid', $malformed->getErrorOutput());
        $this->assertSame($original, file_get_contents($this->temporaryDirectory.'/crontab-state'));

        unlink($this->temporaryDirectory.'/crontab-state');
        $unreadable = $this->runScript(['FAKE_CRONTAB_READ_ERROR' => 'true']);
        $this->assertFalse($unreadable->isSuccessful());
        $this->assertStringContainsString('reason=crontab_read_failed', $unreadable->getErrorOutput());
    }

    #[Test]
    public function staging_does_not_install_a_competing_runner(): void
    {
        $process = $this->runScript(required: false);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('reason=cron_not_required', $process->getOutput());
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/crontab-state');
    }

    #[Test]
    public function deploy_bounds_queue_reload_and_chains_scheduler_heartbeat(): void
    {
        $deploy = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');

        $this->assertStringContainsString("' --attempts=1'", $deploy);
        $this->assertStringContainsString("' --restart-timeout-seconds=390'", $deploy);
        $this->assertStringContainsString('timeout: 420', $deploy);
        $this->assertStringContainsString('\\$2 != \\"RUNNING\\" && \\$2 != \\"STOPPED\\"', $deploy);
        $this->assertStringContainsString('requires a recoverable supervisor program', $deploy);
        $this->assertStringContainsString('timeout --signal=TERM --kill-after=5s 90s bash', $deploy);
        $this->assertStringContainsString("after('queue:reload-workers', 'scheduler:install-managed-cron')", $deploy);
        $this->assertStringContainsString("after('scheduler:install-managed-cron', 'scheduler:wait-natural-heartbeat')", $deploy);
        $this->assertMatchesRegularExpression("/task\\('scheduler:install-managed-cron'[\\s\\S]+currentHost\\(\\)->getAlias\\(\\) !== 'production'[\\s\\S]+return;/", $deploy);
    }

    private function addSupervisorScheduleWork(bool $owned): void
    {
        mkdir($this->temporaryDirectory.'/proc/301', 0700, true);
        mkdir($this->temporaryDirectory.'/proc/401', 0700, true);
        file_put_contents($this->temporaryDirectory.'/proc/301/cmdline', "/usr/local/bin/run-scheduler\0");
        file_put_contents($this->temporaryDirectory.'/proc/301/stat', "301 (runner) S 1 0 0 0\n");
        file_put_contents($this->temporaryDirectory.'/proc/401/cmdline', "/usr/bin/php\0artisan\0schedule:work\0");
        file_put_contents($this->temporaryDirectory.'/proc/401/stat', "401 (php) S 301 0 0 0\n");
        $cwd = $owned
            ? $this->temporaryDirectory.'/deploy/releases/'.$this->revision.'/backend'
            : $this->temporaryDirectory;
        symlink($cwd, $this->temporaryDirectory.'/proc/401/cwd');
    }

    private function removeProc(): void
    {
        (new Process(['find', $this->temporaryDirectory.'/proc', '-mindepth', '1', '-depth', '-delete']))->mustRun();
    }

    /** @param array<string, string> $environment */
    private function runScript(array $environment = [], bool $required = true): Process
    {
        $backendRoot = dirname(__DIR__, 2);
        $process = new Process([
            'bash',
            $backendRoot.'/scripts/deploy/restart_supervisor_scheduler.sh',
            '--supervisorctl='.$this->temporaryDirectory.'/supervisorctl',
            '--sudo='.$this->temporaryDirectory.'/sudo',
            '--timeout-bin='.$this->temporaryDirectory.'/timeout',
            '--crontab='.$this->temporaryDirectory.'/crontab',
            '--php-bin='.$this->temporaryDirectory.'/php',
            '--deploy-path='.$this->temporaryDirectory.'/deploy',
            '--proc-root='.$this->temporaryDirectory.'/proc',
            '--required='.($required ? 'true' : 'false'),
        ], $backendRoot, $environment + [
            'FAKE_CRONTAB_FILE' => $this->temporaryDirectory.'/crontab-state',
            'FAKE_STOP_LOG' => $this->temporaryDirectory.'/stop-log',
        ]);
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    private function writeExecutable(string $name, string $contents): void
    {
        file_put_contents($this->temporaryDirectory.'/'.$name, $contents);
        chmod($this->temporaryDirectory.'/'.$name, 0700);
    }
}
