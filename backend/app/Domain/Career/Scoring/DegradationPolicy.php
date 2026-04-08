<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class DegradationPolicy
{
    /**
     * @param  array{integrity_state:string,critical_missing_fields:list<string>,confidence_cap:int}  $integrity
     * @param  array<string,mixed>  $context
     * @return array{value:int,degradation_factor:float,penalties:list<array<string,mixed>>}
     */
    public function apply(string $scoreType, float $rawValue, array $integrity, array $context): array
    {
        $state = (string) ($integrity['integrity_state'] ?? IntegrityState::PROVISIONAL);
        $criticalMissingFields = is_array($integrity['critical_missing_fields'] ?? null)
            ? $integrity['critical_missing_fields']
            : [];

        $penalties = [];
        $baseFactor = match ($state) {
            IntegrityState::FULL => 1.0,
            IntegrityState::PROVISIONAL => 0.88,
            IntegrityState::RESTRICTED => 0.7,
            IntegrityState::BLOCKED => 0.42,
            default => 0.75,
        };

        if ($criticalMissingFields !== []) {
            $penalties[] = [
                'code' => 'critical_missing_fields',
                'weight' => min(0.24, count($criticalMissingFields) * 0.05),
                'value' => 1.0,
                'fields' => array_values($criticalMissingFields),
            ];
        }

        if (! in_array((string) ($context['reviewer_status'] ?? ''), ['approved', 'reviewed'], true)) {
            $penalties[] = [
                'code' => 'review_pending',
                'weight' => 0.04,
                'value' => 1.0,
            ];
        }

        if ((bool) ($context['cross_market_mismatch'] ?? false)) {
            $penalties[] = [
                'code' => 'cross_market_mismatch',
                'weight' => in_array($scoreType, ['ai_survival_score', 'mobility_score'], true) ? 0.08 : 0.03,
                'value' => 1.0,
            ];
        }

        if ((bool) ($context['editorial_patch_required'] ?? false) && ! (bool) ($context['editorial_patch_complete'] ?? false)) {
            $penalties[] = [
                'code' => 'editorial_patch_required',
                'weight' => 0.12,
                'value' => 1.0,
            ];
        }

        if ($scoreType === 'ai_survival_score' && ($context['ai_exposure'] ?? null) === null) {
            $penalties[] = [
                'code' => 'missing_ai_exposure',
                'weight' => 0.35,
                'value' => 1.0,
            ];
        }

        $factor = $baseFactor * ScoreMath::penaltyFactor($penalties, 0.2);

        return [
            'value' => ScoreMath::clamp100($rawValue * $factor),
            'degradation_factor' => round($factor, 4),
            'penalties' => array_values($penalties),
        ];
    }
}
