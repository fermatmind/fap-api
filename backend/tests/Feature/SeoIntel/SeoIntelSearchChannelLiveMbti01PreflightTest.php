<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelSearchChannelLiveMbti01PreflightTest extends TestCase
{
    public function test_generated_artifact_exists_and_locks_live_preflight_boundaries(): void
    {
        $path = base_path('docs/seo/generated/search-channel-live-mbti-01-preflight.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['queue_item_id']);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $payload['target_url']
        );
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertSame('pending', $payload['approval_state']);
        $this->assertSame('dry_run_ready', $payload['execution_state']);
        $this->assertSame('scale_catalog', $payload['source_authority']);
        $this->assertSame('test_detail', $payload['page_entity_type']);
        $this->assertFalse($payload['duplicate_active_queue_item']);
        $this->assertFalse($payload['queue_write_gate_state']);
        $this->assertFalse($payload['live_submission_gate_state']);
        $this->assertFalse($payload['external_api_gate_state']);
        $this->assertFalse($payload['indexnow_live_api_gate_state']);
        $this->assertTrue($payload['indexnow_key_present']);
        $this->assertTrue($payload['indexnow_key_location_present']);
        $this->assertTrue($payload['indexnow_key_location_publicly_verified']);
        $this->assertTrue($payload['submit_dry_run_passed']);
        $this->assertFalse($payload['live_submission_attempted']);
        $this->assertFalse($payload['external_api_call_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['url_truth_write_attempted']);
        $this->assertNotEmpty($payload['exact_future_approval_phrase']);
        $this->assertSame('ready_for_human_approved_live_submission', $payload['final_decision']);
    }
}
