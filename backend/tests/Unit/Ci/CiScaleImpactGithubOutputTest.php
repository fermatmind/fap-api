<?php

declare(strict_types=1);

namespace Tests\Unit\Ci;

use App\Console\Commands\CiScaleImpact;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CiScaleImpactGithubOutputTest extends TestCase
{
    private string $originalServerGithubOutput = '';

    private bool $hadServerGithubOutput = false;

    private string $originalEnvGithubOutput = '';

    private bool $hadEnvGithubOutput = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadServerGithubOutput = array_key_exists('GITHUB_OUTPUT', $_SERVER);
        $this->originalServerGithubOutput = (string) ($_SERVER['GITHUB_OUTPUT'] ?? '');
        $this->hadEnvGithubOutput = array_key_exists('GITHUB_OUTPUT', $_ENV);
        $this->originalEnvGithubOutput = (string) ($_ENV['GITHUB_OUTPUT'] ?? '');
    }

    protected function tearDown(): void
    {
        if ($this->hadServerGithubOutput) {
            $_SERVER['GITHUB_OUTPUT'] = $this->originalServerGithubOutput;
        } else {
            unset($_SERVER['GITHUB_OUTPUT']);
        }

        if ($this->hadEnvGithubOutput) {
            $_ENV['GITHUB_OUTPUT'] = $this->originalEnvGithubOutput;
        } else {
            unset($_ENV['GITHUB_OUTPUT']);
        }

        parent::tearDown();
    }

    public function test_github_output_rejects_php_stream_wrappers_without_writing(): void
    {
        $targetFile = tempnam(sys_get_temp_dir(), 'ci_scale_impact_output_');
        $this->assertIsString($targetFile);
        file_put_contents($targetFile, '');

        $_SERVER['GITHUB_OUTPUT'] = 'php://filter/write=string.rot13/resource='.$targetFile;
        unset($_ENV['GITHUB_OUTPUT']);

        $this->writeGithubOutput([
            'run_big5_ocean_gate' => true,
            'scale_scope' => 'full_regression',
            'reason' => 'semgrep_stream_wrapper_regression',
        ]);

        $this->assertSame('', file_get_contents($targetFile));

        @unlink($targetFile);
    }

    public function test_github_output_allows_local_runner_output_file(): void
    {
        $targetFile = tempnam(sys_get_temp_dir(), 'ci_scale_impact_output_');
        $this->assertIsString($targetFile);
        file_put_contents($targetFile, '');

        $_SERVER['GITHUB_OUTPUT'] = $targetFile;
        unset($_ENV['GITHUB_OUTPUT']);

        $this->writeGithubOutput([
            'run_big5_ocean_gate' => true,
            'scale_scope' => 'full_regression',
            'reason' => 'local_runner_output',
        ]);

        $output = (string) file_get_contents($targetFile);

        $this->assertStringContainsString('run_big5_ocean_gate=1', $output);
        $this->assertStringContainsString('scale_scope=full_regression', $output);
        $this->assertStringContainsString('reason=local_runner_output', $output);

        @unlink($targetFile);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeGithubOutput(array $payload): void
    {
        $method = new ReflectionMethod(CiScaleImpact::class, 'writeGithubOutput');
        $method->invoke(new CiScaleImpact, $payload);
    }
}
