<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\CrawlerLog\CrawlerLogAggregateDryRun;
use App\Services\SeoIntel\CrawlerLog\CrawlerLogFixtureParser;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class SeoIntelCrawlerLogAggregateDryRunTest extends TestCase
{
    #[Test]
    public function aggregate_dry_run_groups_sanitized_fixture_rows_without_writes_or_production_reads(): void
    {
        $lines = $this->fixtureLines();
        $report = (new CrawlerLogAggregateDryRun)->report([$lines[0], $lines[0], $lines[1]], 20);

        $this->assertSame('crawler_log_observe', $report['runtime']);
        $this->assertSame('success', $report['status']);
        $this->assertTrue($report['dry_run']);
        $this->assertTrue($report['no_write']);
        $this->assertFalse($report['writes_attempted']);
        $this->assertFalse($report['writes_committed']);
        $this->assertFalse($report['production_log_read_attempted']);
        $this->assertFalse($report['external_calls_attempted']);
        $this->assertFalse($report['search_submission_attempted']);
        $this->assertFalse($report['raw_persistence']);
        $this->assertSame(3, $report['parsed_line_count']);
        $this->assertSame(2, $report['aggregate_row_count']);
        $this->assertSame(CrawlerLogFixtureParser::PRIVACY_TRANSFORM_VERSION, $report['privacy_transform_version']);

        $research = $this->findAggregateRow($report['aggregate_rows'], 'route_family', 'research');

        $this->assertSame(2, $research['hit_count']);
        $this->assertSame('/en/research/mbti-personality-types-salary-turnover-report', $research['canonical_path']);
        $this->assertNull($research['path_hash']);
        $this->assertSame('googlebot', $research['bot_family']);
        $this->assertSame('tracking_only', $research['query_risk_state']);
    }

    #[Test]
    public function command_emits_json_aggregate_report_from_bundled_fixture_only(): void
    {
        $exitCode = Artisan::call('seo-intel:crawler-log-observe', [
            '--fixture' => true,
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--limit' => 20,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('crawler_log_observe', $payload['runtime']);
        $this->assertSame('synthetic_fixture_aggregate_dry_run', $payload['mode']);
        $this->assertTrue($payload['fixture_only']);
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['no_write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['production_log_read_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['scheduler_enabled']);
        $this->assertFalse($payload['collector_write_attempted']);
        $this->assertFalse($payload['raw_persistence']);
        $this->assertSame(10, $payload['parsed_line_count']);
        $this->assertSame(10, $payload['aggregate_row_count']);
        $this->assertSame('seo_crawler_logs_daily', $payload['target_table']);
        $this->assertFalse($payload['target_table_write_attempted']);
    }

    #[Test]
    public function command_blocks_non_fixture_mode_without_reading_production_logs(): void
    {
        $exitCode = Artisan::call('seo-intel:crawler-log-observe', [
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('blocked', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['no_write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['production_log_read_attempted']);
        $this->assertFalse($payload['external_calls_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['raw_persistence']);
        $this->assertContains('fixture_or_source_required', $payload['issues']);
        $this->assertContains('single_source_canary_requires_explicit_source', $payload['issues']);
    }

    #[Test]
    public function aggregate_output_does_not_expose_raw_or_sensitive_log_fields(): void
    {
        Artisan::call('seo-intel:crawler-log-observe', [
            '--fixture' => true,
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--limit' => 20,
        ]);

        $encoded = json_encode($this->artisanJsonOutput(), JSON_THROW_ON_ERROR);

        foreach ([
            '198.51.100',
            'Googlebot/2.1',
            'Baiduspider',
            'Sogou web spider',
            'Mozilla/5.0',
            'utm_source=google',
            'token=secret',
            'attempt_id=attempt-secret',
            '/zh/result/private-attempt',
            '/build/assets/app.js',
            '/api/healthz',
            '/ops/seo',
            'raw_user_agent',
            'raw_request_uri',
            'raw_query_string',
            'raw_log_line',
            'cookie',
            'event_payload',
            'metadata_json',
            'attributes_json',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function command_has_no_production_tail_schedule_write_or_submit_options(): void
    {
        Artisan::call('seo-intel:crawler-log-observe --help');
        $help = Artisan::output();

        foreach ([
            '--production',
            '--tail',
            '--schedule',
            '--write',
            '--submit',
        ] as $forbiddenOption) {
            $this->assertDoesNotMatchRegularExpression('/(^|\\s)'.preg_quote($forbiddenOption, '/').'(=|\\s|$)/', $help);
        }

        foreach ([
            '--fixture',
            '--dry-run',
            '--no-write',
            '--json',
            '--limit',
        ] as $allowedOption) {
            $this->assertStringContainsString($allowedOption, $help);
        }
    }

    #[Test]
    public function docs_and_generated_artifact_lock_aggregate_dry_run_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-aggregate-dry-run.md')));
        $artifact = $this->artifact();
        $artifactJson = strtolower((string) json_encode($artifact, JSON_THROW_ON_ERROR));

        $this->assertSame('crawler-log-aggregate-dry-run.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-03', $artifact['task'] ?? null);
        $this->assertSame('CRAWLER-LOG-04', $artifact['next_task'] ?? null);
        $this->assertTrue($artifact['no_production_log_read'] ?? false);
        $this->assertTrue($artifact['no_database_writes'] ?? false);
        $this->assertTrue($artifact['no_search_submission'] ?? false);

        foreach ([
            'synthetic fixture',
            'aggregate dry-run',
            'no production crawler log read',
            'no database writes',
            'no scheduler',
            'no search submission',
            'crawler logs are not url truth',
            'crawler_log_privacy_transform_v1',
        ] as $required) {
            $this->assertStringContainsString($required, $doc."\n".$artifactJson);
        }
    }

    /**
     * @return list<string>
     */
    private function fixtureLines(): array
    {
        $path = base_path('tests/Fixtures/SeoIntel/crawler_logs/nginx_access_sample.log');
        $this->assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);

        return array_values($lines);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function findAggregateRow(array $rows, string $key, string $value): array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        $this->fail('Expected aggregate row not found.');
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
        $path = base_path('docs/seo/generated/crawler-log-aggregate-dry-run.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
