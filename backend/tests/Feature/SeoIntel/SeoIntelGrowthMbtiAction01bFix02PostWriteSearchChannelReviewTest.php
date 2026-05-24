<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02PostWriteSearchChannelReviewTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'seo-growth-mbti-action-01b-fix-02-post-write-search-channel-review.v1',
            $artifact['schema_version'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-01B-FIX-02-POST-WRITE-SEARCH-CHANNEL-REVIEW',
            $artifact['task'] ?? null
        );
    }

    #[Test]
    public function no_write_and_no_submission_boundaries_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['no_write_performed'] ?? false);
        $this->assertTrue($artifact['no_enqueue'] ?? false);
        $this->assertTrue($artifact['no_submission'] ?? false);
        $this->assertTrue($artifact['no_external_api_call'] ?? false);
        $this->assertTrue($artifact['no_cms_mutation'] ?? false);
        $this->assertTrue($artifact['no_sitemap_mutation'] ?? false);
        $this->assertTrue($artifact['no_llms_mutation'] ?? false);
        $this->assertTrue($artifact['no_fap_web_mutation'] ?? false);
        $this->assertFalse($artifact['accidental_enqueue_detected'] ?? true);
        $this->assertFalse($artifact['accidental_submission_detected'] ?? true);
        $this->assertFalse($artifact['external_api_call_detected'] ?? true);
    }

    #[Test]
    public function old_www_research_rows_are_listed_as_non_eligible(): void
    {
        $rows = collect($this->artifact()['old_www_rows_state'] ?? [])->keyBy('canonical_url');

        foreach ([
            'https://www.fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            'https://www.fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
        ] as $url) {
            $this->assertTrue($rows->has($url));
            $this->assertSame('superseded_canonical', $rows[$url]['indexability_state'] ?? null);
            $this->assertSame('superseded_canonical', $rows[$url]['entity_authority_status'] ?? null);
            $this->assertSame('blocked', $rows[$url]['search_channel_eligibility'] ?? null);
            $this->assertTrue($rows[$url]['non_eligible'] ?? false);
        }
    }

    #[Test]
    public function apex_research_and_zh_mbti_rows_are_listed(): void
    {
        $researchUrls = array_column($this->artifact()['apex_research_rows_state'] ?? [], 'canonical_url');

        $this->assertContains(
            'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            $researchUrls
        );
        $this->assertContains(
            'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
            $researchUrls
        );

        foreach ($this->artifact()['apex_research_rows_state'] ?? [] as $row) {
            $this->assertSame('indexable', $row['indexability_state'] ?? null);
            $this->assertSame('backend_cms', $row['source_authority'] ?? null);
            $this->assertSame('eligible', $row['search_channel_eligibility'] ?? null);
        }

        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $this->artifact()['zh_mbti_row_state']['canonical_url'] ?? null
        );
        $this->assertSame('indexable', $this->artifact()['zh_mbti_row_state']['indexability_state'] ?? null);
        $this->assertSame('eligible', $this->artifact()['zh_mbti_row_state']['search_channel_eligibility'] ?? null);
    }

    #[Test]
    public function queue_item_2_state_is_recorded_as_unchanged(): void
    {
        $queue = $this->artifact()['queue_item_2_state'] ?? [];

        $this->assertSame(2, $queue['id'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $queue['canonical_url'] ?? null
        );
        $this->assertSame('indexnow', $queue['channel'] ?? null);
        $this->assertSame('approved', $queue['approval_state'] ?? null);
        $this->assertSame('submitted', $queue['execution_state'] ?? null);
        $this->assertTrue($queue['unchanged'] ?? false);
        $this->assertFalse($queue['duplicate_en_mbti_queue_item_detected'] ?? true);
    }

    #[Test]
    public function cleanup_dry_run_and_search_channel_review_are_safe(): void
    {
        $artifact = $this->artifact();
        $cleanup = $artifact['cleanup_command_idempotency_dry_run'] ?? [];
        $search = $artifact['search_channel_dry_run'] ?? [];

        $this->assertTrue($cleanup['dry_run'] ?? false);
        $this->assertTrue($cleanup['no_write'] ?? false);
        $this->assertFalse($cleanup['writes_committed'] ?? true);
        $this->assertTrue($cleanup['queue_item_2_untouched'] ?? false);
        $this->assertTrue($cleanup['duplicate_cluster_prevented'] ?? false);
        $this->assertSame([], $cleanup['issues'] ?? null);

        $this->assertTrue($search['old_www_rows_not_eligible'] ?? false);
        $this->assertTrue($search['apex_candidates_clean'] ?? false);
        $this->assertFalse($search['broad']['enqueue_attempted'] ?? true);
        $this->assertFalse($search['broad']['live_submission_attempted'] ?? true);
        $this->assertFalse($search['broad']['external_calls_attempted'] ?? true);
    }

    #[Test]
    public function final_decision_and_recommended_next_task_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'mbti_action_01b_fix_02_post_write_review_completed_ready_for_zh_mbti_queue_preflight',
            $artifact['final_decision'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-ZH-MBTI-QUEUE-PREFLIGHT',
            $artifact['recommended_next_task'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-ZH-MBTI-QUEUE-PREFLIGHT',
            $artifact['next_task'] ?? null
        );
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-fix-02-post-write-search-channel-review.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
