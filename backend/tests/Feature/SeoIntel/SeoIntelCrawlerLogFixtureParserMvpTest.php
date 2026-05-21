<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\CrawlerLog\CrawlerLogFixtureParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogFixtureParserMvpTest extends TestCase
{
    #[Test]
    public function fixture_parser_returns_sanitized_v1_rows_without_writes_or_live_reads(): void
    {
        $report = $this->parseFixture();

        $this->assertTrue($report['dry_run']);
        $this->assertFalse($report['writes_attempted']);
        $this->assertFalse($report['writes_committed']);
        $this->assertFalse($report['production_log_read_attempted']);
        $this->assertFalse($report['external_calls_attempted']);
        $this->assertFalse($report['search_submission_attempted']);
        $this->assertFalse($report['raw_persistence']);
        $this->assertSame(10, $report['parsed_line_count']);
        $this->assertSame(10, $report['sanitized_row_count']);
        $this->assertSame(CrawlerLogFixtureParser::PRIVACY_TRANSFORM_VERSION, $report['privacy_transform_version']);
    }

    #[Test]
    public function known_public_paths_map_to_safe_canonical_paths_and_page_types(): void
    {
        $rows = $this->parseFixture()['sanitized_rows'];
        $research = $this->findRow($rows, 'bot_family', 'googlebot', 'route_family', 'research');
        $testDetail = $this->findRow($rows, 'bot_family', 'baiduspider', 'route_family', 'test_detail');
        $home = $this->findRow($rows, 'bot_family', 'non_bot', 'route_family', 'home');

        $this->assertSame('/en/research/mbti-personality-types-salary-turnover-report', $research['canonical_path']);
        $this->assertSame('research_report', $research['page_entity_type']);
        $this->assertSame('tracking_only', $research['query_risk_state']);
        $this->assertTrue($research['seo_candidate']);

        $this->assertSame('/zh/tests/mbti-personality-test-16-personality-types', $testDetail['canonical_path']);
        $this->assertSame('test_detail', $testDetail['page_entity_type']);
        $this->assertTrue($testDetail['seo_candidate']);

        $this->assertSame('/en', $home['canonical_path']);
        $this->assertSame('home', $home['page_entity_type']);
        $this->assertTrue($home['seo_candidate']);
    }

    #[Test]
    public function private_unknown_static_api_and_ops_paths_do_not_return_raw_paths_as_canonical(): void
    {
        $rows = $this->parseFixture()['sanitized_rows'];
        $private = $this->findRow($rows, 'route_family', 'blocked_private_path');
        $unknown = $this->findRow($rows, 'route_family', 'unknown_public_path');
        $static = $this->findRow($rows, 'route_family', 'static_asset');
        $api = $this->findRow($rows, 'surface_family', 'api');
        $ops = $this->findRow($rows, 'surface_family', 'ops');

        foreach ([$private, $unknown, $static, $api, $ops] as $row) {
            $this->assertNull($row['canonical_path']);
            $this->assertIsString($row['path_hash']);
            $this->assertNotSame('', $row['path_hash']);
            $this->assertFalse($row['seo_candidate']);
        }

        $this->assertTrue($private['private_path_blocked']);
        $this->assertSame('sensitive_key_present', $private['query_risk_state']);
        $this->assertSame('api', $api['route_family']);
        $this->assertSame('ops', $ops['route_family']);
    }

    #[Test]
    public function bot_families_variants_and_ua_claim_only_verification_are_normalized(): void
    {
        $report = $this->parseFixture();

        $this->assertSame(3, $report['bot_family_breakdown']['googlebot']);
        $this->assertSame(2, $report['bot_family_breakdown']['bingbot']);
        $this->assertSame(1, $report['bot_family_breakdown']['baiduspider']);
        $this->assertSame(1, $report['bot_family_breakdown']['sogou']);
        $this->assertSame(1, $report['bot_family_breakdown']['bytespider']);
        $this->assertSame(1, $report['bot_family_breakdown']['petalbot']);
        $this->assertSame(1, $report['bot_family_breakdown']['non_bot']);

        foreach ($report['sanitized_rows'] as $row) {
            $this->assertSame('ua_claim_only', $row['bot_verification_state']);
            $this->assertContains($row['bot_variant'], ['web', 'image', 'ads', 'media', 'mobile', 'unknown']);
        }
    }

    #[Test]
    public function query_handling_records_presence_and_risk_without_query_values(): void
    {
        $rows = $this->parseFixture()['sanitized_rows'];
        $tracking = $this->findRow($rows, 'query_risk_state', 'tracking_only');
        $sensitive = $this->findRow($rows, 'bot_family', 'sogou');
        $none = $this->findRow($rows, 'bot_family', 'baiduspider');

        $this->assertTrue($tracking['query_present']);
        $this->assertSame('tracking_only', $tracking['query_risk_state']);
        $this->assertTrue($sensitive['query_present']);
        $this->assertSame('sensitive_key_present', $sensitive['query_risk_state']);
        $this->assertFalse($none['query_present']);
        $this->assertSame('none', $none['query_risk_state']);
    }

    #[Test]
    public function parser_output_does_not_expose_raw_ip_user_agent_query_or_private_route_text(): void
    {
        $encoded = json_encode($this->parseFixture(), JSON_THROW_ON_ERROR);

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
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function docs_and_artifact_lock_fixture_parser_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-fixture-parser-mvp.md')));
        $artifact = $this->artifact();
        $artifactJson = strtolower((string) json_encode($artifact, JSON_THROW_ON_ERROR));

        $this->assertSame('crawler-log-fixture-parser-mvp.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-02', $artifact['task'] ?? null);
        $this->assertSame('CRAWLER-LOG-03', $artifact['next_task'] ?? null);

        foreach ([
            'fixture-only',
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
     * @return array<string, mixed>
     */
    private function parseFixture(): array
    {
        $path = base_path('tests/Fixtures/SeoIntel/crawler_logs/nginx_access_sample.log');
        $this->assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);

        return (new CrawlerLogFixtureParser)->parseLines($lines);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function findRow(array $rows, string $key, string $value, ?string $secondKey = null, ?string $secondValue = null): array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) !== $value) {
                continue;
            }

            if ($secondKey !== null && ($row[$secondKey] ?? null) !== $secondValue) {
                continue;
            }

            return $row;
        }

        $this->fail('Expected fixture parser row not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-fixture-parser-mvp.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
