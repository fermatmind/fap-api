<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModulesGateReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();
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

    private function createAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_module_unlock_controls_paid_cards_and_section_lock_state(): void
    {
        $this->seedScales();

        $anonId = 'anon_module_gate';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createAttemptWithResult($anonId);

        $before = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $before->assertStatus(200);
        $before->assertJson([
            'variant' => 'free',
            'locked' => true,
        ]);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'MBTI_CAREER',
            $attemptId,
            null,
            'attempt',
            null,
            ['career']
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $after = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $after->assertStatus(200);
        $after->assertJson([
            'variant' => 'full',
            'access_level' => 'full',
        ]);

        $sections = $after->json('report.sections');
        $this->assertIsArray($sections);

        $this->assertFalse((bool) data_get($sections, 'career.locked'));
        $this->assertTrue((bool) data_get($sections, 'relationships.locked'));

        $careerCards = (array) data_get($sections, 'career.cards', []);
        $relationshipCards = (array) data_get($sections, 'relationships.cards', []);

        $this->assertContains('paid', array_map(fn (array $card): string => (string) ($card['access_level'] ?? ''), $careerCards));
        $this->assertNotContains('paid', array_map(fn (array $card): string => (string) ($card['access_level'] ?? ''), $relationshipCards));
    }
}
