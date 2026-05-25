<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelSearchChannelLiveZhMbti02Test extends TestCase
{
    public function test_generated_artifact_exists_and_locks_live_submission_result(): void
    {
        $path = base_path('docs/seo/generated/search-channel-live-zh-mbti-02.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['approval_phrase_verified']);
        $this->assertSame(3, $payload['queue_item_id']);
        $this->assertSame('https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types', $payload['target_url']);
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertTrue($payload['live_submission_performed']);
        $this->assertTrue($payload['external_api_call_performed']);
        $this->assertFalse($payload['enqueue_performed']);
        $this->assertTrue($payload['research_deferred']);
        $this->assertTrue($payload['gates_closed_after_submission']);
        $this->assertSame('accepted', $payload['submission_status']);
        $this->assertSame(200, $payload['http_status']);
        $this->assertSame('submitted', $payload['queue_item_3_post_state']['execution_state'] ?? null);
        $this->assertSame('submitted', $payload['queue_item_2_state']['execution_state'] ?? null);
        $this->assertSame(1, $payload['duplicate_check']['zh_mbti_queue_count'] ?? null);
        $this->assertSame(0, $payload['duplicate_check']['research_queue_count'] ?? null);
        $this->assertFalse($payload['cms_mutation_performed']);
        $this->assertFalse($payload['fap_web_mutation_performed']);
        $this->assertNotEmpty($payload['final_decision'] ?? null);
        $this->assertNotEmpty($payload['next_task'] ?? null);
    }
}
