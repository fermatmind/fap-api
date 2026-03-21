<?php

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Models\Attempt;
use App\Models\Result;
use App\Support\OrgContext;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MbtiResultTelemetryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_mbti_result_and_report_events_include_personalization_meta(): void
    {
        $this->seedScales();
        Config::set('fap_experiments.experiments', []);

        $anonId = 'mbti_phase3a_anon';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);
        $this->seedPhase7bSignals($attemptId);

        $resultResponse = $this->invokeController('result', $attemptId, $anonId);
        $this->assertSame(200, $resultResponse->getStatusCode());

        $reportResponse = $this->invokeController('report', $attemptId, $anonId);
        $this->assertSame(200, $reportResponse->getStatusCode());

        $eventMeta = [];
        foreach (['result_view', 'report_view'] as $eventCode) {
            $event = $this->findLatestEventByAttempt($eventCode, $attemptId);
            $this->assertNotNull($event, 'missing event row: '.$eventCode);

            $meta = json_decode((string) ($event->meta_json ?? '{}'), true) ?: [];
            $eventMeta[$eventCode] = $meta;
            $this->assertSame('INTJ-A', (string) ($meta['type_code'] ?? ''));
            $this->assertSame('A', (string) ($meta['identity'] ?? ''));
            $this->assertSame('report_phase4a_contract', (string) ($meta['engine_version'] ?? ''));
            $this->assertSame('mbti.personalization.phase9e.v1', (string) ($meta['schema_version'] ?? ''));
            $this->assertSame('phase9c.v1', (string) ($meta['dynamic_sections_version'] ?? ''));
            $this->assertSame('controlled_narrative.v1', (string) ($meta['narrative_contract_version'] ?? ''));
            $this->assertSame('narrative_runtime_contract.v1', (string) ($meta['narrative_runtime_contract_version'] ?? ''));
            $this->assertSame('off', (string) ($meta['narrative_runtime_mode'] ?? ''));
            $this->assertSame('null', (string) ($meta['narrative_provider_name'] ?? ''));
            $this->assertSame('off', (string) ($meta['narrative_fail_open_mode'] ?? ''));
            $this->assertNotSame('', trim((string) ($meta['narrative_fingerprint'] ?? '')));
            $this->assertIsArray($meta['axis_bands'] ?? null);
            $this->assertSame('boundary', (string) (($meta['axis_bands']['EI'] ?? '')));
            $this->assertSame('boundary', (string) (($meta['axis_bands']['AT'] ?? '')));
            $this->assertIsArray($meta['boundary_flags'] ?? null);
            $this->assertTrue((bool) (($meta['boundary_flags']['EI'] ?? false)));
            $this->assertTrue((bool) (($meta['boundary_flags']['AT'] ?? false)));
            $this->assertIsArray($meta['variant_keys'] ?? null);
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['overview'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['traits.decision_style'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['growth.stress_recovery'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['relationships.communication_style'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['career.collaboration_fit'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['career.work_environment'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['career.next_step'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['career.work_experiments'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['traits.why_this_type'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['traits.close_call_axes'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['traits.adjacent_type_contrast'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['growth.stability_confidence'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['growth.next_actions'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['growth.weekly_experiments'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['growth.watchouts'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['relationships.try_this_week'] ?? ''))));
            $this->assertIsArray($meta['contrast_keys'] ?? null);
            $this->assertNotSame('', trim((string) (($meta['contrast_keys']['traits.adjacent_type_contrast'] ?? ''))));
            $this->assertNotSame('', trim((string) ($meta['explainability_summary'] ?? '')));
            $this->assertIsArray($meta['close_call_axes'] ?? null);
            $this->assertIsArray($meta['neighbor_type_keys'] ?? null);
            $this->assertIsArray($meta['confidence_or_stability_keys'] ?? null);
            $this->assertIsArray($meta['scene_fingerprint'] ?? null);
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['work'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['decision'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['stress_recovery'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['communication'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['work_style_summary'] ?? ''))));
            $this->assertIsArray($meta['role_fit_keys'] ?? null);
            $this->assertIsArray($meta['collaboration_fit_keys'] ?? null);
            $this->assertIsArray($meta['work_env_preference_keys'] ?? null);
            $this->assertIsArray($meta['career_next_step_keys'] ?? null);
            $this->assertIsArray($meta['working_life_v1'] ?? null);
            $this->assertIsString((string) ($meta['career_focus_key'] ?? ''));
            $this->assertIsArray($meta['career_journey_keys'] ?? null);
            $this->assertIsArray($meta['career_action_priority_keys'] ?? null);
            $this->assertIsArray($meta['intra_type_profile_v1'] ?? null);
            $this->assertSame('mbti.intra_type_profile.v1', (string) (($meta['intra_type_profile_v1']['version'] ?? '')));
            $this->assertIsString((string) ($meta['profile_seed_key'] ?? ''));
            $this->assertIsArray($meta['same_type_divergence_keys'] ?? null);
            $this->assertIsArray($meta['section_selection_keys'] ?? null);
            $this->assertIsArray($meta['action_selection_keys'] ?? null);
            $this->assertIsArray($meta['recommendation_selection_keys'] ?? null);
            $this->assertNotSame('', trim((string) ($meta['selection_fingerprint'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['action_plan_summary'] ?? '')));
            $this->assertIsArray($meta['weekly_action_keys'] ?? null);
            $this->assertIsArray($meta['relationship_action_keys'] ?? null);
            $this->assertIsArray($meta['work_experiment_keys'] ?? null);
            $this->assertIsArray($meta['watchout_keys'] ?? null);
            $this->assertIsArray($meta['ordered_recommendation_keys'] ?? []);
            $this->assertIsArray($meta['ordered_action_keys'] ?? []);
            $this->assertIsArray($meta['recommendation_priority_keys'] ?? []);
            $this->assertIsArray($meta['action_priority_keys'] ?? []);
            $this->assertIsString((string) ($meta['reading_focus_key'] ?? ''));
            $this->assertIsString((string) ($meta['action_focus_key'] ?? ''));
            $this->assertIsArray($meta['user_state'] ?? null);
            $this->assertIsArray($meta['orchestration'] ?? null);
            $this->assertIsArray($meta['continuity'] ?? null);
            $this->assertIsArray($meta['action_journey_v1'] ?? null);
            $this->assertIsArray($meta['pulse_check_v1'] ?? null);
            $this->assertSame('mbti.privacy_contract.v1', (string) ($meta['privacy_contract_version'] ?? ''));
            $this->assertIsArray($meta['consent_scope'] ?? null);
            $this->assertSame('comparative.norming.v1', (string) ($meta['comparative_contract_version'] ?? ''));
            $this->assertNotSame('', trim((string) ($meta['comparative_fingerprint'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['norming_scope'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['norming_source'] ?? '')));
            $this->assertGreaterThan(0, (int) data_get($meta, 'comparative_v1.percentile.value', 0));
            $this->assertSame('same_type.boundary_axes', data_get($meta, 'comparative_v1.same_type_contrast.key'));
            $this->assertSame('zh-CN', (string) ($meta['locale_context'] ?? ''));
            $this->assertSame('CN_MAINLAND.zh-CN', (string) ($meta['cultural_context'] ?? ''));
            $this->assertSame('cultural_calibration.v1', (string) ($meta['calibration_contract_version'] ?? ''));
            $this->assertSame('governance.v1', (string) ($meta['calibration_policy_version'] ?? ''));
            $this->assertSame('content_governance', (string) ($meta['calibration_source'] ?? ''));
            $this->assertContains('growth.next_actions', $meta['calibrated_section_keys'] ?? []);
            $this->assertNotSame('', trim((string) ($meta['calibration_fingerprint'] ?? '')));
            $this->assertContains('role_fit.role.NT', $meta['role_fit_keys'] ?? []);
            $this->assertSame('action_journey.v1', (string) ($meta['journey_contract_version'] ?? ''));
            $this->assertSame('action_journey.fingerprint.v1', (string) ($meta['journey_fingerprint_version'] ?? ''));
            $this->assertSame('result_revisit', (string) ($meta['journey_scope'] ?? ''));
            $this->assertNotSame('', trim((string) ($meta['journey_fingerprint'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['journey_state'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['progress_state'] ?? '')));
            if (array_key_exists('completed_action_keys', $meta)) {
                $this->assertIsArray($meta['completed_action_keys']);
            }
            $this->assertIsArray($meta['recommended_next_pulse_keys'] ?? null);
            $this->assertNotSame('', trim((string) ($meta['revisit_reorder_reason'] ?? '')));
            $this->assertSame('pulse_check.v1', (string) ($meta['pulse_contract_version'] ?? ''));
            $this->assertNotSame('', trim((string) ($meta['pulse_state'] ?? '')));
            if (array_key_exists('pulse_prompt_keys', $meta)) {
                $this->assertIsArray($meta['pulse_prompt_keys']);
            }
        }

        $this->assertSame($eventMeta['report_view']['variant_keys'] ?? null, $eventMeta['result_view']['variant_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['scene_fingerprint'] ?? null, $eventMeta['result_view']['scene_fingerprint'] ?? null);
        $this->assertSame($eventMeta['report_view']['axis_bands'] ?? null, $eventMeta['result_view']['axis_bands'] ?? null);
        $this->assertSame($eventMeta['report_view']['boundary_flags'] ?? null, $eventMeta['result_view']['boundary_flags'] ?? null);
        $this->assertSame($eventMeta['report_view']['schema_version'] ?? null, $eventMeta['result_view']['schema_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['dynamic_sections_version'] ?? null, $eventMeta['result_view']['dynamic_sections_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['contrast_keys'] ?? null, $eventMeta['result_view']['contrast_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['close_call_axes'] ?? null, $eventMeta['result_view']['close_call_axes'] ?? null);
        $this->assertSame($eventMeta['report_view']['neighbor_type_keys'] ?? null, $eventMeta['result_view']['neighbor_type_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['confidence_or_stability_keys'] ?? null, $eventMeta['result_view']['confidence_or_stability_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['action_plan_summary'] ?? null, $eventMeta['result_view']['action_plan_summary'] ?? null);
        $this->assertSame($eventMeta['report_view']['weekly_action_keys'] ?? null, $eventMeta['result_view']['weekly_action_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['relationship_action_keys'] ?? null, $eventMeta['result_view']['relationship_action_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['work_experiment_keys'] ?? null, $eventMeta['result_view']['work_experiment_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['watchout_keys'] ?? null, $eventMeta['result_view']['watchout_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['working_life_v1'] ?? null, $eventMeta['result_view']['working_life_v1'] ?? null);
        $this->assertSame($eventMeta['report_view']['privacy_contract_version'] ?? null, $eventMeta['result_view']['privacy_contract_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['consent_scope'] ?? null, $eventMeta['result_view']['consent_scope'] ?? null);
        $this->assertSame($eventMeta['report_view']['narrative_contract_version'] ?? null, $eventMeta['result_view']['narrative_contract_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['narrative_runtime_contract_version'] ?? null, $eventMeta['result_view']['narrative_runtime_contract_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['narrative_fingerprint'] ?? null, $eventMeta['result_view']['narrative_fingerprint'] ?? null);
        $this->assertSame($eventMeta['report_view']['comparative_contract_version'] ?? null, $eventMeta['result_view']['comparative_contract_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['comparative_fingerprint'] ?? null, $eventMeta['result_view']['comparative_fingerprint'] ?? null);
        $this->assertSame($eventMeta['report_view']['norming_version'] ?? null, $eventMeta['result_view']['norming_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['norming_scope'] ?? null, $eventMeta['result_view']['norming_scope'] ?? null);
        $this->assertSame($eventMeta['report_view']['norming_source'] ?? null, $eventMeta['result_view']['norming_source'] ?? null);
        $this->assertSame($eventMeta['report_view']['locale_context'] ?? null, $eventMeta['result_view']['locale_context'] ?? null);
        $this->assertSame($eventMeta['report_view']['cultural_context'] ?? null, $eventMeta['result_view']['cultural_context'] ?? null);
        $this->assertSame($eventMeta['report_view']['calibrated_section_keys'] ?? null, $eventMeta['result_view']['calibrated_section_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['calibration_fingerprint'] ?? null, $eventMeta['result_view']['calibration_fingerprint'] ?? null);
        $this->assertSame(true, data_get($eventMeta, 'result_view.user_state.is_first_view'));
        $this->assertSame(false, data_get($eventMeta, 'result_view.user_state.is_revisit'));
        $this->assertSame(true, data_get($eventMeta, 'result_view.user_state.has_share'));
        $this->assertSame(true, data_get($eventMeta, 'result_view.user_state.has_feedback'));
        $this->assertSame(true, data_get($eventMeta, 'result_view.user_state.has_action_engagement'));
        $this->assertSame('negative', data_get($eventMeta, 'result_view.user_state.feedback_sentiment'));
        $this->assertSame('explainability_only', data_get($eventMeta, 'result_view.user_state.feedback_coverage'));
        $this->assertSame('warming_up', data_get($eventMeta, 'result_view.user_state.action_completion_tendency'));
        $this->assertSame('traits.close_call_axes', data_get($eventMeta, 'result_view.user_state.last_deep_read_section'));
        $this->assertSame('action_activation', data_get($eventMeta, 'result_view.user_state.current_intent_cluster'));
        $this->assertSame(false, data_get($eventMeta, 'report_view.user_state.is_first_view'));
        $this->assertSame(true, data_get($eventMeta, 'report_view.user_state.is_revisit'));
        $this->assertSame('negative', data_get($eventMeta, 'report_view.user_state.feedback_sentiment'));
        $this->assertSame('explainability_only', data_get($eventMeta, 'report_view.user_state.feedback_coverage'));
        $this->assertSame('repeatable', data_get($eventMeta, 'report_view.user_state.action_completion_tendency'));
        $this->assertSame('traits.close_call_axes', data_get($eventMeta, 'report_view.user_state.last_deep_read_section'));
        $this->assertSame('action_activation', data_get($eventMeta, 'report_view.user_state.current_intent_cluster'));
        $this->assertSame(
            'traits.close_call_axes',
            data_get($eventMeta, 'result_view.orchestration.primary_focus_key')
        );
        $this->assertSame(
            'traits.close_call_axes',
            data_get($eventMeta, 'report_view.orchestration.primary_focus_key')
        );
        $this->assertSame(
            ['unlock_full_report', 'share_result', 'career_bridge'],
            data_get($eventMeta, 'result_view.orchestration.cta_priority_keys')
        );
        $this->assertSame(
            ['unlock_full_report', 'share_result', 'career_bridge'],
            data_get($eventMeta, 'report_view.orchestration.cta_priority_keys')
        );
        $this->assertSame('career.work_experiments', data_get($eventMeta, 'result_view.working_life_v1.career_focus_key'));
        $this->assertSame('mbti.intra_type_profile.v1', data_get($eventMeta, 'result_view.intra_type_profile_v1.version'));
        $this->assertSame(
            data_get($eventMeta, 'result_view.profile_seed_key'),
            data_get($eventMeta, 'report_view.profile_seed_key')
        );
        $this->assertContains('same_type.boundary_axis.jp', data_get($eventMeta, 'result_view.same_type_divergence_keys', []));
        $this->assertIsArray(data_get($eventMeta, 'result_view.section_selection_keys', []));
        $this->assertIsArray(data_get($eventMeta, 'result_view.action_selection_keys', []));
        $this->assertIsArray(data_get($eventMeta, 'result_view.recommendation_selection_keys', []));
        $this->assertNotSame('', trim((string) data_get($eventMeta, 'result_view.selection_fingerprint', '')));
        $this->assertSame(
            ['career.work_experiments', 'career.next_step', 'career.work_environment', 'career.collaboration_fit'],
            data_get($eventMeta, 'result_view.working_life_v1.career_journey_keys')
        );
        $this->assertContains('career_bridge', data_get($eventMeta, 'result_view.working_life_v1.career_action_priority_keys', []));
        $this->assertIsArray(data_get($eventMeta, 'result_view.ordered_recommendation_keys', []));
        $this->assertIsArray(data_get($eventMeta, 'result_view.recommendation_priority_keys', []));
        $this->assertSame('weekly_action.theme.protect_energy_lane', data_get($eventMeta, 'result_view.action_focus_key'));
        $this->assertSame('work_experiment.theme.protect_energy_lane', data_get($eventMeta, 'report_view.action_focus_key'));
        $this->assertSame('repeatable', data_get($eventMeta, 'report_view.progress_state'));
        $this->assertSame('warming_up', data_get($eventMeta, 'result_view.progress_state'));
        $this->assertSame('refine_after_feedback', data_get($eventMeta, 'report_view.journey_state'));
        $this->assertSame('first_view_activation', data_get($eventMeta, 'result_view.journey_state'));
        $this->assertSame(
            'traits.close_call_axes',
            data_get($eventMeta, 'result_view.continuity.carryover_focus_key')
        );
        $this->assertSame(
            'traits.close_call_axes',
            data_get($eventMeta, 'report_view.continuity.carryover_focus_key')
        );
        $this->assertSame(
            'unlock_to_continue_focus',
            data_get($eventMeta, 'result_view.continuity.carryover_reason')
        );
        $this->assertSame(
            'resume_action_loop',
            data_get($eventMeta, 'report_view.continuity.carryover_reason')
        );
        $this->assertSame(
            ['traits.close_call_axes', 'growth.weekly_experiments', 'career.work_experiments'],
            data_get($eventMeta, 'result_view.continuity.recommended_resume_keys')
        );
        $this->assertSame(
            ['traits.close_call_axes', 'growth.weekly_experiments', 'career.work_experiments'],
            data_get($eventMeta, 'report_view.continuity.recommended_resume_keys')
        );
        $this->assertSame(
            ['explainability', 'growth', 'work'],
            data_get($eventMeta, 'result_view.continuity.carryover_scene_keys')
        );
        $this->assertSame(
            ['explainability', 'growth', 'work'],
            data_get($eventMeta, 'report_view.continuity.carryover_scene_keys')
        );
        $this->assertSame(
            ['weekly_action.theme.protect_energy_lane', 'work_experiment.theme.protect_energy_lane'],
            data_get($eventMeta, 'result_view.continuity.carryover_action_keys')
        );
        $this->assertSame(
            [
                'weekly_action.theme.protect_energy_lane',
                'work_experiment.theme.protect_energy_lane',
            ],
            data_get($eventMeta, 'report_view.continuity.carryover_action_keys')
        );
        $this->assertSame(true, data_get($eventMeta, 'result_view.consent_scope.subject_export'));
        $this->assertSame(true, data_get($eventMeta, 'result_view.consent_scope.telemetry_product_improvement'));
        $this->assertSame(true, data_get($eventMeta, 'result_view.consent_scope.experimentation_pseudonymous'));
        $this->assertSame(true, data_get($eventMeta, 'result_view.consent_scope.norming_anonymized_only'));
    }

    public function test_mbti_report_view_records_experiments_in_public_context_without_org_scope_failures(): void
    {
        $this->seedScales();
        Config::set('fap_experiments.experiments', [
            'PR23_STICKY_BUCKET' => [
                'is_active' => true,
                'variants' => [
                    'control' => 50,
                    'variant_a' => 50,
                ],
            ],
        ]);

        $anonId = 'mbti_report_experiment_anon';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);

        $reportResponse = $this->invokeController('report', $attemptId, $anonId);
        $this->assertSame(200, $reportResponse->getStatusCode());

        $event = $this->findLatestEventByAttempt('report_view', $attemptId);
        $this->assertNotNull($event);
        $experiments = json_decode((string) ($event->experiments_json ?? '{}'), true) ?: [];
        $this->assertArrayHasKey('PR23_STICKY_BUCKET', $experiments);

        $assignment = DB::table('experiment_assignments')
            ->where('org_id', 0)
            ->where('anon_id', $anonId)
            ->where('experiment_key', 'PR23_STICKY_BUCKET')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame(
            (string) data_get($experiments, 'PR23_STICKY_BUCKET'),
            (string) ($assignment->variant ?? '')
        );
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function createMbtiAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

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
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
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
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'report_phase4a_contract',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function invokeController(string $method, string $attemptId, string $anonId): \Illuminate\Http\JsonResponse
    {
        $path = "/api/v0.3/attempts/{$attemptId}/".($method === 'report' ? 'report' : 'result');
        $request = Request::create($path, 'GET');
        $request->headers->set('X-Anon-Id', $anonId);
        $request->attributes->set('anon_id', $anonId);
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_kind', OrgContext::KIND_PUBLIC);

        $this->app->instance('request', $request);
        app(OrgContext::class)->set(0, null, 'public', $anonId, OrgContext::KIND_PUBLIC);

        /** @var AttemptReadController $controller */
        $controller = app(AttemptReadController::class);

        return $controller->{$method}($request, $attemptId);
    }

    private function findLatestEventByAttempt(string $eventCode, string $attemptId): ?object
    {
        $query = DB::table('events')
            ->where('event_code', $eventCode)
            ->where(function ($inner) use ($attemptId): void {
                $inner->where('attempt_id', $attemptId);

                $driver = DB::connection()->getDriverName();
                if ($driver === 'mysql') {
                    $inner->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.attempt_id')) = ?",
                        [$attemptId]
                    );

                    return;
                }

                if ($driver === 'sqlite') {
                    $inner->orWhereRaw(
                        "json_extract(meta_json, '$.attempt_id') = ?",
                        [$attemptId]
                    );

                    return;
                }

                $inner->orWhereRaw('meta_json like ?', ['%"attempt_id":"'.$attemptId.'"%']);
            });

        return $query->orderByDesc('occurred_at')->first();
    }

    private function seedPhase7bSignals(string $attemptId): void
    {
        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'accuracy_feedback',
                'event_name' => 'accuracy_feedback',
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.stability_confidence',
                    'feedback' => 'unclear',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'meta_json' => json_encode([
                    'sectionKey' => 'traits.close_call_axes',
                    'interaction' => 'dwell_2500ms',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(9),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.next_actions',
                    'actionKey' => 'weekly_action.theme.name_decision_rule',
                    'interaction' => 'click',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(8),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('shares')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'anon_id' => 'mbti_phase3a_anon',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'v0.3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
