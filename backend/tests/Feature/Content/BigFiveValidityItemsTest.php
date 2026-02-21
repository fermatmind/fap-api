<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveValidityItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_questions_include_validity_items_meta(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $response = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=zh-CN');
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $items = (array) $response->json('meta.validity_items');
        $this->assertNotEmpty($items);
        $this->assertSame('V1', (string) ($items[0]['item_id'] ?? ''));
        $this->assertNotSame('', (string) ($items[0]['text'] ?? ''));
    }

    public function test_attention_check_failed_adds_flag_and_downgrades_quality(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $anonId = 'anon_big5_validity';
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
                'code' => (string) (($i % 5) + 1),
            ];
        }

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'validity_items' => [
                ['item_id' => 'V1', 'code' => 1],
                ['item_id' => 'V2', 'code' => 1],
            ],
            'duration_ms' => 360000,
        ]);

        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);

        $level = (string) $submit->json('result.quality.level');
        $this->assertNotContains($level, ['A', 'B']);
        $flags = (array) $submit->json('result.quality.flags');
        $this->assertContains('ATTENTION_CHECK_FAILED', $flags);

        /** @var Attempt $attempt */
        $attempt = Attempt::query()->findOrFail($attemptId);
        $snapshot = is_array($attempt->calculation_snapshot_json) ? $attempt->calculation_snapshot_json : [];
        $validityItems = (array) ($snapshot['validity_items'] ?? []);
        $this->assertCount(2, $validityItems);
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
