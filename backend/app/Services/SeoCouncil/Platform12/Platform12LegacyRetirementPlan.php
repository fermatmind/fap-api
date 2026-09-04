<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform12LegacyRetirementPlan
{
    /** @var list<string> */
    private const PRESERVED_EVIDENCE = [
        'exact_sha_ci_receipts',
        'authority_packages_and_manifests',
        'audit_and_history_records',
        'rollback_and_lkg_evidence',
    ];

    public function __construct(
        private readonly Platform12LegacyCallerInventory $inventory,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed>|null $observedInventory @return array<string, mixed> */
    public function build(?array $observedInventory = null): array
    {
        $inventory = $observedInventory ?? $this->inventory->build();
        $inventoryValid = $this->inventoryValid($inventory);
        $eligible = $inventoryValid ? array_values(array_filter(
            $inventory['legacy_entrypoints'],
            static fn (array $row): bool => ($row['classification'] ?? null) === 'retired'
                && ($row['zero_call_proven'] ?? null) === true
                && ($row['delete_ready'] ?? null) === true
                && is_string($row['replacement'] ?? null)
                && $row['replacement'] !== '',
        )) : [];
        $deferred = $inventoryValid ? array_values(array_map(
            static fn (array $row): array => [
                'entrypoint' => $row['entrypoint'],
                'reason' => ($row['zero_call_proven'] ?? false) === true
                    ? 'NO_APPROVED_TOMBSTONE_OR_ARCHIVE_ACTION'
                    : 'CALL_OR_REFERENCE_REMAINS',
                'reference_count' => $row['reference_count'],
            ],
            array_filter($inventory['legacy_entrypoints'], static fn (array $row): bool => ! in_array($row, $eligible, true)),
        )) : [];
        $protected = $inventoryValid ? array_values(array_map(
            static fn (array $row): array => [
                'caller_id' => $row['caller_id'],
                'entrypoint' => $row['entrypoint'],
                'state' => $row['state'],
                'authority_owner' => $row['authority_owner'],
            ],
            array_filter($inventory['current_callers'], static fn (array $row): bool => ($row['state'] ?? null) === 'active_not_owned_by_council'),
        )) : [];

        $plan = [
            'receipt_version' => 'seo.platform12_legacy_retirement_plan.v1',
            'state' => ! $inventoryValid
                ? 'INVENTORY_HOLD'
                : ($eligible === [] ? 'NO_ELIGIBLE_SUPERSEDED_ENTRYPOINTS' : 'REVIEW_REQUIRED'),
            'inventory_ref' => [
                'version' => $inventory['inventory_version'] ?? null,
                'hash' => $inventory['inventory_hash'] ?? null,
            ],
            'eligibility_rule' => 'zero_call_proven=true AND classification=retired AND delete_ready=true AND replacement is present',
            'tombstone_actions' => [],
            'redirect_actions' => [],
            'artifact_archive_actions' => [],
            'eligible_entrypoints' => array_column($eligible, 'entrypoint'),
            'deferred_entrypoints' => $deferred,
            'protected_current_operations' => $protected,
            'preserved_evidence' => self::PRESERVED_EVIDENCE,
            'summary' => [
                'eligible_count' => count($eligible),
                'tombstone_count' => 0,
                'redirect_count' => 0,
                'archive_count' => 0,
                'deferred_count' => count($deferred),
                'protected_active_operation_count' => count($protected),
            ],
            'destructive_data_deletion' => false,
            'authority_created' => false,
            'scheduler_changes' => 0,
            'runtime_switches_changed' => false,
            'execution_allowed' => false,
            'writes' => 0,
        ];
        $plan['receipt_hash'] = $this->hasher->hash($plan);

        return $plan;
    }

    /** @param array<string, mixed> $inventory */
    private function inventoryValid(array $inventory): bool
    {
        if (($inventory['schema_version'] ?? null) !== 'seo.platform12_legacy_caller_inventory.v1'
            || ($inventory['inventory_version'] ?? null) !== '1.0.0'
            || ! is_array($inventory['legacy_entrypoints'] ?? null)
            || ! is_array($inventory['current_callers'] ?? null)
            || ! is_string($inventory['inventory_hash'] ?? null)
            || ! hash_equals($this->hasher->hashWithout($inventory, 'inventory_hash'), $inventory['inventory_hash'])) {
            return false;
        }

        foreach ($inventory['legacy_entrypoints'] as $row) {
            if (! is_array($row)
                || ! is_string($row['entrypoint'] ?? null)
                || ! is_int($row['reference_count'] ?? null)
                || ! is_bool($row['zero_call_proven'] ?? null)
                || ! is_bool($row['delete_ready'] ?? null)
                || (($row['zero_call_proven'] ?? false) === true) !== (($row['reference_count'] ?? -1) === 0)
                || (($row['delete_ready'] ?? false) === true && (
                    ($row['classification'] ?? null) !== 'retired'
                    || ($row['zero_call_proven'] ?? false) !== true
                    || ($row['reference_count'] ?? -1) !== 0
                    || ! is_string($row['replacement'] ?? null)
                    || $row['replacement'] === ''
                ))) {
                return false;
            }
        }

        return true;
    }
}
