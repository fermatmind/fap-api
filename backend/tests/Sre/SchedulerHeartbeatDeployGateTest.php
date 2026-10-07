<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SchedulerHeartbeatDeployGateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/scheduler-deploy-gate-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/release', 0700);
        symlink($this->directory.'/release', $this->directory.'/current');
        $this->executable('date', <<<'BASH'
#!/usr/bin/env bash
count="$(cat "$FAKE_CLOCK_FILE" 2>/dev/null || printf 0)"
count="$((count + 1))"
printf '%s\n' "$count" > "$FAKE_CLOCK_FILE"
if [[ "$count" -le 2 ]]; then printf '100\n'; else printf '200\n'; fi
BASH);
        $this->executable('stat', '#!/usr/bin/env bash'."\n".'printf "%s\\n" "${FAKE_ACTIVATION_EPOCH:-90}"'."\n");
        $this->executable('sleep', "#!/usr/bin/env bash\nexit 0\n");
        $this->executable('php', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == artisan ]]; then
  printf '%s\n' "$FAKE_HEARTBEAT"
  exit "$FAKE_CHECK_EXIT"
fi
exec "$REAL_PHP" "$@"
BASH);
    }

    protected function tearDown(): void
    {
        (new Process(['find', $this->directory, '-depth', '-delete']))->mustRun();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool, int, int}> */
    public static function observations(): iterable
    {
        yield 'natural tick after activation and before the wait' => ['1970-01-01T00:01:35Z', true, 0, 0];
        yield 'natural tick after the wait' => ['1970-01-01T00:01:40Z', true, 0, 0];
        yield 'previous release heartbeat' => ['1970-01-01T00:01:29Z', true, 0, 1];
        yield 'failed stale or overlapping heartbeat' => ['1970-01-01T00:01:35Z', false, 1, 1];
        yield 'checker fails despite valid-looking output' => ['1970-01-01T00:01:35Z', true, 2, 1];
        yield 'malformed observation' => ['invalid', true, 0, 1];
    }

    #[Test]
    #[DataProvider('observations')]
    public function activation_requires_a_healthy_natural_tick_from_the_active_release(
        string $observedAt,
        bool $healthy,
        int $checkerExit,
        int $expectedExit,
    ): void {
        $process = $this->gate($observedAt, $healthy, $checkerExit);
        $process->run();
        $this->assertSame($expectedExit, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString(
            $expectedExit === 0 ? 'scheduler_heartbeat_gate_pass' : 'natural_tick_timeout',
            $process->getOutput().$process->getErrorOutput(),
        );
    }

    public function test_missing_activation_symlink_fails_closed(): void
    {
        unlink($this->directory.'/current');
        $process = $this->gate('1970-01-01T00:01:35Z', true, 0);
        $process->run();
        $this->assertFalse($process->isSuccessful());
    }

    public function test_invalid_or_future_activation_timestamps_fail_closed(): void
    {
        foreach (['invalid', '101'] as $activationEpoch) {
            $process = $this->gate('1970-01-01T00:01:40Z', true, 0, $activationEpoch);
            $process->run();
            $this->assertFalse($process->isSuccessful());
        }
    }

    private function gate(string $observedAt, bool $healthy, int $checkerExit, string $activationEpoch = '90'): Process
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        $task = substr($source, (int) strpos($source, "task('scheduler:wait-natural-heartbeat'"));
        $this->assertSame(1, preg_match("/<<<'BASH'\n(.*?)\nBASH, timeout: 95/s", $task, $matches));
        $script = str_replace(
            ['{{bin/php}}', '{{current_path}}'],
            [$this->directory.'/php', $this->directory.'/current'],
            $matches[1],
        );

        return new Process(['bash', '-c', $script], $this->directory, [
            'PATH' => $this->directory.':'.getenv('PATH'),
            'FAKE_CLOCK_FILE' => $this->directory.'/clock',
            'FAKE_HEARTBEAT' => json_encode(['ok' => $healthy, 'observed_at' => $observedAt], JSON_THROW_ON_ERROR),
            'FAKE_CHECK_EXIT' => (string) $checkerExit,
            'FAKE_ACTIVATION_EPOCH' => $activationEpoch,
            'REAL_PHP' => PHP_BINARY,
        ], null, 5);
    }

    private function executable(string $name, string $contents): void
    {
        file_put_contents($this->directory.'/'.$name, $contents);
        chmod($this->directory.'/'.$name, 0700);
    }
}
