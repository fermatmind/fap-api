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
}
