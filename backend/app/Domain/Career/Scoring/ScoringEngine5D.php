<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class ScoringEngine5D
{
    public function __construct(
        private readonly FitScoreCalculator $fitScoreCalculator,
        private readonly StrainScoreCalculator $strainScoreCalculator,
        private readonly AISurvivalScoreCalculator $aiSurvivalScoreCalculator,
        private readonly MobilityScoreCalculator $mobilityScoreCalculator,
        private readonly ConfidenceScoreCalculator $confidenceScoreCalculator,
        private readonly IntegrityStateResolver $integrityStateResolver,
        private readonly DegradationPolicy $degradationPolicy,
        private readonly WarningMatrix $warningMatrix,
        private readonly ClaimPermissionsCompiler $claimPermissionsCompiler,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function compile(array $context): array
    {
        $integrity = [
            'fit_score' => $this->integrityStateResolver->resolve('fit_score', $context),
            'strain_score' => $this->integrityStateResolver->resolve('strain_score', $context),
            'ai_survival_score' => $this->integrityStateResolver->resolve('ai_survival_score', $context),
            'mobility_score' => $this->integrityStateResolver->resolve('mobility_score', $context),
            'confidence_score' => $this->integrityStateResolver->resolve('confidence_score', $context),
        ];

        $scoreBundle = [
            'fit_score' => $this->fitScoreCalculator->calculate($context, $integrity['fit_score'], $this->degradationPolicy),
            'strain_score' => $this->strainScoreCalculator->calculate($context, $integrity['strain_score'], $this->degradationPolicy),
            'ai_survival_score' => $this->aiSurvivalScoreCalculator->calculate($context, $integrity['ai_survival_score'], $this->degradationPolicy),
            'mobility_score' => $this->mobilityScoreCalculator->calculate($context, $integrity['mobility_score'], $this->degradationPolicy),
            'confidence_score' => $this->confidenceScoreCalculator->calculate($context, $integrity['confidence_score'], $this->degradationPolicy),
        ];

        $warnings = $this->warningMatrix->build($context, $scoreBundle);
        $claimPermissions = $this->claimPermissionsCompiler->compile($scoreBundle, $warnings, $context);

        return [
            'score_bundle' => array_map(
                static fn (CareerScoreResult $result): array => $result->toArray(),
                $scoreBundle
            ),
            'warnings' => $warnings,
            'claim_permissions' => $claimPermissions,
            'integrity_summary' => $this->integritySummary($scoreBundle),
        ];
    }

    /**
     * @param  array<string,CareerScoreResult>  $scoreBundle
     * @return array<string,mixed>
     */
    private function integritySummary(array $scoreBundle): array
    {
        $criticalMissingFields = [];
        $states = [];
        $confidenceCaps = [];
        $degradationFactors = [];

        foreach ($scoreBundle as $key => $result) {
            $states[$key] = $result->integrityState;
            $confidenceCaps[$key] = $result->confidenceCap;
            $degradationFactors[$key] = round($result->degradationFactor, 4);
            $criticalMissingFields = array_merge($criticalMissingFields, $result->criticalMissingFields);
        }

        $overallState = IntegrityState::FULL;
        if (in_array(IntegrityState::BLOCKED, $states, true)) {
            $overallState = IntegrityState::BLOCKED;
        } elseif (in_array(IntegrityState::RESTRICTED, $states, true)) {
            $overallState = IntegrityState::RESTRICTED;
        } elseif (in_array(IntegrityState::PROVISIONAL, $states, true)) {
            $overallState = IntegrityState::PROVISIONAL;
        }

        return [
            'overall_state' => $overallState,
            'score_states' => $states,
            'critical_missing_fields' => array_values(array_unique($criticalMissingFields)),
            'confidence_caps' => $confidenceCaps,
            'degradation_factors' => $degradationFactors,
        ];
    }
}
