<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerSearchResultBundle
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $titles
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $trustSummary
     * @param  array<string, mixed>  $provenanceMeta
     */
    public function __construct(
        public readonly string $matchKind,
        public readonly string $matchedText,
        public readonly array $identity,
        public readonly array $titles,
        public readonly array $seoContract,
        public readonly array $trustSummary,
        public readonly array $provenanceMeta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bundle_kind' => 'career_search_result',
            'bundle_version' => 'career.protocol.search_result.v1',
            'match_kind' => $this->matchKind,
            'matched_text' => $this->matchedText,
            'identity' => $this->identity,
            'titles' => $this->titles,
            'seo_contract' => $this->seoContract,
            'trust_summary' => $this->trustSummary,
            'provenance_meta' => $this->provenanceMeta,
        ];
    }
}
