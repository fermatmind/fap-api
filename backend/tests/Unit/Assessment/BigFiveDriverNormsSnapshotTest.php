<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveDriverNormsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_submit_persists_norm_snapshot_into_attempt(): void
    {
        (new ScaleRegistrySeeder())->run();
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $anonId = 'anon_big5_snapshot';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $answers = [];
        for ($i = 1; $i <= 120; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => '3',
            ];
        }

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 360000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);

        /** @var Attempt $attempt */
        $attempt = Attempt::query()->findOrFail($attemptId);
        $this->assertNotSame('', (string) ($attempt->norm_version ?? ''));

        $snapshot = is_array($attempt->calculation_snapshot_json) ? $attempt->calculation_snapshot_json : [];
        $norms = is_array($snapshot['norms'] ?? null) ? $snapshot['norms'] : [];

        $this->assertSame('zh-CN_prod_all_18-60', (string) ($norms['group_id'] ?? ''));
        $this->assertSame('CALIBRATED', (string) ($norms['status'] ?? ''));
        $this->assertNotSame('', (string) ($norms['source_id'] ?? ''));
        $this->assertNotSame('', (string) ($norms['norms_version'] ?? ''));
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
