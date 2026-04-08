<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerJobListItemBundle
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $titles
     * @param  array<string, mixed>  $truthSummary
     * @param  array<string, mixed>  $trustSummary
     * @param  array<string, mixed>  $scoreSummary
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $provenanceMeta
     */
    public function __construct(
        public readonly array $identity,
        public readonly array $titles,
        public readonly array $truthSummary,
        public readonly array $trustSummary,
        public readonly array $scoreSummary,
        public readonly array $seoContract,
        public readonly array $provenanceMeta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bundle_kind' => 'career_job_list_item',
            'bundle_version' => 'career.protocol.job_list_item.v1',
            'identity' => $this->identity,
            'titles' => $this->titles,
            'truth_summary' => $this->truthSummary,
            'trust_summary' => $this->trustSummary,
            'score_summary' => $this->scoreSummary,
            'seo_contract' => $this->seoContract,
            'provenance_meta' => $this->provenanceMeta,
        ];
    }
}
