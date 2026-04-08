<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class IntegrityStateResolver
{
    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   integrity_state:string,
     *   critical_missing_fields:list<string>,
     *   confidence_cap:int
     * }
     */
    public function resolve(string $scoreType, array $context): array
    {
        $criticalMissingFields = [];
        foreach ($this->requiredFields($scoreType) as $field) {
            $value = $context[$field] ?? null;
            if ($value === null || $value === [] || $value === '') {
                $criticalMissingFields[] = $field;
            }
        }

        $reviewerStatus = strtolower(trim((string) ($context['reviewer_status'] ?? '')));
        $qualityConfidence = ScoreMath::normalizeNullable($context['quality_confidence'] ?? null, 0.72);
        $crossMarketMismatch = (bool) ($context['cross_market_mismatch'] ?? false);
        $editorialPatchRequired = (bool) ($context['editorial_patch_required'] ?? false);
        $editorialPatchComplete = (bool) ($context['editorial_patch_complete'] ?? false);
        $psychometricAxisCoverage = ScoreMath::normalizeNullable($context['psychometric_axis_coverage'] ?? null, 0.0);

        $state = IntegrityState::FULL;

        if ($scoreType === 'ai_survival_score' && in_array('ai_exposure', $criticalMissingFields, true)) {
            $state = IntegrityState::BLOCKED;
        } elseif ($scoreType === 'confidence_score' && in_array('trust_manifest', $criticalMissingFields, true)) {
            $state = IntegrityState::BLOCKED;
        } elseif (count($criticalMissingFields) >= 3) {
            $state = IntegrityState::BLOCKED;
        } elseif (count($criticalMissingFields) >= 2) {
            $state = IntegrityState::RESTRICTED;
        } elseif (count($criticalMissingFields) === 1) {
            $state = IntegrityState::PROVISIONAL;
        }

        if (! in_array($reviewerStatus, ['approved', 'reviewed'], true) && $state === IntegrityState::FULL) {
            $state = IntegrityState::PROVISIONAL;
        }

        if ($editorialPatchRequired && ! $editorialPatchComplete) {
            $state = $state === IntegrityState::BLOCKED
                ? IntegrityState::BLOCKED
                : IntegrityState::RESTRICTED;
        }

        if ($crossMarketMismatch && in_array($scoreType, ['ai_survival_score', 'mobility_score', 'confidence_score'], true) && $state === IntegrityState::FULL) {
            $state = IntegrityState::PROVISIONAL;
        }

        if ($psychometricAxisCoverage < 0.35 && in_array($scoreType, ['fit_score', 'strain_score', 'confidence_score'], true)) {
            $state = IntegrityState::RESTRICTED;
            if (! in_array('psychometric_axis_coverage', $criticalMissingFields, true)) {
                $criticalMissingFields[] = 'psychometric_axis_coverage';
            }
        }

        $baseCap = 92;
        $baseCap = min($baseCap, (int) round($qualityConfidence * 100));

        if (! in_array($reviewerStatus, ['approved', 'reviewed'], true)) {
            $baseCap -= 10;
        }

        $baseCap -= count($criticalMissingFields) * 10;

        if ($crossMarketMismatch) {
            $baseCap -= 6;
        }

        if ($editorialPatchRequired && ! $editorialPatchComplete) {
            $baseCap -= 12;
        }

        $confidenceCap = match ($state) {
            IntegrityState::FULL => max(55, min(95, $baseCap)),
            IntegrityState::PROVISIONAL => max(48, min(88, $baseCap - 4)),
            IntegrityState::RESTRICTED => max(35, min(72, $baseCap - 8)),
            IntegrityState::BLOCKED => max(20, min(50, $baseCap - 12)),
            default => 40,
        };

        return [
            'integrity_state' => $state,
            'critical_missing_fields' => array_values(array_unique($criticalMissingFields)),
            'confidence_cap' => $confidenceCap,
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredFields(string $scoreType): array
    {
        return match ($scoreType) {
            'fit_score' => ['task_prototype_signature', 'psychometric_axis_coverage', 'skill_overlap'],
            'strain_score' => ['task_prototype_signature', 'psychometric_axis_coverage', 'structural_stability'],
            'ai_survival_score' => ['ai_exposure', 'source_trace_evidence', 'structural_stability'],
            'mobility_score' => ['skill_overlap', 'task_overlap', 'crosswalk_confidence'],
            'confidence_score' => ['trust_manifest', 'source_trace_evidence', 'reviewer_status'],
            default => [],
        };
    }
}
