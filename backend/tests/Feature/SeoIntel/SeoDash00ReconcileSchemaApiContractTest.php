<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoDash00ReconcileSchemaApiContractTest extends TestCase
{
    #[Test]
    public function artifact_locks_contract_only_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo-dash-00-reconcile-schema-api-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-DASH-00-RECONCILE', $artifact['task'] ?? null);
        $this->assertSame('fap-api', $artifact['repo'] ?? null);
        $this->assertTrue((bool) ($artifact['contract_only'] ?? false));

        foreach ([
            'runtime_changes_allowed',
            'api_route_added_in_this_pr',
            'production_database_created',
            'production_migration_executed',
            'collector_writes_enabled',
            'external_api_connected',
            'cms_mutation_allowed',
            'search_submission_allowed',
            'deploy_allowed',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function source_of_truth_boundary_is_explicit(): void
    {
        $authority = $this->artifact()['authority'] ?? [];

        $this->assertTrue((bool) ($authority['cms_backend_content_authority'] ?? false));
        $this->assertTrue((bool) ($authority['backend_orders_payments_benefits_purchase_truth'] ?? false));
        $this->assertTrue((bool) ($authority['seo_intel_observation_only'] ?? false));
        $this->assertTrue((bool) ($authority['fap_web_dashboard_shell_only'] ?? false));
        $this->assertTrue((bool) ($authority['gsc_baidu_ga4_signal_only'] ?? false));

        foreach ([
            'node2_local_laravel',
            'node2_local_db',
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'ga4_purchase_truth',
            'baidu_purchase_truth',
        ] as $source) {
            $this->assertContains($source, $this->artifact()['forbidden_authority_sources'] ?? []);
        }
    }

    #[Test]
    public function pii_and_read_model_table_boundaries_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'seo_urls',
            'seo_url_entities',
            'seo_issue_queue',
            'seo_gsc_daily',
            'seo_consent_daily',
            'seo_event_funnel_daily',
            'seo_landing_attribution_daily',
            'seo_revenue_daily',
            'seo_search_channel_queue_items',
            'seo_crawler_log_daily_aggregates',
        ] as $table) {
            $this->assertContains($table, $artifact['read_model_tables'] ?? []);
        }

        foreach ([
            'email',
            'raw_email',
            'raw_ip',
            'raw_user_agent',
            'cookie',
            'token',
            'api_key',
            'secret',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'raw_payload',
            'payment_payload',
            'raw_crawler_log_line',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_fields'] ?? []);
        }
    }

    #[Test]
    public function issue_queue_and_consent_reconciliation_are_defined(): void
    {
        $artifact = $this->artifact();
        $issueQueue = $artifact['issue_queue_reconciliation'] ?? [];
        $consent = $artifact['consent_reconciliation'] ?? [];

        $this->assertSame('issue_uid', $issueQueue['storage_issue_id_field'] ?? null);
        $this->assertSame('issue_id', $issueQueue['api_issue_id_field'] ?? null);
        $this->assertTrue((bool) ($issueQueue['issue_id_aliases_storage_issue_uid'] ?? false));
        $this->assertSame('medium', $issueQueue['severity_mapping']['warning'] ?? null);
        $this->assertSame('triaged', $issueQueue['lifecycle_mapping']['acknowledged'] ?? null);
        $this->assertSame('suppressed', $issueQueue['lifecycle_mapping']['ignored'] ?? null);
        $this->assertContains('dedupe_key', $issueQueue['required_future_fields'] ?? []);
        $this->assertContains('sla_due_at', $issueQueue['required_future_fields'] ?? []);

        $this->assertContains('analytics_granted', $consent['api_values'] ?? []);
        $this->assertContains('analytics_denied', $consent['api_values'] ?? []);
        $this->assertSame('analytics_granted', $consent['current_backend_aliases']['granted'] ?? null);
        $this->assertSame(
            'not_applicable_backend_business_event',
            $consent['current_backend_aliases']['not_applicable'] ?? null
        );
        $this->assertFalse((bool) ($consent['consent_can_override_purchase_truth'] ?? true));
    }

    #[Test]
    public function read_only_api_contract_remains_design_only_and_non_mutating(): void
    {
        $api = $this->artifact()['read_only_api_contract'] ?? [];

        $this->assertSame('design_only_no_route_added', $api['status'] ?? null);
        $this->assertSame('admin.seo_intel.read', $api['preferred_permission'] ?? null);
        $this->assertContains('admin.owner', $api['current_private_filament_permissions'] ?? []);
        $this->assertContains('admin.ops.read', $api['current_private_filament_permissions'] ?? []);
        $this->assertContains('GET /api/v0.5/ops/seo-intel/issues', $api['route_family'] ?? []);

        foreach ([
            'cms_mutation',
            'publish',
            'issue_row_mutation',
            'url_submission',
            'search_channel_retry',
            'collector_write',
            'production_raw_log_read',
            'public_metabase_link',
        ] as $behavior) {
            $this->assertContains($behavior, $api['forbidden_behaviors'] ?? []);
        }
    }

    #[Test]
    public function docs_lock_deferred_runtime_work_and_next_tasks(): void
    {
        $doc = strtolower((string) file_get_contents(base_path(
            'docs/seo/seo-dash-00-reconcile-schema-api-contract.md'
        )));
        $artifactJson = strtolower((string) file_get_contents(base_path(
            'docs/seo/generated/seo-dash-00-reconcile-schema-api-contract.v1.json'
        )));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not add a runtime route',
            'does not add a runtime route, run production',
            'dashboard shell',
            '`seo_intel` observes, aggregates, and queues issues only',
            '`warning` severity maps to dashboard `medium`',
            'analytics_granted',
            'admin.seo_intel.read',
            'get /api/v0.5/ops/seo-intel/issues',
            'seo-dash-api-01',
            'seo-dash-migration-01',
            'seo-dash-collector-01',
            'seo-dash-web-api-adapter-01',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-dash-00-reconcile-schema-api-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
