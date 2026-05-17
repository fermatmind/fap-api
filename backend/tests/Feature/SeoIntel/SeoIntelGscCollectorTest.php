<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\GscQueryClassifier;
use App\Services\SeoIntel\GscSearchAnalyticsRowNormalizer;
use App\Services\SeoIntel\SeoIntelCollectorManager;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscCollectorTest extends TestCase
{
    #[Test]
    public function gsc_foundation_collector_is_registered_and_disabled_by_default(): void
    {
        $this->assertContains('gsc_foundation', config('seo_intel.allowed_collectors'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));
        $this->assertFalse((bool) config('seo_intel.gsc_enabled'));
        $this->assertFalse((bool) config('seo_intel.gsc_live_api_enabled'));

        $result = (new SeoIntelCollectorManager)->collect('gsc_foundation', ['dry_run' => true]);

        $this->assertSame('gsc_foundation', $result->collector);
        $this->assertSame('success', $result->status);
        $this->assertTrue($result->dryRun);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertSame(2, $result->metadata['rows_seen'] ?? null);
        $this->assertSame(2, $result->metadata['rows_normalized'] ?? null);
        $this->assertSame(3, $result->metadata['data_lag_days'] ?? null);
        $this->assertSame('google', $result->metadata['source_engine'] ?? null);
        $this->assertFalse((bool) ($result->metadata['gsc_live_api_enabled'] ?? true));
        $this->assertFalse((bool) ($result->metadata['query_purchase_attribution_allowed'] ?? true));
        $this->assertSame('backend_orders_payment_benefits', $result->metadata['purchase_truth_source'] ?? null);
    }

    #[Test]
    public function gsc_daily_migration_does_not_include_forbidden_columns(): void
    {
        $paths = glob(base_path('database/migrations/*seo_gsc_daily*'));

        $this->assertCount(1, $paths);

        $contents = strtolower((string) file_get_contents($paths[0]));

        foreach ($this->forbiddenColumns() as $column) {
            $this->assertStringNotContainsString("'".$column."'", $contents, $paths[0].' must not define '.$column);
            $this->assertStringNotContainsString('"'.$column.'"', $contents, $paths[0].' must not define '.$column);
        }
    }

    #[Test]
    public function query_classifier_identifies_brand_non_brand_mixed_and_unknown(): void
    {
        $classifier = new GscQueryClassifier(['fermatmind', '费马']);

        $this->assertSame('brand', $classifier->classify('fermatmind'));
        $this->assertSame('brand', $classifier->classify('费马'));
        $this->assertSame('mixed', $classifier->classify('fermatmind mbti'));
        $this->assertSame('mixed', $classifier->classify('费马 测试'));
        $this->assertSame('non_brand', $classifier->classify('人格测试'));
        $this->assertSame('unknown', $classifier->classify(''));
        $this->assertTrue($classifier->isBrand('fermatmind mbti'));
        $this->assertFalse($classifier->isBrand('人格测试'));
    }

    #[Test]
    public function row_normalizer_masks_queries_and_uses_google_without_purchase_attribution(): void
    {
        $normalizer = new GscSearchAnalyticsRowNormalizer(new GscQueryClassifier(['fermatmind']));

        $row = $normalizer->normalize([
            'date' => '2026-05-14',
            'page' => 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            'query' => 'fermatmind mbti',
            'locale' => 'zh-CN',
            'device' => 'DESKTOP',
            'country' => 'chn',
            'search_type' => 'web',
            'clicks' => 3,
            'impressions' => 100,
            'position' => 4.2,
        ]);

        $this->assertSame('2026-05-14', $row['report_date']);
        $this->assertSame('google', $row['source_engine']);
        $this->assertSame('mixed', $row['query_type']);
        $this->assertTrue((bool) $row['is_brand_query']);
        $this->assertSame(30000, $row['ctr_ppm']);
        $this->assertSame(4200, $row['average_position_milli']);
        $this->assertNotSame('fermatmind mbti', $row['query_display_masked']);
        $this->assertFalse((bool) ($row['metadata_json']['purchase_attribution_allowed'] ?? true));
    }

    #[Test]
    public function gsc_dry_run_command_outputs_safe_json_without_credentials_or_external_calls(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'gsc_foundation',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('gsc_foundation', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['credentials_required'] ?? true));
        $this->assertSame(3, $decoded['metadata']['data_lag_days'] ?? null);

        foreach (['email', 'order_no', 'attempt_id', 'payment_id', 'provider_event_id', 'cookie', 'token', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
    }

    #[Test]
    public function generated_artifact_locks_gsc_foundation_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-00B', $artifact['source_documents'] ?? []);
        $this->assertContains('SEO-DASH-03B', $artifact['source_documents'] ?? []);
        $this->assertSame('gsc_foundation', $artifact['collector'] ?? null);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['live_api_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertSame('google', $artifact['source_engine'] ?? null);
        $this->assertSame(3, $artifact['backfill_lag_days'] ?? null);
        $this->assertSame(28, $artifact['default_window_days'] ?? null);
        $this->assertFalse((bool) ($artifact['query_purchase_attribution_allowed'] ?? true));
        $this->assertSame('backend_orders_payment_benefits', $artifact['purchase_truth_source'] ?? null);
        $this->assertTrue((bool) ($artifact['pii_forbidden'] ?? false));
        $this->assertContains('fermatmind', $artifact['brand_query_terms'] ?? []);
        $this->assertSame('SEO-DASH-04B', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function gsc_pr_does_not_enable_scheduler_or_live_baidu_indexnow_connections(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
        $this->assertStringNotContainsString('SeoIntelCollectCommand', $bootstrap);
        $this->assertFalse((bool) config('seo_intel.baidu_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.indexnow_live_api_enabled'));
    }

    /**
     * @return list<string>
     */
    private function forbiddenColumns(): array
    {
        return [
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_payload',
            'payment_payload',
            'raw_email',
            'raw_ip',
            'raw_cookie',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-intel-gsc-collector.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
