<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelLogicalDbFoundationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_COLUMNS = [
        'email',
        'order_no',
        'attempt_id',
        'payment_id',
        'provider_event_id',
        'cookie',
        'raw_payload',
        'payment_payload',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_PAGE_ENTITY_TYPES = [
        'take',
        'result',
        'order',
        'share',
        'pay',
        'checkout',
        'report_private',
    ];

    #[Test]
    public function seo_intel_config_is_disabled_by_default(): void
    {
        $snapshot = $this->snapshotEnv([
            'SEO_INTEL_ENABLED',
            'SEO_INTEL_WRITE_ENABLED',
            'SEO_INTEL_COLLECTORS_ENABLED',
            'SEO_INTEL_DB_CONNECTION',
        ]);

        try {
            $this->clearEnv([
                'SEO_INTEL_ENABLED',
                'SEO_INTEL_WRITE_ENABLED',
                'SEO_INTEL_COLLECTORS_ENABLED',
                'SEO_INTEL_DB_CONNECTION',
            ]);

            config(['seo_intel' => require base_path('config/seo_intel.php')]);

            $this->assertFalse((bool) config('seo_intel.enabled'));
            $this->assertSame('seo_intel', config('seo_intel.connection'));
            $this->assertFalse((bool) config('seo_intel.write_enabled'));
            $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
            $this->assertFalse((bool) config('seo_intel.allow_pii_detail'));
            $this->assertFalse((bool) config('seo_intel.allow_raw_order_no'));
            $this->assertFalse((bool) config('seo_intel.allow_raw_attempt_id'));
            $this->assertFalse((bool) config('seo_intel.allow_raw_payment_payload'));
            $this->assertFalse((bool) config('seo_intel.allow_raw_email'));
        } finally {
            $this->restoreEnv($snapshot);
            config(['seo_intel' => require base_path('config/seo_intel.php')]);
        }
    }

    #[Test]
    public function seo_intel_database_connection_exists_without_business_db_defaults(): void
    {
        $connection = config('database.connections.seo_intel');

        $this->assertIsArray($connection);
        $this->assertSame('mysql', $connection['driver'] ?? null);
        $this->assertArrayHasKey('host', $connection);
        $this->assertArrayHasKey('database', $connection);
        $this->assertArrayHasKey('username', $connection);
        $this->assertArrayHasKey('password', $connection);
        $this->assertNull($connection['host']);
        $this->assertNull($connection['database']);
        $this->assertNull($connection['username']);
        $this->assertNull($connection['password']);
    }

    #[Test]
    public function generated_artifact_locks_non_production_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-00B', $artifact['source_documents'] ?? []);
        $this->assertContains('SEO-DASH-TRAIN-00', $artifact['source_documents'] ?? []);
        $this->assertContains('BACKEND-RUNTIME-02D', $artifact['source_documents'] ?? []);
        $this->assertSame('fap-api', $artifact['repo'] ?? null);
        $this->assertSame('seo_intel', $artifact['connection_name'] ?? null);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['collectors_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['production_migration_executed'] ?? true));
        $this->assertFalse((bool) ($artifact['production_db_created'] ?? true));
        $this->assertFalse((bool) ($artifact['external_api_connected'] ?? true));
        $this->assertFalse((bool) ($artifact['metabase_deployed'] ?? true));
        $this->assertSame('SEO-DASH-01B', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function artifact_lists_required_tables_and_entity_type_boundaries(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            ['seo_urls', 'seo_url_entities', 'seo_internal_traffic_rules'],
            $artifact['tables'] ?? []
        );
        $this->assertContains('home', $artifact['allowed_page_entity_types'] ?? []);
        $this->assertContains('article', $artifact['allowed_page_entity_types'] ?? []);
        $this->assertContains('career_recommendation', $artifact['allowed_page_entity_types'] ?? []);

        foreach (self::FORBIDDEN_PAGE_ENTITY_TYPES as $entityType) {
            $this->assertContains($entityType, $artifact['forbidden_page_entity_types'] ?? []);
            $this->assertNotContains($entityType, $artifact['allowed_page_entity_types'] ?? []);
        }
    }

    #[Test]
    public function foundation_migrations_do_not_define_forbidden_pii_columns(): void
    {
        foreach ($this->seoIntelMigrationSources() as $path => $source) {
            foreach (self::FORBIDDEN_COLUMNS as $column) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\$table->[a-zA-Z0-9_]+\(\''
                        .preg_quote($column, '/')
                        .'\'\)/',
                    $source,
                    "{$path} must not define {$column}."
                );
            }
        }
    }

    #[Test]
    public function internal_traffic_rules_store_only_masked_or_hashed_patterns(): void
    {
        $source = (string) file_get_contents(base_path(
            'database/migrations/2026_05_17_000300_create_seo_internal_traffic_rules_table.php'
        ));

        $this->assertStringContainsString("char('pattern_hash', 64)", $source);
        $this->assertStringContainsString("string('pattern_display_masked', 255)", $source);

        foreach (self::FORBIDDEN_COLUMNS as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/\$table->[a-zA-Z0-9_]+\(\''
                    .preg_quote($column, '/')
                    .'\'\)/',
                $source
            );
        }
    }

    #[Test]
    public function node2_local_laravel_is_not_a_seo_intel_data_source(): void
    {
        $artifact = $this->artifact();
        $piiRules = $artifact['pii_rules'] ?? [];

        $this->assertIsArray($piiRules);
        $this->assertFalse((bool) ($piiRules['node2_local_laravel_data_source_allowed'] ?? true));
        $this->assertFalse((bool) ($piiRules['node2_local_db_data_source_allowed'] ?? true));
        $this->assertSame('backend_orders_payments_benefits', $piiRules['purchase_truth_source'] ?? null);
        $this->assertFalse((bool) ($piiRules['ga4_purchase_truth_allowed'] ?? true));
    }

    #[Test]
    public function this_pr_does_not_create_collector_commands_or_scheduler_activation(): void
    {
        $this->assertSame([], glob(base_path('app/Console/Commands/*SeoIntel*.php')) ?: []);
        $this->assertSame([], glob(base_path('app/Console/Commands/*SearchIntelligence*.php')) ?: []);

        $kernelSource = (string) file_get_contents(base_path('app/Console/Kernel.php'));
        $this->assertStringNotContainsString('SeoIntel', $kernelSource);
        $this->assertStringNotContainsString('SearchIntelligence', $kernelSource);

        $artifact = $this->artifact();
        $this->assertFalse((bool) ($artifact['collector_command_created'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_activation_created'] ?? true));
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-intel-logical-db-foundation.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function seoIntelMigrationSources(): array
    {
        $files = [
            base_path('database/migrations/2026_05_17_000100_create_seo_urls_table.php'),
            base_path('database/migrations/2026_05_17_000200_create_seo_url_entities_table.php'),
            base_path('database/migrations/2026_05_17_000300_create_seo_internal_traffic_rules_table.php'),
        ];

        $sources = [];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $sources[$file] = (string) file_get_contents($file);
        }

        return $sources;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{getenv: string|false, env: mixed, server: mixed}>
     */
    private function snapshotEnv(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = [
                'getenv' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * @param  list<string>  $keys
     */
    private function clearEnv(array $keys): void
    {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /**
     * @param  array<string, array{getenv: string|false, env: mixed, server: mixed}>  $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $key => $values) {
            $getenvValue = $values['getenv'];
            if ($getenvValue === false) {
                putenv($key);
            } else {
                putenv($key.'='.$getenvValue);
            }

            if ($values['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $values['env'];
            }

            if ($values['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $values['server'];
            }
        }
    }
}
