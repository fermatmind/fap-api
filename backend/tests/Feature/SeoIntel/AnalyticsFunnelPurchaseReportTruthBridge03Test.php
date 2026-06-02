<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AnalyticsFunnelPurchaseReportTruthBridge03Test extends TestCase
{
    #[Test]
    public function purchase_report_truth_bridge_artifact_freezes_backend_ops_truth_policy(): void
    {
        $path = base_path('docs/seo/generated/analytics-funnel-purchase-report-truth-bridge-03.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload);
        $this->assertSame('ANALYTICS-FUNNEL-PURCHASE-REPORT-TRUTH-BRIDGE-03', $payload['task'] ?? null);
        $this->assertSame(
            'analytics_funnel_purchase_report_truth_bridge_completed_backend_ops_truth_ga4_public_auxiliary',
            $payload['final_decision'] ?? null
        );

        $sourceOfTruth = $payload['source_of_truth'] ?? [];
        $this->assertSame('backend_orders_payment_events_provider_reconciliation', $sourceOfTruth['payment_success'] ?? null);
        $this->assertSame('active_backend_benefit_grants', $sourceOfTruth['report_unlock'] ?? null);
        $this->assertSame('ready_unified_access_projection_with_attempt_receipt_and_result', $sourceOfTruth['report_ready'] ?? null);
        $this->assertSame('analytics_funnel_daily', $sourceOfTruth['ops_read_model'] ?? null);
        $this->assertSame('public_funnel_reporting_auxiliary_only', $sourceOfTruth['ga4_role'] ?? null);
        $this->assertSame('public_traffic_and_page_observation_only', $sourceOfTruth['baidu_role'] ?? null);

        $this->assertContains('test_start', $payload['ga4_public_auxiliary_events'] ?? []);
        $this->assertContains('order_created', $payload['ga4_public_auxiliary_events'] ?? []);
        $this->assertContains('payment_success', $payload['ga4_conditional_server_side_events'] ?? []);
        $this->assertContains('report_unlock', $payload['ga4_conditional_server_side_events'] ?? []);
        $this->assertContains('report_ready', $payload['ga4_conditional_server_side_events'] ?? []);

        $serverSideRequirements = $payload['ga4_conditional_server_side_requirements'] ?? [];
        $this->assertTrue((bool) ($serverSideRequirements['separate_pr_required'] ?? false));
        $this->assertTrue((bool) ($serverSideRequirements['measurement_protocol_not_enabled_in_this_pr'] ?? false));
        $this->assertTrue((bool) ($serverSideRequirements['server_side_only'] ?? false));
        $this->assertTrue((bool) ($serverSideRequirements['privacy_safe_payload_only'] ?? false));
        $this->assertTrue((bool) ($serverSideRequirements['dedupe_event_id_required'] ?? false));

        $this->assertContains('payment_success', $payload['baidu_forbidden_truth_scope'] ?? []);
        $this->assertContains('report_unlock', $payload['baidu_forbidden_truth_scope'] ?? []);
        $this->assertContains('report_ready', $payload['baidu_forbidden_truth_scope'] ?? []);

        $this->assertTrue((bool) ($payload['analytics_funnel_daily_policy']['remains_backend_truth_for_ops'] ?? false));
        $this->assertTrue((bool) ($payload['no_production_db_mutation'] ?? false));
        $this->assertTrue((bool) ($payload['no_analytics_refresh'] ?? false));
        $this->assertTrue((bool) ($payload['no_payment_repair'] ?? false));
        $this->assertTrue((bool) ($payload['no_benefit_grant_creation'] ?? false));
        $this->assertTrue((bool) ($payload['no_payment_provider_call'] ?? false));
        $this->assertTrue((bool) ($payload['no_cms_mutation'] ?? false));
        $this->assertTrue((bool) ($payload['no_ga4_admin_change'] ?? false));
        $this->assertTrue((bool) ($payload['no_baidu_admin_change'] ?? false));
        $this->assertTrue((bool) ($payload['no_measurement_protocol_export'] ?? false));
        $this->assertTrue((bool) ($payload['no_search_channel_action'] ?? false));
        $this->assertTrue((bool) ($payload['no_url_submission'] ?? false));
        $this->assertTrue((bool) ($payload['no_deploy'] ?? false));
        $this->assertSame('ANALYTICS-FUNNEL-GA4-PUBLIC-EVENT-SMOKE-01', $payload['next_task'] ?? null);
    }
}
