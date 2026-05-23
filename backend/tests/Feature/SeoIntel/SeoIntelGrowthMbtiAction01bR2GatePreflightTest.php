<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bR2GatePreflightTest extends TestCase
{
    public function test_generated_artifact_exists_and_locks_gate_boundaries(): void
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-action-01b-r2-gate-preflight.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
            $payload['target_url']
        );
        $this->assertSame('indexnow', $payload['channel']);
        $this->assertSame('SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED', $payload['queue_write_gate_env']);
        $this->assertTrue($payload['no_gate_change_performed']);
        $this->assertTrue($payload['no_enqueue']);
        $this->assertTrue($payload['no_submission']);
        $this->assertTrue($payload['no_external_api_call']);
        $this->assertFalse($payload['live_submission_gate_current_state']);
        $this->assertFalse($payload['external_api_gate_current_state']);
        $this->assertFalse($payload['indexnow_live_api_gate_current_state']);
        $this->assertContains(
            'SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED',
            $payload['forbidden_gate_changes']
        );
        $this->assertContains(
            'SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED',
            $payload['forbidden_gate_changes']
        );
        $this->assertContains(
            'SEO_INTEL_INDEXNOW_LIVE_API_ENABLED',
            $payload['forbidden_gate_changes']
        );
        $this->assertNotEmpty($payload['exact_future_approval_phrase']);
        $this->assertArrayHasKey('next_task', $payload);
    }
}
