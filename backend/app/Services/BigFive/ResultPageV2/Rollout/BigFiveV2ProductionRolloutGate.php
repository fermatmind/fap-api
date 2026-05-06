<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Rollout;

use App\Models\Attempt;

final class BigFiveV2ProductionRolloutGate
{
    private const ALLOWED_MODES = [
        'disabled',
        'allowlist_only',
        'percentage',
        'allowlist_or_percentage',
    ];

    public function decide(Attempt $attempt): BigFiveV2ProductionRolloutDecision
    {
        $context = $this->context($attempt);

        if ((bool) config('big5_result_page_v2.production_emergency_disabled', false)) {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_emergency_disabled', null, $context);
        }

        if (! (bool) config('big5_result_page_v2.production_rollout_enabled', false)) {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_disabled', null, $context);
        }

        if (! (bool) config('big5_result_page_v2.production_rollout_configured', false)) {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_not_configured', null, $context);
        }

        if (! (bool) config('big5_result_page_v2.production_rollout_manual_approval_granted', false)) {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_manual_approval_missing', null, $context);
        }

        if (! (bool) config('big5_result_page_v2.production_import_gate_passed', false)) {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_import_gate_missing', null, $context);
        }

        $releaseFailure = $this->releaseFailureReason();
        if ($releaseFailure !== null) {
            return new BigFiveV2ProductionRolloutDecision(false, $releaseFailure, null, $context);
        }

        $configErrors = $this->configErrors();
        if ($configErrors !== []) {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_invalid_config', null, $context, $configErrors);
        }

        $scopeFailure = $this->scopeFailureReason($context);
        if ($scopeFailure !== null) {
            return new BigFiveV2ProductionRolloutDecision(false, $scopeFailure, null, $context);
        }

        $mode = (string) config('big5_result_page_v2.production_rollout_mode', 'disabled');
        if ($mode === 'disabled') {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_mode_disabled', null, $context);
        }

        $allowlistMatch = $this->allowlistMatch($context);
        if ($allowlistMatch !== null) {
            return new BigFiveV2ProductionRolloutDecision(true, 'production_rollout_allowed', $allowlistMatch, $context);
        }

        if ($mode === 'allowlist_only') {
            return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_allowlist_denied', null, $context);
        }

        if ($this->percentageAllows($context)) {
            return new BigFiveV2ProductionRolloutDecision(true, 'production_rollout_allowed', 'rollout_percentage', $context);
        }

        return new BigFiveV2ProductionRolloutDecision(false, 'production_rollout_percentage_denied', null, $context);
    }

    /**
     * @return array<string,string|int>
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
            'rollout_percentage' => (int) config('big5_result_page_v2.production_rollout_percentage', 0),
        ];
    }

    private function releaseFailureReason(): ?string
    {
        $snapshotId = trim((string) config('big5_result_page_v2.production_release_snapshot_id', ''));
        if ($snapshotId === '') {
            return 'production_rollout_snapshot_missing';
        }

        if (! in_array($snapshotId, $this->configuredList('production_approved_release_snapshot_ids'), true)) {
            return 'production_rollout_snapshot_not_approved';
        }

        if (in_array($snapshotId, $this->configuredList('production_disabled_release_snapshot_ids'), true)) {
            return 'production_rollout_snapshot_disabled';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function configErrors(): array
    {
        $errors = [];
        $mode = (string) config('big5_result_page_v2.production_rollout_mode', 'disabled');
        $percentage = (int) config('big5_result_page_v2.production_rollout_percentage', 0);
        $maxPercentage = (int) config('big5_result_page_v2.production_rollout_max_percentage', 0);

        if (! in_array($mode, self::ALLOWED_MODES, true)) {
            $errors[] = 'production_rollout_mode_invalid';
        }

        if ($percentage < 0 || $percentage > 100) {
            $errors[] = 'production_rollout_percentage_out_of_range';
        }

        if ($maxPercentage < 0 || $maxPercentage > 100) {
            $errors[] = 'production_rollout_max_percentage_out_of_range';
        }

        if ($percentage > $maxPercentage) {
            $errors[] = 'production_rollout_blast_radius_exceeded';
        }

        foreach ([
            'production_rollout_allowed_scale_codes' => 'production_rollout_scale_scope_missing',
            'production_rollout_allowed_form_codes' => 'production_rollout_form_scope_missing',
            'production_rollout_allowed_locales' => 'production_rollout_locale_scope_missing',
        ] as $configKey => $errorCode) {
            if ($this->configuredList($configKey) === []) {
                $errors[] = $errorCode;
            }
        }

        if ((bool) config('big5_result_page_v2.production_rollout_require_tenant_scope', true)
            && $this->configuredList('production_rollout_allowed_tenant_ids') === []) {
            $errors[] = 'production_rollout_tenant_scope_missing';
        }

        return $errors;
    }

    /**
     * @param  array<string,string|int>  $context
     */
    private function scopeFailureReason(array $context): ?string
    {
        if (! in_array($context['scale_code'], $this->configuredUpperList('production_rollout_allowed_scale_codes'), true)) {
            return 'production_rollout_scale_denied';
        }

        if (! in_array($context['form_code'], $this->configuredList('production_rollout_allowed_form_codes'), true)) {
            return 'production_rollout_form_denied';
        }

        if (! in_array($context['locale'], $this->configuredList('production_rollout_allowed_locales'), true)) {
            return 'production_rollout_locale_denied';
        }

        $orgId = (string) $context['org_id'];
        if ((bool) config('big5_result_page_v2.production_rollout_require_tenant_scope', true)
            && ! in_array($orgId, $this->configuredList('production_rollout_allowed_tenant_ids'), true)) {
            return 'production_rollout_tenant_denied';
        }

        return null;
    }

    /**
     * @param  array<string,string|int>  $context
     */
    private function allowlistMatch(array $context): ?string
    {
        foreach ([
            'attempt_id' => 'production_rollout_allowed_attempt_ids',
            'user_id' => 'production_rollout_allowed_user_ids',
            'anon_id' => 'production_rollout_allowed_anon_ids',
            'org_id' => 'production_rollout_allowed_org_ids',
        ] as $field => $configKey) {
            $candidate = (string) ($context[$field] ?? '');
            if ($candidate !== '' && in_array($candidate, $this->configuredList($configKey), true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string,string|int>  $context
     */
    private function percentageAllows(array $context): bool
    {
        $percentage = (int) config('big5_result_page_v2.production_rollout_percentage', 0);
        if ($percentage <= 0) {
            return false;
        }

        if ($percentage >= 100) {
            return true;
        }

        $seed = (string) ($context['attempt_id'] !== '' ? $context['attempt_id'] : ($context['anon_id'] ?? ''));
        if ($seed === '') {
            return false;
        }

        $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;

        return $bucket < ($percentage * 100);
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
}
