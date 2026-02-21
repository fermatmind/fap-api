<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

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

final class BigFiveModulesUnlockFlowTest extends TestCase
{
    use RefreshDatabase;

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
    private function buildScorePayload(): array
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
            if (!is_array($meta)) {
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
            (array) (($policyCompiled['policy'] ?? [])),
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

    public function test_big5_modules_unlock_flow(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $anonId = 'anon_big5_unlock';
        $token = $this->issueAnonToken($anonId);

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

        $before = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/attempts/' . $attemptId . '/report');

        $before->assertStatus(200);
        $before->assertJson([
            'locked' => true,
            'variant' => 'free',
        ]);
        $before->assertJsonPath('norms.status', (string) ($scorePayload['norms']['status'] ?? ''));
        $before->assertJsonPath('quality.level', (string) ($scorePayload['quality']['level'] ?? ''));

        $beforeAllowed = (array) $before->json('modules_allowed');
        $this->assertContains('big5_core', $beforeAllowed);
        $beforeSections = array_map('strval', (array) array_column((array) $before->json('report.sections'), 'key'));
        $this->assertSame(['disclaimer_top', 'summary', 'domains_overview', 'disclaimer'], $beforeSections);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'BIG5_FULL_REPORT',
            $attemptId,
            'order_big5_1',
            'attempt',
            null,
            ['big5_full', 'big5_action_plan']
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $after = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/attempts/' . $attemptId . '/report');

        $after->assertStatus(200);
        $after->assertJson([
            'locked' => false,
            'variant' => 'full',
        ]);

        $afterAllowed = (array) $after->json('modules_allowed');
        $this->assertContains('big5_core', $afterAllowed);
        $this->assertContains('big5_full', $afterAllowed);
        $this->assertContains('big5_action_plan', $afterAllowed);

        $offers = (array) $after->json('offers');
        $this->assertNotEmpty($offers);
        foreach ($offers as $offer) {
            $this->assertIsArray($offer);
            $this->assertArrayHasKey('sku', $offer);
            $this->assertNotSame('', (string) ($offer['sku'] ?? ''));
            $this->assertArrayHasKey('sku_code', $offer);
            $this->assertSame((string) $offer['sku'], (string) $offer['sku_code']);
            $this->assertArrayHasKey('benefit_code', $offer);
            $this->assertNotSame('', (string) ($offer['benefit_code'] ?? ''));
            $this->assertArrayHasKey('offer_code', $offer);
            $this->assertNotSame('', (string) ($offer['offer_code'] ?? ''));
            $this->assertArrayHasKey('price_cents', $offer);
            $this->assertIsInt($offer['price_cents']);
            $this->assertArrayHasKey('currency', $offer);
            $this->assertNotSame('', (string) ($offer['currency'] ?? ''));
            $this->assertArrayHasKey('modules_included', $offer);
            $this->assertIsArray($offer['modules_included']);
        }

        $afterSections = array_map('strval', (array) array_column((array) $after->json('report.sections'), 'key'));
        $this->assertSame(['disclaimer_top', 'summary', 'domains_overview', 'top_facets', 'facets_deepdive', 'action_plan', 'disclaimer'], $afterSections);
    }
}
