<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

final class CompetitivePolicySemanticReviewer
{
    /** @return array{decision:string,reason_code:string} */
    public function review(string $kind, string $body, string $normalizedText, bool $contentDrifted): array
    {
        $accessReason = $this->accessBarrierReason($body, $normalizedText);
        if ($accessReason !== null) {
            return ['decision' => 'hold', 'reason_code' => $accessReason];
        }
        if ($this->automationProhibited($normalizedText)) {
            return [
                'decision' => 'hold',
                'reason_code' => $kind === 'terms'
                    ? 'TERMS_AUTOMATION_PROHIBITED'
                    : 'LICENSE_AUTOMATION_PROHIBITED',
            ];
        }

        // An unchanged, previously approved baseline only needs a fresh negative-signal review.
        if (! $contentDrifted) {
            return ['decision' => 'approved', 'reason_code' => 'NONE'];
        }

        if ($kind === 'terms') {
            $termsDocument = array_filter(
                ['terms of service', 'terms of use', 'terms and conditions', 'conditions of use', 'user conduct'],
                static fn (string $marker): bool => str_contains($normalizedText, $marker),
            ) !== [];
            $usageScope = preg_match('/\b(?:access|use|service|content|intellectual property|user conduct)\b/i', $normalizedText) === 1;

            return $termsDocument && $usageScope
                ? ['decision' => 'approved', 'reason_code' => 'NONE']
                : ['decision' => 'hold', 'reason_code' => 'TERMS_POLICY_AMBIGUOUS'];
        }

        $automatedAccess = preg_match('/\b(?:ai agents?|automated access|machine[- ]readable|public (?:rest )?api|model context protocol|mcp)\b/i', $normalizedText) === 1;
        $structuralUse = preg_match('/\b(?:metadata|structured (?:data|facts?|information)|catalog|embed|(?:rest )?api)\b/i', $normalizedText) === 1;
        $publicAccess = preg_match('/\b(?:public|no api key|without (?:an )?(?:api key|account|login)|no auth(?:entication)?|free access)\b/i', $normalizedText) === 1;

        return $automatedAccess && $structuralUse && $publicAccess
            ? ['decision' => 'approved', 'reason_code' => 'NONE']
            : ['decision' => 'hold', 'reason_code' => 'LICENSE_STRUCTURE_SCOPE_AMBIGUOUS'];
    }

    private function accessBarrierReason(string $body, string $normalizedText): ?string
    {
        if (preg_match('/<input\b[^>]*\btype\s*=\s*["\']?password\b/i', $body) === 1
            || preg_match('/\b(?:sign in|log in)\s+(?:required|to continue|before you can)\b/i', $normalizedText) === 1) {
            return 'POLICY_LOGIN_REQUIRED';
        }
        if (preg_match('/\bdata-(?:paywall|metered)\b/i', $body) === 1
            || preg_match('/"isAccessibleForFree"\s*:\s*false/i', $body) === 1
            || preg_match('/\b(?:subscribe|payment)\s+(?:to|is )?(?:continue|required)\b/i', $normalizedText) === 1) {
            return 'POLICY_PAYWALL_HELD';
        }
        if (preg_match('/\b(?:captcha|recaptcha|hcaptcha|turnstile|data-sitekey)\b/i', $body) === 1) {
            return 'POLICY_CAPTCHA_HELD';
        }

        return null;
    }

    private function automationProhibited(string $text): bool
    {
        $automation = '(?:robots?|bots?|crawlers?|spiders?|scrap(?:e|er|ers|ing)|automated (?:access|collection|retrieval|requests?))';
        $prohibition = '(?:may not|must not|shall not|cannot|can\'t|do not|not allowed to|not permitted to|prohibited from|forbidden from|agree not to)';

        return preg_match('/'.$prohibition.'.{0,80}\b'.$automation.'\b/i', $text) === 1
            || preg_match('/\b'.$automation.'\b\s+(?:(?:is|are)\s+)?(?:not allowed|not permitted|prohibited|forbidden)\b/i', $text) === 1
            || preg_match('/\bno\s+'.$automation.'\b/i', $text) === 1;
    }
}
