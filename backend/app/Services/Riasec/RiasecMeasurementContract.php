<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecMeasurementContract
{
    public const SCHEMA_VERSION = 'riasec.measurement_contract.v1';

    public const COMPARE_POLICY_VERSION = 'riasec.compare_policy.v1';

    public const STANDARD_60_SCORE_SPACE = 'riasec_60_likert5_activity_sum_space.v1';

    public const ENHANCED_140_SCORE_SPACE = 'riasec_140_likert5_activity_context_space.v1';

    /**
     * @return array<string,mixed>
     */
    public function forFormCode(?string $formCode, ?int $questionCount = null): array
    {
        $canonical = $this->canonicalFormCode($formCode, $questionCount);
        $isEnhanced = $canonical === 'riasec_140';
        $scoreSpaceVersion = $isEnhanced ? self::ENHANCED_140_SCORE_SPACE : self::STANDARD_60_SCORE_SPACE;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scale_code' => 'RIASEC',
            'measured_signal_kind' => 'career_interest',
            'form' => [
                'form_code' => $canonical,
                'question_count' => $isEnhanced ? 140 : 60,
                'form_kind' => $isEnhanced ? 'enhanced_contextual' : 'standard',
                'score_space_version' => $scoreSpaceVersion,
                'score_space_label' => $isEnhanced
                    ? '140Q contextual daily-work interest signal'
                    : '60Q career interest signal',
            ],
            'scoring' => [
                'normalization_method' => $isEnhanced
                    ? 'activity_environment_role_weighted_0_100'
                    : 'raw_sum_per_dimension_min10_max50_to_0_100',
                'raw_score_delta_allowed' => false,
                'cross_form_raw_delta_allowed' => false,
            ],
            'quality' => [
                'low_quality_strength' => $isEnhanced ? 'caution_flags_available' : 'not_available_for_strong_low_quality',
                'quality_rule_status' => $isEnhanced ? 'quality_flags_available' : 'minimal_answer_completion_only',
            ],
            'claim_boundary' => [
                'measures' => ['career_interest'],
                'does_not_measure' => ['ability', 'personality', 'values', 'career_success_probability', 'job_fit'],
                'occupation_examples_policy' => 'content_example_not_registry_match_without_reviewed_registry_source',
            ],
            'compare_policy' => $this->comparePolicyForFormCode($canonical, $questionCount),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function comparePolicyForFormCode(?string $formCode, ?int $questionCount = null): array
    {
        $canonical = $this->canonicalFormCode($formCode, $questionCount);
        $scoreSpaceVersion = $canonical === 'riasec_140'
            ? self::ENHANCED_140_SCORE_SPACE
            : self::STANDARD_60_SCORE_SPACE;

        return [
            'version' => self::COMPARE_POLICY_VERSION,
            'scale_code' => 'RIASEC',
            'form_code' => $canonical,
            'score_space_version' => $scoreSpaceVersion,
            'compare_compatibility_group' => $this->compareCompatibilityGroup($canonical, $scoreSpaceVersion),
            'cross_form_comparable' => false,
            'raw_score_delta_allowed' => false,
            'copy_key' => 'riasec.compare.same_form_only',
        ];
    }

    public function scoreSpaceVersion(?string $formCode, ?int $questionCount = null): string
    {
        return (string) $this->comparePolicyForFormCode($formCode, $questionCount)['score_space_version'];
    }

    public function compareCompatibilityGroup(?string $formCode, ?string $scoreSpaceVersion = null): string
    {
        $canonical = $this->canonicalFormCode($formCode);
        $space = trim((string) ($scoreSpaceVersion ?? $this->scoreSpaceVersion($canonical)));

        return 'RIASEC:'.$canonical.':'.$space;
    }

    public function canonicalFormCode(?string $formCode, ?int $questionCount = null): string
    {
        $normalized = strtolower(trim((string) $formCode));
        if (in_array($normalized, ['riasec_140', '140', 'enhanced', 'v1-enhanced-140'], true)) {
            return 'riasec_140';
        }

        if (in_array($normalized, ['riasec_60', '60', 'standard', 'v1-standard-60'], true)) {
            return 'riasec_60';
        }

        return ((int) ($questionCount ?? 0)) >= 140 ? 'riasec_140' : 'riasec_60';
    }
}
