<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

final class Post12L3CanaryAdapter
{
    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function evaluate(array $manifest): array
    {
        $allowlist = $manifest['exact_url_allowlist'] ?? [];
        $featureFlag = $manifest['feature_flag'] ?? null;
        if (($manifest['shared_layer'] ?? false) === true && ($featureFlag === null || ! is_array($allowlist) || $allowlist === [])) {
            return $this->denied('NOT_A_CANARY');
        }

        $required = [
            'signed_manifest_valid', 'page_family', 'locale', 'feature_flag', 'rollback_unit',
            'current_evidence', 'prior_stage_readback', 'independent_review', 'policy_gateway_approved',
        ];
        foreach ($required as $field) {
            if (! array_key_exists($field, $manifest) || $manifest[$field] === false || $manifest[$field] === null || $manifest[$field] === '') {
                return $this->denied('CANARY_MANIFEST_INVALID');
            }
        }
        foreach (['signed_manifest_valid', 'current_evidence', 'prior_stage_readback', 'independent_review', 'policy_gateway_approved'] as $field) {
            if (($manifest[$field] ?? null) !== true) {
                return $this->denied('CANARY_MANIFEST_INVALID');
            }
        }
        if (! is_array($allowlist) || count($allowlist) < 1 || count($allowlist) > 3
            || count(array_filter($allowlist, static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1)) !== count($allowlist)) {
            return $this->denied('CANARY_ALLOWLIST_INVALID');
        }

        return [
            'status' => 'IMPLEMENTED_WRITE_DISABLED',
            'reason' => 'POST12_WRITE_DISABLED',
            'cohort_sequence' => ['1-3', '10', '50', 'approved_cohort'],
            'canary_started' => false,
            'write_count' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function denied(string $reason): array
    {
        return [
            'status' => 'HOLD',
            'reason' => $reason,
            'cohort_sequence' => ['1-3', '10', '50', 'approved_cohort'],
            'canary_started' => false,
            'write_count' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
        ];
    }
}
