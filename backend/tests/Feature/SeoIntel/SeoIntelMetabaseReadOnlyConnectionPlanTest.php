<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelMetabaseReadOnlyConnectionPlanTest extends TestCase
{
    #[Test]
    public function generated_artifact_locks_read_only_seo_intel_boundary(): void
    {
        $artifact = $this->artifact();
        $boundary = $artifact['readonly_user_boundary'] ?? [];

        $this->assertSame('metabase-read-only-connection-plan.v1', $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-PROD-03D-R2', $artifact['source_documents'] ?? []);
        $this->assertSame('seo_intel', $artifact['allowed_database'] ?? null);
        $this->assertTrue((bool) ($boundary['required'] ?? false));
        $this->assertSame('seo_intel_metabase_readonly', $boundary['planned_user_role'] ?? null);
        $this->assertTrue((bool) ($boundary['select_only'] ?? false));
        $this->assertFalse((bool) ($boundary['write_allowed'] ?? true));
        $this->assertFalse((bool) ($boundary['ddl_allowed'] ?? true));
        $this->assertFalse((bool) ($boundary['grant_allowed'] ?? true));
        $this->assertFalse((bool) ($boundary['migration_allowed'] ?? true));
    }

    #[Test]
    public function generated_artifact_forbids_business_node2_raw_pii_and_deployment_activation(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'business_db',
            'cms_write_tables',
            'node2_local_db',
            'node2_local_laravel',
            'tencent_rds_fap_prod',
            'raw_orders',
            'raw_payments',
            'raw_email_tables',
            'production_crawler_logs',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_sources'] ?? []);
        }

        foreach ($this->forbiddenFields() as $field) {
            $this->assertContains($field, $artifact['forbidden_fields'] ?? []);
        }

        foreach ([
            'metabase_deployed_in_this_pr',
            'metabase_connection_created_in_this_pr',
            'credentials_added_in_this_pr',
            'db_user_created_in_this_pr',
            'env_edit_in_this_pr',
            'production_write_execution',
            'scheduler_enabled_in_this_pr',
            'external_api_live_activation',
            'url_submission_performed',
            'production_crawler_log_read',
            'business_db_access_allowed',
            'cms_write_table_access_allowed',
            'node2_local_db_access_allowed',
            'raw_pii_allowed',
            'research_publish_in_this_pr',
            'pseo_generation_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function allowed_reporting_surfaces_match_post_03d_safe_state(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'seo_urls',
            'seo_url_entities',
            'seo_issue_queue',
        ] as $table) {
            $this->assertContains($table, $artifact['allowed_source_tables'] ?? []);
        }

        foreach ([
            'url_truth_counts',
            'url_entity_mapping_coverage',
            'source_authority_distribution',
            'locale_distribution',
            'page_entity_type_distribution',
            'indexability_distribution',
            'sanitized_issue_queue_counts',
            'forbidden_source_authority_checks',
            'private_flow_count',
            'collector_empty_state_panels',
        ] as $surface) {
            $this->assertContains($surface, $artifact['allowed_reporting_surfaces'] ?? []);
        }

        $this->assertSame(7, $artifact['production_state_reference']['seo_urls'] ?? null);
        $this->assertSame(7, $artifact['production_state_reference']['seo_url_entities'] ?? null);
        $this->assertSame(5, $artifact['production_state_reference']['seo_issue_queue'] ?? null);
        $this->assertSame(0, $artifact['production_state_reference']['other_checked_collector_tables'] ?? null);
    }

    #[Test]
    public function docs_state_no_credentials_no_deployment_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/metabase-read-only-connection-plan.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/metabase-read-only-connection-plan.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'does not deploy metabase',
            'does not deploy Metabase',
            'add credentials',
            'read-only database user',
            'metabase may connect only to `seo_intel`',
            'business db',
            'cms write tables',
            'node2 local db',
            'raw order',
            'raw email',
            'no dashboard may infer broader seo truth',
            'next task: seo-dash-prod-04b',
            '"next_task": "seo-dash-prod-04b"',
        ] as $required) {
            $this->assertStringContainsString(strtolower($required), $combined);
        }
    }

    #[Test]
    public function plan_does_not_introduce_metabase_runtime_or_scheduler_hooks(): void
    {
        $bootstrap = strtolower((string) file_get_contents(base_path('bootstrap/app.php')));
        $config = (string) file_get_contents(config_path('seo_intel.php'));
        $artifactJson = strtolower(json_encode($this->artifact(), JSON_THROW_ON_ERROR));

        $this->assertStringNotContainsString('METABASE_PASSWORD', $config);
        $this->assertStringNotContainsString('METABASE_SECRET', $config);
        $this->assertStringNotContainsString('METABASE_TOKEN', $config);
        $this->assertStringNotContainsString('metabase', $bootstrap);
        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
        $this->assertStringNotContainsString('password', $artifactJson);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/metabase-read-only-connection-plan.v1.json');

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
