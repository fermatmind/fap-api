<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerExplainabilitySummary
{
    /**
     * @param  array<string, mixed>  $subjectIdentity
     * @param  array<string, array<string, mixed>>  $scoreBundle
     * @param  array<string, mixed>|null  $strainRadar
     * @param  array<string, mixed>  $warnings
     * @param  array<string, mixed>  $claimPermissions
     * @param  array<string, mixed>  $integritySummary
     */
    public function __construct(
        public readonly string $subjectKind,
        public readonly array $subjectIdentity,
        public readonly array $scoreBundle,
        public readonly ?array $strainRadar,
        public readonly array $warnings,
        public readonly array $claimPermissions,
        public readonly array $integritySummary = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary_kind' => 'career_explainability',
            'summary_version' => 'career.explainability.v1',
            'subject_kind' => $this->subjectKind,
            'subject_identity' => $this->subjectIdentity,
            'score_bundle' => $this->scoreBundle,
            'strain_radar' => $this->strainRadar,
            'warnings' => $this->warnings,
            'claim_permissions' => $this->claimPermissions,
            'integrity_summary' => $this->integritySummary,
        ];
    }
}
