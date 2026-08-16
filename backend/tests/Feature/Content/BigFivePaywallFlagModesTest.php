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

    public function test_free_only_mode_forces_full_readable_variant_and_removes_offers(): void
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
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);

        $allowed = array_map('strval', (array) $resp->json('modules_allowed'));
        $this->assertContains('big5_core', $allowed);
        $this->assertContains('big5_full', $allowed);
        $this->assertContains('big5_action_plan', $allowed);

        $sections = array_map('strval', (array) array_column((array) $resp->json('report.sections'), 'section_key'));
        $this->assertSame(
            ['hero_summary', 'domains_overview', 'domain_deep_dive', 'facet_details', 'core_portrait', 'norms_comparison', 'action_plan', 'methodology_and_access'],
            $sections
        );
        $this->assertSame([], (array) $resp->json('offers'));
        $this->assertNull($resp->json('upgrade_sku'));
        $this->assertNull($resp->json('upgrade_sku_effective'));
    }

    public function test_registry_paywall_drift_cannot_reenable_big5_paid_access(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->seedBigFiveWithPaywallMode('full');

        $anonId = 'anon_big5_paywall_full';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedBigFiveAttemptWithResult($anonId);
        $headers = [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $resp = $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$attemptId.'/report');

        $resp->assertStatus(200);
        $resp->assertJson([
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);

        $allowed = array_map('strval', (array) $resp->json('modules_allowed'));
        $this->assertContains('big5_core', $allowed);
        $this->assertContains('big5_full', $allowed);
        $this->assertContains('big5_action_plan', $allowed);
        $this->assertSame([], (array) $resp->json('offers'));
        $this->assertNull($resp->json('upgrade_sku'));
        $this->assertNull($resp->json('upgrade_sku_effective'));

        $access = $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access');
        $access->assertStatus(200);
        $access->assertJsonPath('access_state', 'ready');
        $access->assertJsonPath('report_state', 'ready');
        $access->assertJsonPath('unlock_stage', 'full');
        $access->assertJsonPath('payload.access_level', 'full');
        $access->assertJsonPath('payload.variant', 'full');

        $history = $this->withHeaders($headers)->getJson('/api/v0.3/me/attempts?scale=BIG5_OCEAN');
        $history->assertStatus(200);
        $history->assertJsonPath('items.0.access_summary.access_state', 'ready');
        $history->assertJsonPath('items.0.access_summary.access_level', 'full');
        $history->assertJsonPath('items.0.access_summary.variant', 'full');

        $pdf = $this->withHeaders($headers)->get('/api/v0.3/attempts/'.$attemptId.'/report.pdf?inline=1');
        $pdf->assertStatus(200);
        $pdf->assertHeader('X-Report-Variant', 'full');
        $pdf->assertHeader('X-Report-Locked', 'false');
    }

    private function seedBigFiveWithPaywallMode(string $mode): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

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

    private function enableBigFiveCommerceForAttempt(string $attemptId): void
    {
        config()->set('report_unlock.rollout_scales', ['MBTI', 'BIG5_OCEAN']);
        config()->set('report_unlock.big5_rollout', [
            'mode' => 'allowlist_only',
            'percentage' => 0,
            'max_percentage' => 10,
            'emergency_disabled' => false,
            'allowed_attempt_ids' => [$attemptId],
            'allowed_user_ids' => [],
            'allowed_anon_ids' => [],
            'allowed_org_ids' => [],
            'allowed_tenant_ids' => ['0'],
            'allowed_form_codes' => ['big5_90', 'big5_120'],
            'allowed_locales' => ['zh-CN'],
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
