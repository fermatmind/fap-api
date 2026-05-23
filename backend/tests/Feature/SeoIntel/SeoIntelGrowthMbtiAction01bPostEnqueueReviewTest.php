<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bPostEnqueueReviewTest extends TestCase
{
    public function test_generated_artifact_exists_and_locks_post_enqueue_review_boundaries(): void
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-post-enqueue-review.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['queue_item_id']);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $payload['target_url']
        );
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertFalse($payload['live_submission_attempted']);
        $this->assertFalse($payload['external_api_call_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['enqueue_attempted']);
        $this->assertFalse($payload['queue_write_gate_state']);
        $this->assertNotEmpty($payload['final_decision']);
        $this->assertArrayHasKey('next_task', $payload);
    }
}
