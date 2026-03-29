<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportGatekeeper;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class ReportGatekeeperDecompositionContractTest extends TestCase
{
    use BuildsSds20ScorerInput;
    use RefreshDatabase;

    public function test_free_state_contract_is_preserved(): void
    {
        (new ScaleRegistrySeeder)->run();
        $this->configureSdsCommercialOffers();

        $anonId = 'anon_decomp_free';
        $attemptId = $this->createSdsAttemptWithResult($anonId, false);

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, $anonId, 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertTrue((bool) ($gate['locked'] ?? true));
        $this->assertSame('free', (string) ($gate['variant'] ?? ''));
        $this->assertContains(ReportAccess::MODULE_SDS_CORE, (array) ($gate['modules_allowed'] ?? []));
        $this->assertContains(ReportAccess::MODULE_SDS_FULL, (array) ($gate['modules_preview'] ?? []));
    }

    public function test_full_state_contract_is_preserved(): void
    {
        (new ScaleRegistrySeeder)->run();
        $this->configureSdsCommercialOffers();

        $anonId = 'anon_decomp_full';
        $attemptId = $this->createSdsAttemptWithResult($anonId, false);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'SDS_20_FULL',
            $attemptId,
            null,
            null,
            null,
            [
                ReportAccess::MODULE_SDS_CORE,
                ReportAccess::MODULE_SDS_FULL,
                ReportAccess::MODULE_SDS_FACTOR_DEEPDIVE,
                ReportAccess::MODULE_SDS_ACTION_PLAN,
            ]
        );

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, $anonId, 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertFalse((bool) ($gate['locked'] ?? true));
        $this->assertSame('full', (string) ($gate['variant'] ?? ''));
        $this->assertContains(ReportAccess::MODULE_SDS_FULL, (array) ($gate['modules_allowed'] ?? []));
        $this->assertNotSame([], (array) ($gate['offers'] ?? []));
    }

    public function test_crisis_state_contract_is_preserved(): void
    {
        (new ScaleRegistrySeeder)->run();
        $this->configureSdsCommercialOffers();

        $anonId = 'anon_decomp_crisis';
        $attemptId = $this->createSdsAttemptWithResult($anonId, true);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $entitlements->grantAttemptUnlock(0, null, $anonId, 'SDS_20_FULL', $attemptId, null);

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, $anonId, 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertTrue((bool) data_get($gate, 'quality.crisis_alert', false));
        $this->assertSame([], (array) ($gate['offers'] ?? ['unexpected']));
        $this->assertTrue((bool) ($gate['locked'] ?? false));
        $this->assertSame('free', (string) ($gate['variant'] ?? ''));
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

    private function createSdsAttemptWithResult(string $anonId, bool $crisis): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'SDS_20',
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

        $score = $this->scoreSds($crisis ? [19 => 'C'] : [], [
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
}
