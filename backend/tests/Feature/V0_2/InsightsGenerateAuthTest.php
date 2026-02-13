<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InsightsGenerateAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_requires_fm_token(): void
    {
        config()->set('fap.features.insights', true);
        config()->set('ai.enabled', true);
        config()->set('ai.insights_enabled', true);
        config()->set('ai.breaker_enabled', false);

        $response = $this->postJson('/api/v0.2/insights/generate', [
            'period_type' => 'week',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-07',
            'anon_id' => 'anon_insight_test',
        ]);

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error_code' => 'UNAUTHENTICATED',
        ]);
    }
}
