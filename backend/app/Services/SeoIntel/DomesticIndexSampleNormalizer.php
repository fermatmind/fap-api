<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class DomesticIndexSampleNormalizer
{
    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    public function normalize(array $sample): array
    {
        $url = $this->stringOrNull($sample['canonical_url'] ?? null);

        return [
            'engine' => $this->stringOrNull($sample['engine'] ?? null) ?? 'unknown',
            'canonical_url_hash' => $url === null ? null : hash('sha256', $url),
            'locale' => $this->stringOrNull($sample['locale'] ?? null),
            'sample_type' => $this->stringOrNull($sample['sample_type'] ?? null) ?? 'manual_or_serp_sample',
            'index_status' => $this->normalizeStatus($sample['index_status'] ?? null),
            'title_hash' => $this->hashOptional($sample['title'] ?? null),
            'snippet_hash' => $this->hashOptional($sample['snippet'] ?? null),
            'metadata_json' => [
                'fixture_only' => true,
                'raw_title_stored' => false,
                'raw_snippet_stored' => false,
                'seo_truth_source' => false,
            ],
        ];
    }

    private function normalizeStatus(mixed $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'indexed', 'present' => 'indexed',
            'not_indexed', 'missing' => 'not_indexed',
            default => 'unknown',
        };
    }

    private function hashOptional(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value === null ? null : hash('sha256', $value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
