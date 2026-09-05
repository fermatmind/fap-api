<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SchedulerTickWrapperTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/fap-scheduler-tick-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/backend/storage/app/ops', 0700, true);
        file_put_contents($this->temporaryDirectory.'/backend/artisan', "#!/usr/bin/env php\n");
        $this->writeExecutable('php', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_TICK_LOG"
if [[ "$*" == *"ops:scheduler-heartbeat-record"* && "${FAKE_HEARTBEAT_BLOCK:-false}" == true ]]; then sleep 20; fi
if [[ "$*" == *"artisan schedule:run"* ]]; then
  sleep "${FAKE_SCHEDULE_SLEEP:-0}"
  exit "${FAKE_SCHEDULE_EXIT:-0}"
fi
BASH);
        $this->writeExecutable('flock', <<<'PYTHON'
#!/usr/bin/python3
import fcntl
import sys
try:
    fcntl.flock(9, fcntl.LOCK_EX | fcntl.LOCK_NB)
except BlockingIOError:
    sys.exit(1)
PYTHON);
        $this->writeExecutable('timeout', <<<'PYTHON'
#!/usr/bin/python3
import os
import signal
import subprocess
import sys
args = sys.argv[1:]
assert args[:3] == ['--signal=TERM', '--kill-after=5s', '10s']
process = subprocess.Popen(args[3:], start_new_session=True)
try:
    sys.exit(process.wait(timeout=float(os.environ.get('FAKE_PROBE_TIMEOUT', '10'))))
except subprocess.TimeoutExpired:
    os.killpg(process.pid, signal.SIGKILL)
    process.wait()
    sys.exit(124)
PYTHON);
    }

    protected function tearDown(): void
    {
        (new Process(['find', $this->temporaryDirectory, '-depth', '-delete']))->mustRun();
        parent::tearDown();
    }

    #[Test]
    public function lock_overlap_records_failure_and_does_not_run_a_second_tick(): void
    {
        $first = $this->process(['FAKE_SCHEDULE_SLEEP' => '2']);
        $first->start();
        $this->waitForLog('artisan schedule:run');

        $second = $this->process();
        $second->run();
        $first->wait();
        $log = (string) file_get_contents($this->temporaryDirectory.'/tick.log');

        $this->assertSame(75, $second->getExitCode());
        $this->assertStringContainsString('reason=overlap', $second->getErrorOutput());
        $this->assertSame(1, substr_count($log, 'artisan schedule:run'));
        $this->assertStringContainsString('--status=overlap', $log);
        $this->assertStringContainsString('--status=completed --exit-code=0', $log);
        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
    }

    #[Test]
    public function schedule_failure_is_recorded_and_returned(): void
    {
        $process = $this->process(['FAKE_SCHEDULE_EXIT' => '9']);
        $process->run();
        $log = (string) file_get_contents($this->temporaryDirectory.'/tick.log');

        $this->assertSame(9, $process->getExitCode());
        $this->assertStringContainsString('--status=started', $log);
        $this->assertStringContainsString('--status=completed --exit-code=9', $log);
    }

    #[Test]
    public function blocked_heartbeat_times_out_without_starting_business_work_and_releases_lock(): void
    {
        $blocked = $this->process(['FAKE_HEARTBEAT_BLOCK' => 'true', 'FAKE_PROBE_TIMEOUT' => '0.2']);
        $blocked->run();
        $this->assertSame(124, $blocked->getExitCode());
        $this->assertStringNotContainsString('artisan schedule:run', (string) file_get_contents($this->temporaryDirectory.'/tick.log'));

        $next = $this->process();
        $next->run();
        $this->assertTrue($next->isSuccessful(), $next->getErrorOutput());
    }

    #[Test]
    public function repeated_contention_never_bootstraps_php(): void
    {
        $owner = $this->process(['FAKE_SCHEDULE_SLEEP' => '2']);
        $owner->start();
        $this->waitForLog('artisan schedule:run');
        $before = file_get_contents($this->temporaryDirectory.'/tick.log');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $overlap = $this->process(['FAKE_HEARTBEAT_BLOCK' => 'true']);
            $overlap->run();
            $this->assertSame(75, $overlap->getExitCode());
        }
        $this->assertSame($before, file_get_contents($this->temporaryDirectory.'/tick.log'));
        $owner->wait();
        $this->assertTrue($owner->isSuccessful(), $owner->getErrorOutput());
    }

    /** @param array<string, string> $environment */
    private function process(array $environment = []): Process
    {
        return new Process([
            'bash',
            dirname(__DIR__, 2).'/scripts/deploy/run_scheduler_tick.sh',
            '--php-bin='.$this->temporaryDirectory.'/php',
            '--backend-path='.$this->temporaryDirectory.'/backend',
            '--flock-bin='.$this->temporaryDirectory.'/flock',
            '--timeout-bin='.$this->temporaryDirectory.'/timeout',
        ], dirname(__DIR__, 2), $environment + [
            'FAKE_TICK_LOG' => $this->temporaryDirectory.'/tick.log',
            'FAKE_FLOCK_DIR' => $this->temporaryDirectory.'/flock-held',
        ], null, 10);
    }

    private function waitForLog(string $needle): void
    {
        $deadline = microtime(true) + 3;
        do {
            if (is_file($this->temporaryDirectory.'/tick.log')
                && str_contains((string) file_get_contents($this->temporaryDirectory.'/tick.log'), $needle)) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for scheduler tick fixture.');
    }

    private function writeExecutable(string $name, string $contents): void
    {
        file_put_contents($this->temporaryDirectory.'/'.$name, $contents);
        chmod($this->temporaryDirectory.'/'.$name, 0700);
    }
}
