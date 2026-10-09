<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\EqSourceExecutionMutex;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EqSourceExecutionMutexTest extends TestCase
{
    public function test_recovery_waits_for_the_inherited_phase_lock_after_driver_disconnect(): void
    {
        $directory = sys_get_temp_dir().'/eq-mutex-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $execution = str_repeat('a', 40).'-1234-1';
        $root = dirname(__DIR__, 3);
        $script = <<<'SCRIPT'
require $argv[1].'/vendor/autoload.php';
$lock = App\Services\ContentPromotion\EqSourceExecutionMutex::acquire($argv[2], $argv[3]);
$child = proc_open([PHP_BINARY, '-r', 'usleep(600000);file_put_contents($argv[1], "done");', $argv[2].'/done'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w'],3=>$lock], $pipes);
echo "phase_started"; flush();
usleep(3000000);
proc_close($child);
SCRIPT;
        $driver = new Process([PHP_BINARY, '-r', $script, $root, $directory, $execution]);
        try {
            $driver->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($driver->getOutput(), 'phase_started') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertStringContainsString('phase_started', $driver->getOutput());
            $this->assertLocked($directory, $execution);
            // Kill only this test's driver. Its phase keeps the inherited mutex.
            self::assertTrue(posix_kill($driver->getPid(), SIGKILL));
            $this->assertLocked($directory, $execution);
            while (! is_file($directory.'/done') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertSame('done', file_get_contents($directory.'/done'));
            $lock = EqSourceExecutionMutex::acquire($directory, $execution, 1);
            self::assertIsResource($lock);
            fclose($lock);
        } finally {
            if ($driver->isRunning()) {
                $driver->stop();
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_recovery_first_permanently_closes_admission_to_a_delayed_driver(): void
    {
        $directory = sys_get_temp_dir().'/eq-delayed-driver-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $execution = str_repeat('b', 40).'-1234-1';
        $root = dirname(__DIR__, 3);
        $script = <<<'SCRIPT'
require $argv[1].'/vendor/autoload.php';
echo "paused_before_mutex"; flush();
$deadline=microtime(true)+5;
while(!is_file($argv[2].'/resume')&&microtime(true)<$deadline)usleep(10000);
$lock=App\Services\ContentPromotion\EqSourceExecutionMutex::acquire($argv[2],$argv[3]);
try {
    App\Services\ContentPromotion\EqSourceExecutionMutex::claimExecution($argv[2],$argv[3],false);
    file_put_contents($argv[2].'/publication_started','unsafe');
} catch (DomainException $error) { echo "|".$error->getMessage(); }
SCRIPT;
        $driver = new Process([PHP_BINARY, '-r', $script, $root, $directory, $execution]);
        try {
            $driver->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($driver->getOutput(), 'paused_before_mutex') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertStringContainsString('paused_before_mutex', $driver->getOutput());
            $recovery = EqSourceExecutionMutex::acquire($directory, $execution);
            EqSourceExecutionMutex::claimExecution($directory, $execution, true);
            fclose($recovery);
            file_put_contents($directory.'/resume', 'continue');
            $driver->wait();
            self::assertSame(0, $driver->getExitCode());
            self::assertStringContainsString('eq_source_execution_already_started_or_closed', $driver->getOutput());
            self::assertFileDoesNotExist($directory.'/publication_started');
        } finally {
            if ($driver->isRunning()) {
                $driver->stop();
            }
            foreach (glob($directory.'/*') as $path) {
                is_dir($path) ? rmdir($path) : unlink($path);
            }
            rmdir($directory);
        }
    }

    private function assertLocked(string $directory, string $execution): void
    {
        try {
            $lock = EqSourceExecutionMutex::acquire($directory, $execution, 0.08);
            fclose($lock);
            self::fail('Recovery must not pass a still-running publication phase.');
        } catch (DomainException $exception) {
            self::assertSame('eq_source_execution_still_running', $exception->getMessage());
        }
    }
}
