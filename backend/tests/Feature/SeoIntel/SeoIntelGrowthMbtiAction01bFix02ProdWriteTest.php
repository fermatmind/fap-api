<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02ProdWriteTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'seo-growth-mbti-action-01b-fix-02-prod-write.v1',
            $artifact['schema_version'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-01B-FIX-02-PROD-WRITE',
            $artifact['task'] ?? null
        );
    }

    #[Test]
    public function approval_and_safety_flags_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['approval_phrase_verified'] ?? false);
        $this->assertTrue($artifact['queue_item_2_untouched'] ?? false);
        $this->assertFalse($artifact['enqueue_performed'] ?? true);
        $this->assertFalse($artifact['live_submission_performed'] ?? true);
        $this->assertFalse($artifact['external_api_call_performed'] ?? true);
        $this->assertFalse($artifact['cms_mutation_performed'] ?? true);
        $this->assertFalse($artifact['sitemap_mutation_performed'] ?? true);
        $this->assertFalse($artifact['llms_mutation_performed'] ?? true);
    }

    #[Test]
    public function execute_result_records_bounded_write_success(): void
    {
        $execute = $this->artifact()['execute_result'] ?? [];

        $this->assertSame('success', $execute['status'] ?? null);
        $this->assertTrue($execute['execute_attempted'] ?? false);
        $this->assertTrue($execute['writes_committed'] ?? false);
        $this->assertSame(2, $execute['old_www_rows_retired'] ?? null);
        $this->assertSame(2, $execute['apex_research_rows_written'] ?? null);
        $this->assertTrue($execute['zh_mbti_row_written'] ?? false);
        $this->assertSame(5, $execute['seo_url_entities_updated'] ?? null);
        $this->assertSame([], $execute['issues'] ?? null);
    }

    #[Test]
    public function old_www_rows_are_listed_as_retired(): void
    {
        $urls = array_column($this->artifact()['retired_www_rows'] ?? [], 'canonical_url');

        $this->assertContains(
            'https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            $urls
        );
        $this->assertContains(
            'https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
            $urls
        );

        foreach ($this->artifact()['retired_www_rows'] ?? [] as $row) {
            $this->assertSame('superseded_canonical', $row['indexability_state'] ?? null);
            $this->assertSame('superseded_canonical', $row['entity_authority_status'] ?? null);
        }
    }

    #[Test]
    public function research_apex_rows_and_zh_mbti_row_are_listed_as_written(): void
    {
        $researchUrls = array_column($this->artifact()['written_apex_research_rows'] ?? [], 'canonical_url');

        $this->assertContains(
            'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            $researchUrls
        );
        $this->assertContains(
            'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
            $researchUrls
        );
        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $this->artifact()['written_zh_mbti_row']['canonical_url'] ?? null
        );
    }

    #[Test]
    public function search_channel_after_write_records_apex_eligible_and_old_www_blocked(): void
    {
        $results = collect($this->artifact()['search_channel_dry_run_after_write']['url_results'] ?? [])
            ->keyBy('canonical_url');

        $this->assertSame('eligible', $results['https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types']['status'] ?? null);
        $this->assertSame('eligible', $results['https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report']['status'] ?? null);
        $this->assertSame('eligible', $results['https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report']['status'] ?? null);
        $this->assertSame('blocked', $results['https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report']['status'] ?? null);
        $this->assertSame('blocked', $results['https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report']['status'] ?? null);
    }

    #[Test]
    public function final_decision_and_next_task_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'mbti_action_01b_fix_02_prod_write_completed_ready_for_search_channel_rerun',
            $artifact['final_decision'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-01B-FIX-02-POST-WRITE-SEARCH-CHANNEL-REVIEW',
            $artifact['next_task'] ?? null
        );
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-fix-02-prod-write.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
