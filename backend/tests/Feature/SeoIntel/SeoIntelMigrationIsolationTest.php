<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelMigrationIsolationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const SEO_INTEL_MIGRATIONS = [
        '2026_05_17_000100_create_seo_urls_table.php',
        '2026_05_17_000200_create_seo_url_entities_table.php',
        '2026_05_17_000300_create_seo_internal_traffic_rules_table.php',
        '2026_05_17_000400_create_seo_event_funnel_daily_table.php',
        '2026_05_17_000500_create_seo_landing_attribution_daily_table.php',
        '2026_05_17_000600_create_seo_revenue_daily_table.php',
        '2026_05_17_000700_create_seo_cluster_daily_table.php',
        '2026_05_17_000800_create_seo_consent_daily_table.php',
        '2026_05_17_000900_create_seo_gsc_daily_table.php',
        '2026_05_17_001000_create_seo_baidu_push_logs_table.php',
        '2026_05_17_001100_create_seo_baidu_landing_daily_table.php',
        '2026_05_17_001200_create_seo_indexnow_submissions_table.php',
        '2026_05_17_001300_create_seo_search_engine_verification_statuses_table.php',
        '2026_05_17_001400_create_seo_domestic_submission_logs_table.php',
        '2026_05_17_001500_create_seo_domestic_index_samples_table.php',
        '2026_05_17_001600_create_seo_crawler_logs_daily_table.php',
        '2026_05_17_001700_create_seo_issue_queue_table.php',
    ];

    #[Test]
    public function default_migration_path_no_longer_contains_seo_intel_analytics_migrations(): void
    {
        foreach (self::SEO_INTEL_MIGRATIONS as $migration) {
            $this->assertFileDoesNotExist(base_path("database/migrations/{$migration}"));
        }

        $defaultCreateSeoMigrations = glob(base_path('database/migrations/*create_seo_*'));

        $this->assertSame([], $defaultCreateSeoMigrations ?: []);
    }

    #[Test]
    public function dedicated_seo_intel_migration_path_contains_all_expected_migrations(): void
    {
        foreach (self::SEO_INTEL_MIGRATIONS as $migration) {
            $this->assertFileExists(base_path("database/migrations/seo_intel/{$migration}"));
        }
    }

    #[Test]
    public function every_seo_intel_migration_is_explicitly_scoped_to_seo_intel_connection(): void
    {
        foreach (self::SEO_INTEL_MIGRATIONS as $migration) {
            $contents = (string) file_get_contents(base_path("database/migrations/seo_intel/{$migration}"));

            $this->assertStringContainsString("protected \$connection = 'seo_intel';", $contents, $migration);
        }
    }

    #[Test]
    public function non_seo_intel_cms_seo_migrations_remain_in_default_path(): void
    {
        foreach ([
            '2026_02_15_160000_add_i18n_seo_content_fields_to_scales_registry.php',
            '2026_03_05_154121_create_article_seo_meta_table.php',
            '2026_03_08_000120_create_personality_profile_seo_meta_table.php',
            '2026_03_08_000230_create_topic_profile_seo_meta_table.php',
            '2026_03_08_000320_create_career_job_seo_meta_table.php',
            '2026_03_15_000110_create_career_guide_seo_meta_table.php',
        ] as $migration) {
            $this->assertFileExists(base_path("database/migrations/{$migration}"));
        }
    }

    #[Test]
    public function runbook_artifact_forbids_bare_database_migration_command(): void
    {
        $artifact = $this->artifact();

        $this->assertFalse((bool) ($artifact['production_migration_executed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['collectors_enabled_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled_in_this_pr'] ?? true));
        $this->assertSame('database/migrations/seo_intel', $artifact['migration_path'] ?? null);
        $this->assertFalse((bool) ($artifact['default_migration_path_allowed_for_seo_intel'] ?? true));
        $this->assertFalse((bool) ($artifact['bare_database_migration_command_allowed'] ?? true));
        $this->assertStringContainsString('--path=database/migrations/seo_intel', $artifact['required_pretend_command'] ?? '');
        $this->assertStringContainsString('--path=database/migrations/seo_intel', $artifact['required_actual_migration_command'] ?? '');
        $this->assertSame('SEO-DASH-PROD-01B-STAGE1-RETRY', $artifact['next_task'] ?? null);
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
