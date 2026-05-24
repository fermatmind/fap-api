<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelSearchChannelLiveMbti02TwentyFourHourReviewTest extends TestCase
{
    public function test_generated_artifact_exists_and_locks_twenty_four_hour_review_boundary(): void
    {
        $path = base_path('docs/seo/generated/search-channel-live-mbti-02-24h-review.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['queue_item_id']);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $payload['target_url']
        );
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertFalse((bool) $payload['live_submission_attempted']);
        $this->assertFalse((bool) $payload['external_api_call_attempted']);
        $this->assertFalse((bool) $payload['enqueue_attempted']);
        $this->assertFalse((bool) $payload['cms_mutation_attempted']);
        $this->assertFalse((bool) $payload['url_truth_write_attempted']);
        $this->assertFalse((bool) $payload['indexing_claim_made']);
        $this->assertFalse((bool) $payload['ranking_claim_made']);

        $this->assertSame([
            'SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED' => false,
            'SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED' => false,
            'SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED' => false,
            'SEO_INTEL_INDEXNOW_LIVE_API_ENABLED' => false,
        ], $payload['gate_state']);

        $this->assertNotEmpty($payload['final_decision'] ?? null);
        $this->assertNotEmpty($payload['next_task'] ?? null);
    }
}
