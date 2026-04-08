<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerRecommendationIndexItemBundle
{
    /**
     * @param  array<string, mixed>  $recommendationSubjectMeta
     * @param  array<string, mixed>  $scoreSummary
     * @param  array<string, mixed>  $trustSummary
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $provenanceMeta
     */
    public function __construct(
        public readonly array $recommendationSubjectMeta,
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
            'bundle_kind' => 'career_recommendation_index_item',
            'bundle_version' => 'career.protocol.recommendation_index_item.v1',
            'recommendation_subject_meta' => $this->recommendationSubjectMeta,
            'score_summary' => $this->scoreSummary,
            'trust_summary' => $this->trustSummary,
            'seo_contract' => $this->seoContract,
            'provenance_meta' => $this->provenanceMeta,
        ];
    }
}
