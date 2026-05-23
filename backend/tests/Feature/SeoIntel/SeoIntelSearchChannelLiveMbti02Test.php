<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelSearchChannelLiveMbti02Test extends TestCase
{
    public function test_generated_artifact_exists_and_locks_live_submission_result(): void
    {
        $path = base_path('docs/seo/generated/search-channel-live-mbti-02.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['queue_item_id']);
        $this->assertSame('https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types', $payload['url']);
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertTrue($payload['approval_phrase_verified']);
        $this->assertTrue($payload['no_bulk_submission']);
        $this->assertFalse($payload['queue_write_gate_state']);
        $this->assertTrue($payload['live_gates_closed']);
        $this->assertTrue($payload['only_queue_item_2_submitted']);
        $this->assertContains('https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types', $payload['deferred_urls']);
        $this->assertContains('https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report', $payload['deferred_urls']);
        $this->assertContains('https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report', $payload['deferred_urls']);
        $this->assertNotEmpty($payload['final_decision'] ?? null);
        $this->assertNotEmpty($payload['next_task'] ?? null);
    }
}
