<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerStrongIndexEligibilityMember
{
    /**
     * @param  list<string>  $decisionReasons
     * @param  array<string, mixed>  $decisionEvidence
     */
    public function __construct(
        public readonly string $memberKind,
        public readonly string $canonicalSlug,
        public readonly string $strongIndexDecision,
        public readonly array $decisionReasons,
        public readonly array $decisionEvidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => $this->memberKind,
            'canonical_slug' => $this->canonicalSlug,
            'strong_index_decision' => $this->strongIndexDecision,
            'decision_reasons' => $this->decisionReasons,
            'decision_evidence' => $this->decisionEvidence,
        ];
    }
}
