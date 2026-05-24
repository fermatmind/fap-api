<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02ProdWritePreflightTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'seo-growth-mbti-action-01b-fix-02-prod-write-preflight.v1',
            $artifact['schema_version'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-01B-FIX-02-PROD-WRITE-PREFLIGHT',
            $artifact['task'] ?? null
        );
    }

    #[Test]
    public function safety_flags_lock_no_write_no_production_write_no_enqueue_no_submission_and_no_external_api(): void
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
    }

    #[Test]
    public function planned_old_www_rows_and_research_apex_rows_are_listed(): void
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
    }

    #[Test]
    public function planned_zh_mbti_row_and_queue_item_two_status_are_recorded(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $artifact['planned_write_zh_mbti_row'] ?? null
        );
        $this->assertFalse($artifact['queue_item_2_untouched'] ?? true);
        $this->assertTrue($artifact['queue_item_2_persisted_unchanged_after_dry_run'] ?? false);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $artifact['queue_item_2_persisted_state_after_dry_run']['canonical_url'] ?? null
        );
        $this->assertSame('submitted', $artifact['queue_item_2_persisted_state_after_dry_run']['execution_state'] ?? null);
    }

    #[Test]
    public function future_approval_final_decision_and_next_task_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertIsString($artifact['future_human_approval_phrase'] ?? null);
        $this->assertStringContainsString('Do not enqueue Search Channel items.', $artifact['future_human_approval_phrase']);
        $this->assertSame('blocked_queue_item_2_risk', $artifact['final_decision'] ?? null);
        $this->assertIsString($artifact['next_task'] ?? null);
        $this->assertNotSame('', $artifact['next_task'] ?? '');
    }

    #[Test]
    public function dry_run_result_records_fail_closed_no_write_behavior(): void
    {
        $dryRun = $this->artifact()['dry_run_result'] ?? [];

        $this->assertTrue($dryRun['dry_run'] ?? false);
        $this->assertTrue($dryRun['no_write'] ?? false);
        $this->assertFalse($dryRun['writes_committed'] ?? true);
        $this->assertFalse($dryRun['search_channel_enqueue_attempted'] ?? true);
        $this->assertFalse($dryRun['live_submission_attempted'] ?? true);
        $this->assertFalse($dryRun['external_api_call_attempted'] ?? true);
        $this->assertTrue($dryRun['apex_research_candidates_found'] ?? false);
        $this->assertTrue($dryRun['zh_mbti_candidate_found'] ?? false);
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-fix-02-prod-write-preflight.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
