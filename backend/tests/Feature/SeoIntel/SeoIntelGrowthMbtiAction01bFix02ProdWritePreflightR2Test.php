<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02ProdWritePreflightR2Test extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'seo-growth-mbti-action-01b-fix-02-prod-write-preflight-r2.v1',
            $artifact['schema_version'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-01B-FIX-02-PROD-WRITE-PREFLIGHT-R2',
            $artifact['task'] ?? null
        );
    }

    #[Test]
    public function safety_flags_lock_no_write_no_enqueue_no_submission_and_no_external_api(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['no_write_performed'] ?? false);
        $this->assertFalse($artifact['production_write_performed'] ?? true);
        $this->assertFalse($artifact['enqueue_performed'] ?? true);
        $this->assertFalse($artifact['live_submission_performed'] ?? true);
        $this->assertFalse($artifact['external_api_call_performed'] ?? true);
        $this->assertFalse($artifact['cms_mutation_performed'] ?? true);
        $this->assertFalse($artifact['sitemap_mutation_performed'] ?? true);
        $this->assertFalse($artifact['llms_mutation_performed'] ?? true);
        $this->assertFalse($artifact['fap_web_mutation_performed'] ?? true);
    }

    #[Test]
    public function queue_item_2_safety_fix_is_proven_in_production_dry_run(): void
    {
        $artifact = $this->artifact();
        $dryRun = $artifact['dry_run_result'] ?? [];

        $this->assertTrue($artifact['queue_item_2_untouched'] ?? false);
        $this->assertTrue($dryRun['queue_item_2_untouched'] ?? false);
        $this->assertSame('dry_run_ready', $dryRun['status'] ?? null);
        $this->assertTrue($dryRun['dry_run'] ?? false);
        $this->assertTrue($dryRun['no_write'] ?? false);
        $this->assertFalse($dryRun['writes_committed'] ?? true);
        $this->assertSame([], $dryRun['issues'] ?? null);
    }

    #[Test]
    public function planned_rows_are_listed(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            'https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
        ] as $url) {
            $this->assertContains($url, $artifact['planned_retire_www_rows'] ?? []);
        }

        foreach ([
            'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
        ] as $url) {
            $this->assertContains($url, $artifact['planned_write_apex_rows'] ?? []);
        }

        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $artifact['planned_write_zh_mbti_row'] ?? null
        );
    }

    #[Test]
    public function persisted_state_after_dry_run_remains_unwritten(): void
    {
        $state = $this->artifact()['persisted_state_after_dry_run'] ?? [];

        $this->assertTrue($state['old_research_www_rows_still_present'] ?? false);
        $this->assertTrue($state['research_apex_rows_absent'] ?? false);
        $this->assertTrue($state['zh_mbti_apex_row_absent'] ?? false);
        $this->assertTrue($state['queue_item_2_unchanged'] ?? false);
    }

    #[Test]
    public function future_approval_final_decision_and_next_task_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertIsString($artifact['future_human_approval_phrase'] ?? null);
        $this->assertStringContainsString('Do not enqueue Search Channel items.', $artifact['future_human_approval_phrase']);
        $this->assertIsString($artifact['final_decision'] ?? null);
        $this->assertSame(
            'mbti_action_01b_fix_02_prod_write_preflight_r2_ready_for_human_approved_write',
            $artifact['final_decision'] ?? null
        );
        $this->assertIsString($artifact['next_task'] ?? null);
        $this->assertNotSame('', $artifact['next_task'] ?? '');
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-fix-02-prod-write-preflight-r2.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
