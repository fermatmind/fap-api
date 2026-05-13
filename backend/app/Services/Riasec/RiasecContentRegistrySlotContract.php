<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecContentRegistrySlotContract
{
    public const SCHEMA_VERSION = 'riasec.content_registry_slot_contract.v1';

    /** @var list<string> */
    private const SLOTS = [
        'dimension_copy',
        'pair_blend_copy',
        'triad_blend_copy',
        'shadow_cost_copy',
        '140q_task_card_copy',
        '140q_environment_card_copy',
        '140q_role_card_copy',
        'low_quality_copy',
        'structural_difference_copy',
        'aspiration_calibration_copy',
        'feedback_response_copy',
        'module_boundary_copy',
    ];

    /** @var list<string> */
    private const FORBIDDEN_FIELDS = [
        'career_match',
        'occupation_match',
        'job_fit',
        'fit_score',
        'success_prediction',
        'ranking',
        'recommended_career',
        'source_url',
        'soc_code',
        'onet_code',
    ];

    /**
     * @return array<string,mixed>
     */
    public function schema(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slot_status' => 'readiness_contract_only',
            'runtime_public_copy_included' => false,
            'missing_content_policy' => 'omit_module_fail_closed',
            'unknown_slot_policy' => 'reject',
            'frontend_fallback_allowed' => false,
            'required_fields' => ['slot', 'version', 'locale', 'owner', 'evidence_level', 'status'],
            'allowed_statuses' => ['draft', 'reviewed', 'approved', 'deprecated'],
            'allowed_evidence_levels' => ['content_example', 'theory_based', 'expert_reviewed', 'validated'],
            'slots' => array_map(
                fn (string $slot): array => $this->slotDefinition($slot),
                self::SLOTS
            ),
            'forbidden_fields' => self::FORBIDDEN_FIELDS,
        ];
    }

    /**
     * @param  array<string,mixed>  $slot
     * @return array{ok:bool,errors:list<string>}
     */
    public function validate(array $slot): array
    {
        $errors = [];
        $slotName = trim((string) ($slot['slot'] ?? ''));
        if (! in_array($slotName, self::SLOTS, true)) {
            $errors[] = 'unsupported_slot';
        }

        foreach (['version', 'locale', 'owner', 'evidence_level', 'status'] as $required) {
            if (trim((string) ($slot[$required] ?? '')) === '') {
                $errors[] = 'missing_'.$required;
            }
        }

        if (! in_array((string) ($slot['status'] ?? ''), ['draft', 'reviewed', 'approved', 'deprecated'], true)) {
            $errors[] = 'unsupported_status';
        }
        if (! in_array((string) ($slot['evidence_level'] ?? ''), ['content_example', 'theory_based', 'expert_reviewed', 'validated'], true)) {
            $errors[] = 'unsupported_evidence_level';
        }

        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $slot)) {
                $errors[] = 'forbidden_field_'.$field;
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function slotDefinition(string $slot): array
    {
        return [
            'slot' => $slot,
            'version_required' => true,
            'locale_required' => true,
            'owner_required' => true,
            'evidence_level_required' => true,
            'status_required' => true,
            'missing_behavior' => 'omit_module',
            'public_runtime_authority' => 'backend_or_cms_registry_only',
        ];
    }
}
