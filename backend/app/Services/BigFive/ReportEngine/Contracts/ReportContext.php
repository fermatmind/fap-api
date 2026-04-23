<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Contracts;

final class ReportContext
{
    /**
     * @param  array<string,mixed>  $domains
     * @param  array<string,mixed>  $facets
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $scaleCode,
        public readonly string $formCode,
        public readonly array $domains,
        public readonly array $facets,
        public readonly array $quality = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $scoreVector = is_array($payload['score_vector'] ?? null) ? $payload['score_vector'] : [];

        return new self(
            locale: (string) ($payload['locale'] ?? 'zh-CN'),
            scaleCode: (string) ($payload['scale_code'] ?? 'BIG5_OCEAN'),
            formCode: (string) ($payload['form_code'] ?? 'big5_90'),
            domains: is_array($scoreVector['domains'] ?? null) ? $scoreVector['domains'] : [],
            facets: is_array($scoreVector['facets'] ?? null) ? $scoreVector['facets'] : [],
            quality: is_array($payload['quality'] ?? null) ? $payload['quality'] : [],
            meta: [
                'fixture_id' => (string) ($payload['fixture_id'] ?? ''),
                'sample_label' => (string) ($payload['sample_label'] ?? ''),
            ],
        );
    }

    public function domainPercentile(string $traitCode): int
    {
        $domain = is_array($this->domains[$traitCode] ?? null) ? $this->domains[$traitCode] : [];

        return (int) ($domain['percentile'] ?? 0);
    }

    public function domainBand(string $traitCode): string
    {
        $domain = is_array($this->domains[$traitCode] ?? null) ? $this->domains[$traitCode] : [];

        return (string) ($domain['band'] ?? '');
    }

    public function domainGradientId(string $traitCode): string
    {
        $domain = is_array($this->domains[$traitCode] ?? null) ? $this->domains[$traitCode] : [];

        return (string) ($domain['gradient_id'] ?? '');
    }

    public function facetPercentile(string $facetCode): int
    {
        $facet = is_array($this->facets[$facetCode] ?? null) ? $this->facets[$facetCode] : [];

        return (int) ($facet['percentile'] ?? 0);
    }

    public function hasFacetPercentile(string $facetCode): bool
    {
        $facet = is_array($this->facets[$facetCode] ?? null) ? $this->facets[$facetCode] : [];

        return array_key_exists('percentile', $facet);
    }

    /**
     * @return array<string,mixed>
     */
    public function scoreVector(): array
    {
        return [
            'domains' => $this->domains,
            'facets' => $this->facets,
        ];
    }
}
