<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\ReportSnapshot;
use App\Models\Result;
use App\Services\Report\Composer\ReportComposeContext;
use App\Services\Report\Composer\ReportPayloadAssembler;
use App\Services\Mbti\MbtiPublicProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiPhase2AssemblerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase2_personalization_survives_assembler_snapshot_round_trip_and_projection(): void
    {
        $attempt = Attempt::query()->create([
            'anon_id' => 'anon_mbti_phase2_contract',
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 8,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.03',
            'started_at' => now(),
            'submitted_at' => now(),
        ]);

        $result = Result::query()->create([
            'id' => (string) Str::uuid(),
            'attempt_id' => (string) $attempt->id,
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'scale_version' => 'v0.3',
            'type_code' => 'ENFP-T',
            'scores_json' => [
                'EI' => ['a' => 13, 'b' => 7, 'neutral' => 0, 'sum' => 6, 'total' => 20],
                'SN' => ['a' => 12, 'b' => 8, 'neutral' => 0, 'sum' => 4, 'total' => 20],
                'TF' => ['a' => 9, 'b' => 11, 'neutral' => 0, 'sum' => -2, 'total' => 20],
                'JP' => ['a' => 9, 'b' => 11, 'neutral' => 0, 'sum' => -2, 'total' => 20],
                'AT' => ['a' => 6, 'b' => 14, 'neutral' => 0, 'sum' => -8, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 67,
                'SN' => 64,
                'TF' => 59,
                'JP' => 57,
                'AT' => 68,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
            'profile_version' => 'mbti32-v2.5',
            'content_package_version' => 'v0.3',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.03',
            'report_engine_version' => 'report_phase4a_contract',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $payload = app(ReportPayloadAssembler::class)->assemble(
            ReportComposeContext::fromAttempt($attempt, $result, [
                'org_id' => 0,
                'variant' => 'free',
                'report_access_level' => 'free',
                'modules_allowed' => [],
                'modules_preview' => [],
                'persist' => false,
                'strict' => false,
                'explain' => false,
            ])
        );

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame(
            'mbti.personalization.phase8a.v1',
            data_get($payload, 'report._meta.personalization.schema_version')
        );
        $this->assertSame(
            'report_phase4a_contract',
            data_get($payload, 'report._meta.personalization.engine_version')
        );
        $this->assertSame(
            'phase8a.v1',
            data_get($payload, 'report._meta.personalization.dynamic_sections_version')
        );
        $this->assertSame(
            [
                'is_first_view' => true,
                'is_revisit' => false,
                'has_unlock' => false,
                'has_feedback' => false,
                'has_share' => false,
                'has_action_engagement' => false,
            ],
            data_get($payload, 'report._meta.personalization.user_state')
        );
        $this->assertSame(
            'growth.next_actions',
            data_get($payload, 'report._meta.personalization.orchestration.primary_focus_key')
        );
        $this->assertSame(
            'growth.next_actions',
            data_get($payload, 'report._meta.personalization.continuity.carryover_focus_key')
        );
        $this->assertSame(
            'unlock_to_continue_focus',
            data_get($payload, 'report._meta.personalization.continuity.carryover_reason')
        );
        $this->assertContains(
            'weekly_action.theme.name_decision_rule',
            data_get($payload, 'report._meta.personalization.continuity.carryover_action_keys', [])
        );
        $this->assertNotSame(
            '',
            trim((string) data_get($payload, 'report._meta.personalization.explainability_summary', ''))
        );
        $this->assertSame(
            ['ENFJ', 'ENTP'],
            data_get($payload, 'report._meta.personalization.neighbor_type_keys')
        );
        $this->assertSame(
            ['JP', 'TF'],
            array_map(static fn (array $axis): string => (string) ($axis['axis'] ?? ''), data_get($payload, 'report._meta.personalization.close_call_axes', []))
        );
        $this->assertContains(
            'stability.bucket.context_sensitive',
            data_get($payload, 'report._meta.personalization.confidence_or_stability_keys', [])
        );
        $this->assertStringContainsString(
            '先用把能量投向外部互动',
            (string) data_get($payload, 'report._meta.personalization.work_style_summary', '')
        );
        $this->assertSame(
            'work.primary.EI.E.clear',
            data_get($payload, 'report._meta.personalization.scene_fingerprint.work.style_key')
        );
        $this->assertSame(
            [
                'communication.primary.EI.E.clear',
                'communication.support.TF.T.boundary',
                'communication.identity.T',
                'communication.boundary.TF',
                'communication.boundary.JP',
            ],
            data_get($payload, 'report._meta.personalization.communication_style_keys')
        );
        $this->assertSame(
            [
                'role_fit.role.NF',
                'role_fit.primary.EI.E.clear',
                'role_fit.support.JP.J.boundary',
                'role_fit.identity.T',
                'role_fit.boundary.JP',
                'role_fit.boundary.TF',
            ],
            data_get($payload, 'report._meta.personalization.role_fit_keys')
        );
        $this->assertContains(
            'career_next_step.theme.clarify_decision_criteria',
            data_get($payload, 'report._meta.personalization.career_next_step_keys', [])
        );
        $this->assertNotSame(
            '',
            trim((string) data_get($payload, 'report._meta.personalization.action_plan_summary', ''))
        );
        $this->assertContains(
            'weekly_action.theme.name_decision_rule',
            data_get($payload, 'report._meta.personalization.weekly_action_keys', [])
        );
        $this->assertContains(
            'relationship_action.theme.name_decision_rule',
            data_get($payload, 'report._meta.personalization.relationship_action_keys', [])
        );
        $this->assertContains(
            'work_experiment.theme.name_decision_rule',
            data_get($payload, 'report._meta.personalization.work_experiment_keys', [])
        );
        $this->assertContains(
            'watchout.stability.context_sensitive',
            data_get($payload, 'report._meta.personalization.watchout_keys', [])
        );

        ReportSnapshot::query()->create([
            'org_id' => 0,
            'attempt_id' => (string) $attempt->id,
            'order_no' => null,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.03',
            'report_engine_version' => 'report_phase4a_contract',
            'snapshot_version' => 'phase2.contract',
            'report_json' => data_get($payload, 'report', []),
            'report_free_json' => data_get($payload, 'report', []),
            'report_full_json' => data_get($payload, 'report', []),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = ReportSnapshot::query()->findOrFail((string) $attempt->id);
        $roundTrippedReport = is_array($snapshot->report_json) ? $snapshot->report_json : [];
        $roundTrippedVariantKeys = Arr::wrap(data_get($roundTrippedReport, '_meta.personalization.variant_keys', []));

        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            $roundTrippedVariantKeys['relationships.rel_risks'] ?? null
        );
        $this->assertSame(
            'traits.decision_style:TF.T.boundary:identity.T:boundary.TF',
            $roundTrippedVariantKeys['traits.decision_style'] ?? null
        );
        $this->assertSame(
            'growth.stress_recovery:JP.J.boundary:identity.T:boundary.JP',
            $roundTrippedVariantKeys['growth.stress_recovery'] ?? null
        );
        $this->assertSame(
            'relationships.communication_style:EI.E.clear:identity.T:boundary.TF',
            $roundTrippedVariantKeys['relationships.communication_style'] ?? null
        );
        $this->assertSame(
            'career.collaboration_fit:EI.E.clear:identity.T:boundary.TF',
            $roundTrippedVariantKeys['career.collaboration_fit'] ?? null
        );
        $this->assertSame(
            'career.work_environment:EI.E.clear:identity.T:boundary.JP',
            $roundTrippedVariantKeys['career.work_environment'] ?? null
        );
        $this->assertSame(
            'career.next_step:TF.T.boundary:identity.T:boundary.TF',
            $roundTrippedVariantKeys['career.next_step'] ?? null
        );
        $this->assertSame(
            'career.work_experiments:EI.E.clear:identity.T:action.work_experiment_theme_name_decision_rule:boundary.JP',
            $roundTrippedVariantKeys['career.work_experiments'] ?? null
        );
        $this->assertSame(
            'traits.why_this_type:EI.E.clear:identity.T:boundary.JP',
            $roundTrippedVariantKeys['traits.why_this_type'] ?? null
        );
        $this->assertSame(
            'traits.close_call_axes:JP.J.boundary:identity.T:boundary.JP',
            $roundTrippedVariantKeys['traits.close_call_axes'] ?? null
        );
        $this->assertSame(
            'traits.adjacent_type_contrast:JP.J.boundary:identity.T:neighbor.ENFJ',
            $roundTrippedVariantKeys['traits.adjacent_type_contrast'] ?? null
        );
        $this->assertSame(
            'growth.stability_confidence:stability.context_sensitive:identity.T:boundary.JP',
            $roundTrippedVariantKeys['growth.stability_confidence'] ?? null
        );
        $this->assertSame(
            'growth.next_actions:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            $roundTrippedVariantKeys['growth.next_actions'] ?? null
        );
        $this->assertSame(
            'growth.weekly_experiments:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            $roundTrippedVariantKeys['growth.weekly_experiments'] ?? null
        );
        $this->assertSame(
            'growth.watchouts:JP.J.boundary:identity.T:action.watchout_stability_context_sensitive:boundary.JP',
            $roundTrippedVariantKeys['growth.watchouts'] ?? null
        );
        $this->assertSame(
            'relationships.try_this_week:EI.E.clear:identity.T:action.relationship_action_theme_name_decision_rule:boundary.TF',
            $roundTrippedVariantKeys['relationships.try_this_week'] ?? null
        );

        $projection = app(MbtiPublicProjectionService::class)->buildForReportEnvelope(
            $result,
            [
                'report' => $roundTrippedReport,
                'meta' => [
                    'pack_id' => (string) $attempt->pack_id,
                    'dir_version' => (string) $attempt->dir_version,
                    'report_engine_version' => (string) $result->report_engine_version,
                ],
            ],
            'zh-CN',
            0
        );

        $relationshipsRelRisks = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'relationships.rel_risks');
        $decisionStyle = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'traits.decision_style');
        $stressRecovery = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'growth.stress_recovery');
        $communicationStyle = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'relationships.communication_style');
        $careerCollaborationFit = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'career.collaboration_fit');
        $careerWorkEnvironment = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'career.work_environment');
        $careerNextStep = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'career.next_step');
        $whyThisType = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'traits.why_this_type');
        $closeCallAxes = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'traits.close_call_axes');
        $adjacentTypeContrast = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'traits.adjacent_type_contrast');
        $stabilityConfidence = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'growth.stability_confidence');
        $careerWorkExperiments = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'career.work_experiments');
        $growthNextActions = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'growth.next_actions');
        $growthWeeklyExperiments = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'growth.weekly_experiments');
        $growthWatchouts = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'growth.watchouts');
        $relationshipsTryThisWeek = collect(Arr::wrap($projection['sections'] ?? []))
            ->first(static fn (array $section): bool => (string) ($section['key'] ?? '') === 'relationships.try_this_week');

        $this->assertSame(
            'mbti.personalization.phase8a.v1',
            data_get($projection, '_meta.personalization.schema_version')
        );
        $this->assertSame(
            'phase8a.v1',
            data_get($projection, '_meta.personalization.dynamic_sections_version')
        );
        $this->assertSame(
            'growth.next_actions',
            data_get($projection, '_meta.personalization.orchestration.primary_focus_key')
        );
        $this->assertSame(
            'growth.next_actions',
            data_get($projection, '_meta.personalization.continuity.carryover_focus_key')
        );
        $this->assertSame(
            'overview:EI.E.clear:identity.T:boundary.none',
            data_get($projection, '_meta.personalization.variant_keys.overview')
        );
        $projectionVariantKeys = Arr::wrap(data_get($projection, '_meta.personalization.variant_keys', []));
        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            $projectionVariantKeys['relationships.rel_risks'] ?? null
        );
        $this->assertSame(
            'traits.decision_style:TF.T.boundary:identity.T:boundary.TF',
            $projectionVariantKeys['traits.decision_style'] ?? null
        );
        $this->assertSame(
            'growth.stress_recovery:JP.J.boundary:identity.T:boundary.JP',
            $projectionVariantKeys['growth.stress_recovery'] ?? null
        );
        $this->assertSame(
            'relationships.communication_style:EI.E.clear:identity.T:boundary.TF',
            $projectionVariantKeys['relationships.communication_style'] ?? null
        );
        $this->assertSame(
            'career.collaboration_fit:EI.E.clear:identity.T:boundary.TF',
            $projectionVariantKeys['career.collaboration_fit'] ?? null
        );
        $this->assertSame(
            'career.work_environment:EI.E.clear:identity.T:boundary.JP',
            $projectionVariantKeys['career.work_environment'] ?? null
        );
        $this->assertSame(
            'career.next_step:TF.T.boundary:identity.T:boundary.TF',
            $projectionVariantKeys['career.next_step'] ?? null
        );
        $this->assertSame(
            'traits.why_this_type:EI.E.clear:identity.T:boundary.JP',
            $projectionVariantKeys['traits.why_this_type'] ?? null
        );
        $this->assertSame(
            'traits.close_call_axes:JP.J.boundary:identity.T:boundary.JP',
            $projectionVariantKeys['traits.close_call_axes'] ?? null
        );
        $this->assertSame(
            'traits.adjacent_type_contrast:JP.J.boundary:identity.T:neighbor.ENFJ',
            $projectionVariantKeys['traits.adjacent_type_contrast'] ?? null
        );
        $this->assertSame(
            'growth.stability_confidence:stability.context_sensitive:identity.T:boundary.JP',
            $projectionVariantKeys['growth.stability_confidence'] ?? null
        );
        $this->assertSame(
            'career.work_experiments:EI.E.clear:identity.T:action.work_experiment_theme_name_decision_rule:boundary.JP',
            $projectionVariantKeys['career.work_experiments'] ?? null
        );
        $this->assertSame(
            'growth.next_actions:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            $projectionVariantKeys['growth.next_actions'] ?? null
        );
        $this->assertSame(
            'growth.weekly_experiments:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            $projectionVariantKeys['growth.weekly_experiments'] ?? null
        );
        $this->assertSame(
            'growth.watchouts:JP.J.boundary:identity.T:action.watchout_stability_context_sensitive:boundary.JP',
            $projectionVariantKeys['growth.watchouts'] ?? null
        );
        $this->assertSame(
            'relationships.try_this_week:EI.E.clear:identity.T:action.relationship_action_theme_name_decision_rule:boundary.TF',
            $projectionVariantKeys['relationships.try_this_week'] ?? null
        );
        $this->assertIsArray($relationshipsRelRisks);
        $this->assertIsArray($decisionStyle);
        $this->assertIsArray($stressRecovery);
        $this->assertIsArray($communicationStyle);
        $this->assertIsArray($careerCollaborationFit);
        $this->assertIsArray($careerWorkEnvironment);
        $this->assertIsArray($careerNextStep);
        $this->assertIsArray($whyThisType);
        $this->assertIsArray($closeCallAxes);
        $this->assertIsArray($adjacentTypeContrast);
        $this->assertIsArray($stabilityConfidence);
        $this->assertIsArray($careerWorkExperiments);
        $this->assertIsArray($growthNextActions);
        $this->assertIsArray($growthWeeklyExperiments);
        $this->assertIsArray($growthWatchouts);
        $this->assertIsArray($relationshipsTryThisWeek);
        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            data_get($relationshipsRelRisks, '_meta.variant_key')
        );
        $this->assertSame(
            'traits.decision_style:TF.T.boundary:identity.T:boundary.TF',
            data_get($decisionStyle, '_meta.variant_key')
        );
        $this->assertSame(
            'growth.stress_recovery:JP.J.boundary:identity.T:boundary.JP',
            data_get($stressRecovery, '_meta.variant_key')
        );
        $this->assertSame(
            'relationships.communication_style:EI.E.clear:identity.T:boundary.TF',
            data_get($communicationStyle, '_meta.variant_key')
        );
        $this->assertSame(
            'career.collaboration_fit:EI.E.clear:identity.T:boundary.TF',
            data_get($careerCollaborationFit, '_meta.variant_key')
        );
        $this->assertSame(
            'career.work_environment:EI.E.clear:identity.T:boundary.JP',
            data_get($careerWorkEnvironment, '_meta.variant_key')
        );
        $this->assertSame(
            'career.next_step:TF.T.boundary:identity.T:boundary.TF',
            data_get($careerNextStep, '_meta.variant_key')
        );
        $this->assertSame(
            'traits.why_this_type:EI.E.clear:identity.T:boundary.JP',
            data_get($whyThisType, '_meta.variant_key')
        );
        $this->assertSame(
            'traits.close_call_axes:JP.J.boundary:identity.T:boundary.JP',
            data_get($closeCallAxes, '_meta.variant_key')
        );
        $this->assertSame(
            'traits.adjacent_type_contrast:JP.J.boundary:identity.T:neighbor.ENFJ',
            data_get($adjacentTypeContrast, '_meta.variant_key')
        );
        $this->assertSame(
            'growth.stability_confidence:stability.context_sensitive:identity.T:boundary.JP',
            data_get($stabilityConfidence, '_meta.variant_key')
        );
        $this->assertSame(
            'career.work_experiments:EI.E.clear:identity.T:action.work_experiment_theme_name_decision_rule:boundary.JP',
            data_get($careerWorkExperiments, '_meta.variant_key')
        );
        $this->assertSame(
            'growth.next_actions:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            data_get($growthNextActions, '_meta.variant_key')
        );
        $this->assertSame(
            'growth.weekly_experiments:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            data_get($growthWeeklyExperiments, '_meta.variant_key')
        );
        $this->assertSame(
            'growth.watchouts:JP.J.boundary:identity.T:action.watchout_stability_context_sensitive:boundary.JP',
            data_get($growthWatchouts, '_meta.variant_key')
        );
        $this->assertSame(
            'relationships.try_this_week:EI.E.clear:identity.T:action.relationship_action_theme_name_decision_rule:boundary.TF',
            data_get($relationshipsTryThisWeek, '_meta.variant_key')
        );
        $this->assertSame(
            'decision',
            data_get($decisionStyle, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'stress_recovery',
            data_get($stressRecovery, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'communication',
            data_get($communicationStyle, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'collaboration_fit',
            data_get($careerCollaborationFit, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'work_env',
            data_get($careerWorkEnvironment, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'career_next_step',
            data_get($careerNextStep, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'why_this_type',
            data_get($whyThisType, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'borderline_axis',
            data_get($closeCallAxes, 'payload.blocks.0.kind')
        );
        $this->assertSame(
            'adjacent_type_contrast',
            data_get($adjacentTypeContrast, 'payload.blocks.0.kind')
        );
        $this->assertSame(
            'stability_explanation',
            data_get($stabilityConfidence, 'payload.blocks.0.kind')
        );
        $this->assertSame(
            'work_experiment',
            data_get($careerWorkExperiments, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'next_action',
            data_get($growthNextActions, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'weekly_experiment',
            data_get($growthWeeklyExperiments, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'watchout',
            data_get($growthWatchouts, 'payload.blocks.1.kind')
        );
        $this->assertSame(
            'relationship_practice',
            data_get($relationshipsTryThisWeek, 'payload.blocks.1.kind')
        );
        $this->assertStringContainsString(
            '两套入口之间切换',
            (string) data_get($decisionStyle, 'payload.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '过载时和恢复时可能会切到不同挡位',
            (string) data_get($stressRecovery, 'payload.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '你的起手表达方式',
            (string) data_get($communicationStyle, 'payload.blocks.1.text', '')
        );
        $this->assertStringContainsString(
            '两套判断入口之间来回校准',
            (string) data_get($relationshipsRelRisks, 'payload.blocks.3.text', '')
        );
        $this->assertStringContainsString(
            '主类型',
            (string) data_get($whyThisType, 'payload.blocks.1.text', '')
        );
        $this->assertStringContainsString(
            '只拉开了7个点差',
            (string) data_get($closeCallAxes, 'payload.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '最容易把你看成ENFJ',
            (string) data_get($adjacentTypeContrast, 'payload.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '情境敏感型稳定',
            (string) data_get($stabilityConfidence, 'payload.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '可逆动作',
            (string) data_get($careerWorkExperiments, 'payload.blocks.1.text', '')
        );
        $this->assertStringContainsString(
            '下一步动作',
            (string) data_get($growthNextActions, 'payload.blocks.1.text', '')
        );
        $this->assertStringContainsString(
            '可执行实验',
            (string) data_get($growthWeeklyExperiments, 'payload.blocks.1.text', '')
        );
        $this->assertStringContainsString(
            '风险提醒',
            (string) data_get($growthWatchouts, 'payload.blocks.1.text', '')
        );
        $this->assertStringContainsString(
            '本周关系练习',
            (string) data_get($relationshipsTryThisWeek, 'payload.blocks.1.text', '')
        );
    }
}
