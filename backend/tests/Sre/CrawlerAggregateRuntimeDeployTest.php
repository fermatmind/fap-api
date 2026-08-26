<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CrawlerAggregateRuntimeDeployTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        parent::tearDown();
    }

    #[Test]
    public function deploy_helper_configures_only_the_four_gated_runtime_keys_idempotently(): void
    {
        $envFile = $this->temporaryEnvFile("APP_ENV=production\nUNRELATED=value\nSEO_INTEL_CRAWLER_LOG_SCHEDULER_ENABLED=false\n");
        $source = $this->temporaryFile("safe log line\n", 'access.log');
        $command = [PHP_BINARY, base_path('scripts/deploy/configure_crawler_aggregate_runtime.php'), $envFile];

        foreach ([1, 2] as $_attempt) {
            $process = new Process($command, base_path(), ['SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY' => $source]);
            $process->mustRun();
            $this->assertStringContainsString('crawler_aggregate_runtime_configured keys=4', $process->getOutput());
            $this->assertStringNotContainsString($source, $process->getOutput());
        }

        $contents = (string) file_get_contents($envFile);
        $this->assertStringContainsString('APP_ENV=production', $contents);
        $this->assertStringContainsString('UNRELATED=value', $contents);
        foreach ([
            'SEO_INTEL_CRAWLER_LOG_SOURCE="'.$source.'"',
            'SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED="true"',
            'SEO_INTEL_CRAWLER_LOG_PRODUCTION_READ_ENABLED="true"',
            'SEO_INTEL_CRAWLER_LOG_SCHEDULER_ENABLED="true"',
        ] as $line) {
            $this->assertSame(1, substr_count($contents, $line));
        }
    }

    #[Test]
    public function deploy_helper_rejects_missing_source_without_mutating_env(): void
    {
        $envFile = $this->temporaryEnvFile("APP_ENV=production\n");
        $before = file_get_contents($envFile);
        $process = new Process(
            [PHP_BINARY, base_path('scripts/deploy/configure_crawler_aggregate_runtime.php'), $envFile],
            base_path(),
            ['SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY' => '/missing/crawler.log'],
        );
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('crawler_runtime_source_invalid', $process->getErrorOutput());
        $this->assertSame($before, file_get_contents($envFile));
    }

    #[Test]
    public function deploy_control_plane_consumes_only_the_production_environment_secret_and_fails_closed(): void
    {
        $deploy = (string) file_get_contents(dirname(base_path()).'/deploy.php');
        $workflow = (string) file_get_contents(dirname(base_path()).'/.github/workflows/deploy.yml');

        $this->assertStringContainsString("task('crawler:configure-aggregate-runtime'", $deploy);
        $this->assertStringContainsString("currentHost()->getAlias() !== 'production'", $deploy);
        $this->assertStringContainsString('deploySafeAbsolutePath(', $deploy);
        $this->assertStringContainsString("after('guard:shared-permissions', 'crawler:configure-aggregate-runtime')", $deploy);
        $this->assertStringContainsString('SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY: ${{ secrets.SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY }}', $workflow);
        $this->assertStringNotContainsString('SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY: ${{ vars.', $workflow);
    }

    private function temporaryFile(string $contents, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'seo07-crawler-deploy-');
        $this->assertIsString($path);
        $target = $path.'-'.$suffix;
        rename($path, $target);
        file_put_contents($target, $contents);
        $this->temporaryPaths[] = $target;

        return $target;
    }

    private function temporaryEnvFile(string $contents): string
    {
        $directory = sys_get_temp_dir().'/seo07-crawler-env-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $target = $directory.'/.env';
        file_put_contents($target, $contents);
        $this->temporaryPaths[] = $directory;
        $this->temporaryPaths[] = $target;

        return $target;
    }
}
