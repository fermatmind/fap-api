<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSearchChannelLiveZhMbti01PreflightTest extends TestCase
{
    #[Test]
    public function generated_json_exists_and_parses(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('search-channel-live-zh-mbti-01-preflight.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-LIVE-ZH-MBTI-01', $artifact['task'] ?? null);
    }

    #[Test]
    public function queue_item_target_and_channel_are_locked(): void
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
        $this->assertSame('pending', $state['approval_state'] ?? null);
        $this->assertSame('dry_run_ready', $state['execution_state'] ?? null);
        $this->assertSame('eligible', $state['eligibility_state'] ?? null);
        $this->assertSame('scale_catalog', $state['source_authority'] ?? null);
        $this->assertSame('test_detail', $state['page_entity_type'] ?? null);
        $this->assertSame('claim_safe', $state['claim_boundary_state'] ?? null);
        $this->assertFalse($state['private_flow'] ?? true);
    }

    #[Test]
    public function duplicate_check_and_queue_item_two_are_safe(): void
    {
        $artifact = $this->artifact();
        $duplicate = $artifact['duplicate_check'] ?? [];
        $item2 = $artifact['queue_item_2_state'] ?? [];

        $this->assertSame(1, $duplicate['target_queue_count'] ?? null);
        $this->assertTrue($duplicate['duplicate_detected'] ?? false);
        $this->assertSame('existing_active_queue_item', $duplicate['blocked_reason'] ?? null);
        $this->assertFalse($duplicate['live_submission_response_event_exists'] ?? true);

        $this->assertSame(2, $item2['id'] ?? null);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $item2['canonical_url'] ?? null
        );
        $this->assertSame('approved', $item2['approval_state'] ?? null);
        $this->assertSame('submitted', $item2['execution_state'] ?? null);
        $this->assertTrue($item2['unchanged'] ?? false);
    }

    #[Test]
    public function gate_and_submit_dry_run_state_are_safe(): void
    {
        $artifact = $this->artifact();
        $gates = $artifact['gate_state'] ?? [];
        $dryRun = $artifact['submit_dry_run'] ?? [];

        $this->assertFalse($gates['queue_write_enabled'] ?? true);
        $this->assertFalse($gates['live_submission_enabled'] ?? true);
        $this->assertFalse($gates['external_api_calls_enabled'] ?? true);
        $this->assertFalse($gates['indexnow_live_api_enabled'] ?? true);

        $this->assertSame('success', $dryRun['status'] ?? null);
        $this->assertTrue($dryRun['dry_run'] ?? false);
        $this->assertFalse($dryRun['external_calls_attempted'] ?? true);
        $this->assertFalse($dryRun['search_submission_attempted'] ?? true);
        $this->assertFalse($dryRun['writes_attempted'] ?? true);
        $this->assertFalse($dryRun['writes_committed'] ?? true);
    }

    #[Test]
    public function indexnow_key_readiness_blocker_is_recorded_without_exposing_key(): void
    {
        $readiness = $this->artifact()['indexnow_key_readiness'] ?? [];

        $this->assertTrue($readiness['key_configured'] ?? false);
        $this->assertTrue($readiness['key_location_configured'] ?? false);
        $this->assertSame(
            'https://fermatmind.com/8d59565935303aad72c5eb0ec5bfa42e.txt',
            $readiness['key_location'] ?? null
        );
        $this->assertSame(404, $readiness['public_key_location_status'] ?? null);
        $this->assertFalse($readiness['public_key_location_matches_configured_key'] ?? true);
        $this->assertFalse($readiness['ready'] ?? true);
        $this->assertFalse($readiness['raw_key_exposed'] ?? true);
    }

    #[Test]
    public function safety_boundaries_future_phrase_and_next_task_are_present(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(
            'I explicitly approve SEARCH-CHANNEL-LIVE-ZH-MBTI-02 live submission for queue item 3 channel indexnow URL https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types.',
            $artifact['future_approval_phrase'] ?? null
        );
        $this->assertFalse($artifact['live_submission_performed'] ?? true);
        $this->assertFalse($artifact['external_api_call_performed'] ?? true);
        $this->assertFalse($artifact['enqueue_performed'] ?? true);
        $this->assertTrue($artifact['research_deferred'] ?? false);
        $this->assertSame('blocked_indexnow_key_missing', $artifact['final_decision'] ?? null);
        $this->assertSame('SEARCH-CHANNEL-LIVE-ZH-MBTI-01A-INDEXNOW-KEYLOCATION-FIX', $artifact['next_task'] ?? null);
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/search-channel-live-zh-mbti-01-preflight.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
