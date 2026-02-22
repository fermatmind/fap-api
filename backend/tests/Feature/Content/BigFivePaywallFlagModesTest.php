<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Commerce\EntitlementManager;
use App\Services\Content\BigFivePackLoader;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFivePaywallFlagModesTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_only_mode_forces_free_variant_even_with_entitlement(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->seedBigFiveWithPaywallMode('free_only');

        $anonId = 'anon_big5_paywall_free_only';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedBigFiveAttemptWithResult($anonId);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'BIG5_FULL_REPORT',
            $attemptId,
            'order_big5_paywall_free_only',
            'attempt',
            null,
            ['big5_full', 'big5_action_plan']
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $resp = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $resp->assertStatus(200);
        $resp->assertJson([
            'locked' => true,
            'variant' => 'free',
        ]);

        $allowed = array_map('strval', (array) $resp->json('modules_allowed'));
        $this->assertContains('big5_core', $allowed);
        $this->assertNotContains('big5_full', $allowed);
        $this->assertNotContains('big5_action_plan', $allowed);

        $sections = array_map('strval', (array) array_column((array) $resp->json('report.sections'), 'key'));
        $this->assertSame(['disclaimer_top', 'summary', 'domains_overview', 'disclaimer'], $sections);
        $this->assertNotEmpty((array) $resp->json('offers'));
    }

    public function test_full_mode_allows_full_variant_after_entitlement(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->seedBigFiveWithPaywallMode('full');

        $anonId = 'anon_big5_paywall_full';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedBigFiveAttemptWithResult($anonId);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'BIG5_FULL_REPORT',
            $attemptId,
            'order_big5_paywall_full',
            'attempt',
            null,
            ['big5_full', 'big5_action_plan']
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $resp = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $resp->assertStatus(200);
        $resp->assertJson([
            'locked' => false,
            'variant' => 'full',
        ]);

        $allowed = array_map('strval', (array) $resp->json('modules_allowed'));
        $this->assertContains('big5_core', $allowed);
        $this->assertContains('big5_full', $allowed);
        $this->assertContains('big5_action_plan', $allowed);
    }

    private function seedBigFiveWithPaywallMode(string $mode): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $row = DB::table('scales_registry')->where('org_id', 0)->where('code', 'BIG5_OCEAN')->first();
        $caps = [];
        if ($row && is_string($row->capabilities_json)) {
            $decoded = json_decode($row->capabilities_json, true);
            $caps = is_array($decoded) ? $decoded : [];
        }
        $caps['paywall_mode'] = strtolower(trim($mode));

        DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'BIG5_OCEAN')
            ->update([
                'capabilities_json' => json_encode($caps, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
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

    private function createSubmittedBigFiveAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
        ]);

        $scorePayload = $this->buildScorePayload();

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => [
                'domains_mean' => $scorePayload['raw_scores']['domains_mean'] ?? [],
            ],
            'scores_pct' => $scorePayload['scores_0_100']['domains_percentile'] ?? [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => $scorePayload,
                'breakdown_json' => ['score_result' => $scorePayload],
                'axis_scores_json' => ['score_result' => $scorePayload],
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildScorePayload(): array
    {
        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $policyCompiled = $loader->readCompiledJson('policy.compiled.json', 'v1');

        $questionIndex = [];
        foreach ((array) ($questions['question_index'] ?? []) as $qid => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $questionIndex[(int) $qid] = $meta;
        }

        $answersById = [];
        for ($i = 1; $i <= 120; $i++) {
            $answersById[$i] = 3;
        }

        /** @var BigFiveScorerV3 $scorer */
        $scorer = app(BigFiveScorerV3::class);

        return $scorer->score(
            $answersById,
            $questionIndex,
            (array) $norms,
            (array) ($policyCompiled['policy'] ?? []),
            [
                'locale' => 'zh-CN',
                'country' => 'CN_MAINLAND',
                'region' => 'CN_MAINLAND',
                'gender' => 'ALL',
                'age_band' => 'all',
                'time_seconds_total' => 450,
                'duration_ms' => 450000,
            ]
        );
    }
}
