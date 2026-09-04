<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Throwable;

final readonly class Platform12DailySecurityDriftEvaluator
{
    private const DRIFT_FIELDS = ['role', 'binding', 'policy', 'tool', 'schema', 'prompt'];

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            $privateRoutes = $this->privateRoutes($evidence['private_routes'] ?? null);
            $query = $this->querySecurity($evidence['query_security'] ?? null);
            $drift = $this->drift($evidence['drift'] ?? null);
            $freshness = $this->freshness($evidence['evidence_freshness'] ?? null);
            $injection = $this->injection($evidence['injection'] ?? null);
            $tools = $this->tools($evidence['tools'] ?? null);
            $posture = $this->posture($evidence['posture'] ?? null);
            $decision = $this->decision($privateRoutes, $query, $drift, $freshness, $injection, $tools, $posture);
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $privateRoutes = ['tested_count' => null, 'rejected_count' => null, 'rejection_rate_ppm' => null, 'state' => 'UNAVAILABLE'];
            $query = ['hmac_state' => 'UNAVAILABLE', 'key_version_state' => 'UNAVAILABLE', 'pii_state' => 'UNKNOWN'];
            $drift = array_fill_keys(self::DRIFT_FIELDS, 'UNAVAILABLE');
            $freshness = ['total_count' => null, 'fresh_count' => null, 'expired_count' => null, 'model_evidence_count' => 0, 'stale_evidence_model_allowed' => false];
            $injection = ['prompt_state' => 'UNAVAILABLE', 'tool_metadata_state' => 'UNAVAILABLE'];
            $tools = ['requested_count' => null, 'authorized_count' => null, 'unauthorized_count' => null];
            $posture = ['retention_state' => 'UNAVAILABLE', 'egress_state' => 'UNAVAILABLE'];
            $decision = ['state' => 'DENY', 'reason_codes' => ['INPUT_UNAVAILABLE']];
        }

        $receipt = [
            'receipt_version' => 'seo.platform12_daily_security_drift.v1',
            'mission_id' => 'seo.platform12.daily_private_policy_evidence_drift',
            'evaluated_at' => $evaluatedAt,
            'state' => $decision['state'],
            'reason_codes' => $decision['reason_codes'],
            'private_routes' => $privateRoutes,
            'query_security' => $query,
            'drift' => $drift,
            'evidence_freshness' => $freshness,
            'injection' => $injection,
            'tools' => $tools,
            'posture' => $posture,
            'automatic_repair_allowed' => false,
            'authority_mutation_allowed' => false,
            'permission_mutation_allowed' => false,
            'read_only' => true,
            'execution_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function privateRoutes(mixed $source): array
    {
        if (! is_array($source)) {
            throw new \InvalidArgumentException('PRIVATE_NEGATIVE_SET_INVALID');
        }
        $tested = $this->count($source, 'tested_count');
        $rejected = $this->count($source, 'rejected_count');
        if ($tested === 0 || $rejected > $tested) {
            throw new \InvalidArgumentException('PRIVATE_NEGATIVE_SET_INVALID');
        }

        return [
            'tested_count' => $tested,
            'rejected_count' => $rejected,
            'rejection_rate_ppm' => intdiv($rejected * 1000000, $tested),
            'state' => $rejected === $tested ? 'REJECTED_100_PERCENT' : 'LEAK_DETECTED',
        ];
    }

    /** @return array<string,string> */
    private function querySecurity(mixed $source): array
    {
        if (! is_array($source)
            || ! in_array($source['hmac_state'] ?? null, ['VALID', 'INVALID', 'UNAVAILABLE'], true)
            || ! in_array($source['key_version_state'] ?? null, ['CURRENT', 'DRIFT', 'UNAVAILABLE'], true)
            || ! in_array($source['pii_state'] ?? null, ['ABSENT', 'PRESENT', 'UNKNOWN'], true)) {
            throw new \InvalidArgumentException('QUERY_SECURITY_INVALID');
        }

        return array_intersect_key($source, array_flip(['hmac_state', 'key_version_state', 'pii_state']));
    }

    /** @return array<string,string> */
    private function drift(mixed $source): array
    {
        if (! is_array($source) || array_keys($source) !== self::DRIFT_FIELDS) {
            throw new \InvalidArgumentException('DRIFT_VECTOR_INVALID');
        }
        foreach ($source as $state) {
            if (! in_array($state, ['MATCH', 'DRIFT', 'UNAVAILABLE'], true)) {
                throw new \InvalidArgumentException('DRIFT_VECTOR_INVALID');
            }
        }

        return $source;
    }

    /** @return array<string,mixed> */
    private function freshness(mixed $source): array
    {
        if (! is_array($source)) {
            throw new \InvalidArgumentException('EVIDENCE_FRESHNESS_INVALID');
        }
        $total = $this->count($source, 'total_count');
        $fresh = $this->count($source, 'fresh_count');
        $expired = $this->count($source, 'expired_count');
        if ($fresh + $expired !== $total) {
            throw new \InvalidArgumentException('EVIDENCE_FRESHNESS_INVALID');
        }

        return [
            'total_count' => $total,
            'fresh_count' => $fresh,
            'expired_count' => $expired,
            'model_evidence_count' => $fresh,
            'stale_evidence_model_allowed' => false,
        ];
    }

    /** @return array<string,string> */
    private function injection(mixed $source): array
    {
        if (! is_array($source)
            || ! in_array($source['prompt_state'] ?? null, ['PASS', 'DETECTED', 'UNAVAILABLE'], true)
            || ! in_array($source['tool_metadata_state'] ?? null, ['PASS', 'DETECTED', 'UNAVAILABLE'], true)) {
            throw new \InvalidArgumentException('INJECTION_STATE_INVALID');
        }

        return array_intersect_key($source, array_flip(['prompt_state', 'tool_metadata_state']));
    }

    /** @return array<string,int> */
    private function tools(mixed $source): array
    {
        if (! is_array($source)) {
            throw new \InvalidArgumentException('TOOL_AUTHORIZATION_INVALID');
        }
        $requested = $this->count($source, 'requested_count');
        $authorized = $this->count($source, 'authorized_count');
        if ($authorized > $requested) {
            throw new \InvalidArgumentException('TOOL_AUTHORIZATION_INVALID');
        }

        return ['requested_count' => $requested, 'authorized_count' => $authorized, 'unauthorized_count' => $requested - $authorized];
    }

    /** @return array<string,string> */
    private function posture(mixed $source): array
    {
        if (! is_array($source)
            || ! in_array($source['retention_state'] ?? null, ['COMPLIANT', 'VIOLATION', 'UNAVAILABLE'], true)
            || ! in_array($source['egress_state'] ?? null, ['COMPLIANT', 'VIOLATION', 'UNAVAILABLE'], true)) {
            throw new \InvalidArgumentException('POSTURE_INVALID');
        }

        return array_intersect_key($source, array_flip(['retention_state', 'egress_state']));
    }

    /** @return array{state:string,reason_codes:list<string>} */
    private function decision(array $private, array $query, array $drift, array $freshness, array $injection, array $tools, array $posture): array
    {
        $deny = [];
        $hold = [];
        if ($private['state'] !== 'REJECTED_100_PERCENT') {
            $deny[] = 'PRIVATE_NEGATIVE_SET_LEAK';
        }
        if ($query['hmac_state'] !== 'VALID' || $query['pii_state'] !== 'ABSENT') {
            $deny[] = 'QUERY_SECURITY_DENY';
        }
        if (in_array('DETECTED', $injection, true)) {
            $deny[] = 'METADATA_INJECTION_DENY';
        }
        if ($tools['unauthorized_count'] > 0) {
            $deny[] = 'UNAUTHORIZED_TOOL_DENY';
        }
        if (in_array('VIOLATION', $posture, true)) {
            $deny[] = 'RETENTION_EGRESS_DENY';
        }
        if ($query['key_version_state'] !== 'CURRENT' || in_array('DRIFT', $drift, true) || in_array('UNAVAILABLE', $drift, true)) {
            $hold[] = 'AUTHORITY_HASH_DRIFT_HOLD';
        }
        if ($freshness['expired_count'] > 0) {
            $hold[] = 'STALE_EVIDENCE_HOLD';
        }
        if (in_array('UNAVAILABLE', $query, true) || in_array('UNAVAILABLE', $injection, true) || in_array('UNAVAILABLE', $posture, true)) {
            $hold[] = 'SECURITY_EVIDENCE_UNAVAILABLE';
        }

        return match (true) {
            $deny !== [] => ['state' => 'DENY', 'reason_codes' => array_values(array_unique($deny))],
            $hold !== [] => ['state' => 'HOLD', 'reason_codes' => array_values(array_unique($hold))],
            default => ['state' => 'READY', 'reason_codes' => []],
        };
    }

    private function count(array $source, string $field): int
    {
        $value = $source[$field] ?? null;
        if (! is_int($value) || $value < 0 || $value > 100000000) {
            throw new \InvalidArgumentException('COUNT_INVALID');
        }

        return $value;
    }

    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new \InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }
}
