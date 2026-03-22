<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Commerce\EntitlementManager;
use App\Services\Content\BigFivePackLoader;
use App\Services\Report\ReportAccess;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class NonMbtiReportContractRegressionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSds20ScorerInput;

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

    /**
     * @return array<string,mixed>
     */
    private function buildBigFiveScorePayload(): array
    {
        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $policyCompiled = $loader->readCompiledJson('policy.compiled.json', 'v1');

        $this->assertIsArray($questions);
        $this->assertIsArray($norms);
        $this->assertIsArray($policyCompiled);

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

    private function createBigFiveAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL',
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

        $scorePayload = $this->buildBigFiveScorePayload();

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL',
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

    private function configureSdsCommercialOffers(): void
    {
        DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'SDS_20')
            ->update([
                'commercial_json' => json_encode([
                    'report_benefit_code' => 'SDS_20_FULL',
                    'credit_benefit_code' => 'SDS_20_FULL',
                    'report_unlock_sku' => 'SKU_SDS_20_FULL_299',
                    'offers' => [[
                        'sku' => 'SKU_SDS_20_FULL_299',
                        'sku_code' => 'SKU_SDS_20_FULL_299',
                        'price_cents' => 29900,
                        'currency' => 'CNY',
                        'title' => 'SDS Full Report',
                        'modules_included' => [
                            ReportAccess::MODULE_SDS_FULL,
                            ReportAccess::MODULE_SDS_FACTOR_DEEPDIVE,
                            ReportAccess::MODULE_SDS_ACTION_PLAN,
                        ],
                    ]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function createSdsAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'SDS_20',
            'scale_code_v2' => 'DEPRESSION_SCREENING_STANDARD',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 20,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);

        $score = $this->scoreSds([], [
            'duration_ms' => 98000,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'locale' => 'zh-CN',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'SDS_20',
            'scale_code_v2' => 'DEPRESSION_SCREENING_STANDARD',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'SDS_20',
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_big5_report_contract_does_not_gain_mbti_only_fields_in_free_or_full_variants(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $anonId = 'anon_non_mbti_big5';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createBigFiveAttemptWithResult($anonId);

        $locked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/attempts/' . $attemptId . '/report');

        $locked->assertStatus(200);
        $locked->assertJson([
            'ok' => true,
            'locked' => true,
            'access_level' => 'free',
            'variant' => 'free',
        ]);
        $this->assertSharedNonMbtiEnvelope($locked);
        $this->assertMbtiOnlyFieldsAreMissing($locked);
        $this->assertSame(
            [
                'traits.overview',
                'traits.why_this_profile',
                'relationships.interpersonal_style',
                'career.work_style',
                'growth.next_actions',
                'disclaimer_top',
                'summary',
                'domains_overview',
                'disclaimer',
            ],
            array_map('strval', (array) array_column((array) $locked->json('report.sections'), 'key'))
        );

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'BIG5_FULL_REPORT',
            $attemptId,
            'order_big5_regression_1',
            'attempt',
            null,
            ['big5_full', 'big5_action_plan']
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $unlocked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/attempts/' . $attemptId . '/report');

        $unlocked->assertStatus(200);
        $unlocked->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);
        $this->assertSharedNonMbtiEnvelope($unlocked);
        $this->assertMbtiOnlyFieldsAreMissing($unlocked);
        $this->assertSame(
            [
                'traits.overview',
                'traits.why_this_profile',
                'relationships.interpersonal_style',
                'career.work_style',
                'growth.next_actions',
                'disclaimer_top',
                'summary',
                'domains_overview',
                'facet_table',
                'top_facets',
                'facets_deepdive',
                'action_plan',
                'disclaimer',
            ],
            array_map('strval', (array) array_column((array) $unlocked->json('report.sections'), 'key'))
        );
    }

    public function test_sds20_report_contract_does_not_gain_mbti_only_fields_in_free_or_full_variants(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();
        $this->configureSdsCommercialOffers();

        $anonId = 'anon_non_mbti_sds';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSdsAttemptWithResult($anonId);

        $locked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/attempts/' . $attemptId . '/report');

        $locked->assertStatus(200);
        $locked->assertJson([
            'ok' => true,
            'locked' => true,
            'access_level' => 'free',
            'variant' => 'free',
        ]);
        $this->assertSharedNonMbtiEnvelope($locked);
        $this->assertMbtiOnlyFieldsAreMissing($locked);
        $this->assertContains(ReportAccess::MODULE_SDS_CORE, (array) $locked->json('modules_allowed'));
        $this->assertNotEmpty((array) $locked->json('report.sections'));

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'SDS_20_FULL',
            $attemptId,
            null,
            'attempt',
            null,
            [
                ReportAccess::MODULE_SDS_CORE,
                ReportAccess::MODULE_SDS_FULL,
                ReportAccess::MODULE_SDS_FACTOR_DEEPDIVE,
                ReportAccess::MODULE_SDS_ACTION_PLAN,
            ]
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $unlocked = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/attempts/' . $attemptId . '/report');

        $unlocked->assertStatus(200);
        $unlocked->assertJson([
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
        ]);
        $this->assertSharedNonMbtiEnvelope($unlocked);
        $this->assertMbtiOnlyFieldsAreMissing($unlocked);
        $this->assertContains(ReportAccess::MODULE_SDS_FULL, (array) $unlocked->json('modules_allowed'));
        $this->assertNotEmpty((array) $unlocked->json('report.sections'));
    }

    private function assertSharedNonMbtiEnvelope(TestResponse $response): void
    {
        /** @var array<string,mixed> $payload */
        $payload = $response->json();

        foreach ([
            'locked',
            'access_level',
            'variant',
            'offers',
            'modules_allowed',
            'view_policy',
            'meta',
            'report',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertIsBool($payload['locked']);
        $this->assertIsString($payload['access_level']);
        $this->assertIsString($payload['variant']);
        $this->assertIsArray($payload['offers']);
        $this->assertIsArray($payload['modules_allowed']);
        $this->assertIsArray($payload['view_policy']);
        $this->assertIsArray($payload['meta']);
        $this->assertIsArray($payload['report']);
    }

    private function assertMbtiOnlyFieldsAreMissing(TestResponse $response): void
    {
        $response->assertJsonMissingPath('cta');
        $response->assertJsonMissingPath('report.recommended_reads');
        $response->assertJsonMissingPath('report.layers.identity');

        foreach ([
            'report.profile',
            'report.identity_card',
            'report.highlights',
            'report.tags',
            'report.scores_pct',
            'report.axis_states',
            'report.warnings',
            'report.borderline_note',
            'report.versions',
        ] as $path) {
            $response->assertJsonMissingPath($path);
        }
    }
}
