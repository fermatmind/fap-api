<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

/** Fixed, model-free operator guidance keyed only by deterministic reason code. */
final class Platform12IssueExplanation
{
    /** @return array{reason_code:string,problem_key:string,impact_key:string,recommendation_key:string} */
    public function for(array $output, string $state, bool $hasSourceGap): array
    {
        $reason = match (true) {
            $hasSourceGap => 'SOURCE_UNAVAILABLE',
            $state === 'STALE' => 'STALE_EVIDENCE_HOLD',
            default => (string) (($output['reason_codes'][0] ?? null) ?: ($output['state'] ?? $state)),
        };
        if (preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $reason) !== 1) {
            $reason = 'UNCLASSIFIED_HOLD';
        }
        $known = [
            'READY', 'SOURCE_UNAVAILABLE', 'GSC_UNAVAILABLE_HOLD', 'MAPPING_FAILED_HOLD',
            'WINDOW_INCOMPLETE_HOLD', 'DATA_FRESHNESS_HOLD', 'DATA_QUALITY_HOLD',
            'RUNTIME_UNAVAILABLE_HOLD', 'RUNTIME_READBACK_HOLD', 'URL_TRUTH_UNAVAILABLE_HOLD',
            'WRONG_CANONICAL_HOLD', 'FALSE_NOINDEX_HOLD', 'RECONCILIATION_INCOMPLETE_HOLD',
            'CLUSTER_DEDUPE_UNAVAILABLE_HOLD', 'D1_OBSERVATION_HOLD', 'OBSERVATION_UNAVAILABLE_HOLD',
            'PRIVATE_NEGATIVE_SET_LEAK', 'QUERY_SECURITY_DENY', 'METADATA_INJECTION_DENY',
            'UNAUTHORIZED_TOOL_DENY', 'RETENTION_EGRESS_DENY', 'AUTHORITY_HASH_DRIFT_HOLD',
            'STALE_EVIDENCE_HOLD', 'SECURITY_EVIDENCE_UNAVAILABLE', 'INPUT_UNAVAILABLE',
        ];
        if (! in_array($reason, $known, true)) {
            $reason = $state === 'READY' ? 'READY' : 'UNCLASSIFIED_HOLD';
        }

        return [
            'reason_code' => $reason,
            'problem_key' => 'seo-council.reasons.'.$reason.'.problem',
            'impact_key' => 'seo-council.reasons.'.$reason.'.impact',
            'recommendation_key' => 'seo-council.reasons.'.$reason.'.recommendation',
        ];
    }
}
