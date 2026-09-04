<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;

final class Platform12MissionCatalogValidator
{
    private const CATALOG_FIELDS = [
        'schema_version', 'catalog_id', 'catalog_version', 'catalog_state',
        'dependency_refs', 'missions', 'runtime_activation_allowed', 'catalog_hash',
    ];

    private const MISSION_FIELDS = [
        'mission_id', 'cadence', 'timezone', 'natural_slot', 'family', 'locale', 'review_domain',
        'required_evidence', 'eligible_capability', 'priority', 'timeout_seconds', 'max_attempts',
        'budgets', 'failure_policy', 'output_schema',
    ];

    public function __construct(
        private readonly Platform12ContractRegistry $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $catalog @return array<string, mixed> */
    public function validate(array $catalog): array
    {
        $this->exactKeys($catalog, self::CATALOG_FIELDS, 'MISSION_CATALOG_FIELDS_INVALID');
        if (($catalog['schema_version'] ?? null) !== 'seo.platform12_mission_catalog.v1'
            || ($catalog['catalog_id'] ?? null) !== 'fermatmind.seo.platform12_mission_catalog'
            || ! is_string($catalog['catalog_version'] ?? null)
            || preg_match('/^\d+\.\d+\.\d+$/D', $catalog['catalog_version']) !== 1
            || ($catalog['catalog_state'] ?? null) !== 'FOUNDATION_ONLY'
            || ($catalog['runtime_activation_allowed'] ?? null) !== false) {
            throw new InvalidArgumentException('MISSION_CATALOG_IDENTITY_INVALID');
        }
        if (! is_array($catalog['dependency_refs'] ?? null)
            || $catalog['dependency_refs'] !== $this->contracts->missionCatalogDependencyRefs()) {
            throw new InvalidArgumentException('MISSION_CATALOG_DEPENDENCY_DRIFT');
        }
        if (! is_array($catalog['missions'] ?? null) || ! array_is_list($catalog['missions']) || count($catalog['missions']) > 128) {
            throw new InvalidArgumentException('MISSION_CATALOG_MISSIONS_INVALID');
        }

        $seen = [];
        foreach ($catalog['missions'] as $mission) {
            if (! is_array($mission)) {
                throw new InvalidArgumentException('MISSION_CATALOG_ENTRY_INVALID');
            }
            $this->validateMission($mission);
            $missionId = (string) $mission['mission_id'];
            if (isset($seen[$missionId])) {
                throw new InvalidArgumentException('MISSION_CATALOG_DUPLICATE_ID');
            }
            $seen[$missionId] = true;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', (string) ($catalog['catalog_hash'] ?? '')) !== 1
            || ! hash_equals($this->hasher->hashWithout($catalog, 'catalog_hash'), (string) $catalog['catalog_hash'])) {
            throw new InvalidArgumentException('MISSION_CATALOG_HASH_INVALID');
        }

        return $catalog;
    }

    /** @param array<string, mixed> $delivery */
    public function deliveryMatchesCurrentCatalog(array $delivery): bool
    {
        if (array_keys($delivery) !== ['catalog_id', 'catalog_version', 'catalog_hash']) {
            return false;
        }
        $catalog = $this->contracts->missionCatalog();

        return $delivery['catalog_id'] === $catalog['catalog_id']
            && $delivery['catalog_version'] === $catalog['catalog_version']
            && $delivery['catalog_hash'] === $catalog['catalog_hash'];
    }

    /** @param array<string, mixed> $mission */
    private function validateMission(array $mission): void
    {
        $this->exactKeys($mission, self::MISSION_FIELDS, 'MISSION_CATALOG_ENTRY_FIELDS_INVALID');
        $cadence = $mission['cadence'] ?? null;
        $slotPattern = match ($cadence) {
            'daily' => '/^daily:ALL:(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',
            'weekly' => '/^weekly:(MON|TUE|WED|THU|FRI|SAT|SUN):(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',
            'monthly' => '/^monthly:(0[1-9]|[12][0-9]|3[01]):(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',
            default => null,
        };
        if (preg_match('/^seo\.platform12\.[a-z0-9][a-z0-9._-]{0,95}$/D', (string) ($mission['mission_id'] ?? '')) !== 1
            || $slotPattern === null
            || ($mission['timezone'] ?? null) !== 'Asia/Shanghai'
            || preg_match($slotPattern, (string) ($mission['natural_slot'] ?? '')) !== 1
            || ! in_array($mission['family'] ?? null, ['tests', 'articles_topics', 'career', 'personality', 'trust_method_help', 'other_public'], true)
            || ! in_array($mission['locale'] ?? null, ['en', 'zh-CN'], true)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string) ($mission['review_domain'] ?? '')) !== 1
            || preg_match('/^seo\.[a-z][a-z0-9._-]{0,95}$/D', (string) ($mission['eligible_capability'] ?? '')) !== 1
            || ! in_array($mission['priority'] ?? null, ['critical', 'high', 'normal', 'low'], true)
            || ! is_int($mission['timeout_seconds'] ?? null)
            || $mission['timeout_seconds'] < 1 || $mission['timeout_seconds'] > 900
            || ! is_int($mission['max_attempts'] ?? null)
            || $mission['max_attempts'] < 1 || $mission['max_attempts'] > 5) {
            throw new InvalidArgumentException('MISSION_CATALOG_ENTRY_INVALID');
        }
        $this->stringList($mission['required_evidence'] ?? null, 32, 'MISSION_CATALOG_EVIDENCE_INVALID');
        $this->validateBudgets($mission['budgets'] ?? null);
        $this->validateFailurePolicy($mission['failure_policy'] ?? null);
        $this->validateReference($mission['output_schema'] ?? null, 'MISSION_CATALOG_OUTPUT_SCHEMA_INVALID');
    }

    private function validateBudgets(mixed $budgets): void
    {
        if (! is_array($budgets)) {
            throw new InvalidArgumentException('MISSION_CATALOG_BUDGET_INVALID');
        }
        $this->exactKeys($budgets, ['model_calls', 'model_input_tokens', 'model_output_tokens', 'tool_calls', 'cost_microusd'], 'MISSION_CATALOG_BUDGET_INVALID');
        $limits = [
            'model_calls' => 8,
            'model_input_tokens' => 100000,
            'model_output_tokens' => 20000,
            'tool_calls' => 32,
            'cost_microusd' => 10000000,
        ];
        foreach ($limits as $field => $limit) {
            if (! is_int($budgets[$field] ?? null) || $budgets[$field] < 0 || $budgets[$field] > $limit) {
                throw new InvalidArgumentException('MISSION_CATALOG_BUDGET_INVALID');
            }
        }
    }

    private function validateFailurePolicy(mixed $policy): void
    {
        if (! is_array($policy)) {
            throw new InvalidArgumentException('MISSION_CATALOG_FAILURE_POLICY_INVALID');
        }
        $this->exactKeys($policy, ['terminal_state', 'retry_strategy', 'initial_backoff_seconds', 'max_backoff_seconds'], 'MISSION_CATALOG_FAILURE_POLICY_INVALID');
        if (! in_array($policy['terminal_state'] ?? null, ['HOLD', 'FAILED'], true)
            || ! in_array($policy['retry_strategy'] ?? null, ['none', 'bounded_exponential'], true)
            || ! is_int($policy['initial_backoff_seconds'] ?? null)
            || ! is_int($policy['max_backoff_seconds'] ?? null)
            || $policy['initial_backoff_seconds'] < 0 || $policy['initial_backoff_seconds'] > 300
            || $policy['max_backoff_seconds'] < $policy['initial_backoff_seconds']
            || $policy['max_backoff_seconds'] > 1800) {
            throw new InvalidArgumentException('MISSION_CATALOG_FAILURE_POLICY_INVALID');
        }
        if ($policy['retry_strategy'] === 'none'
            && ($policy['initial_backoff_seconds'] !== 0 || $policy['max_backoff_seconds'] !== 0)) {
            throw new InvalidArgumentException('MISSION_CATALOG_FAILURE_POLICY_INVALID');
        }
    }

    private function validateReference(mixed $reference, string $error): void
    {
        if (! is_array($reference)) {
            throw new InvalidArgumentException($error);
        }
        $this->exactKeys($reference, ['id', 'version', 'hash'], $error);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', (string) ($reference['id'] ?? '')) !== 1
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,31}$/D', (string) ($reference['version'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($reference['hash'] ?? '')) !== 1) {
            throw new InvalidArgumentException($error);
        }
    }

    private function stringList(mixed $value, int $maximum, string $error): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > $maximum || count(array_unique($value)) !== count($value)) {
            throw new InvalidArgumentException($error);
        }
        foreach ($value as $item) {
            if (! is_string($item) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $item) !== 1) {
                throw new InvalidArgumentException($error);
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys, string $error): void
    {
        if (array_keys($value) !== $keys) {
            throw new InvalidArgumentException($error);
        }
    }
}
