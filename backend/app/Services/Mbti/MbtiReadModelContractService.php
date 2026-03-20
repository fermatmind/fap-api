<?php

declare(strict_types=1);

namespace App\Services\Mbti;

final class MbtiReadModelContractService
{
    private const VERSION = 'mbti.read_contract.v1';

    /**
     * @var list<string>
     */
    private const OVERLAY_PERSONALIZATION_FIELDS = [
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
    ];

    /**
     * @var list<string>
     */
    private const CANONICAL_SURFACE_FIELDS = [
        'report.summary',
        'report.profile',
        'report.identity_card',
        'report.layers',
        'report.sections',
        'report.recommended_reads',
        'report.highlights',
        'mbti_public_summary_v1',
        'mbti_privacy_contract_v1',
        'mbti_public_projection_v1.summary_card',
        'mbti_public_projection_v1.profile',
        'mbti_public_projection_v1.dimensions',
        'mbti_public_projection_v1.sections',
    ];

    /**
     * @var list<string>
     */
    private const CACHEABLE_FIELDS = [
        'report',
        'report.summary',
        'report.profile',
        'report.identity_card',
        'report.layers',
        'report.sections',
        'report.recommended_reads',
        'report.highlights',
        'mbti_public_summary_v1',
        'mbti_privacy_contract_v1',
        'mbti_public_projection_v1',
        'mbti_public_projection_v1.summary_card',
        'mbti_public_projection_v1.profile',
        'mbti_public_projection_v1.dimensions',
        'mbti_public_projection_v1.sections',
        'mbti_read_contract_v1',
    ];

    /**
     * @var list<string>
     */
    private const TELEMETRY_PARITY_FIELDS = [
        'user_state',
        'orchestration.primary_focus_key',
        'orchestration.secondary_focus_keys',
        'orchestration.cta_priority_keys',
        'continuity.carryover_focus_key',
        'continuity.carryover_reason',
        'continuity.recommended_resume_keys',
        'ordered_recommendation_keys',
        'ordered_action_keys',
        'recommendation_priority_keys',
        'action_priority_keys',
        'reading_focus_key',
        'action_focus_key',
        'cross_assessment_v1.synthesis_keys',
        'cross_assessment_v1.supporting_scales',
        'cross_assessment_v1.big5_influence_keys',
        'cross_assessment_v1.mbti_adjusted_focus_keys',
        'working_life_v1.career_focus_key',
        'working_life_v1.career_journey_keys',
        'working_life_v1.career_action_priority_keys',
    ];

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function attachContract(array $personalization): array
    {
        if ($personalization === []) {
            return [];
        }

        $personalization['read_contract_v1'] = $this->buildContract($personalization);

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $canonicalPersonalization
     * @param  array<string, mixed>  $effectivePersonalization
     * @return array<string, mixed>
     */
    public function applyOverlayPatch(array $canonicalPersonalization, array $effectivePersonalization): array
    {
        if ($canonicalPersonalization === []) {
            return $this->attachContract($effectivePersonalization);
        }

        $patched = $canonicalPersonalization;

        foreach (self::OVERLAY_PERSONALIZATION_FIELDS as $field) {
            if (! array_key_exists($field, $effectivePersonalization)) {
                continue;
            }

            $patched[$field] = $effectivePersonalization[$field];
        }

        return $this->attachContract($patched);
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function buildContract(array $personalization): array
    {
        $canonicalPersonalizationFields = array_values(array_filter(
            array_keys($personalization),
            static fn (string $field): bool => $field !== 'read_contract_v1'
                && ! in_array($field, self::OVERLAY_PERSONALIZATION_FIELDS, true)
        ));

        $nonCacheableFields = [];
        foreach (self::OVERLAY_PERSONALIZATION_FIELDS as $field) {
            $nonCacheableFields[] = "report._meta.personalization.{$field}";
            $nonCacheableFields[] = "mbti_public_projection_v1._meta.personalization.{$field}";
        }

        return [
            'version' => self::VERSION,
            'canonical_read_model' => [
                'personalization_fields' => $canonicalPersonalizationFields,
                'surface_fields' => self::CANONICAL_SURFACE_FIELDS,
                'sources' => [
                    'report_snapshot',
                    'report_projection',
                ],
            ],
            'overlay_patch' => [
                'personalization_fields' => self::OVERLAY_PERSONALIZATION_FIELDS,
                'surface_fields' => $nonCacheableFields,
                'sources' => [
                    'attempt_access',
                    'attempt_events',
                    'share_rows',
                ],
            ],
            'cacheable_fields' => self::CACHEABLE_FIELDS,
            'non_cacheable_fields' => $nonCacheableFields,
            'telemetry_parity_fields' => self::TELEMETRY_PARITY_FIELDS,
        ];
    }
}
