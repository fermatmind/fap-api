<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bR3OneShotEnqueueTest extends TestCase
{
    public function test_generated_artifact_exists_and_locks_one_shot_enqueue_boundaries(): void
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-r3-one-shot-enqueue.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['approval_phrase_verified']);
        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $payload['target_url']
        );
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertTrue($payload['queue_write_gate_opened']);
        $this->assertTrue($payload['queue_write_gate_closed']);
        $this->assertFalse($payload['bulk_enqueue_attempted']);
        $this->assertFalse($payload['live_submission_attempted']);
        $this->assertFalse($payload['external_api_call_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['url_truth_write_attempted']);
        $this->assertFalse($payload['sitemap_mutation_attempted']);
        $this->assertFalse($payload['llms_mutation_attempted']);
        $this->assertContains(
            'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
            $payload['deferred_urls']
        );
        $this->assertContains(
            'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            $payload['deferred_urls']
        );
        $this->assertContains(
            'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report',
            $payload['deferred_urls']
        );
        $this->assertNotEmpty($payload['final_decision']);
        $this->assertArrayHasKey('next_task', $payload);
    }
}
