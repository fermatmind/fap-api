<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\AttributionDailyBuilder;
use App\Services\SeoIntel\ConsentStateNormalizer;
use App\Services\SeoIntel\InternalTrafficFilter;
use App\Services\SeoIntel\RevenueDailyBuilder;
use App\Services\SeoIntel\SeoIntelCollectorManager;
use App\Services\SeoIntel\SourceEngineNormalizer;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelAttributionRevenueBuildersTest extends TestCase
{
    #[Test]
    public function attribution_revenue_foundation_collector_is_registered_and_dry_run_safe(): void
    {
        $this->assertContains('attribution_revenue_foundation', config('seo_intel.allowed_collectors'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));

        $result = (new SeoIntelCollectorManager)->collect('attribution_revenue_foundation', ['dry_run' => true]);

        $this->assertSame('attribution_revenue_foundation', $result->collector);
        $this->assertSame('success', $result->status);
        $this->assertTrue($result->dryRun);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertFalse((bool) ($result->metadata['scheduler_enabled'] ?? true));
        $this->assertFalse((bool) ($result->metadata['queue_worker_enabled'] ?? true));
        $this->assertFalse((bool) ($result->metadata['node2_local_laravel_data_source'] ?? true));
        $this->assertSame('backend_orders_payment_benefits', $result->metadata['purchase_truth_source'] ?? null);
        $this->assertFalse((bool) ($result->metadata['ga4_purchase_truth'] ?? true));
        $this->assertFalse((bool) ($result->metadata['baidu_purchase_truth'] ?? true));
        $this->assertFalse((bool) ($result->metadata['keyword_purchase_attribution_allowed'] ?? true));
    }

    #[Test]
    public function default_write_is_blocked_when_collectors_are_disabled(): void
    {
        $result = (new SeoIntelCollectorManager)->collect('attribution_revenue_foundation', [
            'dry_run' => false,
            'no_write' => false,
        ]);

        $this->assertSame('blocked', $result->status);
        $this->assertContains('collectors_disabled', $result->issues);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
    }

    #[Test]
    public function daily_aggregate_migrations_do_not_include_forbidden_columns(): void
    {
        $forbidden = [
            'email',
            'order_no',
            'raw_order_no',
            'attempt_id',
            'raw_attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_payload',
            'payment_payload',
            'provider_payload',
            'raw_email',
            'raw_ip',
            'raw_cookie',
        ];
        $paths = glob(base_path('database/migrations/*seo_*daily*'));

        $this->assertCount(5, $paths);

        foreach ($paths as $path) {
            $contents = strtolower((string) file_get_contents($path));

            foreach ($forbidden as $column) {
                $this->assertStringNotContainsString("'".$column."'", $contents, $path.' must not define '.$column);
                $this->assertStringNotContainsString('"'.$column.'"', $contents, $path.' must not define '.$column);
            }
        }
    }

    #[Test]
    public function source_engine_normalizer_handles_contract_values(): void
    {
        $normalizer = new SourceEngineNormalizer;

        $this->assertSame('google', $normalizer->normalizeFromPayload(['referrer' => 'https://www.google.com/search?q=fixture']));
        $this->assertSame('baidu', $normalizer->normalizeFromPayload(['referrer' => 'https://www.baidu.com/s?wd=fixture']));
        $this->assertSame('paid_google', $normalizer->normalizeFromPayload(['gclid' => 'fixture-click-id']));
        $this->assertSame('paid_baidu', $normalizer->normalizeFromPayload(['utm_source' => 'baidu', 'utm_medium' => 'cpc']));
        $this->assertSame('direct', $normalizer->normalizeFromPayload([]));
        $this->assertSame('unknown', $normalizer->normalizeFromPayload(['referrer' => 'https://example.com']));
    }

    #[Test]
    public function consent_state_normalizer_handles_contract_values(): void
    {
        $normalizer = new ConsentStateNormalizer;

        $this->assertSame('granted', $normalizer->normalize('granted'));
        $this->assertSame('denied', $normalizer->normalize('denied'));
        $this->assertSame('unknown', $normalizer->normalize('unexpected'));
        $this->assertSame('not_applicable', $normalizer->normalize('n/a'));
    }

    #[Test]
    public function attribution_builder_aggregates_fixture_events_without_raw_pii(): void
    {
        $builder = new AttributionDailyBuilder(
            new SourceEngineNormalizer,
            new ConsentStateNormalizer,
            new InternalTrafficFilter,
        );

        $result = $builder->build([
            [
                'event_name' => 'start_attempt',
                'occurred_at' => '2026-05-17T00:00:00Z',
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti'),
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'entity_id_or_slug' => 'mbti',
                'cluster' => 'mbti',
                'source_engine' => 'google',
                'consent_state' => 'granted',
                'traffic_quality' => 'production_user',
                'environment' => 'production',
                'touch_type' => 'first',
                'is_landing_event' => true,
                'keyword' => 'should-not-attribute-purchase',
            ],
            [
                'event_name' => 'pay_success',
                'occurred_at' => '2026-05-17T00:05:00Z',
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti'),
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'entity_id_or_slug' => 'mbti',
                'cluster' => 'mbti',
                'source_engine' => 'google',
                'consent_state' => 'granted',
                'traffic_quality' => 'production_user',
                'environment' => 'production',
                'touch_type' => 'last',
            ],
            [
                'event_name' => 'start_attempt',
                'occurred_at' => '2026-05-17T00:10:00Z',
                'source_engine' => 'paid_google',
                'consent_state' => 'granted',
                'traffic_quality' => 'qa',
                'is_qa' => true,
            ],
        ]);

        $this->assertSame(1, $result['excluded_internal_qa_bot_count']);
        $this->assertFalse($result['keyword_purchase_attribution_allowed']);
        $this->assertSame(1, $result['event_funnel_daily'][0]['start_attempt_count']);
        $this->assertSame(1, $result['event_funnel_daily'][0]['purchase_success_count']);
        $this->assertSame(1, $result['landing_attribution_daily'][0]['first_touch_count']);
        $this->assertSame(1, $result['landing_attribution_daily'][0]['last_touch_count']);
        $this->assertSame(2, $result['consent_daily'][0]['event_count']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['email', 'order_no', 'attempt_id', 'payment_id', 'provider_event_id', 'cookie'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function revenue_builder_uses_backend_truth_excludes_ga4_and_computes_aov_rpv_proxy(): void
    {
        $builder = new RevenueDailyBuilder(
            new SourceEngineNormalizer,
            new InternalTrafficFilter,
        );

        $result = $builder->build([
            [
                'truth_source' => 'backend_orders_payment_benefits',
                'status' => 'paid',
                'occurred_at' => '2026-05-17T00:00:00Z',
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti'),
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'cluster' => 'mbti',
                'source_engine' => 'google',
                'traffic_quality' => 'production_user',
                'environment' => 'production',
                'revenue_cents' => 19900,
                'currency' => 'CNY',
                'sessions_proxy_count' => 10,
            ],
            [
                'truth_source' => 'backend_orders_payment_benefits',
                'status' => 'paid',
                'occurred_at' => '2026-05-17T00:00:00Z',
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti'),
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'cluster' => 'mbti',
                'source_engine' => 'google',
                'traffic_quality' => 'production_user',
                'environment' => 'production',
                'revenue_cents' => 10100,
                'currency' => 'CNY',
                'sessions_proxy_count' => 10,
            ],
            [
                'truth_source' => 'ga4',
                'status' => 'paid',
                'revenue_cents' => 99900,
            ],
            [
                'truth_source' => 'backend_orders_payment_benefits',
                'status' => 'paid',
                'traffic_quality' => 'bot',
                'is_bot' => true,
                'revenue_cents' => 99900,
            ],
        ]);

        $row = $result['revenue_daily'][0];

        $this->assertSame('backend_orders_payment_benefits', $result['purchase_truth_source']);
        $this->assertFalse($result['ga4_purchase_truth']);
        $this->assertFalse($result['baidu_purchase_truth']);
        $this->assertSame(1, $result['ignored_non_backend_purchase_truth_count']);
        $this->assertSame(1, $result['excluded_internal_qa_bot_count']);
        $this->assertSame(2, $row['orders_count']);
        $this->assertSame(2, $row['purchase_count']);
        $this->assertSame(30000, $row['revenue_cents']);
        $this->assertSame(15000, $row['aov_cents']);
        $this->assertSame(1500, $row['rpv_proxy_cents']);
        $this->assertSame(100000, $row['purchase_rate_ppm']);
    }

    #[Test]
    public function attribution_revenue_command_outputs_safe_dry_run_json(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'attribution_revenue_foundation',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('attribution_revenue_foundation', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertSame('transport_only', $decoded['metadata']['api_track_role'] ?? null);

        foreach (['email', 'order_no', 'attempt_id', 'payment_id', 'provider_event_id', 'cookie', 'token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
    }

    #[Test]
    public function generated_artifact_locks_attribution_revenue_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-03A', $artifact['source_documents'] ?? []);
        $this->assertSame('attribution_revenue_foundation', $artifact['collector'] ?? null);
        $this->assertContains('seo_event_funnel_daily', $artifact['tables'] ?? []);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled'] ?? true));
        $this->assertSame('backend_orders_payment_benefits', $artifact['purchase_truth_source'] ?? null);
        $this->assertFalse((bool) ($artifact['ga4_purchase_truth'] ?? true));
        $this->assertFalse((bool) ($artifact['baidu_purchase_truth'] ?? true));
        $this->assertFalse((bool) ($artifact['keyword_purchase_attribution_allowed'] ?? true));
        $this->assertTrue((bool) ($artifact['pii_forbidden'] ?? false));
        $this->assertTrue((bool) ($artifact['internal_qa_filtering_enabled'] ?? false));
        $this->assertSame('SEO-DASH-04A', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function attribution_revenue_foundation_does_not_add_scheduler_activation(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('attribution_revenue_foundation', $bootstrap);
        $this->assertStringNotContainsString('AttributionRevenueFoundationCollector', $bootstrap);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-intel-attribution-revenue-builders.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
