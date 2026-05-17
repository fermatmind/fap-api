<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class GscQueryClassifier
{
    /**
     * @param  list<string>|null  $brandTerms
     */
    public function __construct(
        private readonly ?array $brandTerms = null,
    ) {}

    public function classify(?string $query): string
    {
        $query = $this->normalize($query);

        if ($query === '') {
            return 'unknown';
        }

        $hasBrand = false;
        foreach ($this->brandTerms() as $term) {
            if ($term !== '' && str_contains($query, $this->normalize($term))) {
                $hasBrand = true;
                break;
            }
        }

        if (! $hasBrand) {
            return 'non_brand';
        }

        $withoutBrand = $query;
        foreach ($this->brandTerms() as $term) {
            $withoutBrand = str_replace($this->normalize($term), '', $withoutBrand);
        }

        $withoutBrand = trim((string) preg_replace('/[\s\-_]+/u', ' ', $withoutBrand));

        return $withoutBrand === '' ? 'brand' : 'mixed';
    }

    public function isBrand(?string $query): bool
    {
        return in_array($this->classify($query), ['brand', 'mixed'], true);
    }

    /**
     * @return list<string>
     */
    public function brandTerms(): array
    {
        if ($this->brandTerms !== null) {
            return $this->brandTerms;
        }

        $terms = config('seo_intel.gsc_foundation.brand_query_terms', []);

        if (! is_array($terms)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $term): string => (string) $term, $terms),
            static fn (string $term): bool => $term !== ''
        ));
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value), 'UTF-8');
    }
}
