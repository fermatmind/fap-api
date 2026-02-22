<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5RetakeCooldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_retake_cooldown_blocks_recent_restart(): void
    {
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_big5_cooldown';
        $this->createAttempt($anonId, now()->subHours(1));

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('error_code', 'RETAKE_COOLDOWN');
    }

    public function test_big5_retake_limit_blocks_when_max_attempts_reached_in_30_days(): void
    {
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_big5_retake_limit';
        $this->createAttempt($anonId, now()->subDays(2));
        $this->createAttempt($anonId, now()->subDays(10));
        $this->createAttempt($anonId, now()->subDays(20));

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('error_code', 'RETAKE_LIMIT_EXCEEDED');
    }

    private function createAttempt(string $anonId, \DateTimeInterface $startedAt): void
    {
        Attempt::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'started_at' => $startedAt,
            'submitted_at' => $startedAt,
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'answers_summary_json' => ['stage' => 'seed'],
        ]);
    }
}

