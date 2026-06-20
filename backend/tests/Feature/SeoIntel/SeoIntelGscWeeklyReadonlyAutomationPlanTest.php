<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscWeeklyReadonlyAutomationPlanTest extends TestCase
{
    #[Test]
    public function weekly_readonly_runner_is_manual_bounded_and_readonly(): void
    {
        $scriptPath = base_path('scripts/seo/gsc_weekly_readonly_runner.sh');

        $this->assertFileExists($scriptPath);
        $this->assertTrue(is_executable($scriptPath), 'weekly readonly runner must be executable');

        $script = (string) file_get_contents($scriptPath);

        $this->assertStringContainsString('WINDOW_DAYS="${WINDOW_DAYS:-28}"', $script);
        $this->assertStringContainsString('7|28', $script);
        $this->assertStringContainsString('LIMIT="${LIMIT:-250}"', $script);
        $this->assertStringContainsString('DIMENSIONS="${DIMENSIONS:-query,page}"', $script);
        $this->assertStringContainsString('scripts/seo/gsc_sidecar_runner.sh', $script);
        $this->assertStringContainsString('--mode=preflight', $script);
        $this->assertStringContainsString('--mode=live-read', $script);
        $this->assertStringContainsString('seo-intel:gsc-readmodel-import-dry-run', $script);
        $this->assertStringContainsString('--dry-run', $script);
        $this->assertStringContainsString('gsc-weekly-readonly-run.v1', $script);

        $this->assertStringNotContainsString('--execute', $script);
        $this->assertStringNotContainsString('seo-intel:gsc-readmodel-import-canary', $script);
        $this->assertStringNotContainsString('schedule:run', $script);
        $this->assertStringNotContainsString('queue:work', $script);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $script);
        $this->assertStringNotContainsString('client_email', $script);
        $this->assertStringNotContainsString('Bearer ', $script);
        $this->assertStringNotContainsString('ya29.', $script);
    }

    #[Test]
    public function generated_contract_keeps_scheduler_writes_and_execution_actions_disabled(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/gsc-weekly-readonly-automation-plan.v1.json')),
            true
        );

        $this->assertIsArray($artifact);
        $this->assertSame('gsc-weekly-readonly-automation-plan.v1', $artifact['version'] ?? null);
        $this->assertSame('backend/scripts/seo/gsc_weekly_readonly_runner.sh', $artifact['script'] ?? null);
        $this->assertSame(28, data_get($artifact, 'default_runtime.window_days'));
        $this->assertSame([7, 28], data_get($artifact, 'default_runtime.allowed_window_days'));
        $this->assertSame(250, data_get($artifact, 'default_runtime.limit'));
        $this->assertSame('query,page', data_get($artifact, 'default_runtime.dimensions'));
        $this->assertContains('gsc_live_read_readonly', $artifact['workflow'] ?? []);
        $this->assertContains('gsc_readmodel_import_dryrun', $artifact['workflow'] ?? []);
        $this->assertSame('gsc-weekly-readonly-run.v1', $artifact['output_schema'] ?? null);

        foreach ([
            'database_write',
            'seo_gsc_daily_write',
            'controlled_import_execute',
            'scheduler_activation',
            'production_cron_activation',
            'queue_worker_started',
            'opportunity_queue_enqueue',
            'cms_write',
            'search_channel_submit',
            'indexing_request',
            'google_indexing_api_call',
            'production_env_change',
            'pr_train_metadata_change',
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }

        $this->assertTrue((bool) data_get($artifact, 'allowed_external_call.google_search_console_search_analytics_readonly'));
        $this->assertFalse((bool) data_get($artifact, 'allowed_external_call.google_indexing_api', true));
        $this->assertContains('laravel_scheduler_activation', $artifact['held_items'] ?? []);
        $this->assertContains('automatic_tdk_or_content_mutation', $artifact['held_items'] ?? []);
    }
}
