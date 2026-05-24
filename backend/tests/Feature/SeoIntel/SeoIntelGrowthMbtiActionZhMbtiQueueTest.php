<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiActionZhMbtiQueueTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-action-zh-mbti-queue.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-ACTION-ZH-MBTI-QUEUE', $artifact['task'] ?? null);
    }

    #[Test]
    public function approval_target_and_channel_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['approval_phrase_verified'] ?? false);
        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $artifact['target_url'] ?? null
        );
        $this->assertSame('indexnow', $artifact['channel'] ?? null);
    }

    #[Test]
    public function pre_enqueue_dry_run_was_safe_and_exact(): void
    {
        $dryRun = $this->artifact()['pre_enqueue_dry_run'] ?? [];

        $this->assertSame('success', $dryRun['status'] ?? null);
        $this->assertTrue($dryRun['dry_run'] ?? false);
        $this->assertTrue($dryRun['no_write'] ?? false);
        $this->assertSame(1, $dryRun['candidate_count'] ?? null);
        $this->assertSame(1, $dryRun['eligible_count'] ?? null);
        $this->assertSame(1, $dryRun['planned_queue_count'] ?? null);
        $this->assertFalse($dryRun['duplicate_detected'] ?? true);
        $this->assertFalse($dryRun['writes_committed'] ?? true);
        $this->assertFalse($dryRun['live_submission_attempted'] ?? true);
        $this->assertFalse($dryRun['external_calls_attempted'] ?? true);
        $this->assertSame([], $dryRun['issues'] ?? null);

        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $dryRun['selected_candidate']['canonical_url'] ?? null
        );
        $this->assertSame('test_detail', $dryRun['selected_candidate']['page_entity_type'] ?? null);
        $this->assertSame('scale_catalog', $dryRun['selected_candidate']['source_authority'] ?? null);
        $this->assertSame('claim_safe', $dryRun['selected_candidate']['claim_boundary_state'] ?? null);
        $this->assertFalse($dryRun['selected_candidate']['private_flow'] ?? true);
    }

    #[Test]
    public function enqueue_result_created_exactly_one_queue_item_without_submission(): void
    {
        $artifact = $this->artifact();
        $enqueue = $artifact['enqueue_result'] ?? [];

        $this->assertSame('success', $enqueue['status'] ?? null);
        $this->assertTrue($enqueue['writes_attempted'] ?? false);
        $this->assertTrue($enqueue['writes_committed'] ?? false);
        $this->assertTrue($enqueue['enqueue_attempted'] ?? false);
        $this->assertTrue($enqueue['enqueue_committed'] ?? false);
        $this->assertSame(1, $enqueue['written_items'] ?? null);
        $this->assertContains(3, $enqueue['batch_ids'] ?? []);
        $this->assertFalse($enqueue['external_calls_attempted'] ?? true);
        $this->assertFalse($enqueue['search_submission_attempted'] ?? true);
        $this->assertFalse($enqueue['live_submission_attempted'] ?? true);
        $this->assertSame([], $enqueue['issues'] ?? null);

        $this->assertSame(3, $artifact['queue_item_id'] ?? null);
        $this->assertSame(3, $artifact['batch_id'] ?? null);
    }

    #[Test]
    public function queue_item_and_batch_state_are_expected(): void
    {
        $artifact = $this->artifact();
        $queueItem = $artifact['queue_item_state'] ?? [];
        $batch = $artifact['batch_state'] ?? [];

        $this->assertSame(3, $queueItem['id'] ?? null);
        $this->assertSame(3, $queueItem['batch_id'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $queueItem['canonical_url'] ?? null
        );
        $this->assertSame('zh-CN', $queueItem['locale'] ?? null);
        $this->assertSame('test_detail', $queueItem['page_entity_type'] ?? null);
        $this->assertSame('scale_catalog', $queueItem['source_authority'] ?? null);
        $this->assertSame('indexnow', $queueItem['channel'] ?? null);
        $this->assertSame('eligible', $queueItem['eligibility_state'] ?? null);
        $this->assertSame('pending', $queueItem['approval_state'] ?? null);
        $this->assertSame('dry_run_ready', $queueItem['execution_state'] ?? null);
        $this->assertSame('claim_safe', $queueItem['claim_boundary_state'] ?? null);
        $this->assertFalse($queueItem['private_flow'] ?? true);

        $this->assertSame(3, $batch['id'] ?? null);
        $this->assertSame('dry_run', $batch['status'] ?? null);
        $this->assertSame(1, $batch['item_count'] ?? null);
        $this->assertFalse($batch['external_calls_attempted'] ?? true);
    }

    #[Test]
    public function gates_are_closed_after_enqueue(): void
    {
        $gates = $this->artifact()['gate_state_after'] ?? [];

        $this->assertFalse($gates['queue_write_enabled'] ?? true);
        $this->assertFalse($gates['live_submission_enabled'] ?? true);
        $this->assertFalse($gates['external_api_calls_enabled'] ?? true);
        $this->assertFalse($gates['indexnow_live_api_enabled'] ?? true);
        $this->assertTrue($gates['gates_closed_after_enqueue'] ?? false);
    }

    #[Test]
    public function queue_item_2_remains_unchanged(): void
    {
        $queueItem2 = $this->artifact()['queue_item_2_state'] ?? [];

        $this->assertSame(2, $queueItem2['id'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $queueItem2['canonical_url'] ?? null
        );
        $this->assertSame('indexnow', $queueItem2['channel'] ?? null);
        $this->assertSame('approved', $queueItem2['approval_state'] ?? null);
        $this->assertSame('submitted', $queueItem2['execution_state'] ?? null);
        $this->assertTrue($queueItem2['unchanged'] ?? false);
        $this->assertTrue($queueItem2['not_part_of_target'] ?? false);
    }

    #[Test]
    public function safety_boundaries_and_next_task_are_recorded(): void
    {
        $artifact = $this->artifact();

        $this->assertFalse($artifact['live_submission_performed'] ?? true);
        $this->assertFalse($artifact['external_api_call_performed'] ?? true);
        $this->assertFalse($artifact['search_submission_performed'] ?? true);
        $this->assertFalse($artifact['cms_mutation_performed'] ?? true);
        $this->assertFalse($artifact['sitemap_llms_authority_used'] ?? true);
        $this->assertFalse($artifact['frontend_fallback_authority_used'] ?? true);
        $this->assertTrue($artifact['research_deferred'] ?? false);
        $this->assertSame(
            'zh_mbti_queue_enqueue_completed_ready_for_post_enqueue_review',
            $artifact['final_decision'] ?? null
        );
        $this->assertSame(
            'SEO-GROWTH-MBTI-ACTION-ZH-MBTI-POST-ENQUEUE-REVIEW',
            $artifact['next_task'] ?? null
        );
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-zh-mbti-queue.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
