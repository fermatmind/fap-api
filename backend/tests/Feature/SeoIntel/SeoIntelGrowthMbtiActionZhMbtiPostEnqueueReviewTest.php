<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiActionZhMbtiPostEnqueueReviewTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-action-zh-mbti-post-enqueue-review.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-ACTION-ZH-MBTI-POST-ENQUEUE-REVIEW', $artifact['task'] ?? null);
    }

    #[Test]
    public function target_queue_item_and_channel_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(3, $artifact['queue_item_id'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $artifact['target_url'] ?? null
        );
        $this->assertSame('indexnow', $artifact['channel'] ?? null);
    }

    #[Test]
    public function queue_item_state_is_recorded(): void
    {
        $state = $this->artifact()['queue_item_state'] ?? [];

        $this->assertTrue($state['exists'] ?? false);
        $this->assertSame(3, $state['id'] ?? null);
        $this->assertSame(3, $state['batch_id'] ?? null);
        $this->assertSame('zh-CN', $state['locale'] ?? null);
        $this->assertSame('test_detail', $state['page_entity_type'] ?? null);
        $this->assertSame('scale_catalog', $state['source_authority'] ?? null);
        $this->assertSame('indexnow', $state['channel'] ?? null);
        $this->assertSame('pending', $state['approval_state'] ?? null);
        $this->assertSame('dry_run_ready', $state['execution_state'] ?? null);
        $this->assertSame('claim_safe', $state['claim_boundary_state'] ?? null);
        $this->assertFalse($state['private_flow'] ?? true);
    }

    #[Test]
    public function batch_and_event_state_are_safe(): void
    {
        $artifact = $this->artifact();
        $batch = $artifact['batch_state'] ?? [];
        $events = $artifact['event_state'] ?? [];

        $this->assertTrue($batch['exists'] ?? false);
        $this->assertSame(3, $batch['id'] ?? null);
        $this->assertSame('indexnow', $batch['channel'] ?? null);
        $this->assertSame('dry_run', $batch['status'] ?? null);
        $this->assertSame(1, $batch['item_count'] ?? null);
        $this->assertFalse($batch['external_calls_attempted'] ?? true);

        $this->assertTrue($events['queue_item_planned_event_exists'] ?? false);
        $this->assertSame(['queue_item_planned'], $events['event_types'] ?? null);
        $this->assertFalse($events['live_submission_approved_event_exists'] ?? true);
        $this->assertFalse($events['live_submission_response_event_exists'] ?? true);
        $this->assertFalse($events['external_api_call_event_exists'] ?? true);
        $this->assertFalse($events['submission_status_accepted_exists'] ?? true);
    }

    #[Test]
    public function duplicate_check_blocks_second_queue_plan(): void
    {
        $duplicate = $this->artifact()['duplicate_check'] ?? [];

        $this->assertSame(1, $duplicate['target_queue_count'] ?? null);
        $this->assertFalse($duplicate['extra_active_queue_item_for_target'] ?? true);
        $this->assertSame('blocked', $duplicate['duplicate_dry_run_status'] ?? null);
        $this->assertTrue($duplicate['duplicate_detected'] ?? false);
        $this->assertSame('existing_active_queue_item', $duplicate['blocked_reason'] ?? null);
        $this->assertSame(0, $duplicate['planned_queue_count_after_enqueue'] ?? null);
        $this->assertTrue($duplicate['no_extra_duplicate'] ?? false);
    }

    #[Test]
    public function queue_item_2_state_is_recorded_as_unchanged(): void
    {
        $state = $this->artifact()['queue_item_2_state'] ?? [];

        $this->assertSame(2, $state['id'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $state['canonical_url'] ?? null
        );
        $this->assertSame('indexnow', $state['channel'] ?? null);
        $this->assertSame('approved', $state['approval_state'] ?? null);
        $this->assertSame('submitted', $state['execution_state'] ?? null);
        $this->assertTrue($state['unchanged'] ?? false);
        $this->assertFalse($state['duplicate_en_queue_item_detected'] ?? true);
    }

    #[Test]
    public function gates_public_runtime_and_safety_boundaries_are_recorded(): void
    {
        $artifact = $this->artifact();
        $gates = $artifact['gate_state'] ?? [];
        $runtime = $artifact['public_runtime_state'] ?? [];

        $this->assertFalse($gates['queue_write_enabled'] ?? true);
        $this->assertFalse($gates['live_submission_enabled'] ?? true);
        $this->assertFalse($gates['external_api_calls_enabled'] ?? true);
        $this->assertFalse($gates['indexnow_live_api_enabled'] ?? true);
        $this->assertTrue($gates['gates_closed'] ?? false);

        $this->assertSame(200, $runtime['http_status'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $runtime['canonical'] ?? null
        );
        $this->assertFalse($runtime['has_noindex'] ?? true);
        $this->assertFalse($runtime['has_staging_canonical'] ?? true);

        $this->assertFalse($artifact['accidental_live_submission_detected'] ?? true);
        $this->assertFalse($artifact['external_api_call_detected'] ?? true);
        $this->assertFalse($artifact['enqueue_performed'] ?? true);
        $this->assertFalse($artifact['live_submission_performed'] ?? true);
        $this->assertFalse($artifact['cms_mutation_performed'] ?? true);
        $this->assertFalse($artifact['fap_web_mutation_performed'] ?? true);
        $this->assertTrue($artifact['research_deferred'] ?? false);
    }

    #[Test]
    public function final_decision_and_next_task_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'zh_mbti_post_enqueue_review_completed_ready_for_live_preflight',
            $artifact['final_decision'] ?? null
        );
        $this->assertSame('SEARCH-CHANNEL-LIVE-ZH-MBTI-01', $artifact['next_task'] ?? null);
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-zh-mbti-post-enqueue-review.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
