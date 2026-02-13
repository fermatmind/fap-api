<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InsightsAnonHeaderAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_insight_requires_matching_anon_header(): void
    {
        config()->set('fap.features.insights', true);

        $insightId = (string) Str::uuid();
        DB::table('ai_insights')->insert([
            'id' => $insightId,
            'user_id' => null,
            'anon_id' => 'anon_insight_owner',
            'period_type' => 'week',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-07',
            'input_hash' => hash('sha256', 'insight:' . $insightId),
            'prompt_version' => 'v1.0.0',
            'model' => 'mock-model',
            'provider' => 'mock',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0,
            'status' => 'succeeded',
            'output_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'evidence_json' => null,
            'error_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v0.2/insights/{$insightId}")
            ->assertStatus(404)
            ->assertJson([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
            ]);

        $this->withHeaders([
            'X-FAP-Anon-Id' => 'anon_wrong',
        ])->getJson("/api/v0.2/insights/{$insightId}")
            ->assertStatus(404)
            ->assertJson([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
            ]);

        $this->withHeaders([
            'X-FAP-Anon-Id' => 'anon_insight_owner',
        ])->getJson("/api/v0.2/insights/{$insightId}")
            ->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'id' => $insightId,
            ]);
    }
}
