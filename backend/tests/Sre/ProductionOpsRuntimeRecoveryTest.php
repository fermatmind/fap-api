<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProductionOpsRuntimeRecoveryTest extends TestCase
{
    private string $temporaryDirectory;

    private string $releaseSha = '256c7882c0c47347ec9497dc4ab1ce2cfb8a80c0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/ops-runtime-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory.'/deploy/releases/current/backend', 0777, true);
        mkdir($this->temporaryDirectory.'/etc/supervisor', 0777, true);
        file_put_contents($this->temporaryDirectory.'/deploy/releases/current/REVISION', $this->releaseSha."\n");
        symlink(
            $this->temporaryDirectory.'/deploy/releases/current',
            $this->temporaryDirectory.'/deploy/current',
        );
        file_put_contents($this->temporaryDirectory.'/state', "stopped\n");
        file_put_contents(
            $this->temporaryDirectory.'/etc/supervisor/queues.conf',
            "[program:fap-queue-default-high]\ncommand=default\n\n".
            "[program:fap-queue-ops]\ncommand=ops\n",
        );
        file_put_contents($this->temporaryDirectory.'/sudo', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
while [[ "${1:-}" == "-n" || "${1:-}" == "-u" ]]; do
  if [[ "$1" == "-u" ]]; then shift 2; else shift; fi
done
if [[ "${1:-}" == "find" ]]; then
  printf '%s\n' /etc/supervisor/queues.conf
  exit 0
fi
translated=()
for argument in "$@"; do
  if [[ "$argument" == "/etc/supervisor/queues.conf" ]]; then
    translated+=("$FAKE_CONFIG_PATH")
  else
    translated+=("$argument")
  fi
done
exec "${translated[@]}"
BASH);
        file_put_contents($this->temporaryDirectory.'/php', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "${FAKE_OPS_PENDING:-0}"
BASH);
        file_put_contents($this->temporaryDirectory.'/ps', <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        file_put_contents($this->temporaryDirectory.'/supervisorctl', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
state="$(tr -d '\r\n' < "$FAKE_STATE_FILE")"
case "${1:-}" in
  status)
    printf '%s\n' "fap-queue-default-high:fap-queue-default-high_00 RUNNING pid 111, uptime 1:00:00"
    if [[ "$state" == stopped ]]; then
      printf '%s\n' "fap-queue-ops:fap-queue-ops_00 STOPPED Not started"
      exit 3
    fi
    printf '%s\n' "fap-queue-ops:fap-queue-ops_00 RUNNING pid 222, uptime 0:00:01"
    ;;
  restart)
    [[ "${2:-}" == "fap-queue-ops:*" ]]
    printf '%s\n' running > "$FAKE_STATE_FILE"
    ;;
  *)
    exit 2
    ;;
esac
BASH);
        foreach (['sudo', 'php', 'ps', 'supervisorctl'] as $file) {
            chmod($this->temporaryDirectory.'/'.$file, 0700);
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->temporaryDirectory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($this->temporaryDirectory);
        parent::tearDown();
    }

    #[Test]
    public function preflight_is_read_only_and_apply_restarts_only_ops_without_config_changes(): void
    {
        $configBefore = hash_file('sha256', $this->configPath());
        $preflight = $this->runControl('preflight');

        $this->assertTrue($preflight->isSuccessful(), $preflight->getErrorOutput());
        $fields = explode("\t", trim($preflight->getOutput()));
        $this->assertCount(11, $fields);
        $this->assertSame('NOT_RUNNING', $fields[3]);
        $this->assertSame('0', $fields[4]);
        $this->assertSame('SHARED', $fields[7]);
        $this->assertSame('true', $fields[9]);
        $this->assertSame('true', $fields[10]);
        $this->assertSame("stopped\n", file_get_contents($this->temporaryDirectory.'/state'));

        $apply = $this->runControl('apply', [
            'EXPECTED_TARGET_SET_SHA256' => $fields[1],
            'EXPECTED_STATE_SHA256' => $fields[2],
            'EXPECTED_CONFIG_PATH_SHA256' => $fields[5],
            'EXPECTED_CONFIG_SHA256' => $fields[6],
            'EXPECTED_FOREIGN_RUNTIME_SHA256' => $fields[8],
        ]);

        $this->assertTrue($apply->isSuccessful(), $apply->getErrorOutput());
        $this->assertSame("running\n", file_get_contents($this->temporaryDirectory.'/state'));
        $this->assertSame($configBefore, hash_file('sha256', $this->configPath()));
    }

    #[Test]
    public function apply_fails_closed_on_config_drift(): void
    {
        $preflight = $this->runControl('preflight');
        $fields = explode("\t", trim($preflight->getOutput()));
        file_put_contents($this->configPath(), "\n# drift\n", FILE_APPEND);

        $apply = $this->runControl('apply', [
            'EXPECTED_TARGET_SET_SHA256' => $fields[1],
            'EXPECTED_STATE_SHA256' => $fields[2],
            'EXPECTED_CONFIG_PATH_SHA256' => $fields[5],
            'EXPECTED_CONFIG_SHA256' => $fields[6],
            'EXPECTED_FOREIGN_RUNTIME_SHA256' => $fields[8],
        ]);

        $this->assertFalse($apply->isSuccessful());
        $this->assertStringContainsString('OPS_RUNTIME_CONTROL_FAILED:STATE_DRIFT', $apply->getErrorOutput());
        $this->assertSame("stopped\n", file_get_contents($this->temporaryDirectory.'/state'));
    }

    #[Test]
    public function workflow_binds_exact_receipt_and_forbids_adjacent_writes(): void
    {
        $workflow = file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/backend-production-ops-runtime-recovery.yml',
        );
        $this->assertIsString($workflow);
        foreach ([
            'Backend Production Ops Runtime Recovery',
            'backend.production_ops_runtime_recovery.v1',
            'and .program_state == "NOT_RUNNING"',
            'and .ops_pending_total == 0',
            'restart only non-running fap-queue-ops with zero queued ops jobs',
            'config_write_count: 0',
            'deploy_count: 0',
            'publication_or_discoverability_write_count: 0',
            'search_submission_count: 0',
            'secrets.PRODUCTION_DEPLOY_HOST',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString('supervisorctl restart all', $workflow);
        $this->assertStringNotContainsString('cat "$RUNNER_TEMP/ops-runtime-control.err"', $workflow);
    }

    private function configPath(): string
    {
        return $this->temporaryDirectory.'/etc/supervisor/queues.conf';
    }

    private function runControl(string $mode, array $overrides = []): Process
    {
        $process = new Process(
            ['bash', base_path('scripts/deploy/control_ops_supervisor_runtime.sh')],
            base_path(),
            array_merge([
                'MODE' => $mode,
                'DEPLOY_PATH' => $this->temporaryDirectory.'/deploy',
                'EXPECTED_ACTIVE_REVISION' => $this->releaseSha,
                'SUPERVISORCTL_PATH' => $this->temporaryDirectory.'/supervisorctl',
                'PHP_PATH' => $this->temporaryDirectory.'/php',
                'SUDO_PATH' => $this->temporaryDirectory.'/sudo',
                'FAKE_STATE_FILE' => $this->temporaryDirectory.'/state',
                'FAKE_SUPERVISOR_ROOT' => $this->temporaryDirectory.'/etc/supervisor',
                'FAKE_CONFIG_PATH' => $this->configPath(),
                'PATH' => $this->temporaryDirectory.':'.getenv('PATH'),
            ], $overrides),
        );
        $process->setTimeout(10);
        $process->run();

        return $process;
    }
}
