<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerTransitionPreviewBundle
{
    /**
     * @param  array<string, mixed>  $targetJob
     * @param  array<string, mixed>  $scoreSummary
     * @param  array<string, mixed>  $trustSummary
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $provenanceMeta
     */
    public function __construct(
        public readonly string $pathType,
        public readonly array $targetJob,
        public readonly array $scoreSummary,
        public readonly array $trustSummary,
        public readonly array $seoContract,
        public readonly array $provenanceMeta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bundle_kind' => 'career_transition_preview',
            'bundle_version' => 'career.protocol.transition_preview.v1',
            'path_type' => $this->pathType,
            'target_job' => $this->targetJob,
            'score_summary' => $this->scoreSummary,
            'trust_summary' => $this->trustSummary,
            'seo_contract' => $this->seoContract,
            'provenance_meta' => $this->provenanceMeta,
        ];
    }
}
