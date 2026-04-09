<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\Import\FirstWaveAuthorityOverrideReader;

final class FirstWaveBlockedGovernancePolicy
{
    public function __construct(
        private readonly FirstWaveBlockedRegistryReader $blockedRegistryReader,
        private readonly FirstWaveAuthorityOverrideReader $authorityOverrideReader,
    ) {}

    /**
     * @return array{
     *   blocker_type:?string,
     *   override_eligible:bool,
     *   remediation_class:?string,
     *   blocked_governance_status:?string,
     *   authority_override_supplied:bool,
     *   review_required:bool,
     *   notes:list<string>
     * }
     */
    public function classify(string $slug, string $status, ?string $blockedRegistryPath = null, ?string $overridePath = null): array
    {
        $blockedEntry = $this->blockedRegistryReader->bySlug($blockedRegistryPath)[$slug] ?? null;
        $overrideEntry = $this->authorityOverrideReader->bySlug($overridePath)[$slug] ?? null;
        $overrideValue = is_array($overrideEntry['overrides'] ?? null)
            ? trim((string) ($overrideEntry['overrides']['crosswalk_source_code'] ?? ''))
            : '';
        $overrideSupplied = $overrideValue !== '';

        if ($status !== 'blocked') {
            return [
                'blocker_type' => null,
                'override_eligible' => false,
                'remediation_class' => null,
                'blocked_governance_status' => null,
                'authority_override_supplied' => $overrideSupplied,
                'review_required' => false,
                'notes' => [],
            ];
        }

        if (! is_array($blockedEntry)) {
            return [
                'blocker_type' => null,
                'override_eligible' => false,
                'remediation_class' => null,
                'blocked_governance_status' => null,
                'authority_override_supplied' => $overrideSupplied,
                'review_required' => false,
                'notes' => [],
            ];
        }

        $overrideEligible = (bool) ($blockedEntry['override_eligible'] ?? false);
        $blockedGovernanceStatus = null;
        if ($status === 'blocked') {
            $blockedGovernanceStatus = $overrideEligible
                ? 'blocked_override_eligible'
                : 'blocked_not_safely_remediable';
        }

        $notes = array_values(array_filter(array_map(
            static fn (mixed $note): string => trim((string) $note),
            (array) ($blockedEntry['notes'] ?? [])
        )));

        if ($overrideEligible && ! $overrideSupplied) {
            $notes[] = 'authority_override_not_supplied';
        }

        if ($overrideEligible && $overrideSupplied) {
            $notes[] = 'authority_override_supplied';
        }

        return [
            'blocker_type' => (string) ($blockedEntry['blocker_type'] ?? ''),
            'override_eligible' => $overrideEligible,
            'remediation_class' => (string) ($blockedEntry['remediation_class'] ?? ''),
            'blocked_governance_status' => $blockedGovernanceStatus,
            'authority_override_supplied' => $overrideSupplied,
            'review_required' => (bool) ($blockedEntry['review_required'] ?? false),
            'notes' => array_values(array_unique($notes)),
        ];
    }
}
