<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class ConfidenceScoreCalculator
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array{integrity_state:string,critical_missing_fields:list<string>,confidence_cap:int}  $integrity
     */
    public function calculate(array $context, array $integrity, DegradationPolicy $degradationPolicy): CareerScoreResult
    {
        $inputs = [
            'source_coverage' => $this->sourceCoverage($context),
            'freshness' => $this->freshness($context),
            'crosswalk_quality' => ScoreMath::normalizeNullable($context['crosswalk_confidence'] ?? null, 0.55),
            'reviewer_state' => $this->reviewerStateScore($context),
            'psychometric_axis_coverage' => ScoreMath::normalizeNullable($context['psychometric_axis_coverage'] ?? null, 0.0),
            'methodology_completeness' => $this->methodologyCompleteness($context),
        ];

        $weights = [
            'source_coverage' => 0.2,
            'freshness' => 0.16,
            'crosswalk_quality' => 0.16,
            'reviewer_state' => 0.18,
            'psychometric_axis_coverage' => 0.14,
            'methodology_completeness' => 0.16,
        ];

        $base = ScoreMath::weightedGeometricMean($inputs, $weights);
        $rawValue = ScoreMath::clamp100($base * 100);
        $degraded = $degradationPolicy->apply('confidence_score', (float) $rawValue, $integrity, $context);

        return new CareerScoreResult(
            $degraded['value'],
            (string) $integrity['integrity_state'],
            (array) $integrity['critical_missing_fields'],
            (int) $integrity['confidence_cap'],
            'career.confidence_v1.2',
            [
                'inputs' => $inputs,
                'weights' => $weights,
                'base_score' => ScoreMath::clamp100($base * 100),
            ],
            $degraded['penalties'],
            $degraded['degradation_factor'],
        );
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function sourceCoverage(array $context): float
    {
        $fieldsUsedCount = max(0, (int) ($context['source_fields_used_count'] ?? 0));
        $hasTruthMetric = ($context['truth_metric_id'] ?? null) !== null;
        $hasSourceTrace = ($context['source_trace_id'] ?? null) !== null;

        $coverage = 0.35;
        if ($hasTruthMetric) {
            $coverage += 0.2;
        }
        if ($hasSourceTrace) {
            $coverage += 0.2;
        }
        $coverage += min(0.25, $fieldsUsedCount * 0.02);

        return ScoreMath::clamp01($coverage);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function freshness(array $context): float
    {
        $reviewedAt = $context['truth_reviewed_at'] ?? $context['last_substantive_update_at'] ?? null;
        if (! $reviewedAt instanceof \DateTimeInterface) {
            return 0.55;
        }

        $days = now()->diffInDays($reviewedAt);

        return match (true) {
            $days <= 30 => 0.92,
            $days <= 90 => 0.82,
            $days <= 180 => 0.7,
            default => 0.52,
        };
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function reviewerStateScore(array $context): float
    {
        return match (strtolower(trim((string) ($context['reviewer_status'] ?? '')))) {
            'approved', 'reviewed' => 0.88,
            'in_review' => 0.68,
            'changes_required' => 0.44,
            'pending' => 0.52,
            default => 0.46,
        };
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function methodologyCompleteness(array $context): float
    {
        $methodologyCount = max(0, (int) ($context['methodology_key_count'] ?? 0));
        $qualityConfidence = ScoreMath::normalizeNullable($context['quality_confidence'] ?? null, 0.68);

        return ScoreMath::clamp01(min(1.0, 0.35 + ($methodologyCount * 0.08) + ($qualityConfidence * 0.25)));
    }
}
