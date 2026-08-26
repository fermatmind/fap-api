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
        mkdir($this->temporaryDirectory.'/deploy/releases/'.$this->revision.'/backend', 0700, true);
        mkdir($this->temporaryDirectory.'/deploy/releases/old/backend', 0700, true);
        mkdir($this->temporaryDirectory.'/proc/101', 0700, true);
        mkdir($this->temporaryDirectory.'/proc/202', 0700, true);
        mkdir($this->temporaryDirectory.'/proc/301', 0700, true);
        mkdir($this->temporaryDirectory.'/proc/302', 0700, true);
        mkdir($this->temporaryDirectory.'/proc/401', 0700, true);
        mkdir($this->temporaryDirectory.'/proc/402', 0700, true);
        symlink('releases/'.$this->revision, $this->temporaryDirectory.'/deploy/current');
        symlink($this->temporaryDirectory.'/deploy/releases/old/backend', $this->temporaryDirectory.'/proc/101/cwd');
        symlink($this->temporaryDirectory.'/deploy/releases/'.$this->revision.'/backend', $this->temporaryDirectory.'/proc/202/cwd');
        symlink($this->temporaryDirectory.'/deploy/releases/old/backend', $this->temporaryDirectory.'/proc/401/cwd');
        symlink($this->temporaryDirectory.'/deploy/releases/'.$this->revision.'/backend', $this->temporaryDirectory.'/proc/402/cwd');
        file_put_contents(
            $this->temporaryDirectory.'/deploy/releases/'.$this->revision.'/REVISION',
            $this->revision."\n",
        );
        file_put_contents($this->temporaryDirectory.'/deploy/releases/'.$this->revision.'/backend/artisan', "#!/usr/bin/env php\n");
        file_put_contents($this->temporaryDirectory.'/proc/101/cmdline', "/usr/bin/php\0/old/backend/artisan\0schedule:work\0");
        file_put_contents($this->temporaryDirectory.'/proc/202/cmdline', "/usr/bin/php\0/current/backend/artisan\0schedule:work\0");
        file_put_contents($this->temporaryDirectory.'/proc/301/cmdline', "/usr/local/bin/run-scheduler\0");
        file_put_contents($this->temporaryDirectory.'/proc/302/cmdline', "/usr/local/bin/run-scheduler\0");
        file_put_contents($this->temporaryDirectory.'/proc/401/cmdline', "/usr/bin/php\0artisan\0schedule:work\0");
        file_put_contents($this->temporaryDirectory.'/proc/402/cmdline', "/usr/bin/php\0artisan\0schedule:work\0");
        file_put_contents($this->temporaryDirectory.'/proc/401/stat', "401 (php) S 301 0 0 0\n");
        file_put_contents($this->temporaryDirectory.'/proc/402/stat', "402 (php) S 302 0 0 0\n");
        file_put_contents($this->temporaryDirectory.'/state', 'old');

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
  if [[ "${FAKE_CRONTAB_READ_ERROR:-false}" == true ]]; then
    printf 'permission denied\n' >&2
    exit 1
  fi
  if [[ ! -f "$FAKE_CRONTAB_FILE" ]]; then
    printf 'no crontab for test\n' >&2
    exit 1
  fi
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
state="$(cat "$FAKE_STATE_FILE")"
pid=101
[[ "$state" == new ]] && pid=202
if [[ "${FAKE_WRAPPER:-false}" == true ]]; then
  pid=301
  [[ "$state" == new ]] && pid=302
fi

case "$command" in
  status)
    if [[ -n "${FAKE_SECOND_PROGRAM:-}" ]]; then
      printf 'other-scheduler RUNNING pid 202, uptime 0:01:00\n'
    fi
    if [[ "${FAKE_MISSING:-false}" != true ]]; then
      if [[ -z "$target" || "$target" == fap-scheduler ]]; then
        printf 'fap-scheduler RUNNING pid %s, uptime 0:01:00\n' "$pid"
        exit 0
      fi
    fi
    if [[ -z "$target" ]]; then
      printf 'fap-queue RUNNING pid 999, uptime 0:01:00\n'
      exit 0
    fi
    printf '%s: ERROR (no such process)\n' "$target" >&2
    exit 4
    ;;
  restart)
    [[ "$target" == fap-scheduler ]]
    if [[ "${FAKE_STALE_AFTER_RESTART:-false}" != true ]]; then
      printf new > "$FAKE_STATE_FILE"
    fi
    ;;
