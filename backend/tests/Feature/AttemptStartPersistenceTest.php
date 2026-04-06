<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AttemptStartPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr17SimpleScoreDemoSeeder)->run();
    }

    public function test_attempt_start_persists_attempt(): void
    {
        $this->seedScales();

        $anonId = 'test_anon';
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $attemptId = (string) $response->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $this->assertDatabaseHas('attempts', [
            'id' => $attemptId,
            'anon_id' => $anonId,
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'scale_version' => 'v0.3',
        ]);

        $attempt = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($attempt);
        $this->assertGreaterThan(0, (int) ($attempt->question_count ?? 0));
        $this->assertNotNull($attempt->started_at);
    }

    public function test_attempt_start_persists_mbti_entry_attribution_fields_into_meta(): void
    {
        $this->seedScales();

        $anonId = 'test_anon_mbti_attr';
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => $anonId,
            'form_code' => 'mbti_144',
            'entrypoint' => 'mbti_topic_detail',
            'entry_surface' => 'mbti_topic_detail',
            'source_page_type' => 'topic_detail',
            'target_action' => 'start_mbti_test_primary',
            'test_slug' => 'mbti-personality-test-16-personality-types',
            'landing_path' => '/zh/topics/mbti',
        ]);

        $response->assertStatus(200);
        $attemptId = (string) $response->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $attempt = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($attempt);

        $summary = is_array($attempt->answers_summary_json ?? null)
            ? $attempt->answers_summary_json
            : (json_decode((string) ($attempt->answers_summary_json ?? '{}'), true) ?: []);
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];

        $this->assertSame('mbti_topic_detail', $meta['entrypoint'] ?? null);
        $this->assertSame('mbti_topic_detail', $meta['entry_surface'] ?? null);
        $this->assertSame('topic_detail', $meta['source_page_type'] ?? null);
        $this->assertSame('start_mbti_test_primary', $meta['target_action'] ?? null);
        $this->assertSame('mbti-personality-test-16-personality-types', $meta['test_slug'] ?? null);
        $this->assertSame('/zh/topics/mbti', $meta['landing_path'] ?? null);
    }
}
