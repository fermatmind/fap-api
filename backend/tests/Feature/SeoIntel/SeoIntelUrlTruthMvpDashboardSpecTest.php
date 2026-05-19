<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelUrlTruthMvpDashboardSpecTest extends TestCase
{
    #[Test]
    public function artifact_locks_current_url_truth_and_issue_queue_state(): void
    {
        $artifact = $this->artifact();
        $state = $artifact['current_production_state'] ?? [];

        $this->assertSame('url-truth-mvp-dashboard-spec.v1', $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-PROD-04A', $artifact['source_documents'] ?? []);
        $this->assertSame(7, $state['seo_urls'] ?? null);
        $this->assertSame(7, $state['seo_url_entities'] ?? null);
        $this->assertSame(5, $state['seo_issue_queue'] ?? null);

        foreach ([
            'seo_gsc_daily',
            'seo_baidu_push_logs',
            'seo_indexnow_submissions',
            'seo_domestic_submission_logs',
            'seo_crawler_logs_daily',
            'seo_event_funnel_daily',
            'seo_revenue_daily',
            'seo_cluster_daily',
            'seo_consent_daily',
        ] as $emptyTable) {
            $this->assertSame(0, $state[$emptyTable] ?? null, $emptyTable.' remains empty until a scoped canary passes');
        }
    }

    #[Test]
    public function dashboard_groups_cover_url_truth_issue_queue_safety_and_empty_states(): void
    {
        $groups = $this->artifact()['dashboard_groups'] ?? [];
        $names = array_column($groups, 'name');

        foreach ([
            'URL Truth Overview',
            'Issue Queue Overview',
            'Safety Checks',
            'Collector Empty States',
        ] as $name) {
            $this->assertContains($name, $names);
        }

        $encoded = json_encode($groups, JSON_THROW_ON_ERROR);

        foreach ([
            'count_by_page_entity_type',
            'count_by_locale',
            'count_by_indexability_state',
            'count_by_source_authority',
            'issue_count_by_issue_type',
            'forbidden_source_authority_count',
            'private_flow_count',
            'private_flow_indexable_count',
            'missing_lastmod_for_indexable_url_count',
            'seo_gsc_daily_rows',
        ] as $card) {
            $this->assertStringContainsString($card, $encoded);
        }
    }

    #[Test]
    public function sanitized_views_use_only_seo_intel_tables_and_safe_fields(): void
    {
        $views = $this->artifact()['sanitized_views'] ?? [];

        $this->assertSame([
            'seo_v_url_truth_mvp_overview',
            'seo_v_url_truth_authority_distribution',
            'seo_v_url_truth_indexability_distribution',
            'seo_v_url_truth_private_flow_safety',
            'seo_v_issue_queue_mvp_overview',
            'seo_v_collector_empty_state_status',
        ], array_column($views, 'name'));

        foreach ($views as $view) {
            foreach ($view['source_tables'] ?? [] as $sourceTable) {
                $this->assertStringStartsWith('seo_', $sourceTable);
                $this->assertNotContains($sourceTable, [
                    'seo_orders',
                    'seo_payments',
                    'seo_users',
                ]);
            }

            $encoded = strtolower(json_encode($view, JSON_THROW_ON_ERROR));

            foreach ($this->forbiddenFields() as $field) {
                $this->assertStringNotContainsString($field, $encoded, ($view['name'] ?? 'view').' must not expose '.$field);
            }
        }
    }

    #[Test]
    public function source_authority_and_activation_boundaries_remain_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame([
            'backend_public_surface',
            'scale_catalog',
        ], $artifact['safe_source_authorities'] ?? []);

        foreach ([
            'business_db',
            'cms_write_tables',
            'node2_local_db',
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'production_crawler_logs',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_sources'] ?? []);
        }

        foreach ([
            'metabase_deployed_in_this_pr',
            'metabase_connection_created_in_this_pr',
            'business_db_views_created_in_this_pr',
            'sql_views_created_in_this_pr',
            'credentials_added_in_this_pr',
            'env_edit_in_this_pr',
            'production_write_execution',
            'scheduler_enabled_in_this_pr',
            'external_api_live_activation',
            'url_submission_performed',
            'production_crawler_log_read',
            'research_publish_in_this_pr',
            'pseo_generation_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_state_sanitized_views_only_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/url-truth-mvp-dashboard-spec.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/url-truth-mvp-dashboard-spec.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'sanitized view plan',
            'this pr defines view contracts only',
            'it does not create sql views',
            'does not deploy metabase',
            'no business db connection',
            'source authority distribution',
            'private-flow count',
            'forbidden source authority count',
            'next task: pr-research-00',
            '"next_task": "pr-research-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/url-truth-mvp-dashboard-spec.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function forbiddenFields(): array
    {
        return [
            'email',
            'raw_email',
            'order_no',
            'raw_order_no',
            'attempt_id',
            'raw_attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_cookie',
            'raw_ip',
            'raw_user_agent',
            'raw_payload',
            'payment_payload',
            'provider_payload',
            'token',
            'api_key',
            'secret',
        ];
    }
}
