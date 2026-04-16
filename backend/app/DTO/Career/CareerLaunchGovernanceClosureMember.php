<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerLaunchGovernanceClosureMember
{
    /**
     * @param  list<string>  $blockingReasons
     * @param  array<string, mixed>  $evidenceRefs
     */
    public function __construct(
        public readonly string $memberKind,
        public readonly string $canonicalSlug,
        public readonly string $releaseState,
        public readonly string $strongIndexState,
        public readonly string $operationsState,
        public readonly string $governanceState,
        public readonly bool $strongIndexReady,
        public readonly bool $strongOperationsReady,
        public readonly array $blockingReasons,
        public readonly array $evidenceRefs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => $this->memberKind,
            'canonical_slug' => $this->canonicalSlug,
            'release_state' => $this->releaseState,
            'strong_index_state' => $this->strongIndexState,
            'operations_state' => $this->operationsState,
            'governance_state' => $this->governanceState,
            'strong_index_ready' => $this->strongIndexReady,
            'strong_operations_ready' => $this->strongOperationsReady,
            'blocking_reasons' => $this->blockingReasons,
            'evidence_refs' => $this->evidenceRefs,
        ];
    }
}
