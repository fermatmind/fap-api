<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormPrivacyPolicy
{
    public const POLICY_VERSION = 'big5_norm_privacy.v0.1';

    /**
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            'policy_version' => self::POLICY_VERSION,
            'capture_default' => 'internal_only',
            'public_exposure' => 'disabled',
            'runtime_attachment' => 'disabled',
            'requires_explicit_consent' => true,
            'requires_revoke_handling' => true,
            'small_cell_minimum' => 50,
            'retention_policy' => [
                'observation_retention' => 'limited_governance_window',
                'expired_record_behavior' => 'exclude_from_future_aggregation',
                'append_only_delete_strategy' => 'retain_observation_with_suppressed_subject_linkage',
            ],
            'dsar_policy' => [
                'locate_by_privacy_subject_key' => true,
                'raw_identifier_lookup' => 'not_supported_in_observation_table',
                'delete_behavior' => 'append_tombstone_or_exclusion_marker_in_future_governance_layer',
                'aggregation_behavior_after_revoke' => 'exclude_from_future_snapshots',
            ],
        ];
    }

    public function canPublishCell(int $cellCount): bool
    {
        return $cellCount >= (int) $this->defaults()['small_cell_minimum'];
    }

    /**
     * @param  array<string,mixed>  $observationPayload
     */
    public function hasPublicExposureRisk(array $observationPayload): bool
    {
        foreach (['subject_key', 'stable_subject_reference', 'consent_record_reference', 'contact_address', 'telephone_number'] as $key) {
            if (array_key_exists($key, $observationPayload)) {
                return true;
            }
        }

        return false;
    }
}
