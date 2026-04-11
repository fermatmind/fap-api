<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

use App\Domain\Career\IndexStateValue;

final class ClaimPermissionsCompiler
{
    /**
     * @param  array<string,CareerScoreResult>  $scoreBundle
     * @param  array{red_flags:list<string>,amber_flags:list<string>,blocked_claims:list<string>}  $warnings
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function compile(array $scoreBundle, array $warnings, array $context): array
    {
        $blocked = array_fill_keys($warnings['blocked_claims'], true);
        $confidenceScore = $scoreBundle['confidence_score'] ?? null;
        $aiScore = $scoreBundle['ai_survival_score'] ?? null;
        $mobilityScore = $scoreBundle['mobility_score'] ?? null;
        $indexState = (string) ($context['index_state'] ?? '');
        $indexEligible = (bool) ($context['index_eligible'] ?? false);
        $indexRestricted = IndexStateValue::isIndexRestricted($indexState, $indexEligible);
        $indexBlocked = IndexStateValue::isIndexBlocked($indexState, $indexEligible);

        $allowStrongClaim = ! isset($blocked['strong_claim'])
            && ! $indexRestricted
            && $confidenceScore instanceof CareerScoreResult
            && $confidenceScore->value >= 65
            && ! in_array($confidenceScore->integrityState, [IntegrityState::RESTRICTED, IntegrityState::BLOCKED], true);

        $allowSalaryComparison = ! isset($blocked['salary_comparison'])
            && ! $indexBlocked
            && ($context['median_pay_usd_annual'] ?? null) !== null
            && ! ((bool) ($context['cross_market_mismatch'] ?? false) && ! (bool) ($context['allow_pay_direct_inheritance'] ?? false));

        $allowAiStrategy = ! isset($blocked['ai_strategy'])
            && ! $indexBlocked
            && $aiScore instanceof CareerScoreResult
            && $aiScore->integrityState !== IntegrityState::BLOCKED;

        $allowTransitionRecommendation = ! isset($blocked['transition_recommendation'])
            && ! $indexBlocked
            && $mobilityScore instanceof CareerScoreResult
            && $mobilityScore->integrityState !== IntegrityState::BLOCKED
            && $mobilityScore->value >= 40;

        $allowCrossMarketPayCopy = ! isset($blocked['cross_market_pay_copy'])
            && ! $indexBlocked
            && ! (bool) ($context['cross_market_mismatch'] ?? false);

        $reasonCodes = array_merge(
            $warnings['red_flags'],
            $warnings['amber_flags']
        );

        if (! $allowStrongClaim) {
            $reasonCodes[] = ClaimReasonCode::STRONG_CLAIM_BLOCKED;
        }
        if (! $allowSalaryComparison) {
            $reasonCodes[] = ClaimReasonCode::SALARY_COMPARISON_BLOCKED;
        }
        if (! $allowAiStrategy) {
            $reasonCodes[] = ClaimReasonCode::AI_STRATEGY_BLOCKED;
        }
        if (! $allowTransitionRecommendation) {
            $reasonCodes[] = ClaimReasonCode::TRANSITION_RECOMMENDATION_BLOCKED;
        }
        if (! $allowCrossMarketPayCopy) {
            $reasonCodes[] = ClaimReasonCode::CROSS_MARKET_PAY_BLOCKED;
        }

        return [
            'allow_strong_claim' => $allowStrongClaim,
            'allow_salary_comparison' => $allowSalaryComparison,
            'allow_ai_strategy' => $allowAiStrategy,
            'allow_transition_recommendation' => $allowTransitionRecommendation,
            'allow_cross_market_pay_copy' => $allowCrossMarketPayCopy,
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ];
    }
}
