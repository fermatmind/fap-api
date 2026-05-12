<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class Career2786FullAuditArtifact
{
    /**
     * @param  array<string, int>  $byReason
     * @param  array<string, array<string, int>>  $byLayer
     * @param  list<array<string, mixed>>  $sections
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $status,
        public readonly int $totalExpected,
        public readonly int $auditedCount,
        public readonly int $eligibleCount,
        public readonly int $blockedCount,
        public readonly bool $readyForExpansion,
        public readonly array $byReason,
        public readonly array $byLayer,
        public readonly array $sections,
        public readonly array $sidecars = [],
    ) {
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        foreach (['total_expected' => $this->totalExpected, 'audited_count' => $this->auditedCount, 'eligible_count' => $this->eligibleCount, 'blocked_count' => $this->blockedCount] as $key => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('Career 2786 audit artifact [%s] must be non-negative.', $key));
            }
        }
        if (
            (array_is_list($this->byReason) && $this->byReason !== [])
            || (array_is_list($this->byLayer) && $this->byLayer !== [])
            || ! array_is_list($this->sections)
            || ! array_is_list($this->sidecars)
        ) {
            throw new InvalidArgumentException('Career 2786 audit artifact maps/lists are malformed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_kind' => 'career_2786_canonical_eligibility_audit_report',
            'artifact_version' => 'career.2786_canonical_eligibility_audit_report.v1',
            'status' => $this->status,
            'total_expected' => $this->totalExpected,
            'audited_count' => $this->auditedCount,
            'eligible_count' => $this->eligibleCount,
            'blocked_count' => $this->blockedCount,
            'ready_for_expansion' => $this->readyForExpansion,
            'by_reason' => $this->byReason,
            'by_layer' => $this->byLayer,
            'sections' => $this->sections,
            'sidecars' => array_map(static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(), $this->sidecars),
            'read_only' => true,
            'writes_database' => false,
        ];
    }
}
