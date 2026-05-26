<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelSearchChannelLiveZhMbti02TwentyFourHourReviewTest extends TestCase
{
    public function test_generated_artifact_exists_and_locks_twenty_four_hour_review_boundary(): void
    {
        $path = base_path('docs/seo/generated/search-channel-live-zh-mbti-02-24h-review.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(3, $payload['queue_item_id']);
        $this->assertSame(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $payload['target_url']
        );
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertArrayHasKey('elapsed_window_passed', $payload);
        $this->assertArrayHasKey('queue_item_state', $payload);
        $this->assertArrayHasKey('gate_state', $payload);
        $this->assertArrayHasKey('live_response_state', $payload);
        $this->assertTrue($payload['no_indexing_claim']);
        $this->assertTrue($payload['no_ranking_claim']);
        $this->assertTrue($payload['no_live_submission_performed']);
        $this->assertTrue($payload['no_external_search_api_call']);
        $this->assertTrue($payload['no_search_channel_enqueue']);
        $this->assertTrue($payload['no_cms_mutation']);
        $this->assertTrue($payload['no_deploy']);
        $this->assertSame(1, $payload['event_counts']['live_submission_response'] ?? null);
        $this->assertSame('accepted', $payload['live_response_state']['submission_status'] ?? null);
        $this->assertFalse($payload['duplicate_check']['duplicate_active_queue_item_detected'] ?? true);
        $this->assertNotEmpty($payload['final_decision'] ?? null);
        $this->assertNotEmpty($payload['next_task'] ?? null);
    }
}
