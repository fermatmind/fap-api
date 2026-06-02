<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\Analytics\FunnelEventTaxonomy;
use Tests\TestCase;

final class AnalyticsFunnelGa4BaiduMappingScan01Test extends TestCase
{
    public function test_mapping_artifacts_define_backend_truth_and_observation_boundaries(): void
    {
        $backendPath = dirname(__DIR__, 3);
        $docPath = $backendPath.'/docs/seo/analytics-funnel-ga4-baidu-mapping-scan-01.md';
        $jsonPath = $backendPath.'/docs/seo/generated/analytics-funnel-ga4-baidu-mapping-scan-01.v1.json';

        $this->assertFileExists($docPath);
        $this->assertFileExists($jsonPath);

        $payload = json_decode((string) file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ANALYTICS-FUNNEL-GA4-BAIDU-MAPPING-SCAN-01', $payload['task'] ?? null);
        $this->assertSame(
            'analytics_funnel_ga4_baidu_mapping_scan_completed_ready_for_web_event_alignment',
            $payload['final_decision'] ?? null,
        );

        $this->assertSame('analytics_funnel_daily', data_get($payload, 'source_of_truth.ops_read_model_truth'));
        $this->assertSame('reporting_and_attribution_only', data_get($payload, 'source_of_truth.ga4_truth_role'));
        $this->assertSame('traffic_and_public_page_observation_only', data_get($payload, 'source_of_truth.baidu_truth_role'));

        $events = collect($payload['canonical_event_mapping'] ?? [])->keyBy('backend_event');

        foreach ([FunnelEventTaxonomy::TEST_START, FunnelEventTaxonomy::TEST_SUBMIT, FunnelEventTaxonomy::RESULT_VIEW] as $eventName) {
            $this->assertTrue((bool) data_get($events->get($eventName), 'ga4_key_event'), $eventName.' must be a recommended GA4 key event.');
        }

        $this->assertSame(
            'conditional_privacy_safe_bridge_required',
            data_get($events->get(FunnelEventTaxonomy::PAYMENT_SUCCESS), 'ga4_key_event'),
        );
        $this->assertSame(
            'conditional_privacy_safe_bridge_required',
            data_get($events->get(FunnelEventTaxonomy::REPORT_UNLOCK), 'ga4_key_event'),
        );
        $this->assertSame(
            'conditional_privacy_safe_bridge_required',
            data_get($events->get(FunnelEventTaxonomy::REPORT_READY), 'ga4_key_event'),
        );

        $this->assertContains(FunnelEventTaxonomy::PAYMENT_SUCCESS, $payload['baidu_forbidden_truth_scope'] ?? []);
        $this->assertContains(FunnelEventTaxonomy::REPORT_READY, $payload['baidu_forbidden_truth_scope'] ?? []);

        $this->assertTrue((bool) ($payload['no_ga_admin_change'] ?? false));
        $this->assertTrue((bool) ($payload['no_baidu_admin_change'] ?? false));
        $this->assertTrue((bool) ($payload['no_production_refresh'] ?? false));
        $this->assertTrue((bool) ($payload['no_production_db_mutation'] ?? false));
        $this->assertTrue((bool) ($payload['no_payment_provider_call'] ?? false));
        $this->assertSame('ANALYTICS-FUNNEL-WEB-EVENT-NAME-ALIGNMENT-02', $payload['next_task'] ?? null);
    }
}
