<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoDashMigration01ReadinessTest extends TestCase
{
    #[Test]
    public function artifact_locks_readiness_only_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo-dash-migration-01-readiness.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-DASH-MIGRATION-01', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($artifact['contract_only'] ?? false));

        foreach ([
            'runtime_route_changed',
            'migration_files_changed',
            'production_database_created',
            'production_migration_executed',
            'production_env_edited',
            'deployment_executed',
            'collector_writes_enabled',
            'scheduler_enabled',
            'external_api_connected',
            'cms_mutation_allowed',
            'search_submission_allowed',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function dependencies_and_read_only_api_routes_are_locked(): void
    {
        $dependencies = array_column($this->artifact()['dependencies'] ?? [], 'task');

        $this->assertContains('SEO-DASH-00-RECONCILE', $dependencies);
        $this->assertContains('SEO-DASH-API-01', $dependencies);

        foreach ([
            'api.v0_5.ops.seo_intel.overview',
            'api.v0_5.ops.seo_intel.url_truth',
            'api.v0_5.ops.seo_intel.issues',
            'api.v0_5.ops.seo_intel.trends',
            'api.v0_5.ops.seo_intel.page_performance',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, $routeName.' must remain registered');
            $this->assertContains('GET', $route->methods());
        }
    }

    #[Test]
    public function migration_commands_require_dedicated_path_and_separate_approval(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo_intel', $artifact['migration_connection'] ?? null);
        $this->assertSame('database/migrations/seo_intel', $artifact['migration_path'] ?? null);
        $this->assertFalse((bool) ($artifact['default_migration_path_allowed_for_seo_intel'] ?? true));
        $this->assertFalse((bool) ($artifact['bare_database_migration_command_allowed'] ?? true));
        $this->assertTrue((bool) ($artifact['actual_migration_requires_separate_approval'] ?? false));

        foreach ([
            'required_pretend_command',
            'required_status_command',
            'required_actual_migration_command',
        ] as $commandKey) {
            $command = (string) ($artifact[$commandKey] ?? '');

            $this->assertStringContainsString('--database=seo_intel', $command);
            $this->assertStringContainsString('--path=database/migrations/seo_intel', $command);
            $this->assertStringNotContainsString('SEO_INTEL_DB_PASSWORD', $command);
        }

        $this->assertStringContainsString(
            'I explicitly approve production seo_intel migration for SHA <resolved_sha>',
            (string) ($artifact['human_approval_phrase_template'] ?? '')
        );
    }

    #[Test]
    public function current_migration_inventory_is_isolated_and_connection_scoped(): void
    {
        $artifact = $this->artifact();
        $inventory = $artifact['migration_inventory'] ?? [];

        $this->assertCount(19, $inventory);

        foreach ($inventory as $migration) {
            $migration = (string) $migration;
            $defaultPath = base_path("database/migrations/{$migration}");
            $seoIntelPath = base_path("database/migrations/seo_intel/{$migration}");

            $this->assertFileDoesNotExist($defaultPath, $migration.' must not be in default migration path');
            $this->assertFileExists($seoIntelPath, $migration.' must exist in seo_intel migration path');

            $contents = (string) file_get_contents($seoIntelPath);
            $this->assertStringContainsString("protected \$connection = 'seo_intel';", $contents, $migration);
        }
    }

    #[Test]
    public function migration_files_do_not_add_forbidden_raw_identifier_columns(): void
    {
        foreach ($this->artifact()['migration_inventory'] ?? [] as $migration) {
            $path = base_path('database/migrations/seo_intel/'.(string) $migration);
            $contents = (string) file_get_contents($path);

            foreach ($this->artifact()['forbidden_columns'] ?? [] as $field) {
                $quotedField = preg_quote((string) $field, '/');

                $this->assertDoesNotMatchRegularExpression(
                    "/->(?:char|string|text|uuid|foreignId|json|unsignedBigInteger)\\('{$quotedField}'/",
                    $contents,
                    (string) $migration.' must not define raw field '.(string) $field
                );
            }
        }
    }

    #[Test]
    public function docs_lock_preflight_post_validation_no_go_and_next_task(): void
    {
        $artifact = $this->artifact();
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-dash-migration-01-readiness.md')));

        foreach ([
            'production_seo_intel_db_location_confirmed',
            'pretend_output_reviewed',
            'migration_operator_confirmed_for_temporary_ddl_only',
        ] as $preflight) {
            $this->assertContains($preflight, $artifact['preflight_checklist'] ?? []);
        }

        foreach ([
            'migration_status_uses_seo_intel_path_only',
            'collector_writes_still_disabled',
            'fap_web_remains_dashboard_shell_only',
        ] as $validation) {
            $this->assertContains($validation, $artifact['post_migration_validation'] ?? []);
        }

        foreach ([
            'production_db_target_ambiguous',
            'node2_local_db_selected',
            'default_business_migrations_would_run_against_seo_intel',
            'cms_mutation_publish_url_submission_or_deployment_bundled',
        ] as $condition) {
            $this->assertContains($condition, $artifact['no_go_conditions'] ?? []);
        }

        foreach ([
            'does not create a production database',
            'does not run production migrations',
            'never run a bare `php artisan migrate --database=seo_intel` command',
            'i explicitly approve production seo_intel migration for sha <resolved_sha>',
            'seo-dash-collector-01',
        ] as $required) {
            $this->assertStringContainsString($required, $doc);
        }

        $this->assertSame(
            'SEO-DASH-COLLECTOR-01 after separate production migration readiness/deploy approval',
            $artifact['next_task'] ?? null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-dash-migration-01-readiness.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