esac
BASH);
    }

    protected function tearDown(): void
    {
        $process = new Process(['find', $this->temporaryDirectory, '-depth', '-delete']);
        $process->mustRun();

        parent::tearDown();
    }

    #[Test]
    public function it_discovers_the_real_scheduler_and_verifies_the_current_release_after_restart(): void
    {
        $process = $this->runScript();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertTrue($process->isSuccessful(), $output);
        $this->assertSame('new', file_get_contents($this->temporaryDirectory.'/state'));
        $this->assertStringContainsString('scheduler_refresh_pass revision='.$this->revision, $output);
        $this->assertStringNotContainsString($this->temporaryDirectory, $output);
        $this->assertStringNotContainsString('pid ', $output);
    }

    #[Test]
    public function it_discovers_a_relative_artisan_scheduler_below_a_supervisor_wrapper(): void
    {
        $process = $this->runScript(['FAKE_WRAPPER' => 'true']);
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertTrue($process->isSuccessful(), $output);
        $this->assertSame('new', file_get_contents($this->temporaryDirectory.'/state'));
        $this->assertStringContainsString('scheduler_refresh_pass revision='.$this->revision, $output);
        $this->assertStringNotContainsString($this->temporaryDirectory, $output);
    }

    #[Test]
    public function it_uses_a_unique_scheduler_program_name_when_process_metadata_is_unreadable(): void
    {
        foreach (glob($this->temporaryDirectory.'/proc/*/cmdline') ?: [] as $cmdline) {
            unlink($cmdline);
        }

        $process = $this->runScript();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertTrue($process->isSuccessful(), $output);
        $this->assertSame('new', file_get_contents($this->temporaryDirectory.'/state'));
        $this->assertStringContainsString('members=0 verification=supervisor_restart', $output);
        $this->assertStringNotContainsString($this->temporaryDirectory, $output);
    }

    #[Test]
    public function it_fails_closed_when_more_than_one_supervisor_program_owns_schedule_work(): void
    {
        $process = $this->runScript(['FAKE_SECOND_PROGRAM' => 'true']);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=scheduler_identity_count', $process->getErrorOutput());
        $this->assertSame('old', file_get_contents($this->temporaryDirectory.'/state'));
    }

    #[Test]
    public function production_mode_installs_a_single_current_release_cron_when_supervisor_has_no_scheduler(): void
    {
        $process = $this->runScript(['FAKE_MISSING' => 'true']);
        $output = $process->getOutput().$process->getErrorOutput();
        $crontab = file_get_contents($this->temporaryDirectory.'/crontab-state');

        $this->assertTrue($process->isSuccessful(), $output);
        $this->assertIsString($crontab);
        $this->assertStringContainsString('mode=cron_current', $output);
        $this->assertStringContainsString($this->temporaryDirectory.'/deploy/current/backend', $crontab);
        $this->assertSame(1, substr_count($crontab, 'artisan schedule:run'));
        $this->assertStringNotContainsString($this->temporaryDirectory, $output);
    }

    #[Test]
    public function cron_fallback_replaces_one_legacy_schedule_runner_and_preserves_unrelated_entries(): void
    {
        file_put_contents(
            $this->temporaryDirectory.'/crontab-state',
            "MAILTO=ops@example.test\n* * * * * cd /stale/backend && /usr/bin/php artisan schedule:run\n",
        );

        $process = $this->runScript(['FAKE_MISSING' => 'true']);
        $crontab = file_get_contents($this->temporaryDirectory.'/crontab-state');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertIsString($crontab);
        $this->assertStringContainsString('MAILTO=ops@example.test', $crontab);
        $this->assertStringNotContainsString('/stale/backend', $crontab);
        $this->assertSame(1, substr_count($crontab, 'artisan schedule:run'));
    }

    #[Test]
    public function cron_fallback_is_idempotent(): void
    {
        $first = $this->runScript(['FAKE_MISSING' => 'true']);
        $second = $this->runScript(['FAKE_MISSING' => 'true']);
        $crontab = file_get_contents($this->temporaryDirectory.'/crontab-state');

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $this->assertIsString($crontab);
        $this->assertSame(1, substr_count($crontab, '# BEGIN fap-api managed scheduler'));
        $this->assertSame(1, substr_count($crontab, 'artisan schedule:run'));
    }

    #[Test]
    public function cron_fallback_fails_closed_on_ambiguous_existing_schedule_runners(): void
    {
        $original = "* * * * * cd /one && php artisan schedule:run\n* * * * * cd /two && php artisan schedule:run\n";
        file_put_contents($this->temporaryDirectory.'/crontab-state', $original);

        $process = $this->runScript(['FAKE_MISSING' => 'true']);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=cron_scheduler_identity_count', $process->getErrorOutput());
        $this->assertSame($original, file_get_contents($this->temporaryDirectory.'/crontab-state'));
    }

    #[Test]
    public function cron_fallback_fails_before_mutation_when_the_runtime_probe_is_not_registered(): void
    {
        $process = $this->runScript([
            'FAKE_MISSING' => 'true',
            'FAKE_SCHEDULE_REGISTRATION_MISSING' => 'true',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=scheduler_registration_missing', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/crontab-state');
    }

    #[Test]
    public function scheduler_refresh_rejects_a_relative_php_binary_before_cron_mutation(): void
    {
        $process = $this->runScript(['FAKE_MISSING' => 'true'], phpBin: 'php');

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=invalid_path', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/crontab-state');
    }

    #[Test]
    public function cron_fallback_fails_closed_when_the_existing_crontab_cannot_be_read(): void
    {
        $process = $this->runScript([
            'FAKE_MISSING' => 'true',
            'FAKE_CRONTAB_READ_ERROR' => 'true',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=crontab_read_failed', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/crontab-state');
    }

    #[Test]
    public function cron_fallback_fails_closed_on_a_malformed_managed_block(): void
    {
        $original = "# END fap-api managed scheduler\n# BEGIN fap-api managed scheduler\n";
        file_put_contents($this->temporaryDirectory.'/crontab-state', $original);

        $process = $this->runScript(['FAKE_MISSING' => 'true']);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=cron_managed_block_invalid', $process->getErrorOutput());
        $this->assertSame($original, file_get_contents($this->temporaryDirectory.'/crontab-state'));
    }

    #[Test]
    public function staging_mode_can_skip_a_missing_scheduler_without_touching_supervisor(): void
    {
        $process = $this->runScript(['FAKE_MISSING' => 'true'], required: false);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('scheduler_refresh_optional_skip reason=not_managed', $process->getOutput());
        $this->assertSame('old', file_get_contents($this->temporaryDirectory.'/state'));
    }

    #[Test]
    public function it_rejects_a_restart_that_remains_bound_to_the_old_release(): void
    {
        $process = $this->runScript(['FAKE_STALE_AFTER_RESTART' => 'true']);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('reason=scheduler_release_drift', $process->getErrorOutput());
    }

    #[Test]
    public function deploy_refreshes_the_scheduler_after_queue_programs(): void
    {
        $deploy = file_get_contents(dirname(__DIR__, 3).'/deploy.php');

        $this->assertIsString($deploy);
        $this->assertStringContainsString('restart_supervisor_scheduler.sh', $deploy);
        $this->assertStringContainsString("currentHost()->getAlias() === 'production'", $deploy);
        $this->assertStringContainsString('php_bin="$(command -v {{bin/php}})"', $deploy);
        $this->assertStringContainsString('--php-bin="$php_bin"', $deploy);
        $this->assertStringNotContainsString("--php-bin='.deployPlaceholderPathArg('{{bin/php}}')", $deploy);
        $queueRestart = strpos($deploy, 'foreach ($optionalPrograms as $program)');
        $schedulerRestart = strpos($deploy, 'restart_supervisor_scheduler.sh');

        $this->assertIsInt($queueRestart);
        $this->assertIsInt($schedulerRestart);
        $this->assertGreaterThan($queueRestart, $schedulerRestart);
    }

    /** @param array<string,string> $environment */
    private function runScript(array $environment = [], bool $required = true, ?string $phpBin = null): Process
    {
        $backendRoot = dirname(__DIR__, 2);
        $process = new Process([
            'bash',
            $backendRoot.'/scripts/deploy/restart_supervisor_scheduler.sh',
            '--supervisorctl='.$this->temporaryDirectory.'/supervisorctl',
            '--sudo='.$this->temporaryDirectory.'/sudo',
            '--timeout-bin='.$this->temporaryDirectory.'/timeout',
            '--crontab='.$this->temporaryDirectory.'/crontab',
            '--php-bin='.($phpBin ?? $this->temporaryDirectory.'/php'),
            '--restart-script='.$backendRoot.'/scripts/deploy/restart_supervisor_program_group.sh',
            '--deploy-path='.$this->temporaryDirectory.'/deploy',
            '--proc-root='.$this->temporaryDirectory.'/proc',
            '--required='.($required ? 'true' : 'false'),
        ], $backendRoot, $environment + [
            'FAKE_STATE_FILE' => $this->temporaryDirectory.'/state',
            'FAKE_CRONTAB_FILE' => $this->temporaryDirectory.'/crontab-state',
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
