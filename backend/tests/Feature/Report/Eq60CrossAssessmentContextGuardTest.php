<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\Eq60ContentCompileService;
use App\Services\Report\Eq60ReportComposer;
use App\Services\Report\ReportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60CrossAssessmentContextGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_assessment_context_lists_available_sources_without_changing_eq_scores(): void
    {
        $this->prepareEqContent();

        $anonId = 'anon_eq_cross_context_all_sources';
        $eq = $this->createEqAttemptWithResult($anonId);
        $this->createSourceAttempt($anonId, 'MBTI', 'INTJ-A');
        $this->createSourceAttempt($anonId, 'BIG5_OCEAN', 'balanced');
        $this->createSourceAttempt($anonId, 'ENNEAGRAM', '5w6');
        $this->createSourceAttempt('anon_other_actor', 'MBTI', 'ESFP-A');

        $report = $this->composeEqReport($eq['attempt'], $eq['result']);

        $this->assertSame('sources_available', (string) data_get($report, 'cross_assessment_context.status'));
        $this->assertSame(3, (int) data_get($report, 'cross_assessment_context.source_count'));
        $this->assertSame('eq_cross_assessment_context.v1', (string) data_get($report, 'cross_assessment_context.schema'));
        $this->assertFalse((bool) data_get($report, 'cross_assessment_context.guardrails.affects_scores', true));
        $this->assertFalse((bool) data_get($report, 'cross_assessment_context.guardrails.changes_formulation', true));
        $this->assertFalse((bool) data_get($report, 'cross_assessment_context.guardrails.formal_report_mutation_allowed', true));

        foreach ([
            'MBTI' => 'eq.cross_context.mbti.available',
            'BIG5_OCEAN' => 'eq.cross_context.big_five.available',
            'ENNEAGRAM' => 'eq.cross_context.enneagram.available',
        ] as $scale => $assetId) {
            $this->assertTrue((bool) data_get($report, 'cross_assessment_context.source_scales.'.$scale.'.available'));
            $this->assertSame($assetId, (string) data_get($report, 'cross_assessment_context.source_scales.'.$scale.'.asset_id'));
            $this->assertContains($assetId, (array) data_get($report, 'asset_refs.cross_assessment_context_ids'));
        }

        $this->assertCount(3, (array) data_get($report, 'assets.cross_assessment_context.cards'));
        $this->assertSame('eq.cross_context.boundary.default', (string) data_get($report, 'assets.cross_assessment_context.boundary.id'));
        $this->assertSame('balanced_integrated', (string) data_get($report, 'interpretation.core_formulation_id'));
        $this->assertSame('self_report_trait_mixed_ei', (string) data_get($report, 'measurement_type'));

        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        foreach ([
            'predicts job performance',
            'certified emotional intelligence',
            'MSCEIT-like',
            'hiring suitable',
            'clinical assessment',
            'true emotional ability',
        ] as $forbiddenClaim) {
            $this->assertStringNotContainsString($forbiddenClaim, $json);
        }
    }

    public function test_cross_assessment_context_stays_empty_without_same_actor_sources(): void
    {
        $this->prepareEqContent();

        $eq = $this->createEqAttemptWithResult('anon_eq_cross_context_none');
        $this->createSourceAttempt('anon_different_user', 'MBTI', 'INTP-A');

        $report = $this->composeEqReport($eq['attempt'], $eq['result']);

        $this->assertSame('no_source_assessments', (string) data_get($report, 'cross_assessment_context.status'));
        $this->assertSame(0, (int) data_get($report, 'cross_assessment_context.source_count'));
        $this->assertSame([], (array) data_get($report, 'cross_assessment_context.context_asset_ids'));
        $this->assertSame([], (array) data_get($report, 'asset_refs.cross_assessment_context_ids'));
        $this->assertSame([], (array) data_get($report, 'assets.cross_assessment_context.cards'));

        foreach (['MBTI', 'BIG5_OCEAN', 'ENNEAGRAM'] as $scale) {
            $this->assertFalse((bool) data_get($report, 'cross_assessment_context.source_scales.'.$scale.'.available', true));
            $this->assertSame('not_available', (string) data_get($report, 'cross_assessment_context.source_scales.'.$scale.'.evidence_kind'));
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
    }

    /**
     * @return array{attempt:Attempt,result:Result}
     */
    private function createEqAttemptWithResult(string $anonId): array
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'en',
            'question_count' => 60,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'eq_cross_context_guard'],
            'started_at' => now()->subMinutes(8),
            'submitted_at' => now(),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
        ]);

        $score = [
            'scale_code' => 'EQ_60',
            'quality' => ['level' => 'A', 'flags' => []],
            'norms' => ['status' => 'PROVISIONAL'],
            'version_snapshot' => ['engine_version' => 'v1.0_normed_validity'],
            'scores' => [
                'global' => ['raw_sum' => 210, 'std_score' => 112, 'percentile' => 75, 'level' => 'proficient'],
                'SA' => ['raw_sum' => 54, 'std_score' => 110, 'percentile' => 72, 'level' => 'proficient'],
                'ER' => ['raw_sum' => 53, 'std_score' => 109, 'percentile' => 70, 'level' => 'proficient'],
                'EM' => ['raw_sum' => 55, 'std_score' => 112, 'percentile' => 76, 'level' => 'proficient'],
                'RM' => ['raw_sum' => 54, 'std_score' => 110, 'percentile' => 73, 'level' => 'proficient'],
            ],
            'report' => [],
            'report_tags' => [],
        ];

        $result = Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attempt->id,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => $score['scores'],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
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
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return ['attempt' => $attempt, 'result' => $result];
    }

    private function createSourceAttempt(string $anonId, string $scaleCode, string $typeCode): void
    {
        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'en',
            'question_count' => 100,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'eq_cross_context_source'],
            'started_at' => now()->subMinutes(20),
            'submitted_at' => now()->subMinutes(10),
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'test',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attempt->id,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $typeCode,
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => ['type_code' => $typeCode],
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'scoring_spec_version' => 'test',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function composeEqReport(Attempt $attempt, Result $result): array
    {
        /** @var Eq60ReportComposer $composer */
        $composer = app(Eq60ReportComposer::class);
        $composed = $composer->composeVariant($attempt->fresh() ?? $attempt, $result->fresh() ?? $result, ReportAccess::VARIANT_FULL, [
            'modules_allowed' => ReportAccess::eq60AllRuntimeModules(),
        ]);

        $this->assertTrue((bool) ($composed['ok'] ?? false));

        return (array) ($composed['report'] ?? []);
    }
}
