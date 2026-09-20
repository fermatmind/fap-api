<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SupervisorQueueCapabilityPreflightTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/fap-supervisor-preflight-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);

        $this->writeExecutable('sudo', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == "-n" ]] && shift
exec "$@"
BASH);
        $this->writeExecutable('supervisorctl', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == "status" ]]
call_count=0
if [[ -f "$FAKE_CALL_COUNT" ]]; then call_count="$(cat "$FAKE_CALL_COUNT")"; fi
IFS=',' read -r -a states <<< "$FAKE_STATES"
last_index=$((${#states[@]} - 1))
index=$call_count
(( index <= last_index )) || index=$last_index
state="${states[$index]}"
printf '%s' "$((call_count + 1))" > "$FAKE_CALL_COUNT"
if [[ "$state" != MISSING ]]; then
  printf 'fap-queue-reports:fap-queue-reports_00 %s\n' "$state"
  printf 'fap-queue-reports:fap-queue-reports_01 %s\n' "$state"
fi
BASH);
    }

    protected function tearDown(): void
    {
        (new Process(['find', $this->temporaryDirectory, '-depth', '-delete']))->mustRun();
        parent::tearDown();
    }

    #[Test]
    public function running_and_stopped_programs_are_recoverable(): void
    {
        foreach (['RUNNING', 'STOPPED'] as $state) {
            $process = $this->runPreflight($state);
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringContainsString('status=recoverable', $process->getOutput());
        }
    }

    #[Test]
    public function transient_program_recovers_within_the_retry_budget(): void
    {
        $process = $this->runPreflight('STARTING,STARTING,RUNNING');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('attempts=3', $process->getOutput());
    }

    #[Test]
    public function persistent_transient_program_fails_closed(): void
    {
        $process = $this->runPreflight('STOPPING');

        $this->assertSame(22, $process->getExitCode());
        $this->assertStringContainsString('status=transient_timeout', $process->getErrorOutput());
        $this->assertSame('6', file_get_contents($this->temporaryDirectory.'/calls'));
    }

    #[Test]
    public function missing_and_failed_programs_fail_without_retrying(): void
    {
        foreach (['MISSING' => 20, 'BACKOFF' => 21, 'FATAL' => 21] as $state => $exitCode) {
            $process = $this->runPreflight($state);
            $this->assertSame($exitCode, $process->getExitCode());
            $this->assertSame('1', file_get_contents($this->temporaryDirectory.'/calls'));
        }
    }

    private function runPreflight(string $states): Process
    {
        $callCount = $this->temporaryDirectory.'/calls';
        if (is_file($callCount)) {
            unlink($callCount);
        }
        $backendRoot = dirname(__DIR__, 2);
        $process = new Process([
            'bash',
            $backendRoot.'/scripts/deploy/check_supervisor_program_status.sh',
            '--supervisorctl='.$this->temporaryDirectory.'/supervisorctl',
            '--sudo='.$this->temporaryDirectory.'/sudo',
            '--program=fap-queue-reports',
            '--retries=5',
            '--delay-seconds=0',
        ], $backendRoot, [
            'FAKE_CALL_COUNT' => $this->temporaryDirectory.'/calls',
            'FAKE_STATES' => $states,
        ]);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }

    private function writeExecutable(string $name, string $contents): void
    {
        file_put_contents($this->temporaryDirectory.'/'.$name, $contents);
        chmod($this->temporaryDirectory.'/'.$name, 0700);
    }
}
