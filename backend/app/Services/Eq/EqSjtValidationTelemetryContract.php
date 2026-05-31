<?php

declare(strict_types=1);

namespace App\Services\Eq;

final class EqSjtValidationTelemetryContract
{
    private const FORBIDDEN_CLAIM_FRAGMENTS = [
        'true emotional ability',
        'true eq ability',
        'msceit-like',
        'msceit equivalent',
        'certified emotional intelligence',
        'certified ei',
        'hiring suitable',
        'clinical assessment',
        'predicts job performance',
    ];

    /**
     * @param  array<string,mixed>  $score
     * @param  array<string,mixed>  $context
     * @return array{event_code:string,meta:array<string,mixed>,context:array<string,mixed>}
     */
    public function scoredEvent(array $score, array $context = []): array
    {
        return [
            'event_code' => 'eq_sjt16_scored',
            'meta' => [
                'scale_code' => 'EQ_SJT_16',
                'measurement_type' => 'scenario_based_emotional_judgment',
                'answer_mode' => $this->string($score['answer_mode'] ?? null) ?: 'likely_response',
                'score_method' => $this->string($score['score_method'] ?? null),
                'score_pct' => $this->number($score['score_pct'] ?? null),
                'band' => $this->string($score['band'] ?? null),
                'quality_level' => $this->string(data_get($score, 'quality.level')),
                'quality_flags' => array_values(array_map('strval', (array) data_get($score, 'quality.flags', []))),
                'top_strategy' => $this->string($score['top_strategy'] ?? null),
                'lowest_strategy' => $this->string($score['lowest_strategy'] ?? null),
                'content_version' => $this->string(data_get($score, 'version_snapshot.content_version')) ?: 'EQ_SJT_16/v1',
                'rubric_version' => $this->string(data_get($score, 'version_snapshot.rubric_version')) ?: 'eq_sjt_16.rubric.v1_draft',
                'validation_status' => 'draft_not_yet_validated',
                'stable_validation_claim_allowed' => false,
                'claim_boundary' => $this->claimBoundary($score),
            ],
            'context' => $this->safeContext($context, 'EQ_SJT_16'),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $context
     * @return array{event_code:string,meta:array<string,mixed>,context:array<string,mixed>}
     */
    public function integratedReportComposedEvent(array $report, array $context = []): array
    {
        return [
            'event_code' => 'eq_integrated_report_composed',
            'meta' => [
                'scale_code' => 'EQ_INTEGRATED',
                'eq_report_mode' => 'integrated',
                'measurement_type' => 'integrated_self_report_and_scenario_judgment',
                'gap_count' => count((array) data_get($report, 'interpretation.gap_map', [])),
                'pressure_pattern_id' => $this->string(data_get($report, 'interpretation.pressure_pattern.pattern_id')),
                'scenario_script_count' => count((array) data_get($report, 'interpretation.scenario_script_ids', [])),
                'integrated_action_duration_days' => (int) data_get($report, 'interpretation.integrated_action_path.duration_days', 0),
                'report_version' => $this->string(data_get($report, 'methodology.report_version')),
                'validation_status' => $this->string(data_get($report, 'methodology.validation_status')) ?: 'draft_not_yet_validated',
                'public_runtime_enabled' => (bool) data_get($report, 'visibility.public_runtime_enabled', false),
                'frontend_integrated_report_visible' => (bool) data_get($report, 'visibility.frontend_integrated_report_visible', false),
                'stable_validation_claim_allowed' => false,
                'claim_boundary' => $this->claimBoundary($report),
            ],
            'context' => $this->safeContext($context, 'EQ_INTEGRATED'),
        ];
    }

    /**
     * @param  array<string,mixed>  $score
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    public function qaGate(array $score, array $report): array
    {
        $issues = [];
        $issues = array_merge($issues, $this->claimBoundaryIssues($score, 'score'));
        $issues = array_merge($issues, $this->claimBoundaryIssues($report, 'integrated_report'));

        if ((bool) data_get($report, 'visibility.public_runtime_enabled', false)) {
            $issues[] = 'integrated_report_public_runtime_enabled';
        }
        if ((bool) data_get($report, 'visibility.frontend_integrated_report_visible', false)) {
            $issues[] = 'integrated_report_frontend_visible_before_release';
        }
        if ($this->string(data_get($report, 'methodology.validation_status')) !== 'draft_not_yet_validated') {
            $issues[] = 'integrated_report_validation_status_overclaims';
        }

        return [
            'status' => $issues === [] ? 'pass_for_internal_qa_only' : 'blocked',
            'public_release_allowed' => false,
            'stable_validation_claim_allowed' => false,
            'issues' => array_values(array_unique($issues)),
            'required_next_evidence' => [
                'expert_rubric_calibration',
                'locale_bias_review',
                'scenario_item_pilot_statistics',
                'strategy_score_reliability_review',
                'integrated_report_rendered_qa',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function forbiddenClaimFragments(): array
    {
        return self::FORBIDDEN_CLAIM_FRAGMENTS;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function claimBoundaryIssues(array $payload, string $prefix): array
    {
        $issues = [];
        $encoded = strtolower((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (self::FORBIDDEN_CLAIM_FRAGMENTS as $fragment) {
            if (str_contains($encoded, strtolower($fragment))) {
                $issues[] = "{$prefix}_forbidden_claim_".str_replace([' ', '-'], '_', strtolower($fragment));
            }
        }

        $boundary = $this->claimBoundary($payload);
        foreach ([
            'not_clinical',
            'not_hiring',
            'not_certified_capability_evaluation',
            'not_msceit_equivalent',
        ] as $required) {
            if (($boundary[$required] ?? false) !== true) {
                $issues[] = "{$prefix}_missing_{$required}";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,bool>
     */
    private function claimBoundary(array $payload): array
    {
        $boundary = (array) ($payload['claim_boundary'] ?? []);

        return [
            'not_clinical' => (bool) ($boundary['not_clinical'] ?? false),
            'not_hiring' => (bool) ($boundary['not_hiring'] ?? false),
            'not_certified_capability_evaluation' => (bool) ($boundary['not_certified_capability_evaluation'] ?? false),
            'not_msceit_equivalent' => (bool) ($boundary['not_msceit_equivalent'] ?? false),
            'not_true_emotional_ability_score' => (bool) ($boundary['not_true_emotional_ability_score'] ?? true),
            'does_not_predict_job_performance' => (bool) ($boundary['does_not_predict_job_performance'] ?? true),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function safeContext(array $context, string $scaleCode): array
    {
        return [
            'scale_code' => $scaleCode,
            'attempt_id' => $this->string($context['attempt_id'] ?? null),
            'anon_id' => $this->string($context['anon_id'] ?? null),
            'user_id' => is_numeric($context['user_id'] ?? null) ? (int) $context['user_id'] : null,
            'locale' => $this->string($context['locale'] ?? null),
            'region' => $this->string($context['region'] ?? null),
            'org_id' => is_numeric($context['org_id'] ?? null) ? (int) $context['org_id'] : 0,
        ];
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
