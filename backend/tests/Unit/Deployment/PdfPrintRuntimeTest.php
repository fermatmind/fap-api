<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PdfPrintRuntimeTest extends TestCase
{
    public function test_initialization_preserves_other_environment_bytes_and_is_idempotent(): void
    {
        $this->fixture(function (string $env, string $input, string $key): void {
            $original = "# untouched\r\nAPP_KEY=synthetic-unrelated-key\r\nAPP_ENV=production\r\n";
            file_put_contents($env, $original);
            chmod($env, 0640);
            foreach ([1, 2] as $_) {
                $process = $this->runHelper($env, $input);
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                self::assertSame("pdf_print_runtime=configured\n", $process->getOutput());
                self::assertStringNotContainsString($key, $process->getOutput().$process->getErrorOutput());
                self::assertSame($original.'GOTENBERG_RESULT_PRINT_TOKEN_SECRET='.$key."\n", file_get_contents($env));
                clearstatcache(true, $env);
                self::assertSame(0640, fileperms($env) & 0777);
            }
        });
    }

    public function test_a_conflicting_existing_key_is_never_rotated(): void
    {
        $this->fixture(function (string $env, string $input, string $key): void {
            $original = 'GOTENBERG_RESULT_PRINT_TOKEN_SECRET='.str_repeat('b', 64)."\n";
            file_put_contents($env, $original);
            $process = $this->runHelper($env, $input);
            self::assertNotSame(0, $process->getExitCode());
            self::assertSame($original, file_get_contents($env));
            self::assertSame("PDF_PRINT_RUNTIME_EXISTING_KEY_CONFLICT\n", $process->getErrorOutput());
            self::assertStringNotContainsString($key, $process->getOutput().$process->getErrorOutput());
        });
    }

    public function test_bad_keys_unsafe_inputs_and_symlink_targets_do_not_change_environment(): void
    {
        foreach (['missing', 'short', 'newline', 'public', 'symlink-input', 'symlink-env'] as $mode) {
            $this->fixture(function (string $env, string $input) use ($mode): void {
                $original = "APP_ENV=production\n";
                file_put_contents($env, $original);
                $target = $env;
                if ($mode === 'missing') {
                    unlink($input);
                } elseif ($mode === 'short') {
                    file_put_contents($input, 'bad');
                } elseif ($mode === 'newline') {
                    file_put_contents($input, str_repeat('a', 63)."\n");
                } elseif ($mode === 'public') {
                    chmod($input, 0644);
                } elseif ($mode === 'symlink-input') {
                    rename($input, $input.'.real');
                    symlink($input.'.real', $input);
                } elseif ($mode === 'symlink-env') {
                    $target = $env.'.link';
                    symlink($env, $target);
                }
                self::assertNotSame(0, $this->runHelper($target, $input)->getExitCode(), $mode);
                self::assertSame($original, file_get_contents($env), $mode);
            });
        }
    }

    public function test_unexpected_errors_report_only_fixed_stage_metadata_and_preserve_environment(): void
    {
        foreach (['fsync', 'fsync,fclose'] as $disabled) {
            $this->fixture(function (string $env, string $input, string $key) use ($disabled): void {
                $original = "APP_ENV=production\n";
                file_put_contents($env, $original);
                $process = $this->runHelper($env, $input, ['-d', 'disable_functions='.$disabled]);
                self::assertNotSame(0, $process->getExitCode());
                self::assertMatchesRegularExpression('/\APDF_PRINT_RUNTIME_FAILED stage=sync exception=Error line=[0-9]+ activated=0\n\z/', $process->getErrorOutput());
                self::assertStringNotContainsString($key, $process->getOutput().$process->getErrorOutput());
                self::assertStringNotContainsString($env, $process->getOutput().$process->getErrorOutput());
                self::assertSame($original, file_get_contents($env));
            });
        }
    }

    public function test_native_filesystem_warnings_do_not_expose_paths_or_override_the_fixed_diagnostic(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission denial requires a non-root process.');
        }
        $this->fixture(function (string $env, string $input, string $key): void {
            $original = "APP_ENV=production\n";
            file_put_contents($env, $original);
            chmod($env, 0000);
            try {
                $process = $this->runHelper($env, $input, ['-d', 'display_errors=0']);
            } finally {
                chmod($env, 0600);
            }
            self::assertNotSame(0, $process->getExitCode());
            self::assertMatchesRegularExpression('/\APDF_PRINT_RUNTIME_FAILED stage=lock exception=ErrorException line=[0-9]+ activated=0\n\z/', $process->getErrorOutput());
            self::assertStringNotContainsString($key, $process->getOutput().$process->getErrorOutput());
            self::assertStringNotContainsString(dirname($env), $process->getOutput().$process->getErrorOutput());
            self::assertStringNotContainsString('configure_pdf_print_runtime.php', $process->getOutput().$process->getErrorOutput());
            self::assertSame($original, file_get_contents($env));
        });
    }

    private function runHelper(string $env, string $input, array $phpArguments = []): Process
    {
        $process = new Process([PHP_BINARY, ...$phpArguments, dirname(__DIR__, 3).'/scripts/deploy/configure_pdf_print_runtime.php', $env, $input]);
        $process->run();

        return $process;
    }

    private function fixture(callable $assertions): void
    {
        $directory = sys_get_temp_dir().'/pdf-runtime-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $key = str_repeat('a', 64);
        file_put_contents($directory.'/input', $key);
        chmod($directory.'/input', 0600);
        try {
            $assertions($directory.'/.env', $directory.'/input', $key);
        } finally {
            foreach (glob($directory.'/{*,.*}', GLOB_BRACE) ?: [] as $file) {
                if (! in_array(basename($file), ['.', '..'], true)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
