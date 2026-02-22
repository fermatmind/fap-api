<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveDriverMinCompiledPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_submit_uses_min_compiled_question_index_when_full_compiled_file_is_missing(): void
    {
        (new ScaleRegistrySeeder())->run();
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $anonId = 'anon_big5_driver_min';
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

        $compiledFullPath = base_path('content_packs/BIG5_OCEAN/v1/compiled/questions.compiled.json');
        $compiledFullBackup = $compiledFullPath.'.bak_driver_min';
        $this->assertFileExists($compiledFullPath);
        $this->assertFileExists(base_path('content_packs/BIG5_OCEAN/v1/compiled/questions.min.compiled.json'));

        File::move($compiledFullPath, $compiledFullBackup);

        try {
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
            $submit->assertJsonPath('attempt_id', $attemptId);
            $submit->assertJsonPath('result.norms.status', 'CALIBRATED');
        } finally {
            if (File::exists($compiledFullBackup)) {
                File::move($compiledFullBackup, $compiledFullPath);
            }
        }
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

