<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCrawlerLogSchedulerReadinessScanTest extends TestCase
{
    #[Test]
    public function scheduler_readiness_doc_and_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/crawler-log-scheduler-readiness-scan.md'));

        $artifact = $this->artifact();

        $this->assertSame('crawler-log-scheduler-readiness-scan.v1', $artifact['version'] ?? null);
        $this->assertSame('CRAWLER-LOG-11', $artifact['task'] ?? null);
        $this->assertSame('crawler_log_scheduler_readiness_scan', $artifact['runtime'] ?? null);
        $this->assertSame('SEO-OBSERVATION-QUEUE-00', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function current_pr_does_not_activate_scheduler_or_mutating_paths(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'scheduler_activation',
            'active_schedule_entry_added',
            'active_crawler_log_schedule_found',
            'production_log_read_attempted',
            'canary_execution',
            'database_write_attempted',
            'raw_persistence',
            'issue_queue_write',
            'url_truth_mutation',
            'search_channel_queue_enqueue',
            'search_submission',
            'external_search_api_call',
            'metabase_exposure',
            'deployment',
            'env_edit',
            'production_migration',
        ] as $flag) {
            $this->assertFalse($artifact[$flag] ?? true, $flag);
        }
    }

    #[Test]
    public function scanned_scheduler_surfaces_and_command_are_declared(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'backend/app/Console/Kernel.php',
            'backend/bootstrap/app.php',
            'backend/app/Console/Commands/SeoIntelCrawlerLogObserveCommand.php',
            'backend/config/seo_intel.php',
        ] as $file) {
            $this->assertContains($file, $artifact['scanned_files'] ?? []);
        }

        $this->assertSame('seo-intel:crawler-log-observe', $artifact['current_command'] ?? null);

        foreach ([
            '--fixture',
            '--source',
            '--approval-phrase',
            '--dry-run',
            '--no-write',
            '--json',
            '--limit',
        ] as $mode) {
            $this->assertContains($mode, $artifact['current_command_modes'] ?? []);
        }
    }

    #[Test]
    public function future_scheduler_gates_are_explicit_and_disabled_by_default(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'approved_production_log_source_registry',
            'dry_run_no_write_success_before_write',
            'aggregate_write_gate_env',
            'dedicated_scheduler_gate_env',
            'seo_intel_aggregate_table_verified',
            'raw_persistence_blocked',
            'without_overlapping',
            'single_server_scheduling_where_supported',
            'kill_switch',
        ] as $gate) {
            $this->assertContains($gate, $artifact['required_future_gates'] ?? []);
        }

        foreach ([
            'SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED',
            'SEO_INTEL_CRAWLER_LOG_SCHEDULER_ENABLED',
        ] as $gateEnv) {
            $this->assertContains($gateEnv, $artifact['required_future_env_gates'] ?? []);
        }

        $this->assertFalse($artifact['current_safe_posture']['scheduler_enabled_flag'] ?? true);
        $this->assertTrue($artifact['current_safe_posture']['dry_run_supported'] ?? false);
        $this->assertTrue($artifact['current_safe_posture']['no_write_supported'] ?? false);
        $this->assertSame(1000, $artifact['current_safe_posture']['max_lines_cap'] ?? null);
    }

    #[Test]
    public function active_laravel_scheduler_does_not_include_crawler_log_observe_command(): void
    {
        $scheduleSources = strtolower(
            (string) file_get_contents(app_path('Console/Kernel.php')).
            "\n".
            (string) file_get_contents(base_path('bootstrap/app.php'))
        );

        $this->assertStringNotContainsString("schedule->command('seo-intel:crawler-log-observe", $scheduleSources);
        $this->assertStringNotContainsString('schedule->command("seo-intel:crawler-log-observe', $scheduleSources);
        $this->assertStringNotContainsString('seo-intel:crawler-log-observe --source', $scheduleSources);
    }

    #[Test]
    public function crawler_log_observe_command_does_not_expose_scheduler_or_live_submission_modes(): void
    {
        $command = strtolower((string) file_get_contents(app_path('Console/Commands/SeoIntelCrawlerLogObserveCommand.php')));

        foreach ([
            '--schedule',
            '--write',
            '--submit',
            '--tail',
            '--production',
            'search_submission_attempted\' => true',
            'scheduler_enabled\' => true',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $command);
        }
    }

    #[Test]
    public function docs_lock_boundary_and_handoff_to_next_train(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/crawler-log-scheduler-readiness-scan.md')));

        foreach ([
            'does not enable laravel scheduler',
            'no active laravel schedule entry',
            'seo_intel_crawler_log_aggregate_write_enabled',
            'seo_intel_crawler_log_scheduler_enabled',
            'crawler logs may map safe paths to existing cms/backend url truth',
            'must not create or override url truth',
            'seo-observation-queue-00',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $doc);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/crawler-log-scheduler-readiness-scan.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
