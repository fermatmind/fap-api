<?php

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProductionRequiredQueueRuntimeControlTest extends TestCase
{
    private string $temporaryDirectory;

    private string $releaseSha = '256c7882c0c47347ec9497dc4ab1ce2cfb8a80c0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/required-queue-control-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory.'/deploy/releases/current-release/backend', 0777, true);
        file_put_contents(
            $this->temporaryDirectory.'/deploy/releases/current-release/REVISION',
            $this->releaseSha."\n",
        );
        symlink(
            $this->temporaryDirectory.'/deploy/releases/current-release',
            $this->temporaryDirectory.'/deploy/current',
        );
        file_put_contents($this->temporaryDirectory.'/state', "default_stopped\n");

        file_put_contents($this->temporaryDirectory.'/sudo', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
while [[ "${1:-}" == "-n" || "${1:-}" == "-u" ]]; do
  if [[ "$1" == "-u" ]]; then shift 2; else shift; fi
done
exec "$@"
BASH);
        file_put_contents($this->temporaryDirectory.'/php', <<<'BASH'
#!/usr/bin/env bash
high="${FAKE_HIGH_PENDING:-0}"
default="${FAKE_DEFAULT_PENDING:-0}"
reports="${FAKE_REPORTS_PENDING:-0}"
class="${FAKE_JOB_CLASS:-App.Jobs.Career.WarmCareerJobDetailProjection}"
classes="$(printf '{"high":{"%s":%s},"default":{"%s":%s},"reports":{"%s":%s}}' \
  "$class" "$high" "$class" "$default" "$class" "$reports")"
classes_b64="$(printf '%s' "$classes" | base64 | tr -d '\n')"
printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
  "${FAKE_HIGH_PENDING:-0}" \
  "${FAKE_DEFAULT_PENDING:-0}" \
  "${FAKE_REPORTS_PENDING:-0}" \
  "${FAKE_UNKNOWN_CLASS_COUNT:-0}" \
  "${FAKE_OLDEST_PENDING_SECONDS:-120}" \
  "${FAKE_BACKLOG_SNAPSHOT_SHA256:-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" \
  "$classes_b64"
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
    if [[ "$state" == "default_stopped" ]]; then
      printf '%s\n' \
        "fap-queue-default-high:fap-queue-default-high_00 STOPPED Not started" \
        "fap-queue-reports:fap-queue-reports_00 RUNNING pid 222, uptime 0:01:00"
      exit 3
    fi
    printf '%s\n' \
      "fap-queue-default-high:fap-queue-default-high_00 RUNNING pid 111, uptime 0:00:01" \
      "fap-queue-reports:fap-queue-reports_00 RUNNING pid 222, uptime 0:02:00"
    ;;
  restart)
    [[ "${2:-}" == "fap-queue-default-high:*" ]]
    printf '%s\n' "all_running" > "$FAKE_STATE_FILE"
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
    public function preflight_is_read_only_and_apply_restarts_only_the_non_running_required_group(): void
    {
        $preflight = $this->runControl('preflight');

        $this->assertTrue($preflight->isSuccessful(), $preflight->getErrorOutput());
        $fields = explode("\t", trim($preflight->getOutput()));
        $this->assertSame($this->releaseSha, $fields[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fields[1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fields[2]);
        $this->assertSame('NOT_RUNNING', $fields[3]);
        $this->assertSame('RUNNING', $fields[4]);
        $this->assertSame('0', $fields[5]);
        $this->assertSame('true', $fields[9]);
        $this->assertSame('true', $fields[10]);
        $this->assertSame('0', $fields[12]);
        $this->assertSame('120', $fields[13]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fields[14]);
        $this->assertSame(
            [
                'high' => ['App.Jobs.Career.WarmCareerJobDetailProjection' => 0],
                'default' => ['App.Jobs.Career.WarmCareerJobDetailProjection' => 0],
                'reports' => ['App.Jobs.Career.WarmCareerJobDetailProjection' => 0],
            ],
            json_decode(base64_decode($fields[15]), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame("default_stopped\n", file_get_contents($this->temporaryDirectory.'/state'));

        $apply = $this->runControl('apply', $fields[2]);

        $this->assertTrue($apply->isSuccessful(), $apply->getErrorOutput());
        $this->assertSame("all_running\n", file_get_contents($this->temporaryDirectory.'/state'));
        $applyFields = explode("\t", trim($apply->getOutput()));
        $this->assertSame($fields[2], $applyFields[2]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $applyFields[3]);
    }

    #[Test]
    public function apply_fails_closed_when_runtime_state_drifted_after_preflight(): void
    {
        $preflight = $this->runControl('preflight');
        $fields = explode("\t", trim($preflight->getOutput()));
        file_put_contents($this->temporaryDirectory.'/state', "all_running\n");

        $apply = $this->runControl('apply', $fields[2]);

        $this->assertFalse($apply->isSuccessful());
        $this->assertStringContainsString(
            'REQUIRED_QUEUE_CONTROL_FAILED:STATE_DRIFT',
            $apply->getErrorOutput(),
        );
    }

    #[Test]
    public function preflight_reports_backlog_without_writes_and_apply_remains_fail_closed(): void
    {
        $preflight = $this->runControl('preflight', '', '2');

        $this->assertTrue($preflight->isSuccessful(), $preflight->getErrorOutput());
        $fields = explode("\t", trim($preflight->getOutput()));
        $this->assertSame('6', $fields[5]);
        $this->assertSame('2', $fields[6]);
        $this->assertSame('2', $fields[7]);
        $this->assertSame('2', $fields[8]);
        $this->assertSame('true', $fields[9]);
        $this->assertSame('false', $fields[10]);
        $this->assertSame('0', $fields[12]);
        $this->assertSame('120', $fields[13]);
        $this->assertSame(
            [
                'high' => ['App.Jobs.Career.WarmCareerJobDetailProjection' => 2],
                'default' => ['App.Jobs.Career.WarmCareerJobDetailProjection' => 2],
                'reports' => ['App.Jobs.Career.WarmCareerJobDetailProjection' => 2],
            ],
            json_decode(base64_decode($fields[15]), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString('private-payload-value', $preflight->getOutput());
        $this->assertSame("default_stopped\n", file_get_contents($this->temporaryDirectory.'/state'));

        $apply = $this->runControl('apply', $fields[2], '2');

        $this->assertFalse($apply->isSuccessful());
        $this->assertStringContainsString(
            'REQUIRED_QUEUE_CONTROL_FAILED:QUEUE_BACKLOG_PRESENT',
            $apply->getErrorOutput(),
        );
        $this->assertSame("default_stopped\n", file_get_contents($this->temporaryDirectory.'/state'));
    }

    #[Test]
    public function workflow_binds_latest_main_receipt_and_has_no_unscoped_runtime_target(): void
    {
        $workflow = file_get_contents(
            base_path('../.github/workflows/backend-production-required-queue-runtime-control.yml'),
        );

        $this->assertIsString($workflow);
        $this->assertStringContainsString(
            'name: Backend Production Required Queue Runtime Control',
            $workflow,
        );
        $this->assertStringContainsString(
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            $workflow,
        );
        $this->assertStringContainsString(
            '.contract_version == "backend.production_required_queue_runtime_control.v3"',
            $workflow,
        );
        $this->assertStringContainsString(
            'and .pending_total == 0',
            $workflow,
        );
        $this->assertStringContainsString(
            'restart only non-running fap-queue-default-high and fap-queue-reports workers',
            $workflow,
        );
        $this->assertStringContainsString(
            'secrets.PRODUCTION_DEPLOY_HOST',
            $workflow,
        );
        $this->assertStringNotContainsString(
            'vars.PRODUCTION_DEPLOY_HOST',
            $workflow,
        );
        $this->assertStringNotContainsString(
            'supervisorctl restart all',
            $workflow,
        );
        $this->assertStringContainsString(
            'job_class_counts',
            $workflow,
        );
        $this->assertStringContainsString(
            'backlog_snapshot_sha256',
            $workflow,
        );
        $this->assertStringContainsString(
            'REQUIRED_QUEUE_RESULT_FAILED:%s',
            $workflow,
        );
        foreach ([
            'FIELD_SHAPE',
            'IDENTITY',
            'HASH',
            'STATE',
            'SCALAR',
            'QUEUE_TOTAL',
            'BOOLEAN',
            'CLASS_B64',
            'CLASS_JSON',
            'CLASS_TOTAL',
            'UNKNOWN_TOTAL',
        ] as $safeFailureCategory) {
            $this->assertStringContainsString(
                'parser_fail '.$safeFailureCategory,
                $workflow,
            );
        }
        $this->assertStringNotContainsString(
            'payload:',
            $workflow,
        );

        $runner = file_get_contents(
            base_path('scripts/deploy/control_required_supervisor_programs.sh'),
        );
        $this->assertIsString($runner);
        $this->assertStringContainsString(
            '"$php_path" -d display_errors=0 -- "$current_release/backend"',
            $runner,
        );
        $this->assertStringNotContainsString(
            '-u www-data /usr/bin/env',
            $runner,
        );
        $this->assertStringContainsString(
            '[[ "$queue_probe_rc" -eq 0 ]] || fail QUEUE_PROBE',
            $runner,
        );
    }

    private function runControl(
        string $mode,
        string $expectedStateSha256 = '',
        string $pendingTotal = '0',
    ): Process {
        $process = new Process(
            ['bash', base_path('scripts/deploy/control_required_supervisor_programs.sh')],
            base_path(),
            [
                'MODE' => $mode,
                'DEPLOY_PATH' => $this->temporaryDirectory.'/deploy',
                'EXPECTED_ACTIVE_REVISION' => $this->releaseSha,
                'EXPECTED_STATE_SHA256' => $expectedStateSha256,
                'SUPERVISORCTL_PATH' => $this->temporaryDirectory.'/supervisorctl',
                'PHP_PATH' => $this->temporaryDirectory.'/php',
                'SUDO_PATH' => $this->temporaryDirectory.'/sudo',
                'FAKE_STATE_FILE' => $this->temporaryDirectory.'/state',
                'FAKE_HIGH_PENDING' => $pendingTotal,
                'FAKE_DEFAULT_PENDING' => $pendingTotal,
                'FAKE_REPORTS_PENDING' => $pendingTotal,
                'PATH' => $this->temporaryDirectory.':'.getenv('PATH'),
            ],
        );
        $process->setTimeout(10);
        $process->run();

        return $process;
    }
}
