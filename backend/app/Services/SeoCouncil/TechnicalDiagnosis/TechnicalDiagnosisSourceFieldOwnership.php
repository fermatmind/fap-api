<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class TechnicalDiagnosisSourceFieldOwnership
{
    public function __construct(
        private readonly TechnicalDiagnosisContractRegistry $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function reference(): array
    {
        $matrix = $this->contracts->ownership();

        return [
            'version' => $matrix['ownership_version'],
            'hash' => $this->hasher->hash($matrix),
        ];
    }

    public function valid(): bool
    {
        $matrix = $this->contracts->ownership();
        $expected = ['schema_version', 'ownership_version', 'conflict_policy', 'unknown_pair_policy', 'sources'];
        $actual = array_keys($matrix);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || ($matrix['schema_version'] ?? null) !== 'seo.technical_source_field_ownership.v2'
            || ($matrix['ownership_version'] ?? null) !== '2.0.0'
            || ($matrix['conflict_policy'] ?? null) !== 'AUTHORITY_CONFLICT_HOLD'
            || ($matrix['unknown_pair_policy'] ?? null) !== 'EVIDENCE_HOLD'
            || ! is_array($matrix['sources'] ?? null)) {
            return false;
        }
        $pairs = [];
        foreach ($matrix['sources'] as $rule) {
            if (! is_array($rule)) {
                return false;
            }
            $ruleKeys = array_keys($rule);
            $required = ['source_type', 'authority_type', 'namespace', 'precedence', 'required_for_verified_fact', 'allowed_fields'];
            sort($ruleKeys, SORT_STRING);
            sort($required, SORT_STRING);
            $allowed = $rule['allowed_fields'] ?? null;
            $pair = (string) ($rule['source_type'] ?? '').'|'.(string) ($rule['authority_type'] ?? '');
            if ($ruleKeys !== $required || $pair === '|'
                || ! is_string($rule['namespace'] ?? null) || $rule['namespace'] === ''
                || ! is_int($rule['precedence'] ?? null)
                || ! is_bool($rule['required_for_verified_fact'] ?? null)
                || ! is_array($allowed) || $allowed === []
                || count($allowed) !== count(array_unique(array_filter($allowed, 'is_string')))
                || isset($pairs[$pair])) {
                return false;
            }
            $pairs[$pair] = true;
        }

        return count($pairs) >= 10;
    }

    /** @return null|array<string, mixed> */
    public function rule(string $sourceType, string $authorityType): ?array
    {
        foreach ((array) $this->contracts->ownership()['sources'] as $rule) {
            if (is_array($rule)
                && ($rule['source_type'] ?? null) === $sourceType
                && ($rule['authority_type'] ?? null) === $authorityType) {
                return $rule;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    public function fieldsAllowed(array $payload, array $rule): bool
    {
        $allowed = array_values(array_filter((array) ($rule['allowed_fields'] ?? []), 'is_string'));

        return array_diff(array_keys($payload), $allowed) === [];
    }
}
