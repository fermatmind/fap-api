<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class MobilityScoreCalculator
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array{integrity_state:string,critical_missing_fields:list<string>,confidence_cap:int}  $integrity
     */
    public function calculate(array $context, array $integrity, DegradationPolicy $degradationPolicy): CareerScoreResult
    {
        $inputs = [
            'skill_overlap' => ScoreMath::normalizeNullable($context['skill_overlap'] ?? null, 0.5),
            'task_overlap' => ScoreMath::normalizeNullable($context['task_overlap'] ?? null, 0.5),
            'work_context_overlap' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5),
                1.0 - ScoreMath::normalizeNullable($context['market_semantics_gap'] ?? null, 0.2),
            ]),
            'tool_overlap' => ScoreMath::normalizeNullable($context['tool_overlap'] ?? null, 0.5),
            'credential_transfer' => $this->credentialTransfer($context),
            'education_gap' => $this->educationGap($context),
            'experience_gap' => ScoreMath::normalizeNullable($context['skill_gap_threshold'] ?? null, 0.4),
            'licensure_gap' => ScoreMath::normalizeNullable($context['regulatory_divergence'] ?? null, 0.0),
            'salary_shock' => $this->salaryShock($context),
            'geo_gap' => ScoreMath::normalizeNullable($context['market_semantics_gap'] ?? null, 0.2),
        ];

        $positive = [
            'skill_overlap' => $inputs['skill_overlap'],
            'task_overlap' => $inputs['task_overlap'],
            'work_context_overlap' => $inputs['work_context_overlap'],
            'tool_overlap' => $inputs['tool_overlap'],
            'credential_transfer' => $inputs['credential_transfer'],
        ];

        $weights = [
            'skill_overlap' => 0.26,
            'task_overlap' => 0.22,
            'work_context_overlap' => 0.18,
            'tool_overlap' => 0.18,
            'credential_transfer' => 0.16,
        ];

        $base = ScoreMath::weightedGeometricMean($positive, $weights);
        $penaltyFactor = ScoreMath::penaltyFactor([
            ['weight' => 0.12, 'value' => $inputs['education_gap']],
            ['weight' => 0.12, 'value' => $inputs['experience_gap']],
            ['weight' => 0.08, 'value' => $inputs['licensure_gap']],
            ['weight' => 0.16, 'value' => $inputs['salary_shock']],
            ['weight' => 0.08, 'value' => $inputs['geo_gap']],
        ], 0.35);

        $rawValue = ScoreMath::clamp100($base * $penaltyFactor * 100);
        $degraded = $degradationPolicy->apply('mobility_score', (float) $rawValue, $integrity, $context);

        return new CareerScoreResult(
            $degraded['value'],
            (string) $integrity['integrity_state'],
            (array) $integrity['critical_missing_fields'],
            (int) $integrity['confidence_cap'],
            'career.mobility_v1.2',
            [
                'inputs' => $inputs,
                'weights' => $weights,
                'base_score' => ScoreMath::clamp100($base * 100),
                'penalty_factor' => round($penaltyFactor, 4),
            ],
            $degraded['penalties'],
            $degraded['degradation_factor'],
        );
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function credentialTransfer(array $context): float
    {
        $entryEducation = strtolower(trim((string) ($context['entry_education'] ?? '')));
        $workExperience = strtolower(trim((string) ($context['work_experience'] ?? '')));
        $onTheJobTraining = strtolower(trim((string) ($context['on_the_job_training'] ?? '')));

        $educationScore = match (true) {
            $entryEducation === '' => 0.55,
            str_contains($entryEducation, 'bachelor') => 0.88,
            str_contains($entryEducation, 'associate') => 0.76,
            default => 0.62,
        };
        $experienceScore = $workExperience === '' || $workExperience === 'none' ? 0.94 : 0.78;
        $trainingScore = $onTheJobTraining === '' || $onTheJobTraining === 'none' ? 0.92 : 0.74;

        return ScoreMath::average([$educationScore, $experienceScore, $trainingScore], 0.75);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function educationGap(array $context): float
    {
        $entryEducation = strtolower(trim((string) ($context['entry_education'] ?? '')));

        return match (true) {
            $entryEducation === '' => 0.35,
            str_contains($entryEducation, 'bachelor') => 0.12,
            str_contains($entryEducation, 'master') => 0.24,
            default => 0.28,
        };
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function salaryShock(array $context): float
    {
        $medianPayMissing = ($context['median_pay_usd_annual'] ?? null) === null;
        $crossMarketMismatch = (bool) ($context['cross_market_mismatch'] ?? false);
        $allowPayInheritance = (bool) ($context['allow_pay_direct_inheritance'] ?? false);

        if ($medianPayMissing) {
            return 0.65;
        }

        if ($crossMarketMismatch && ! $allowPayInheritance) {
            return 0.42;
        }

        return 0.16;
    }
}
