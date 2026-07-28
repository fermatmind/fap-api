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
        file_put_contents($this->temporaryDirectory.'/timeout', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
while [[ "${1:-}" == --signal=* || "${1:-}" == --kill-after=* ]]; do
  shift
done
[[ "${1:-}" =~ ^[0-9]+s$ ]]
shift
if [[ "${FAKE_TIMEOUT_FORCE:-false}" == "true" ]]; then
  exit 124
fi
if [[ "${FAKE_TIMEOUT_FORCE_KILL:-false}" == "true" ]]; then
  kill -KILL "$$"
fi
"$@" &
child_pid=$!
cleanup_child() {
  kill -TERM "$child_pid" 2>/dev/null || true
  wait "$child_pid" 2>/dev/null || true
  exit 143
}
if [[ "${FAKE_TIMEOUT_IGNORE_TERM:-false}" == "true" ]]; then
  trap '' TERM
else
  trap cleanup_child HUP INT TERM
fi
set +e
wait "$child_pid"
status=$?
set -e
exit "$status"
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
    if [[ -n "${FAKE_RESTART_PID_FILE:-}" ]]; then
      printf '%s' "$$" > "$FAKE_RESTART_PID_FILE"
    fi
    if [[ "${FAKE_SLOW_RESTART:-false}" == "true" ]]; then
      sleep_pid=""
      cleanup_restart() {
        if [[ -n "$sleep_pid" ]] && kill -0 "$sleep_pid" 2>/dev/null; then
          kill -TERM "$sleep_pid" 2>/dev/null || true
          wait "$sleep_pid" 2>/dev/null || true
        fi
        exit 143
      }
      trap cleanup_restart HUP INT TERM
      sleep "${FAKE_SLOW_RESTART_SECONDS:-2}" &
      sleep_pid=$!
      wait "$sleep_pid"
    fi
    exit 0
    ;;
esac

exit 2
BASH);

        chmod($this->temporaryDirectory.'/sudo', 0700);
        chmod($this->temporaryDirectory.'/timeout', 0700);
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

    #[Test]
    public function a_slow_supervisor_restart_emits_only_sanitized_heartbeats_and_preserves_success(): void
    {
        $process = $this->runScript('group', false, true, slowRestart: true);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'supervisor_program_restart_heartbeat program=fap-queue-default-high attempt=1',
            $process->getOutput(),
        );
        $this->assertStringContainsString(
            'supervisor_program_restart_pass program=fap-queue-default-high attempts=1',
            $process->getOutput(),
        );
        $this->assertStringNotContainsString('pid ', $process->getOutput().$process->getErrorOutput());
    }

    #[Test]
    public function a_supervisor_restart_timeout_is_classified_and_fails_closed(): void
    {
        $process = $this->runScript('group', false, true, forceTimeout: true);

        $this->assertFalse($process->isSuccessful());
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame(
            3,
            substr_count(
                $process->getErrorOutput(),
                'supervisor_program_restart_timeout program=fap-queue-default-high attempt=',
            ),
        );
        $this->assertStringContainsString(
            'supervisor_program_restart_failed program=fap-queue-default-high attempts=3',
            $process->getErrorOutput(),
        );
    }

    #[Test]
    public function a_force_killed_timeout_does_not_leak_the_job_command_or_pid(): void
    {
        $process = $this->runScript('group', false, true, forceKill: true);
        $combinedOutput = $process->getOutput().$process->getErrorOutput();

        $this->assertFalse($process->isSuccessful());
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame(
            3,
            substr_count(
                $process->getErrorOutput(),
                'supervisor_program_restart_timeout program=fap-queue-default-high attempt=',
            ),
        );
        $this->assertStringNotContainsString('Killed', $combinedOutput);
        $this->assertStringNotContainsString($this->temporaryDirectory, $combinedOutput);
        $this->assertStringNotContainsString('supervisorctl restart', $combinedOutput);
    }

    #[Test]
    public function termination_stops_the_exact_restart_child_and_returns_signal_status(): void
    {
        $restartPidFile = $this->temporaryDirectory.'/restart.pid';
        $process = $this->makeProcess(
            'group',
            false,
            true,
            slowRestart: true,
            slowRestartSeconds: 20,
            restartPidFile: $restartPidFile,
            timeoutIgnoresTerm: true,
        );
        $process->start();

        for ($attempt = 0; $attempt < 100 && ! is_file($restartPidFile); $attempt++) {
            usleep(20_000);
        }

        $this->assertFileExists($restartPidFile);
        $restartPid = (int) file_get_contents($restartPidFile);
        $this->assertGreaterThan(1, $restartPid);

        $process->signal(SIGTERM);
        $this->assertSame(143, $process->wait());

        for ($attempt = 0; $attempt < 100 && posix_kill($restartPid, 0); $attempt++) {
            usleep(20_000);
        }

        $this->assertFalse(posix_kill($restartPid, 0), 'The exact Supervisor restart child remained alive.');
    }

    private function runScript(
        string $mode,
        bool $failFirstRestart,
        bool $required,
        bool $slowRestart = false,
        bool $forceTimeout = false,
        bool $forceKill = false,
    ): Process {
        $process = $this->makeProcess(
            $mode,
            $failFirstRestart,
            $required,
            $slowRestart,
            $forceTimeout,
            $forceKill,
        );
        $process->run();

        return $process;
    }

    private function makeProcess(
        string $mode,
        bool $failFirstRestart,
        bool $required,
        bool $slowRestart = false,
        bool $forceTimeout = false,
        bool $forceKill = false,
        int $slowRestartSeconds = 2,
        string $restartPidFile = '',
        bool $timeoutIgnoresTerm = false,
    ): Process {
        $process = new Process(
            [
                'bash',
                base_path('scripts/deploy/restart_supervisor_program_group.sh'),
                '--supervisorctl='.$this->temporaryDirectory.'/supervisorctl',
                '--sudo='.$this->temporaryDirectory.'/sudo',
                '--timeout-bin='.$this->temporaryDirectory.'/timeout',
                '--program=fap-queue-default-high',
                '--attempts=3',
                '--delay-seconds=0',
                '--restart-timeout-seconds=30',
                '--heartbeat-seconds=1',
                '--required='.($required ? 'true' : 'false'),
            ],
            base_path(),
            [
                'FAKE_PROGRAM' => 'fap-queue-default-high',
                'FAKE_MODE' => $mode,
                'FAKE_STATE_FILE' => $this->temporaryDirectory.'/state',
                'FAKE_FAIL_FIRST_RESTART' => $failFirstRestart ? 'true' : 'false',
                'FAKE_SLOW_RESTART' => $slowRestart ? 'true' : 'false',
                'FAKE_TIMEOUT_FORCE' => $forceTimeout ? 'true' : 'false',
                'FAKE_TIMEOUT_FORCE_KILL' => $forceKill ? 'true' : 'false',
                'FAKE_TIMEOUT_IGNORE_TERM' => $timeoutIgnoresTerm ? 'true' : 'false',
                'FAKE_SLOW_RESTART_SECONDS' => (string) $slowRestartSeconds,
                'FAKE_RESTART_PID_FILE' => $restartPidFile,
            ],
        );
        $process->setTimeout(10);

        return $process;
    }
}
