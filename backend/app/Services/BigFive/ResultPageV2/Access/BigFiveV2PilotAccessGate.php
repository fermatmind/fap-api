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

        if ($context['environment'] === 'production'
            && ! (bool) config('big5_result_page_v2.pilot_production_allowlist_enabled', false)
            && ! (bool) config('big5_result_page_v2.public_pilot_production_allowlist_enabled', false)) {
            return new BigFiveV2PilotAccessDecision(false, 'production_pilot_access_denied', null, $context);
        }

        $allowlists = [
            'attempt_id' => $this->configuredList('pilot_access_allowed_attempt_ids'),
            'user_id' => $this->configuredList('pilot_access_allowed_user_ids'),
            'anon_id' => $this->configuredList('pilot_access_allowed_anon_ids'),
            'org_id' => $this->configuredList('pilot_access_allowed_org_ids'),
        ];

        if (! $this->hasConfiguredAllowlist($allowlists)) {
            return $this->decidePublicPilot($context)
                ?? new BigFiveV2PilotAccessDecision(false, 'pilot_access_allowlist_empty', null, $context);
        }

        foreach ($allowlists as $field => $allowedValues) {
            $candidate = $context[$field] ?? '';
            if ($candidate !== '' && in_array($candidate, $allowedValues, true)) {
                return new BigFiveV2PilotAccessDecision(true, 'pilot_access_allowed', $field, $context);
            }
        }

        return $this->decidePublicPilot($context)
            ?? new BigFiveV2PilotAccessDecision(false, 'pilot_access_denied', null, $context);
    }

    /**
     * @param  array<string,string>  $context
     */
    private function decidePublicPilot(array $context): ?BigFiveV2PilotAccessDecision
    {
        if (! (bool) config('big5_result_page_v2.public_pilot_enabled', false)) {
            return null;
        }

        if ((string) config('big5_result_page_v2.public_pilot_surface_scope', 'result_page_only') !== 'result_page_only') {
            return new BigFiveV2PilotAccessDecision(false, 'public_pilot_scope_denied', null, $context);
        }

        if (! in_array($context['environment'], $this->configuredList('public_pilot_allowed_environments'), true)) {
            return new BigFiveV2PilotAccessDecision(false, 'public_pilot_environment_denied', null, $context);
        }

        if ($context['environment'] === 'production' && ! (bool) config('big5_result_page_v2.public_pilot_production_allowlist_enabled', false)) {
            return new BigFiveV2PilotAccessDecision(false, 'public_pilot_production_denied', null, $context);
        }

        if (! in_array($context['scale_code'], $this->configuredUpperList('public_pilot_allowed_scale_codes'), true)) {
            return new BigFiveV2PilotAccessDecision(false, 'public_pilot_scale_denied', null, $context);
        }

        if (! in_array($context['form_code'], $this->configuredList('public_pilot_allowed_form_codes'), true)) {
            return new BigFiveV2PilotAccessDecision(false, 'public_pilot_form_denied', null, $context);
        }

        if (! in_array($context['locale'], $this->configuredList('public_pilot_allowed_locales'), true)) {
            return new BigFiveV2PilotAccessDecision(false, 'public_pilot_locale_denied', null, $context);
        }

        $allowlists = [
            'attempt_id' => $this->configuredList('public_pilot_access_allowed_attempt_ids'),
            'user_id' => $this->configuredList('public_pilot_access_allowed_user_ids'),
            'anon_id' => $this->configuredList('public_pilot_access_allowed_anon_ids'),
            'org_id' => $this->configuredList('public_pilot_access_allowed_org_ids'),
        ];

        foreach ($allowlists as $field => $allowedValues) {
            $candidate = $context[$field] ?? '';
            if ($candidate !== '' && in_array($candidate, $allowedValues, true)) {
                return new BigFiveV2PilotAccessDecision(true, 'public_pilot_gate_allowed', $field, $context);
            }
        }

        if ($this->publicRolloutAllows($context)) {
            return new BigFiveV2PilotAccessDecision(true, 'public_pilot_gate_allowed', 'rollout_percentage', $context);
        }

        return new BigFiveV2PilotAccessDecision(false, 'public_pilot_gate_denied', null, $context);
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
            'form_code' => trim((string) data_get($attempt->answers_summary_json, 'meta.form_code', '')),
            'locale' => trim((string) ($attempt->locale ?? '')),
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
     * @return list<string>
     */
    private function configuredUpperList(string $key): array
    {
        return array_values(array_map(
            static fn (string $value): string => strtoupper($value),
            $this->configuredList($key),
        ));
    }

    /**
     * @param  array<string,string>  $context
     */
    private function publicRolloutAllows(array $context): bool
    {
        $percentage = max(0, min(100, (int) config('big5_result_page_v2.public_pilot_rollout_percentage', 0)));
        if ($percentage <= 0) {
            return false;
        }

        if ($percentage >= 100) {
            return true;
        }

        $seed = $context['attempt_id'] !== '' ? $context['attempt_id'] : ($context['anon_id'] ?? '');
        if ($seed === '') {
            return false;
        }

        $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;

        return $bucket < ($percentage * 100);
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
