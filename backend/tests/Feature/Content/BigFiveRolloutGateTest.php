<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveRolloutGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_start_returns_not_enabled_when_scale_is_disabled(): void
    {
        $this->seedBigFiveWithRollout([
            'enabled_in_prod' => false,
            'enabled_regions' => ['CN_MAINLAND'],
            'rollout_ratio' => 1.0,
        ]);

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_big5_rollout_disabled',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'NOT_ENABLED');
    }

    public function test_big5_submit_returns_not_enabled_when_scale_is_disabled(): void
    {
        $anonId = 'anon_big5_submit_disabled';
        $this->seedBigFiveWithRollout([
            'enabled_in_prod' => false,
            'enabled_regions' => ['CN_MAINLAND'],
            'rollout_ratio' => 1.0,
        ]);

        $attemptId = (string) Str::uuid();
        Attempt::query()->create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'started_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'answers_summary_json' => ['stage' => 'start'],
        ]);

        $token = $this->issueAnonToken($anonId);
        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => '1', 'code' => '3'],
            ],
            'duration_ms' => 1000,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'NOT_ENABLED');
    }

    /**
     * @param array<string,mixed> $rollout
     */
    private function seedBigFiveWithRollout(array $rollout): void
    {
        (new ScaleRegistrySeeder())->run();

        DB::table('scales_registry')
            ->where('code', 'BIG5_OCEAN')
            ->update([
                'capabilities_json' => json_encode($rollout, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}

