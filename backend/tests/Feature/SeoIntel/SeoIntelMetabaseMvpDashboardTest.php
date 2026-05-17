<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelMetabaseMvpDashboardTest extends TestCase
{
    #[Test]
    public function generated_artifact_locks_metabase_policy_and_next_task(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-00B', $artifact['source_documents'] ?? []);
        $this->assertContains('CHINA-SEARCH-04', $artifact['source_documents'] ?? []);
        $this->assertFalse((bool) ($artifact['metabase_deployed'] ?? true));
        $this->assertFalse((bool) ($artifact['production_connection_created'] ?? true));
        $this->assertTrue((bool) ($artifact['read_only_policy_required'] ?? false));
        $this->assertSame('seo_intel', $artifact['allowed_data_source'] ?? null);
        $this->assertContains('business_db', $artifact['forbidden_data_sources'] ?? []);
        $this->assertContains('cms_write_tables', $artifact['forbidden_data_sources'] ?? []);
        $this->assertContains('node2_local_db', $artifact['forbidden_data_sources'] ?? []);
        $this->assertSame('backend_orders_payment_benefits_aggregates', $artifact['purchase_truth_source'] ?? null);
        $this->assertFalse((bool) ($artifact['ga4_purchase_truth'] ?? true));
        $this->assertFalse((bool) ($artifact['baidu_purchase_truth'] ?? true));
        $this->assertFalse((bool) ($artifact['search_channels_are_truth'] ?? true));
        $this->assertFalse((bool) ($artifact['crawler_logs_are_truth'] ?? true));
        $this->assertFalse((bool) ($artifact['metabase_credentials_added'] ?? true));
        $this->assertFalse((bool) ($artifact['production_db_user_created'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled'] ?? true));
        $this->assertFalse((bool) ($artifact['queue_worker_enabled'] ?? true));
        $this->assertSame('SEO-DASH-06', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function dashboards_include_all_mvp_groups(): void
    {
        $names = array_column($this->artifact()['dashboards'] ?? [], 'name');

        foreach ([
            'URL Truth & Drift',
            'Search Channel Health',
            'Landing Attribution',
            'Revenue / Cluster',
            'Crawler Health',
            'Internal / QA Filtering',
        ] as $dashboard) {
            $this->assertContains($dashboard, $names);
        }
    }

    #[Test]
    public function sanitized_views_do_not_expose_forbidden_fields_or_sources(): void
    {
        $artifact = $this->artifact();
        $views = $artifact['sanitized_views'] ?? [];

        $this->assertCount(6, $views);
        $this->assertSame([
            'seo_v_url_truth_overview',
            'seo_v_search_channel_health',
            'seo_v_landing_attribution_daily',
            'seo_v_revenue_cluster_daily',
            'seo_v_crawler_health_daily',
            'seo_v_internal_traffic_filtering',
        ], array_column($views, 'name'));

        foreach ($views as $view) {
            $encodedView = strtolower(json_encode($view, JSON_THROW_ON_ERROR));

            foreach ($this->forbiddenTermsForViewDefinitions() as $term) {
                $this->assertStringNotContainsString($term, $encodedView, ($view['name'] ?? 'view').' must not expose '.$term);
            }

            foreach (['business_db', 'cms_write_tables', 'node2_local_db', 'orders', 'payments', 'email_events'] as $source) {
                $this->assertNotContains($source, $view['source_tables'] ?? [], ($view['name'] ?? 'view').' must not source '.$source);
            }
        }
    }

    #[Test]
    public function docs_and_artifact_forbid_raw_pii_and_metabase_deployment(): void
    {
        $dashboardDoc = (string) file_get_contents(base_path('docs/seo/metabase-mvp-dashboard.md'));
        $runbook = (string) file_get_contents(base_path('docs/seo/metabase-deployment-runbook.md'));
        $artifactJson = (string) file_get_contents(base_path('docs/seo/generated/metabase-mvp-dashboard.v1.json'));
        $combined = strtolower($dashboardDoc."\n".$runbook."\n".$artifactJson);

        foreach ([
            'metabase_deployed": false',
            'production_connection_created": false',
            'read-only db user',
            'metabase must not expose raw email',
            'raw order',
            'raw attempt',
            'provider event',
            'raw ip',
            'raw cookies',
            'no metabase deployment happens',
        ] as $requiredBoundary) {
            $this->assertStringContainsString($requiredBoundary, $combined);
        }
    }

    #[Test]
    public function no_deployment_credentials_or_scheduler_activation_are_introduced(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $config = (string) file_get_contents(config_path('seo_intel.php'));
        $artifact = json_encode($this->artifact(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('metabase', strtolower($bootstrap));
        $this->assertStringNotContainsString('METABASE_', $config);
        $this->assertStringNotContainsString('metabase_password', strtolower($artifact));
        $this->assertStringNotContainsString('metabase_secret', strtolower($artifact));
        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/metabase-mvp-dashboard.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function forbiddenTermsForViewDefinitions(): array
    {
        return [
            'email',
            'order_no',
            'raw_order_no',
            'attempt_id',
            'raw_attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_cookie',
            'raw_ip',
            'ip_address',
            'raw_user_agent',
            'user_agent',
            'raw_payload',
            'payment_payload',
            'provider_payload',
            'token',
            'api_key',
            'secret',
        ];
    }
}
