<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SupervisorQueueGroupReloadTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/fap-supervisor-reload-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);

        file_put_contents($this->temporaryDirectory.'/sudo', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == "-n" ]]; then
  shift
fi
exec "$@"
BASH);
        file_put_contents($this->temporaryDirectory.'/supervisorctl', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

command="${1:-}"
target="${2:-}"
program="${FAKE_PROGRAM:?}"
mode="${FAKE_MODE:?}"
state_file="${FAKE_STATE_FILE:?}"

case "$command" in
  status)
    if [[ "$mode" == "group" && "$target" == "${program}:*" ]]; then
      printf '%s:%s_00 RUNNING pid 101, uptime 0:01:00\n' "$program" "$program"
      printf '%s:%s_01 RUNNING pid 102, uptime 0:01:00\n' "$program" "$program"
      exit 0
    fi
    if [[ "$mode" == "single" && "$target" == "$program" ]]; then
      printf '%s RUNNING pid 101, uptime 0:01:00\n' "$program"
      exit 0
    fi
    printf '%s: ERROR (no such process)\n' "$target" >&2
    exit 4
    ;;
  restart)
    count=0
    if [[ -f "$state_file" ]]; then
      count="$(cat "$state_file")"
    fi
    count=$((count + 1))
    printf '%s' "$count" > "$state_file"
    if [[ "${FAKE_FAIL_FIRST_RESTART:-false}" == "true" && "$count" -eq 1 ]]; then
      exit 1
    fi
    exit 0
    ;;
esac

exit 2
BASH);

        chmod($this->temporaryDirectory.'/sudo', 0700);
        chmod($this->temporaryDirectory.'/supervisorctl', 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    #[Test]
    public function required_group_retries_a_transient_restart_and_never_falls_back_to_a_bare_name(): void
    {
        $this->assertTrue(is_executable(base_path('scripts/deploy/restart_supervisor_program_group.sh')));

        $process = $this->runScript('group', true, true);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'supervisor_program_restart_pass program=fap-queue-default-high attempts=2',
            $process->getOutput(),
        );
        $this->assertSame('2', file_get_contents($this->temporaryDirectory.'/state'));
        $this->assertStringNotContainsString('no such process', $process->getOutput().$process->getErrorOutput());
    }

    #[Test]
    public function a_real_single_process_name_remains_supported(): void
    {
        $process = $this->runScript('single', false, true);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'supervisor_program_restart_pass program=fap-queue-default-high attempts=1',
            $process->getOutput(),
        );
    }

    #[Test]
    public function a_missing_required_program_fails_closed_after_the_exact_retry_limit(): void
    {
        $process = $this->runScript('missing', false, true);

        $this->assertFalse($process->isSuccessful());
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'supervisor_program_restart_failed program=fap-queue-default-high attempts=3',
            $process->getErrorOutput(),
        );
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/state');
    }

    #[Test]
    public function a_missing_optional_program_is_a_safe_non_blocking_skip(): void
    {
        $process = $this->runScript('missing', false, false);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'supervisor_program_restart_optional_skip program=fap-queue-default-high',
            $process->getOutput(),
        );
    }

    private function runScript(string $mode, bool $failFirstRestart, bool $required): Process
    {
        $process = new Process(
            [
                'bash',
                base_path('scripts/deploy/restart_supervisor_program_group.sh'),
                '--supervisorctl='.$this->temporaryDirectory.'/supervisorctl',
                '--sudo='.$this->temporaryDirectory.'/sudo',
                '--program=fap-queue-default-high',
                '--attempts=3',
                '--delay-seconds=0',
                '--required='.($required ? 'true' : 'false'),
            ],
            base_path(),
            [
                'FAKE_PROGRAM' => 'fap-queue-default-high',
                'FAKE_MODE' => $mode,
                'FAKE_STATE_FILE' => $this->temporaryDirectory.'/state',
                'FAKE_FAIL_FIRST_RESTART' => $failFirstRestart ? 'true' : 'false',
            ],
        );
        $process->setTimeout(10);
        $process->run();

        return $process;
    }
}
