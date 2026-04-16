<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerCrosswalkBacklogConvergenceMember
{
    /**
     * @param  list<string>  $queueReason
     * @param  array<string, mixed>  $evidenceRefs
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly ?string $currentCrosswalkMode,
        public readonly string $convergenceState,
        public readonly array $queueReason,
        public readonly ?int $agingDays,
        public readonly ?string $latestPatchStatus,
        public readonly bool $overrideApplied,
        public readonly ?string $resolvedTargetKind,
        public readonly ?string $resolvedTargetSlug,
        public readonly array $evidenceRefs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'current_crosswalk_mode' => $this->currentCrosswalkMode,
            'convergence_state' => $this->convergenceState,
            'queue_reason' => $this->queueReason,
            'aging_days' => $this->agingDays,
            'latest_patch_status' => $this->latestPatchStatus,
            'override_applied' => $this->overrideApplied,
            'resolved_target_kind' => $this->resolvedTargetKind,
            'resolved_target_slug' => $this->resolvedTargetSlug,
            'evidence_refs' => $this->evidenceRefs,
        ];
    }
}
