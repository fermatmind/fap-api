<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoDashCollector01SmokeEvidenceTest extends TestCase
{
    #[Test]
    public function artifact_records_evidence_only_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo-dash-collector-01-smoke-evidence.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($artifact['evidence_only'] ?? false));

        foreach ([
            'runtime_changed',
            'scheduler_enabled',
            'production_writes_allowed',
            'production_writes_attempted',
            'production_writes_committed',
            'external_api_calls_allowed',
            'cms_mutation_allowed',
            'search_submission_allowed',
            'deployment_allowed',
            'production_env_edit_allowed',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function pre_and_post_smoke_evidence_lock_production_state(): void
    {
        $artifact = $this->artifact();
        $pre = $artifact['pre_smoke'] ?? [];
        $post = $artifact['post_smoke'] ?? [];

        $this->assertSame('619ce5881cbb63200568796c07467aacd66b52c2', $pre['production_backend_sha'] ?? null);
        $this->assertSame('619ce5881cbb63200568796c07467aacd66b52c2', $post['production_backend_sha'] ?? null);
        $this->assertSame(5, $pre['seo_intel_routes_present'] ?? null);
        $this->assertTrue((bool) ($pre['seo_intel_migrations_all_ran'] ?? false));
        $this->assertSame('database/migrations/seo_intel', $pre['seo_intel_migration_path'] ?? null);
        $this->assertTrue((bool) ($pre['scheduler_no_activation'] ?? false));
        $this->assertTrue((bool) ($pre['seo_crawler_log_daily_aggregates_exists'] ?? false));
        $this->assertTrue((bool) ($post['seo_crawler_log_daily_aggregates_exists'] ?? false));
        $this->assertSame(0, $pre['seo_crawler_log_daily_aggregates_rows'] ?? null);
        $this->assertSame(0, $post['seo_crawler_log_daily_aggregates_rows'] ?? null);
        $this->assertSame(401, $pre['private_overview_without_token_http_status'] ?? null);
        $this->assertSame(200, $pre['public_scale_lookup_http_status'] ?? null);

        foreach (['collectors_enabled', 'write_enabled', 'allow_external_api_calls', 'crawler_log_aggregate_write_enabled'] as $flag) {
            $this->assertFalse((bool) ($pre[$flag] ?? true), $flag.' pre-smoke must be false');
            $this->assertFalse((bool) ($post[$flag] ?? true), $flag.' post-smoke must be false');
        }

        $this->assertTrue((bool) ($pre['dry_run_default'] ?? false));
        $this->assertTrue((bool) ($post['dry_run_default'] ?? false));
    }

    #[Test]
    public function all_collectors_succeeded_as_dry_run_no_write_no_external_call(): void
    {
        $artifact = $this->artifact();
        $results = $artifact['collector_results'] ?? [];
        $collectorNames = array_column($results, 'collector');

        $this->assertCount(13, $results);
        $this->assertSame([
            'noop',
            'url_truth_inventory',
            'drift_foundation',
            'crawler_log_foundation',
            'attribution_revenue_foundation',
            'gsc_foundation',
            'baidu_foundation',
            'indexnow_foundation',
            'so360_foundation',
            'sogou_foundation',
            'shenma_foundation',
            'chinese_crawler_log_foundation',
            'issue_queue_foundation',
        ], $collectorNames);

        foreach ($results as $result) {
            $command = (string) ($result['command'] ?? '');

            $this->assertSame('success', $result['status'] ?? null, (string) ($result['collector'] ?? 'unknown'));
            $this->assertTrue((bool) ($result['dry_run'] ?? false));
            $this->assertFalse((bool) ($result['writes_attempted'] ?? true));
            $this->assertFalse((bool) ($result['writes_committed'] ?? true));
            $this->assertFalse((bool) ($result['external_calls_attempted'] ?? true));
            $this->assertStringContainsString('--dry-run', $command);
            $this->assertStringContainsString('--no-write', $command);
            $this->assertStringContainsString('--json', $command);
            $this->assertGreaterThanOrEqual(0, $result['items_seen'] ?? -1);
        }
    }

    #[Test]
    public function outcome_and_deferred_work_do_not_enable_future_scope(): void
    {
        $artifact = $this->artifact();
        $outcome = $artifact['outcome'] ?? [];
        $deferred = $artifact['deferred'] ?? [];
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-dash-collector-01-smoke-evidence.md')));

        $this->assertSame(13, $outcome['collector_count'] ?? null);
        $this->assertTrue((bool) ($outcome['all_collectors_success'] ?? false));
        $this->assertTrue((bool) ($outcome['all_collectors_dry_run'] ?? false));
        $this->assertFalse((bool) ($outcome['any_writes_attempted'] ?? true));
        $this->assertFalse((bool) ($outcome['any_writes_committed'] ?? true));
        $this->assertFalse((bool) ($outcome['any_external_calls_attempted'] ?? true));
        $this->assertTrue((bool) ($outcome['scheduler_still_disabled'] ?? false));
        $this->assertTrue((bool) ($outcome['aggregate_rows_unchanged'] ?? false));

        foreach ([
            'scheduler_enablement',
            'collector_write_enablement',
            'live_gsc_baidu_ga4_or_domestic_search_api_connections',
            'cms_issue_summary_writeback',
            'search_platform_submission',
            'production_data_ingestion',
        ] as $item) {
            $this->assertContains($item, $deferred);
        }

        foreach ([
            'evidence only',
            'does not run collectors',
            'dry_run=true',
            'writes_attempted=false',
            'writes_committed=false',
            'external_calls_attempted=false',
            'seo-dash-collector-02',
        ] as $required) {
            $this->assertStringContainsString($required, $doc);
        }

        $this->assertSame(
            'SEO-DASH-COLLECTOR-02 controlled collector runbook or write gate',
            $artifact['next_task'] ?? null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-dash-collector-01-smoke-evidence.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
