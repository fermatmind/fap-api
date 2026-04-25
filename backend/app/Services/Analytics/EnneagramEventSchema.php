<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use InvalidArgumentException;

final class EnneagramEventSchema
{
    /**
     * @var list<string>
     */
    private const BASE_DIMENSIONS = [
        'scale_code',
        'form_code',
        'form_kind',
        'score_space_version',
        'interpretation_scope',
        'confidence_level',
        'close_call_pair',
        'primary_candidate',
        'second_candidate',
        'third_candidate',
        'compare_compatibility_group',
        'cross_form_comparable',
        'interpretation_context_id',
        'content_release_hash',
        'registry_release_hash',
        'projection_version',
        'report_schema_version',
        'close_call_rule_version',
        'confidence_policy_version',
        'quality_policy_version',
    ];

    /**
     * @var array<string,list<string>>
     */
    private const EVENT_SPECIFIC_DIMENSIONS = [
        'enneagram_attempt_started' => [],
        'enneagram_form_selected' => [],
        'enneagram_attempt_submitted' => [],
        'enneagram_result_viewed' => [],
        'enneagram_report_viewed' => [],
        'enneagram_instant_summary_viewed' => [],
        'enneagram_close_call_exposed' => [],
        'enneagram_close_call_expanded' => [],
        'enneagram_observation_assigned' => ['observation_status', 'suggested_next_action'],
        'enneagram_day3_feedback_submitted' => ['observation_status', 'suggested_next_action'],
        'enneagram_day7_feedback_submitted' => ['observation_status', 'suggested_next_action'],
        'enneagram_resonance_feedback_submitted' => ['observation_status', 'suggested_next_action'],
        'enneagram_user_confirmed_type' => ['observation_status', 'suggested_next_action'],
        'enneagram_fc144_recommended' => ['observation_status', 'suggested_next_action'],
        'enneagram_fc144_started' => [],
        'enneagram_fc144_completed' => [],
        'enneagram_share_clicked' => [],
        'enneagram_history_viewed' => [],
        'enneagram_retake_clicked' => [],
        'enneagram_cross_form_compare_blocked' => ['observation_status', 'suggested_next_action'],
        'enneagram_pdf_downloaded' => [],
    ];

    /**
     * @return list<array<string,mixed>>
     */
    public function catalog(): array
    {
        $rows = [];
        foreach (array_keys(self::EVENT_SPECIFIC_DIMENSIONS) as $eventCode) {
            $rows[] = [
                'event_code' => $eventCode,
                'dimensions' => $this->dimensionsFor($eventCode),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function validate(string $eventCode, array $meta): array
    {
        $normalizedCode = strtolower(trim($eventCode));
        if (! isset(self::EVENT_SPECIFIC_DIMENSIONS[$normalizedCode])) {
            throw new InvalidArgumentException('Unsupported ENNEAGRAM event code: '.$eventCode);
        }

        foreach ($this->dimensionsFor($normalizedCode) as $dimension) {
            if (! array_key_exists($dimension, $meta)) {
                throw new InvalidArgumentException('Missing ENNEAGRAM event meta key: '.$dimension);
            }
        }

        if (strtoupper(trim((string) ($meta['scale_code'] ?? ''))) !== 'ENNEAGRAM') {
            throw new InvalidArgumentException('ENNEAGRAM event scale_code must be ENNEAGRAM');
        }

        return $meta;
    }

    /**
     * @return list<string>
     */
    private function dimensionsFor(string $eventCode): array
    {
        return array_values(array_unique(array_merge(
            self::BASE_DIMENSIONS,
            self::EVENT_SPECIFIC_DIMENSIONS[$eventCode] ?? [],
        )));
    }
}
