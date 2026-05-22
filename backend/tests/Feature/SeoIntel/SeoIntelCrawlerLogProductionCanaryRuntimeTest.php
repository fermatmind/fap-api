<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\CrawlerLog\CrawlerLogProductionCanaryDryRun;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class SeoIntelCrawlerLogProductionCanaryRuntimeTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    #[Test]
    public function command_runs_single_source_canary_in_dry_run_no_write_mode_only(): void
    {
        $sourcePath = $this->fixtureSourcePath();

        $exitCode = Artisan::call('seo-intel:crawler-log-observe', [
            '--source' => $sourcePath,
            '--approval-phrase' => $this->approvalPhrase($sourcePath),
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--limit' => 2,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('crawler_log_observe', $payload['runtime']);
        $this->assertSame('success', $payload['status']);
        $this->assertSame('single_source_production_canary_dry_run', $payload['mode']);
        $this->assertFalse($payload['fixture_only']);
        $this->assertTrue($payload['source_canary']);
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['no_write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertTrue($payload['production_log_read_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['scheduler_enabled']);
        $this->assertFalse($payload['collector_write_attempted']);
        $this->assertFalse($payload['raw_persistence']);
        $this->assertTrue($payload['approval_phrase_verified']);
        $this->assertSame(2, $payload['requested_limit']);
        $this->assertSame(2, $payload['effective_limit']);
        $this->assertSame(2, $payload['source_line_count_read']);
        $this->assertSame(2, $payload['parsed_line_count']);
        $this->assertSame(basename($sourcePath), $payload['source_descriptor']['basename']);
        $this->assertStringNotContainsString($sourcePath, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function command_requires_exact_approval_phrase_before_any_source_read(): void
    {
        $sourcePath = $this->fixtureSourcePath();

        $exitCode = Artisan::call('seo-intel:crawler-log-observe', [
            '--source' => $sourcePath,
            '--approval-phrase' => 'invalid approval phrase',
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--limit' => 10,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('single_source_production_canary_dry_run', $payload['mode']);
        $this->assertFalse($payload['production_log_read_attempted']);
        $this->assertContains('exact_approval_phrase_required', $payload['issues']);
    }

    #[Test]
    public function command_requires_dry_run_and_no_write_for_source_canary_mode(): void
    {
        $sourcePath = $this->fixtureSourcePath();

        $exitCode = Artisan::call('seo-intel:crawler-log-observe', [
            '--source' => $sourcePath,
            '--approval-phrase' => $this->approvalPhrase($sourcePath),
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('dry_run_required', $payload['issues']);
        $this->assertContains('no_write_required', $payload['issues']);
        $this->assertFalse($payload['production_log_read_attempted']);
    }

    #[Test]
    public function command_caps_single_source_canary_reads_at_1000_lines(): void
    {
        $path = $this->largeSyntheticSourcePath();

        $exitCode = Artisan::call('seo-intel:crawler-log-observe', [
            '--source' => $path,
            '--approval-phrase' => $this->approvalPhrase($path),
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--limit' => 5000,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame(5000, $payload['requested_limit']);
        $this->assertSame(1000, $payload['effective_limit']);
        $this->assertSame(1000, $payload['source_line_count_read']);
        $this->assertSame(1000, $payload['parsed_line_count']);
    }

    #[Test]
    public function help_lists_source_and_approval_flags_but_not_live_execution_flags(): void
    {
        Artisan::call('seo-intel:crawler-log-observe --help');
        $help = Artisan::output();

        foreach ([
            '--source',
            '--approval-phrase',
            '--fixture',
            '--dry-run',
            '--no-write',
            '--json',
            '--limit',
        ] as $allowedOption) {
            $this->assertStringContainsString($allowedOption, $help);
        }

        foreach ([
            '--production',
            '--tail',
            '--schedule',
            '--write',
            '--submit',
        ] as $forbiddenOption) {
            $this->assertDoesNotMatchRegularExpression('/(^|\\s)'.preg_quote($forbiddenOption, '/').'(=|\\s|$)/', $help);
        }
    }

    #[Test]
    public function docs_and_generated_artifact_lock_single_source_canary_runtime_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-production-canary-runtime.md')));
        $artifact = $this->artifact();
        $artifactJson = strtolower((string) json_encode($artifact, JSON_THROW_ON_ERROR));

        $this->assertSame('crawler-log-production-canary-runtime.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-04-CANARY', $artifact['task'] ?? null);
        $this->assertTrue($artifact['single_source_only'] ?? false);
        $this->assertTrue($artifact['no_raw_persistence'] ?? false);
        $this->assertTrue($artifact['dry_run_only'] ?? false);
        $this->assertTrue($artifact['no_write_only'] ?? false);
        $this->assertTrue($artifact['no_issue_queue_write'] ?? false);
        $this->assertTrue($artifact['no_url_truth_write'] ?? false);
        $this->assertTrue($artifact['no_search_submission'] ?? false);

        foreach ([
            'single-source read',
            'max_lines <= 1000',
            'no raw persistence',
            'dry-run / no-write summary',
            'no scheduler',
            'no issue queue write',
            'no url truth write',
            'no search submission',
            'approval phrase',
            'source /var/log/nginx/access.log',
        ] as $required) {
            $this->assertStringContainsString($required, $doc."\n".$artifactJson);
        }
    }

    private function fixtureSourcePath(): string
    {
        $path = $this->temporaryFilePath('access.log');

        copy(base_path('tests/Fixtures/SeoIntel/crawler_logs/nginx_access_sample.log'), $path);

        $this->assertFileExists($path);

        return $path;
    }

    private function approvalPhrase(string $sourcePath): string
    {
        return app(CrawlerLogProductionCanaryDryRun::class)->expectedApprovalPhrase($sourcePath);
    }

    private function largeSyntheticSourcePath(): string
    {
        $path = $this->temporaryFilePath('large_access.log');
        $line = $this->fixtureLines()[0];
        $content = implode("\n", array_fill(0, 1105, $line))."\n";

        file_put_contents($path, $content);
        $this->assertFileExists($path);

        return $path;
    }

    /**
     * @return list<string>
     */
    private function fixtureLines(): array
    {
        $lines = file(base_path('tests/Fixtures/SeoIntel/crawler_logs/nginx_access_sample.log'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);

        return array_values($lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function artisanJsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-production-canary-runtime.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function temporaryFilePath(string $suffix): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('seo-intel-crawler-log-', true).'-'.$suffix;
        $this->temporaryFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }
}
