<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelProductionActivationRunbookTest extends TestCase
{
    #[Test]
    public function generated_artifact_locks_non_execution_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-PROD-00', $artifact['source_documents'] ?? []);
        $this->assertContains('SEO-DASH-06', $artifact['source_documents'] ?? []);
        $this->assertFalse((bool) ($artifact['production_db_created_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['production_migration_executed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['production_env_edited_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['deployment_executed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['collectors_enabled_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled_in_this_pr'] ?? true));
        $this->assertSame('SEO-DASH-PROD-01B-STAGE1-RETRY', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function db_placement_and_user_permissions_are_constrained(): void
    {
        $artifact = $this->artifact();
        $userNames = array_column($artifact['required_users'] ?? [], 'name');

        $this->assertContains('node2_local_db', $artifact['forbidden_db_locations'] ?? []);
        $this->assertContains('business_db_raw_tables', $artifact['forbidden_db_locations'] ?? []);
        $this->assertContains('seo_intel_writer', $userNames);
        $this->assertContains('seo_intel_metabase_readonly', $userNames);
        $this->assertContains('migration_operator', $userNames);
        $this->assertFalse((bool) ($artifact['permission_policy']['metabase_can_write'] ?? true));
        $this->assertFalse((bool) ($artifact['permission_policy']['metabase_can_read_business_db_raw_tables'] ?? true));
        $this->assertFalse((bool) ($artifact['permission_policy']['metabase_can_read_node2_local_db'] ?? true));
        $this->assertFalse((bool) ($artifact['permission_policy']['migration_operator_used_for_collector_runtime'] ?? true));
    }

    #[Test]
    public function no_go_conditions_include_required_blocks(): void
    {
        $conditions = $this->artifact()['no_go_conditions'] ?? [];

        foreach ([
            'missing_backup',
            'missing_db_user_confirmation',
            'node2_local_db_selected',
            'business_db_raw_tables_exposed_to_metabase',
            'required_checks_red',
        ] as $condition) {
            $this->assertContains($condition, $conditions);
        }
    }

    #[Test]
    public function command_templates_are_placeholders_without_secrets(): void
    {
        $artifact = $this->artifact();
        $commands = $artifact['command_templates'] ?? [];

        $this->assertSame('database/migrations/seo_intel', $artifact['migration_path'] ?? null);
        $this->assertFalse((bool) ($artifact['default_migration_path_allowed_for_seo_intel'] ?? true));
        $this->assertFalse((bool) ($artifact['bare_database_migration_command_allowed'] ?? true));
        $this->assertContains(
            'php artisan migrate --database=seo_intel --path=database/migrations/seo_intel --pretend --no-ansi --force',
            $commands
        );
        $this->assertContains(
            'php artisan migrate --database=seo_intel --path=database/migrations/seo_intel --no-ansi --force',
            $commands
        );
        $this->assertContains(
            'php artisan migrate:status --database=seo_intel --path=database/migrations/seo_intel --no-ansi',
            $commands
        );
        $this->assertNotContains('php artisan migrate --database=seo_intel --pretend --no-ansi', $commands);
        $this->assertNotContains('php artisan migrate --database=seo_intel --no-ansi', $commands);

        $encoded = strtolower(json_encode($commands, JSON_THROW_ON_ERROR));

        foreach ([
            'password=',
            'token=',
            'secret=',
            'api_key=',
            'mysql://',
            'seo_intel_db_password',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function pii_forbidden_fields_cover_required_sensitive_values(): void
    {
        $fields = $this->artifact()['pii_forbidden_fields'] ?? [];

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
    public function runbook_states_no_production_execution_and_required_human_checks(): void
    {
        $runbook = strtolower((string) file_get_contents(base_path('docs/seo/production-seo-intel-db-migration-runbook.md')));

        foreach ([
            'this is a production activation runbook only',
            'does not create a production database',
            'does not create database users',
            'does not run production migrations',
            'node2 local db',
            'seo_intel_writer',
            'seo_intel_metabase_readonly',
            'migration_operator',
            'backup is confirmed',
            'restore procedure is known',
            'forward-fix',
            '--path=database/migrations/seo_intel',
            'never run',
            'default business migrations must not run against seo_intel',
            'seo-dash-prod-01b-stage1-retry',
        ] as $required) {
            $this->assertStringContainsString($required, $runbook);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/production-seo-intel-db-migration-runbook.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
