<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecContentRegistrySlotContract;
use PHPUnit\Framework\TestCase;

final class RiasecContentRegistrySlotContractTest extends TestCase
{
    public function test_schema_defines_deep_copy_slots_without_public_copy_runtime(): void
    {
        $schema = (new RiasecContentRegistrySlotContract)->schema();

        $this->assertSame(RiasecContentRegistrySlotContract::SCHEMA_VERSION, $schema['schema_version']);
        $this->assertSame('RIASEC', $schema['scale_code']);
        $this->assertSame('schema_contract_only', $schema['slot_status']);
        $this->assertFalse($schema['runtime_public_copy_included']);
        $this->assertFalse($schema['frontend_fallback_allowed']);
        $this->assertSame('omit_module_fail_closed', $schema['missing_content_policy']);

        $slotKeys = array_column($schema['slots'], 'slot_key');
        $this->assertContains('interpretation_rule_spec_v2', $slotKeys);
        $this->assertContains('quality_rule_spec_v2', $slotKeys);
        $this->assertContains('module_visibility_policy', $slotKeys);
        $this->assertContains('dimension_deep_copy', $slotKeys);
        $this->assertContains('core_drive_cost_shadow_copy', $slotKeys);
        $this->assertContains('pair_blend_copy', $slotKeys);
        $this->assertContains('140q_task_card_copy', $slotKeys);
        $this->assertContains('140q_environment_card_copy', $slotKeys);
        $this->assertContains('140q_role_card_copy', $slotKeys);
        $this->assertContains('140q_tension_copy', $slotKeys);
        $this->assertContains('low_quality_copy', $slotKeys);
        $this->assertContains('cautious_reading_copy', $slotKeys);
        $this->assertContains('structural_difference_copy', $slotKeys);
        $this->assertContains('aspirations_calibration_copy', $slotKeys);
        $this->assertContains('disagree_path_copy', $slotKeys);
        $this->assertContains('feedback_response_copy', $slotKeys);
    }

    public function test_validator_accepts_backend_governed_schema_fixture(): void
    {
        $result = (new RiasecContentRegistrySlotContract)->validate($this->validSlot([
            'slot_key' => 'dimension_deep_copy',
            'slot_group' => 'dimension_deep_copy',
            'applicable_dimensions' => ['I'],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function test_validator_requires_metadata_and_applicability_fields(): void
    {
        $result = (new RiasecContentRegistrySlotContract)->validate([
            'slot_key' => 'low_quality_copy',
            'slot_group' => 'quality_copy',
            'scale_code' => 'RIASEC',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('missing_content_version', $result['errors']);
        $this->assertContains('missing_locale', $result['errors']);
        $this->assertContains('missing_applicable_form_codes', $result['errors']);
        $this->assertContains('missing_applicable_profile_shapes', $result['errors']);
        $this->assertContains('missing_applicable_quality_states', $result['errors']);
        $this->assertContains('missing_applicable_codes_or_dimensions', $result['errors']);
        $this->assertContains('missing_quality_rule_version', $result['errors']);
    }

    public function test_validator_rejects_unknown_slot_group_and_frontend_fallback(): void
    {
        $result = (new RiasecContentRegistrySlotContract)->validate($this->validSlot([
            'slot_key' => 'career_recommendation_copy',
            'slot_group' => 'career_matching_copy',
            'fallback_behavior' => 'frontend_fallback',
            'applicable_codes' => ['IAS'],
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('unsupported_slot_key', $result['errors']);
        $this->assertContains('unsupported_slot_group', $result['errors']);
        $this->assertContains('unsupported_fallback_behavior', $result['errors']);
        $this->assertContains('frontend_fallback_forbidden', $result['errors']);
    }

    public function test_validator_rejects_forbidden_claim_fields_and_language(): void
    {
        $result = (new RiasecContentRegistrySlotContract)->validate($this->validSlot([
            'slot_key' => 'pair_blend_copy',
            'slot_group' => 'pair_blend_copy',
            'applicable_codes' => ['IA'],
            'career_match' => true,
            'source_url' => 'https://example.test/unreviewed-source',
            'body' => 'This fixture intentionally contains job fit, success probability, and 140Q more accurate claims.',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('forbidden_field_career_match', $result['errors']);
        $this->assertContains('forbidden_field_source_url', $result['errors']);
        $this->assertContains('forbidden_claim_phrase_job_fit', $result['errors']);
        $this->assertContains('forbidden_claim_phrase_success_probability', $result['errors']);
        $this->assertContains('forbidden_claim_phrase_140q_more_accurate', $result['errors']);
    }

    public function test_missing_content_behavior_fails_closed_without_frontend_copy(): void
    {
        $contract = new RiasecContentRegistrySlotContract;

        $known = $contract->missingContentBehavior('pair_blend_copy');
        $this->assertTrue($known['exists']);
        $this->assertSame('omit_module', $known['behavior']);
        $this->assertSame('omitted', $known['module_state']);
        $this->assertFalse($known['frontend_fallback_allowed']);

        $unknown = $contract->missingContentBehavior('unknown_deep_copy_slot');
        $this->assertFalse($unknown['exists']);
        $this->assertSame('reject_payload', $unknown['behavior']);
        $this->assertSame('hidden', $unknown['module_state']);
        $this->assertFalse($unknown['frontend_fallback_allowed']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validSlot(array $overrides = []): array
    {
        return array_merge([
            'slot_key' => 'dimension_deep_copy',
            'slot_group' => 'dimension_deep_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => 'v0.1-schema-fixture',
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_dimensions' => ['I'],
            'title' => 'Schema fixture title',
            'summary' => 'Schema fixture summary',
            'body' => 'Schema fixture body for validator coverage only.',
            'forbidden_claims' => ['career_outcome_claims_forbidden'],
            'required_boundaries' => [
                'interest_evidence_only',
                'not_career_recommendation',
                'not_job_fit',
                'not_success_prediction',
                'not_ability_or_skill_measure',
                'no_60q_140q_raw_delta',
                '140q_contextual_not_more_accurate',
                'feedback_does_not_mutate_measured_result',
                'missing_content_fails_closed',
                'frontend_fallback_forbidden',
            ],
            'evidence_level' => 'theory_based',
            'source_status' => 'placeholder_fixture',
            'review_status' => 'fixture_only',
            'fallback_behavior' => 'omit_module',
        ], $overrides);
    }
}
