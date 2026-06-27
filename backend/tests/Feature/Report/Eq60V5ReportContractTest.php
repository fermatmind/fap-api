<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60ContentCompileService;
use App\Services\Content\Eq60PackLoader;
use App\Services\Report\Eq60ReportComposer;
use App\Services\Report\ReportAccess;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60V5ReportContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string,array{case_id:string,locale:string,file:string,formulation:string,action:string}>
     */
    public static function fixtureCases(): array
    {
        return [
            'balanced_zh' => [
                'EQ60_BALANCED_HIGH_ZH',
                'zh-CN',
                'eq60_v5_balanced_integrated_zh.json',
                'balanced_integrated',
                'relationship_energy_management',
            ],
            'high_empathy_zh' => [
                'EQ60_COMPASSION_OVERLOAD_ZH',
                'zh-CN',
                'eq60_v5_high_empathy_low_recovery_zh.json',
                'high_empathy_low_recovery',
                'empathy_boundary',
            ],
            'low_confidence_zh' => [
                'EQ60_SPEEDING_C_ZH',
                'zh-CN',
                'eq60_v5_low_confidence_zh.json',
                'low_confidence_result',
                'retest_reflection',
            ],
            'balanced_en' => [
                'EQ60_BALANCED_HIGH_ZH',
                'en',
                'eq60_v5_balanced_integrated_en.json',
                'balanced_integrated',
                'relationship_energy_management',
            ],
            'high_empathy_en' => [
                'EQ60_COMPASSION_OVERLOAD_ZH',
                'en',
                'eq60_v5_high_empathy_low_recovery_en.json',
                'high_empathy_low_recovery',
                'empathy_boundary',
            ],
            'low_confidence_en' => [
                'EQ60_SPEEDING_C_ZH',
                'en',
                'eq60_v5_low_confidence_en.json',
                'low_confidence_result',
                'retest_reflection',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixtureCases')]
    public function test_canonical_v5_fixture_matches_composer_output(
        string $caseId,
        string $locale,
        string $file,
        string $formulation,
        string $action
    ): void {
        $this->prepareEqContent();

        $fixture = $this->canonicalFixture($caseId, $locale);
        $this->maybeUpdateFixture($file, $fixture);

        $this->assertSame($this->withoutResolvedAssetPayload($this->loadFixture($file)), $this->withoutResolvedAssetPayload($fixture));
        $this->assertCommonV5Contract($fixture);
        $this->assertSame($formulation, (string) data_get($fixture, 'report.interpretation.core_formulation_id'));
        $this->assertStringStartsWith('route.eq.', (string) data_get($fixture, 'report.interpretation.route_id'));
        $this->assertSame($formulation, (string) data_get($fixture, 'report.assets.core_formulation.id'));
        $this->assertSame('eq.snapshot.'.$formulation, (string) data_get($fixture, 'report.assets.result_snapshot.id'));
        $this->assertSame($action, (string) data_get($fixture, 'report.interpretation.action_prescription_id'));
        $this->assertSame($action, (string) data_get($fixture, 'report.assets.action_prescription.id'));
        $this->assertSame(
            (string) data_get($fixture, 'report.interpretation.route_id'),
            (string) data_get($fixture, 'report.asset_refs.personalization_route_id')
        );
        $this->assertSame(
            (string) data_get($fixture, 'report.interpretation.route_id'),
            (string) data_get($fixture, 'report.assets.personalization_route.id')
        );
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.personalization_route.route_headline'));
    }

    public function test_high_empathy_low_recovery_contract_has_resolved_v5_assets(): void
    {
        $this->prepareEqContent();

        $fixture = $this->canonicalFixture('EQ60_COMPASSION_OVERLOAD_ZH', 'zh-CN');

        $this->assertSame('high_empathy_low_recovery', (string) data_get($fixture, 'report.interpretation.core_formulation_id'));
        $this->assertStringStartsWith('route.eq.', (string) data_get($fixture, 'report.interpretation.route_id'));
        $this->assertSame('empathy_boundary', (string) data_get($fixture, 'report.interpretation.action_prescription_id'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.interpretation.signal_signature.match_pattern'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.interpretation.primary_mechanism_ids'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.interpretation.primary_scene_ids'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.interpretation.primary_scene_variant_ids'));
        $this->assertStringStartsWith('eq.scene.', (string) data_get($fixture, 'report.interpretation.primary_scene_variant_ids.0'));
        $this->assertSame(
            (string) data_get($fixture, 'report.interpretation.primary_scene_variant_ids.0'),
            (string) data_get($fixture, 'report.asset_refs.scene_variant_ids.0')
        );
        $this->assertNotEmpty((array) data_get($fixture, 'report.interpretation.career_environment_ids'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.mechanisms'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.reality_scenes'));
        $this->assertSame(
            (string) data_get($fixture, 'report.interpretation.primary_scene_variant_ids.0'),
            (string) data_get($fixture, 'report.assets.reality_scenes.0.id')
        );
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.reality_scenes.0.scene_family'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.reality_scenes.0.micro_script'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.reality_scenes.0.evidence_signals'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.career_environment'));
        $this->assertSame('eq.snapshot.high_empathy_low_recovery', (string) data_get($fixture, 'report.asset_refs.result_snapshot_id'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.result_snapshot.core_judgment'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.result_snapshot.conversion_actions'));
        $this->assertCount(7, (array) data_get($fixture, 'report.assets.commercial_conversion_actions'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.quality_confidence.why_this_level'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.psychometric_evidence_status'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.agent_dialogue_playbooks'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.backend_integration_contract'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.career_environment.0.interview_question'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.career_environment.0.role_observation_checklist'));
    }

    public function test_low_confidence_contract_routes_to_cautious_formulation(): void
    {
        $this->prepareEqContent();

        $fixture = $this->canonicalFixture('EQ60_SPEEDING_C_ZH', 'zh-CN');

        $this->assertSame('low_confidence_result', (string) data_get($fixture, 'report.interpretation.core_formulation_id'));
        $this->assertStringStartsWith('route.eq.', (string) data_get($fixture, 'report.interpretation.route_id'));
        $this->assertSame('retest_reflection', (string) data_get($fixture, 'report.interpretation.action_prescription_id'));
        $this->assertSame('quality_low_overrides_dimension_pattern', (string) data_get($fixture, 'report.interpretation.signal_signature.match_pattern'));
        $this->assertSame('low', (string) data_get($fixture, 'report.quality.confidence_label'));
        $this->assertSame([], (array) data_get($fixture, 'report.interpretation.primary_mechanism_ids'));
        $this->assertNotSame('high_empathy_low_recovery', (string) data_get($fixture, 'report.assets.core_formulation.id'));
        $this->assertNotSame('balanced_integrated', (string) data_get($fixture, 'report.assets.core_formulation.id'));
        $this->assertSame('eq.snapshot.low_confidence_result', (string) data_get($fixture, 'report.assets.result_snapshot.id'));
        $this->assertSame('eq.quality.level.C', (string) data_get($fixture, 'report.assets.quality_confidence.id'));
    }

    public function test_aware_but_unregulated_route_matrix_selects_deterministic_asset_ids(): void
    {
        $this->prepareEqContent();

        $score = [
            'quality' => ['level' => 'A', 'flags' => []],
            'norms' => ['status' => 'PROVISIONAL'],
            'version_snapshot' => ['engine_version' => 'v1.0_normed_validity'],
            'scores' => [
                'global' => ['raw_sum' => 186, 'std_score' => 103, 'percentile' => 60, 'level' => 'competent'],
                'SA' => ['raw_sum' => 62, 'std_score' => 118, 'percentile' => 82, 'level' => 'exceptional'],
                'ER' => ['raw_sum' => 38, 'std_score' => 82, 'percentile' => 16, 'level' => 'baseline'],
                'EM' => ['raw_sum' => 45, 'std_score' => 98, 'percentile' => 50, 'level' => 'competent'],
                'RM' => ['raw_sum' => 41, 'std_score' => 94, 'percentile' => 42, 'level' => 'competent'],
            ],
            'report' => [],
            'report_tags' => [],
        ];

        $attempt = new Attempt([
            'scale_code' => 'EQ_60',
            'locale' => 'en',
            'dir_version' => 'v1',
        ]);
        $result = new Result([
            'result_json' => [
                'scale_code' => 'EQ_60',
                'quality' => $score['quality'],
                'norms' => $score['norms'],
                'scores' => $score['scores'],
                'report' => [],
                'report_tags' => [],
                'version_snapshot' => $score['version_snapshot'],
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
        ]);

        /** @var Eq60ReportComposer $composer */
        $composer = app(Eq60ReportComposer::class);
        $composed = $composer->composeVariant($attempt, $result, ReportAccess::VARIANT_FULL, [
            'modules_allowed' => ReportAccess::eq60AllRuntimeModules(),
        ]);

        $this->assertTrue((bool) ($composed['ok'] ?? false));
        $report = (array) ($composed['report'] ?? []);
        $this->assertSame('aware_but_unregulated', (string) data_get($report, 'interpretation.core_formulation_id'));
        $this->assertStringStartsWith('route.eq.', (string) data_get($report, 'interpretation.route_id'));
        $this->assertNotSame('', (string) data_get($report, 'interpretation.signal_signature.match_pattern'));
        $this->assertSame('SA_ER_high_low', (string) data_get($report, 'interpretation.primary_mechanism_ids.0'));
        $this->assertSame('feedback', (string) data_get($report, 'interpretation.primary_scene_ids.0'));
        $this->assertSame('eq.scene.feedback.aware_but_unregulated.primary', (string) data_get($report, 'interpretation.primary_scene_variant_ids.0'));
        $this->assertSame('eq.scene.feedback.aware_but_unregulated.primary', (string) data_get($report, 'assets.reality_scenes.0.id'));
        $this->assertSame('pause_recovery', (string) data_get($report, 'interpretation.action_prescription_id'));
        $this->assertSame('pause_recovery', (string) data_get($report, 'interpretation.selected_asset_ids.action_prescription_id'));
        $this->assertSame((string) data_get($report, 'interpretation.route_id'), (string) data_get($report, 'asset_refs.personalization_route_id'));
        $this->assertSame((string) data_get($report, 'interpretation.route_id'), (string) data_get($report, 'assets.personalization_route.id'));
        $this->assertNotSame('', (string) data_get($report, 'assets.personalization_route.route_headline'));
    }

    public function test_eq_v5_payload_has_no_user_visible_paywall_runtime_contract(): void
    {
        $this->prepareEqContent();

        $fixture = $this->canonicalFixture('EQ60_COMPASSION_OVERLOAD_ZH', 'zh-CN');
        $json = json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        $this->assertFalse((bool) data_get($fixture, 'report_access.payload.locked', true));
        $this->assertNull(data_get($fixture, 'report_access.payload.upgrade_sku'));
        $this->assertNull(data_get($fixture, 'report_access.payload.upgrade_sku_effective'));
        $this->assertSame([], (array) data_get($fixture, 'report_access.payload.offers'));
        $this->assertFalse((bool) data_get($fixture, 'report_access.payload.view_policy.blur_others', true));
        $this->assertFalse((bool) data_get($fixture, 'report.access.locked', true));
        $this->assertFalse((bool) data_get($fixture, 'report.access.blur', true));
        $this->assertFalse((bool) data_get($fixture, 'report.access.paywall', true));
        $this->assertStringNotContainsString('SKU_EQ_60_FULL_299', $json);
        $this->assertStringNotContainsString('EQ_60_FULL', $json);
        $this->assertStringNotContainsString('"locked":true', $json);
        $this->assertStringNotContainsString('"blur_others":true', $json);
        $this->assertStringNotContainsString('"paywall":true', $json);

        $visibleReportText = $this->visibleText((array) data_get($fixture, 'report'));
        foreach ([
            '付费',
            '购买',
            '解锁',
            'Premium',
            'premium',
            'Unlock',
            'unlock',
            'SKU_EQ_60_FULL_299',
            'EQ_60_FULL',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $visibleReportText);
        }
    }

    private function prepareEqContent(): void
    {
        /** @var Eq60ContentCompileService $compiler */
        $compiler = app(Eq60ContentCompileService::class);
        $compiled = $compiler->compile('v1');
        $this->assertTrue(
            (bool) ($compiled['ok'] ?? false),
            json_encode($compiled['errors'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );
        (new ScaleRegistrySeeder)->run();
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalFixture(string $caseId, string $locale): array
    {
        $case = $this->goldenCase($caseId);
        $anonId = 'anon_eq_v5_'.Str::slug($caseId, '_').'_'.Str::slug($locale, '_');
        $attemptId = $this->createAttemptWithResult($case, $locale, $anonId);
        $token = $this->issueAnonToken($anonId);

        $access = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access')
            ->assertOk()
            ->json();

        /** @var Eq60ReportComposer $composer */
        $composer = app(Eq60ReportComposer::class);
        $attempt = Attempt::query()->findOrFail($attemptId);
        $result = Result::query()->where('attempt_id', $attemptId)->firstOrFail();
        $composed = $composer->composeVariant(
            $attempt,
            $result,
            ReportAccess::VARIANT_FULL,
            ['modules_allowed' => ReportAccess::eq60AllRuntimeModules()]
        );

        $this->assertTrue((bool) ($composed['ok'] ?? false));

        return $this->canonicalize([
            'fixture_schema' => 'eq60.v5.report_contract_fixture.v1',
            'case_id' => $caseId,
            'locale' => $locale,
            'report_access' => $this->stableReportAccess($access),
            'report' => $this->stableReport((array) ($composed['report'] ?? [])),
        ]);
    }

    /**
     * @param  array<string,mixed>  $fixture
     */
    private function assertCommonV5Contract(array $fixture): void
    {
        $this->assertSame('ready', (string) data_get($fixture, 'report_access.access_state'));
        $this->assertSame('ready', (string) data_get($fixture, 'report_access.report_state'));
        $this->assertSame(ReportAccess::VARIANT_FULL, (string) data_get($fixture, 'report_access.payload.variant'));
        $this->assertSame(ReportAccess::REPORT_ACCESS_FULL, (string) data_get($fixture, 'report_access.payload.access_level'));
        $this->assertFalse((bool) data_get($fixture, 'report_access.payload.locked', true));
        $this->assertNull(data_get($fixture, 'report_access.payload.upgrade_sku'));
        $this->assertSame([], (array) data_get($fixture, 'report_access.payload.offers'));
        $this->assertSame(ReportAccess::eq60FreeSectionKeys(), (array) data_get($fixture, 'report_access.payload.view_policy.free_sections'));
        $this->assertFalse((bool) data_get($fixture, 'report_access.payload.view_policy.blur_others', true));

        $this->assertSame('self_report', (string) data_get($fixture, 'report.eq_report_mode'));
        $this->assertSame('self_report_trait_mixed_ei', (string) data_get($fixture, 'report.measurement_type'));
        $this->assertTrue((bool) data_get($fixture, 'report.access.all_results_free'));
        $this->assertFalse((bool) data_get($fixture, 'report.access.locked', true));
        $this->assertFalse((bool) data_get($fixture, 'report.access.blur', true));
        $this->assertFalse((bool) data_get($fixture, 'report.access.paywall', true));
        $this->assertIsArray(data_get($fixture, 'report.scores.global'));
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $this->assertIsArray(data_get($fixture, 'report.scores.dimensions.'.$code));
        }
        $this->assertCount(4, (array) data_get($fixture, 'report.dimension_summary', []));
        $this->assertNotSame('', (string) data_get($fixture, 'report.quality.confidence_label'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.interpretation.core_formulation_id'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.interpretation.route_id'));
        $this->assertIsArray(data_get($fixture, 'report.interpretation.signal_signature'));
        $this->assertIsArray(data_get($fixture, 'report.interpretation.selected_asset_ids'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.asset_refs'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets'));
        $this->assertFalse((bool) data_get($fixture, 'report.next_module.available', true));
        $this->assertSame('planned', (string) data_get($fixture, 'report.next_module.status'));
        $this->assertSame('eq_report_v5_assets_commercial_ready_v1_9', (string) data_get($fixture, 'report.methodology.report_version'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.asset_refs.result_snapshot_id'));
        $this->assertCount(7, (array) data_get($fixture, 'report.asset_refs.commercial_conversion_ids'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.asset_refs.quality_confidence_id'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.asset_refs.psychometric_evidence_ids'));
        $this->assertSame([
            'eq.depth.how_to_read.default',
            'eq.depth.evidence_stack.default',
            'eq.depth.reality_check.default',
        ], (array) data_get($fixture, 'report.asset_refs.result_page_depth_module_ids'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.result_snapshot'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.commercial_conversion_actions'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.quality_confidence'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.psychometric_evidence_status'));
        $this->assertCount(3, (array) data_get($fixture, 'report.assets.result_page_depth_modules'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.result_page_depth_modules.0.title'));
        $this->assertNotSame('', (string) data_get($fixture, 'report.assets.result_page_depth_modules.0.body'));
        $this->assertNotEmpty((array) data_get($fixture, 'report.assets.score_system.band_details'));
    }

    /**
     * @return array<string,mixed>
     */
    private function stableReportAccess(array $access): array
    {
        return [
            'access_state' => (string) data_get($access, 'access_state'),
            'report_state' => (string) data_get($access, 'report_state'),
            'payload' => [
                'access_level' => data_get($access, 'payload.access_level'),
                'access' => data_get($access, 'payload.access'),
                'locked' => data_get($access, 'payload.locked', false),
                'modules_allowed' => data_get($access, 'payload.modules_allowed', []),
                'modules_preview' => data_get($access, 'payload.modules_preview', []),
                'offers' => data_get($access, 'payload.offers', []),
                'unlock_source' => data_get($access, 'payload.unlock_source'),
                'unlock_stage' => data_get($access, 'payload.unlock_stage'),
                'upgrade_sku' => data_get($access, 'payload.upgrade_sku'),
                'upgrade_sku_effective' => data_get($access, 'payload.upgrade_sku_effective'),
                'variant' => data_get($access, 'payload.variant'),
                'view_policy' => data_get($access, 'payload.view_policy'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function stableReport(array $report): array
    {
        $report['generated_at'] = '2026-05-21T00:00:00.000000Z';

        return $report;
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function withoutResolvedAssetPayload(array $fixture): array
    {
        unset($fixture['report']['asset_refs']);
        unset($fixture['report']['assets']);
        unset($fixture['report']['interpretation']);

        return $fixture;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function visibleText(array $payload): string
    {
        $parts = [];
        $walk = static function (mixed $value) use (&$walk, &$parts): void {
            if (is_string($value)) {
                $parts[] = $value;

                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };

        foreach (['sections', 'assets'] as $key) {
            $walk($payload[$key] ?? []);
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function goldenCase(string $caseId): array
    {
        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        foreach ($loader->loadGoldenCases('v1') as $case) {
            if ((string) ($case['case_id'] ?? '') === $caseId) {
                return $case;
            }
        }

        $this->fail('Missing EQ_60 golden case: '.$caseId);
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function createAttemptWithResult(array $case, string $locale, string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
            'question_count' => 60,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'eq_v5_contract_fixture'],
            'started_at' => now()->subSeconds((int) ($case['time_seconds_total'] ?? 420)),
            'submitted_at' => now(),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
        ]);

        $score = $this->scoreCase($case, $locale);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'EQ_60',
                'quality' => $score['quality'] ?? [],
                'norms' => $score['norms'] ?? [],
                'scores' => $score['scores'] ?? [],
                'report' => $score['report'] ?? [],
                'report_tags' => $score['report_tags'] ?? [],
                'version_snapshot' => $score['version_snapshot'] ?? [],
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attempt->id;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function scoreCase(array $case, string $locale): array
    {
        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        return $scorer->score(
            $this->resolveAnswersMap($case),
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            [
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'content_manifest_hash' => $loader->resolveManifestHash('v1'),
                'score_map' => data_get($loader->loadOptions('v1'), 'score_map', []),
                'server_duration_seconds' => (int) ($case['time_seconds_total'] ?? 420),
                'locale' => $locale,
                'region' => 'CN_MAINLAND',
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<int,string>
     */
    private function resolveAnswersMap(array $case): array
    {
        $answersByQid = is_array($case['answers_by_qid'] ?? null) ? $case['answers_by_qid'] : [];
        $normalized = [];
        foreach ($answersByQid as $qidRaw => $codeRaw) {
            $qid = (int) $qidRaw;
            $code = strtoupper(trim((string) $codeRaw));
            if ($qid >= 1 && $qid <= 60 && in_array($code, ['A', 'B', 'C', 'D', 'E'], true)) {
                $normalized[$qid] = $code;
            }
        }

        ksort($normalized, SORT_NUMERIC);
        $this->assertCount(60, $normalized);

        return $normalized;
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

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function canonicalize(array $fixture): array
    {
        foreach ($fixture as $key => $value) {
            if (is_array($value)) {
                $fixture[$key] = $this->canonicalize($value);
            }
        }

        if (! array_is_list($fixture)) {
            ksort($fixture);
        }

        return $fixture;
    }

    /**
     * @param  array<string,mixed>  $fixture
     */
    private function maybeUpdateFixture(string $file, array $fixture): void
    {
        if (! filter_var(env('UPDATE_EQ60_V5_FIXTURES', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        File::ensureDirectoryExists($this->fixtureDir());
        File::put(
            $this->fixturePath($file),
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function loadFixture(string $file): array
    {
        $path = $this->fixturePath($file);
        $this->assertFileExists($path);
        $decoded = json_decode(File::get($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function fixturePath(string $file): string
    {
        return $this->fixtureDir().DIRECTORY_SEPARATOR.$file;
    }

    private function fixtureDir(): string
    {
        return base_path('tests/Fixtures/eq/v5');
    }
}
