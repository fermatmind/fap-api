<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiReadModelContractService;
use Tests\TestCase;

final class MbtiReadModelContractServiceTest extends TestCase
{
    public function test_build_contract_exposes_canonical_overlay_and_cache_boundaries(): void
    {
        $service = app(MbtiReadModelContractService::class);

        $contract = $service->buildContract([
            'schema_version' => 'mbti.personalization.phase9e.v1',
            'identity' => 'A',
            'privacy_contract_v1' => ['version' => 'mbti.privacy_contract.v1'],
            'comparative_v1' => [
                'version' => 'comparative.norming.v1',
                'percentile' => ['value' => 73],
                'norming_version' => 'norm_2026_02',
            ],
            'controlled_narrative_v1' => ['version' => 'controlled_narrative.v1', 'runtime_mode' => 'off'],
            'narrative_runtime_contract_v1' => ['version' => 'narrative_runtime_contract.v1', 'runtime_mode' => 'off'],
            'cultural_calibration_v1' => [
                'version' => 'cultural_calibration.v1',
                'locale_context' => 'zh-CN',
                'cultural_context' => 'CN_MAINLAND.zh-CN',
                'calibrated_section_keys' => ['growth.next_actions'],
                'calibration_fingerprint' => 'fixture-calibration',
                'calibration_contract_version' => 'cultural_calibration.v1',
            ],
            'variant_keys' => ['overview' => 'overview:clear'],
            'scene_fingerprint' => ['work' => ['style_key' => 'work.primary.EI.E.clear']],
            'user_state' => ['is_first_view' => true],
            'orchestration' => ['primary_focus_key' => 'growth.next_actions'],
            'continuity' => ['carryover_focus_key' => 'growth.next_actions'],
            'longitudinal_memory_v1' => [
                'memory_contract_version' => 'mbti.longitudinal_memory.v1',
                'memory_fingerprint' => 'fixture-memory-fingerprint',
                'memory_scope' => 'identity_recent_mbti_window',
                'memory_state' => 'resume_ready',
                'progression_state' => 'reading_loop',
            ],
            'ordered_recommendation_keys' => ['read-action'],
            'reading_focus_key' => 'read-action',
            'cta_bundle_v1' => [
                'bundle_key' => 'cta_bundle_growth',
                'cta_intent' => 'growth',
                'softness_mode' => 'guided',
                'entry_reason' => 'growth_clarity_loop',
            ],
            'working_life_v1' => ['career_focus_key' => 'career.next_step'],
            'career_focus_key' => 'career.next_step',
        ]);

        $this->assertSame('mbti.read_contract.v1', $contract['version']);
        $this->assertContains('identity', $contract['canonical_read_model']['personalization_fields']);
        $this->assertContains('privacy_contract_v1', $contract['canonical_read_model']['personalization_fields']);
        $this->assertContains('comparative_v1', $contract['canonical_read_model']['personalization_fields']);
        $this->assertContains('controlled_narrative_v1', $contract['canonical_read_model']['personalization_fields']);
        $this->assertContains('narrative_runtime_contract_v1', $contract['canonical_read_model']['personalization_fields']);
        $this->assertContains('cultural_calibration_v1', $contract['canonical_read_model']['personalization_fields']);
        $this->assertNotContains('variant_keys', $contract['canonical_read_model']['personalization_fields']);
        $this->assertNotContains('user_state', $contract['canonical_read_model']['personalization_fields']);
        $this->assertContains('mbti_privacy_contract_v1', $contract['cacheable_fields']);
        $this->assertContains('comparative_v1', $contract['cacheable_fields']);
        $this->assertContains('controlled_narrative_v1', $contract['cacheable_fields']);
        $this->assertContains('narrative_runtime_contract_v1', $contract['cacheable_fields']);
        $this->assertContains('cultural_calibration_v1', $contract['cacheable_fields']);
        $this->assertSame(
            [
                'user_state',
                'orchestration',
                'sections',
                'variant_keys',
                'ordered_recommendation_keys',
                'ordered_action_keys',
                'recommendation_priority_keys',
                'action_priority_keys',
                'reading_focus_key',
                'action_focus_key',
                'action_journey_v1',
                'pulse_check_v1',
                'longitudinal_memory_v1',
                'adaptive_selection_v1',
                'continuity',
                'cross_assessment_v1',
                'synthesis_keys',
                'supporting_scales',
                'big5_influence_keys',
                'mbti_adjusted_focus_keys',
                'working_life_v1',
                'career_focus_key',
                'career_journey_keys',
                'career_action_priority_keys',
                'intra_type_profile_v1',
                'profile_seed_key',
                'same_type_divergence_keys',
                'section_selection_keys',
                'action_selection_keys',
                'recommendation_selection_keys',
                'cta_bundle_v1',
                'selection_fingerprint',
                'selection_evidence',
            ],
            $contract['overlay_patch']['personalization_fields']
        );
        $this->assertContains('report.sections', $contract['cacheable_fields']);
        $this->assertContains('report._meta.personalization.user_state', $contract['non_cacheable_fields']);
        $this->assertContains('mbti_public_projection_v1._meta.personalization.continuity', $contract['non_cacheable_fields']);
        $this->assertContains('user_state', $contract['telemetry_parity_fields']);
        $this->assertContains('continuity.carryover_focus_key', $contract['telemetry_parity_fields']);
        $this->assertContains('orchestration.cta_bundle_key', $contract['telemetry_parity_fields']);
        $this->assertContains('cta_bundle_v1.bundle_key', $contract['telemetry_parity_fields']);
        $this->assertContains('action_journey_v1.journey_state', $contract['telemetry_parity_fields']);
        $this->assertContains('pulse_check_v1.pulse_state', $contract['telemetry_parity_fields']);
        $this->assertContains('longitudinal_memory_v1.memory_contract_version', $contract['telemetry_parity_fields']);
        $this->assertContains('longitudinal_memory_v1.memory_fingerprint', $contract['telemetry_parity_fields']);
        $this->assertContains('longitudinal_memory_v1.memory_state', $contract['telemetry_parity_fields']);
        $this->assertContains('longitudinal_memory_v1.progression_state', $contract['telemetry_parity_fields']);
        $this->assertContains('longitudinal_memory_v1.memory_rewrite_reason', $contract['telemetry_parity_fields']);
        $this->assertContains('adaptive_selection_v1.adaptive_contract_version', $contract['telemetry_parity_fields']);
        $this->assertContains('adaptive_selection_v1.adaptive_fingerprint', $contract['telemetry_parity_fields']);
        $this->assertContains('adaptive_selection_v1.selection_rewrite_reason', $contract['telemetry_parity_fields']);
        $this->assertContains('adaptive_selection_v1.action_effect_weights', $contract['telemetry_parity_fields']);
        $this->assertContains('adaptive_selection_v1.recommendation_effect_weights', $contract['telemetry_parity_fields']);
        $this->assertContains('adaptive_selection_v1.next_best_action_v1.key', $contract['telemetry_parity_fields']);
        $this->assertContains('working_life_v1.career_focus_key', $contract['telemetry_parity_fields']);
        $this->assertContains('intra_type_profile_v1.version', $contract['telemetry_parity_fields']);
        $this->assertContains('intra_type_profile_v1.profile_seed_key', $contract['telemetry_parity_fields']);
        $this->assertContains('intra_type_profile_v1.same_type_divergence_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('intra_type_profile_v1.section_selection_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('intra_type_profile_v1.action_selection_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('intra_type_profile_v1.recommendation_selection_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('intra_type_profile_v1.selection_fingerprint', $contract['telemetry_parity_fields']);
        $this->assertContains('profile_seed_key', $contract['telemetry_parity_fields']);
        $this->assertContains('same_type_divergence_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('section_selection_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('action_selection_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('recommendation_selection_keys', $contract['telemetry_parity_fields']);
        $this->assertContains('selection_fingerprint', $contract['telemetry_parity_fields']);
        $this->assertContains('comparative_v1.percentile.value', $contract['telemetry_parity_fields']);
        $this->assertContains('comparative_v1.norming_version', $contract['telemetry_parity_fields']);
        $this->assertContains('narrative_runtime_contract_v1.runtime_mode', $contract['telemetry_parity_fields']);
        $this->assertContains('cultural_calibration_v1.locale_context', $contract['telemetry_parity_fields']);
        $this->assertContains('cultural_calibration_v1.calibration_fingerprint', $contract['telemetry_parity_fields']);
    }

    public function test_apply_overlay_patch_preserves_canonical_fields_and_replaces_only_overlay_fields(): void
    {
        $service = app(MbtiReadModelContractService::class);

        $canonical = [
            'identity' => 'A',
            'action_plan_summary' => 'Canonical summary',
            'user_state' => ['is_first_view' => true],
            'orchestration' => ['primary_focus_key' => 'growth.next_actions'],
            'continuity' => ['carryover_focus_key' => 'growth.next_actions'],
            'ordered_recommendation_keys' => ['read-action'],
            'working_life_v1' => ['career_focus_key' => 'career.next_step'],
        ];

        $effective = [
            'identity' => 'T',
            'action_plan_summary' => 'Overlay must not rewrite canonical text',
            'user_state' => ['is_first_view' => false, 'is_revisit' => true],
            'orchestration' => ['primary_focus_key' => 'traits.close_call_axes'],
            'action_journey_v1' => ['journey_state' => 'resume_action_loop'],
            'pulse_check_v1' => ['pulse_state' => 'reinforce'],
            'continuity' => ['carryover_focus_key' => 'traits.close_call_axes'],
            'ordered_recommendation_keys' => ['read-explain'],
            'longitudinal_memory_v1' => ['memory_state' => 'resume_ready'],
            'cta_bundle_v1' => ['bundle_key' => 'cta_bundle_revisit'],
            'working_life_v1' => ['career_focus_key' => 'career.work_experiments'],
        ];

        $merged = $service->applyOverlayPatch($canonical, $effective);

        $this->assertSame('A', $merged['identity']);
        $this->assertSame('Canonical summary', $merged['action_plan_summary']);
        $this->assertSame(['is_first_view' => false, 'is_revisit' => true], $merged['user_state']);
        $this->assertSame(['primary_focus_key' => 'traits.close_call_axes'], $merged['orchestration']);
        $this->assertSame(['journey_state' => 'resume_action_loop'], $merged['action_journey_v1']);
        $this->assertSame(['pulse_state' => 'reinforce'], $merged['pulse_check_v1']);
        $this->assertSame(['memory_state' => 'resume_ready'], $merged['longitudinal_memory_v1']);
        $this->assertSame(['bundle_key' => 'cta_bundle_revisit'], $merged['cta_bundle_v1']);
        $this->assertSame(['carryover_focus_key' => 'traits.close_call_axes'], $merged['continuity']);
        $this->assertSame(['read-explain'], $merged['ordered_recommendation_keys']);
        $this->assertSame(['career_focus_key' => 'career.work_experiments'], $merged['working_life_v1']);
        $this->assertSame('mbti.read_contract.v1', data_get($merged, 'read_contract_v1.version'));
    }
}
