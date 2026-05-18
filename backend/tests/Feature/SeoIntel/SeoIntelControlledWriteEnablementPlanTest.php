<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelControlledWriteEnablementPlanTest extends TestCase
{
    #[Test]
    public function generated_artifact_locks_no_write_activation_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('controlled-write-enablement-plan.v1', $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-PROD-01B', $artifact['source_documents'] ?? []);
        $this->assertContains('SEO-DASH-PROD-02', $artifact['source_documents'] ?? []);
        $this->assertTrue((bool) ($artifact['production_schema_ready'] ?? false));
        $this->assertTrue((bool) ($artifact['collector_dry_run_passed'] ?? false));
        $this->assertFalse((bool) ($artifact['write_enabled_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['metabase_deployed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['live_external_api_enabled_in_this_pr'] ?? true));
    }

    #[Test]
    public function first_canary_is_url_truth_and_external_collectors_are_not_first_canary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('url_truth_inventory', $artifact['first_canary_collector'] ?? null);

        foreach ([
            'gsc_foundation',
            'baidu_foundation',
            'indexnow_foundation',
            'so360_foundation',
            'sogou_foundation',
            'shenma_foundation',
        ] as $collector) {
            $this->assertNotSame($collector, $artifact['first_canary_collector'] ?? null);
            $this->assertContains($collector, $artifact['blocked_collectors'] ?? []);
        }
    }

    #[Test]
    public function production_logs_node2_and_business_raw_sources_remain_blocked(): void
    {
        $artifact = $this->artifact();

        $this->assertContains('production_crawler_log_read', $artifact['blocked_collectors'] ?? []);
        $this->assertContains('cdn_openresty_nginx_production_log_ingestion', $artifact['blocked_collectors'] ?? []);
        $this->assertContains('node2_local_db', $artifact['forbidden_sources'] ?? []);
        $this->assertContains('business_db_raw_tables', $artifact['forbidden_sources'] ?? []);
    }

    #[Test]
    public function pii_forbidden_fields_cover_required_sensitive_values(): void
    {
        $fields = $this->artifact()['forbidden_fields'] ?? [];

        foreach ([
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_ip',
        ] as $field) {
            $this->assertContains($field, $fields);
        }
    }

    #[Test]
    public function rollback_policy_requires_disabling_write_flag(): void
    {
        $policy = $this->artifact()['rollback_policy'] ?? [];

        $this->assertSame('set_SEO_INTEL_WRITE_ENABLED_false', $policy['first_action'] ?? null);
        $this->assertTrue((bool) ($policy['scheduler_remains_disabled'] ?? false));
        $this->assertFalse((bool) ($policy['schema_rollback_allowed'] ?? true));
        $this->assertTrue((bool) ($policy['restore_or_forward_fix_owner_required'] ?? false));
    }

    #[Test]
    public function next_task_is_prod_03b(): void
    {
        $this->assertSame('SEO-DASH-PROD-03B', $this->artifact()['next_task'] ?? null);
    }

    #[Test]
    public function docs_forbid_automatic_scheduler_enablement(): void
    {
        $plan = strtolower((string) file_get_contents(base_path('docs/seo/controlled-write-enablement-plan.md')));

        foreach ([
            'this pr does not enable writes',
            'it does not enable scheduler execution',
            'no scheduler may be enabled until repeated manual write smokes pass',
            'do not start queue workers or scheduler',
            'scheduler-triggered collector execution',
            'explicit human approval is required before',
            'enabling scheduler',
        ] as $required) {
            $this->assertStringContainsString($required, $plan);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/controlled-write-enablement-plan.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
