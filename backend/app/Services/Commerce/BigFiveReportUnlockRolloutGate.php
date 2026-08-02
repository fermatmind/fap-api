<?php

declare(strict_types=1);

namespace App\Services\Commerce;

final class BigFiveReportUnlockRolloutGate
{
    public function allows(object $attempt): bool
    {
        if ((bool) config('report_unlock.big5_rollout.emergency_disabled', false)) {
            return false;
        }

        $mode = strtolower(trim((string) config('report_unlock.big5_rollout.mode', 'disabled')));
        if (! in_array($mode, ['allowlist_only', 'allowlist_or_percentage'], true)) {
            return false;
        }

        if (! $this->inScope($attempt)) {
            return false;
        }

        foreach ([
            'id' => 'allowed_attempt_ids',
            'user_id' => 'allowed_user_ids',
            'anon_id' => 'allowed_anon_ids',
            'org_id' => 'allowed_org_ids',
        ] as $field => $key) {
            $candidate = trim((string) ($attempt->{$field} ?? ''));
            if ($candidate !== '' && in_array($candidate, $this->list($key), true)) {
                return true;
            }
        }

        if ($mode === 'allowlist_only') {
            return false;
        }

        $percentage = min(
            max(0, (int) config('report_unlock.big5_rollout.percentage', 0)),
            max(0, (int) config('report_unlock.big5_rollout.max_percentage', 0)),
        );
        $seed = trim((string) (($attempt->id ?? '') ?: ($attempt->anon_id ?? '')));
        if ($percentage <= 0 || $seed === '') {
            return false;
        }

        return (hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000) < ($percentage * 100);
    }

    private function inScope(object $attempt): bool
    {
        $formCode = trim((string) data_get($attempt->answers_summary_json ?? [], 'meta.form_code', ''));
        if ($formCode === '') {
            $questionCount = (int) ($attempt->question_count ?? 0);
            $formCode = in_array($questionCount, [90, 120], true) ? 'big5_'.$questionCount : '';
        }

        return strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'BIG5_OCEAN'
            && in_array(trim((string) ($attempt->org_id ?? '')), $this->list('allowed_tenant_ids'), true)
            && in_array($formCode, $this->list('allowed_form_codes'), true)
            && in_array(trim((string) ($attempt->locale ?? '')), $this->list('allowed_locales'), true);
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        $values = config('report_unlock.big5_rollout.'.$key, []);
        $values = is_string($values) ? explode(',', $values) : $values;

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($values) ? $values : [],
        ), static fn (string $value): bool => $value !== ''));
    }
}
