<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoDashCollector01ReadinessTest extends TestCase
{
    #[Test]
    public function artifact_locks_collector_readiness_only_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo-dash-collector-01-readiness.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-DASH-COLLECTOR-01', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($artifact['contract_only'] ?? false));

        foreach ([
            'scheduler_enabled',
            'queue_worker_enabled',
            'production_writes_allowed',
            'collector_write_enabled',
            'collector_runtime_enabled',
            'crawler_log_aggregate_write_enabled',
            'external_api_calls_allowed',
            'cms_mutation_allowed',
            'search_submission_allowed',
            'content_generation_allowed',
            'competitor_scraping_allowed',
            'deployment_allowed',
            'production_env_edit_allowed',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function dependencies_include_post_migration_state_without_granting_writes(): void
    {
        $artifact = $this->artifact();
        $dependencies = array_column($artifact['dependencies'] ?? [], 'task');
        $observed = $artifact['observed_post_migration_state'] ?? [];

        $this->assertContains('SEO-DASH-00-RECONCILE', $dependencies);
        $this->assertContains('SEO-DASH-API-01', $dependencies);
        $this->assertContains('SEO-DASH-MIGRATION-01', $dependencies);
        $this->assertSame('619ce5881cbb63200568796c07467aacd66b52c2', $observed['production_backend_sha'] ?? null);
        $this->assertTrue((bool) ($observed['seo_intel_migrations_all_ran'] ?? false));
        $this->assertTrue((bool) ($observed['seo_crawler_log_daily_aggregates_exists'] ?? false));
        $this->assertSame(0, $observed['seo_crawler_log_daily_aggregates_rows'] ?? null);
        $this->assertSame(401, $observed['private_overview_without_token_http_status'] ?? null);
        $this->assertSame(200, $observed['public_scale_lookup_http_status'] ?? null);
    }

    #[Test]
    public function config_defaults_still_block_writes_external_calls_and_scheduler(): void
    {
        $artifactDefaults = $this->artifact()['required_config_defaults'] ?? [];

        $this->assertFalse((bool) config('seo_intel.enabled'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));
        $this->assertTrue((bool) config('seo_intel.dry_run_default'));
        $this->assertFalse((bool) config('seo_intel.allow_external_api_calls'));
        $this->assertFalse((bool) config('seo_intel.allow_production_crawl'));
        $this->assertFalse((bool) config('seo_intel.allow_production_log_read'));
        $this->assertFalse((bool) config('seo_intel.crawler_log_aggregate_storage.write_enabled'));
        $this->assertFalse((bool) config('seo_intel.crawler_log_aggregate_storage.scheduler_enabled'));

        $this->assertFalse((bool) ($artifactDefaults['seo_intel.enabled'] ?? true));
        $this->assertFalse((bool) ($artifactDefaults['seo_intel.collectors_enabled'] ?? true));
        $this->assertFalse((bool) ($artifactDefaults['seo_intel.write_enabled'] ?? true));
        $this->assertTrue((bool) ($artifactDefaults['seo_intel.dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifactDefaults['seo_intel.allow_external_api_calls'] ?? true));
    }

    #[Test]
    public function allowed_smoke_commands_cover_all_collectors_and_remain_dry_run_no_write(): void
    {
        $artifact = $this->artifact();
        $allowedCollectors = $artifact['allowed_collectors'] ?? [];
        $commands = $artifact['allowed_smoke_commands'] ?? [];

        $this->assertSame(config('seo_intel.allowed_collectors'), $allowedCollectors);
        $this->assertCount(count($allowedCollectors), $commands);

        $commandCollectors = array_column($commands, 'collector');
        sort($allowedCollectors);
        sort($commandCollectors);
        $this->assertSame($allowedCollectors, $commandCollectors);

        foreach ($commands as $entry) {
            $command = (string) ($entry['command'] ?? '');

            $this->assertStringStartsWith('php artisan seo-intel:collect --collector=', $command);
            $this->assertStringContainsString('--dry-run', $command);
            $this->assertStringContainsString('--no-write', $command);
            $this->assertStringContainsString('--json', $command);
            $this->assertStringNotContainsString('SEO_INTEL_WRITE_ENABLED=true', $command);
            $this->assertStringNotContainsString('SEO_INTEL_COLLECTORS_ENABLED=true', $command);
            $this->assertStringNotContainsString('SEO_INTEL_DRY_RUN_DEFAULT=false', $command);
            $this->assertTrue((bool) ($entry['bounded'] ?? false));

            if (($entry['collector'] ?? null) !== 'noop') {
                $this->assertMatchesRegularExpression('/--(canary|limit=\\d+)/', $command);
            }
        }
    }

    #[Test]
    public function docs_lock_no_go_conditions_and_next_task(): void
    {
        $artifact = $this->artifact();
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-dash-collector-01-readiness.md')));

        foreach ([
            'scheduler_activation',
            'production_write',
            'external_api_call',
            'cms_mutation',
            'search_platform_submission',
            'deployment',
        ] as $operation) {
            $this->assertContains($operation, $artifact['forbidden_operations'] ?? []);
        }

        foreach ([
            'production_backend_sha_matches_reviewed_post_migration_sha',
            'seo_intel_migration_status_all_ran_with_dedicated_path',
            'collector_config_defaults_disabled_no_write_dry_run',
            'scheduler_does_not_contain_seo_intel_collect',
        ] as $check) {
            $this->assertContains($check, $artifact['readiness_checklist'] ?? []);
        }

        foreach ([
            'command_omits_dry_run_no_write_or_json',
            'non_noop_command_unbounded',
            'command_reads_raw_production_crawler_logs',
            'smoke_output_reports_writes_or_external_calls',
        ] as $condition) {
            $this->assertContains($condition, $artifact['no_go_conditions'] ?? []);
        }

        foreach ([
            'does not enable scheduler jobs',
            '--dry-run --no-write --json',
            'seo_intel_collectors_enabled=false',
            'seo_intel_write_enabled=false',
            'seo_intel_dry_run_default=true',
            'url submission',
            'approval-gated production collector dry-run/no-write smoke',
        ] as $required) {
            $this->assertStringContainsString($required, $doc);
        }

        $this->assertSame(
            'approval-gated production collector dry-run/no-write smoke; no write enablement until a separate PR',
            $artifact['next_task'] ?? null
        );
    }

    #[Test]
    public function scheduler_remains_free_of_collector_activation(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
        $this->assertStringNotContainsString('SeoIntelCollectCommand', $bootstrap);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-dash-collector-01-readiness.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
