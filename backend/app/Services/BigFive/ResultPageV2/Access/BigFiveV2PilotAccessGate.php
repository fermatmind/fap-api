<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Access;

use App\Models\Attempt;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;

final class BigFiveV2PilotAccessGate
{
    public function decide(Attempt $attempt): BigFiveV2PilotAccessDecision
    {
        $context = $this->context($attempt);

        if ($context['scale_code'] !== BigFiveResultPageV2Contract::SCALE_CODE) {
            return new BigFiveV2PilotAccessDecision(false, 'non_big5_attempt', null, $context);
        }

        if ($context['environment'] === 'production' && ! (bool) config('big5_result_page_v2.pilot_production_allowlist_enabled', false)) {
            return new BigFiveV2PilotAccessDecision(false, 'production_pilot_access_denied', null, $context);
        }

        $allowlists = [
            'attempt_id' => $this->configuredList('pilot_access_allowed_attempt_ids'),
            'user_id' => $this->configuredList('pilot_access_allowed_user_ids'),
            'anon_id' => $this->configuredList('pilot_access_allowed_anon_ids'),
            'org_id' => $this->configuredList('pilot_access_allowed_org_ids'),
        ];

        if (! $this->hasConfiguredAllowlist($allowlists)) {
            return new BigFiveV2PilotAccessDecision(false, 'pilot_access_allowlist_empty', null, $context);
        }

        foreach ($allowlists as $field => $allowedValues) {
            $candidate = $context[$field] ?? '';
            if ($candidate !== '' && in_array($candidate, $allowedValues, true)) {
                return new BigFiveV2PilotAccessDecision(true, 'pilot_access_allowed', $field, $context);
            }
        }

        return new BigFiveV2PilotAccessDecision(false, 'pilot_access_denied', null, $context);
    }

    /**
     * @return array<string,string>
     */
    private function context(Attempt $attempt): array
    {
        return [
            'attempt_id' => trim((string) ($attempt->id ?? '')),
            'user_id' => trim((string) ($attempt->user_id ?? '')),
            'anon_id' => trim((string) ($attempt->anon_id ?? '')),
            'org_id' => trim((string) ($attempt->org_id ?? '')),
            'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? ''))),
            'environment' => (string) app()->environment(),
        ];
    }

    /**
     * @return list<string>
     */
    private function configuredList(string $key): array
    {
        $configured = config('big5_result_page_v2.'.$key, []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $configured,
        )));
    }

    /**
     * @param  array<string,list<string>>  $allowlists
     */
    private function hasConfiguredAllowlist(array $allowlists): bool
    {
        foreach ($allowlists as $allowedValues) {
            if ($allowedValues !== []) {
                return true;
            }
        }

        return false;
    }
}
