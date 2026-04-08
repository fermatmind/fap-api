<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class WarningMatrix
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,CareerScoreResult>  $scoreBundle
     * @return array{
     *   red_flags:list<string>,
     *   amber_flags:list<string>,
     *   blocked_claims:list<string>
     * }
     */
    public function build(array $context, array $scoreBundle): array
    {
        $redFlags = [];
        $amberFlags = [];
        $blockedClaims = [];

        if (($context['median_pay_usd_annual'] ?? null) === null) {
            $redFlags[] = ClaimReasonCode::MISSING_MEDIAN_PAY;
            $blockedClaims[] = 'salary_comparison';
        }

        if (($context['ai_exposure'] ?? null) === null) {
            $redFlags[] = ClaimReasonCode::MISSING_AI_EXPOSURE;
            $blockedClaims[] = 'ai_strategy';
        }

        if ((bool) ($context['cross_market_mismatch'] ?? false)) {
            $amberFlags[] = ClaimReasonCode::CROSS_MARKET_MISMATCH;
            $blockedClaims[] = 'cross_market_pay_copy';
            if (! (bool) ($context['allow_pay_direct_inheritance'] ?? false)) {
                $blockedClaims[] = 'salary_comparison';
            }
        }

        if (! in_array((string) ($context['reviewer_status'] ?? ''), ['approved', 'reviewed'], true)) {
            $amberFlags[] = ClaimReasonCode::REVIEW_PENDING;
            $blockedClaims[] = 'strong_claim';
        }

        if (ScoreMath::normalizeNullable($context['quality_confidence'] ?? null, 0.7) < 0.72) {
            $amberFlags[] = ClaimReasonCode::LOW_QUALITY_CONFIDENCE;
            $blockedClaims[] = 'strong_claim';
        }

        if ((bool) ($context['editorial_patch_required'] ?? false) && ! (bool) ($context['editorial_patch_complete'] ?? false)) {
            $redFlags[] = ClaimReasonCode::EDITORIAL_PATCH_REQUIRED;
            $blockedClaims[] = 'strong_claim';
            $blockedClaims[] = 'transition_recommendation';
        }

        foreach ($scoreBundle as $scoreType => $scoreResult) {
            if ($scoreResult->integrityState === IntegrityState::BLOCKED) {
                $redFlags[] = $scoreType.'.blocked';
            } elseif ($scoreResult->integrityState === IntegrityState::RESTRICTED) {
                $amberFlags[] = $scoreType.'.restricted';
            } elseif ($scoreResult->integrityState === IntegrityState::PROVISIONAL) {
                $amberFlags[] = $scoreType.'.provisional';
            }
        }

        return [
            'red_flags' => array_values(array_unique($redFlags)),
            'amber_flags' => array_values(array_unique($amberFlags)),
            'blocked_claims' => array_values(array_unique($blockedClaims)),
        ];
    }
}
