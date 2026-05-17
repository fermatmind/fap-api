<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class GscSearchAnalyticsRowNormalizer
{
    public function __construct(
        private readonly GscQueryClassifier $queryClassifier,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        $query = $this->stringOrNull($row['query'] ?? null);
        $canonicalUrl = $this->stringOrNull($row['canonical_url'] ?? $row['page'] ?? null);
        $queryType = $this->queryClassifier->classify($query);
        $impressions = max(0, (int) ($row['impressions'] ?? 0));
        $clicks = max(0, (int) ($row['clicks'] ?? 0));
        $ctr = $row['ctr'] ?? null;
        $position = $row['position'] ?? $row['average_position'] ?? null;

        return [
            'report_date' => substr((string) ($row['date'] ?? now()->subDays(3)->toDateString()), 0, 10),
            'canonical_url_hash' => $canonicalUrl === null ? null : hash('sha256', $canonicalUrl),
            'canonical_url' => $canonicalUrl,
            'query_hash' => $query === null ? null : hash('sha256', $query),
            'query_display_masked' => $this->maskQuery($query),
            'locale' => $this->stringOrNull($row['locale'] ?? null),
            'source_engine' => 'google',
            'device' => $this->stringOrNull($row['device'] ?? null),
            'country' => $this->stringOrNull($row['country'] ?? null),
            'search_type' => $this->stringOrNull($row['search_type'] ?? 'web'),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr_ppm' => $ctr === null ? $this->ctrPartsPerMillion($clicks, $impressions) : max(0, (int) round((float) $ctr * 1_000_000)),
            'average_position_milli' => $position === null ? null : max(0, (int) round((float) $position * 1000)),
            'is_brand_query' => $this->queryClassifier->isBrand($query),
            'query_type' => $queryType,
            'data_state' => (string) ($row['data_state'] ?? 'final'),
            'metadata_json' => [
                'row_source' => 'fixture',
                'purchase_attribution_allowed' => false,
            ],
        ];
    }

    private function ctrPartsPerMillion(int $clicks, int $impressions): ?int
    {
        if ($impressions <= 0) {
            return null;
        }

        return (int) floor(($clicks / $impressions) * 1_000_000);
    }

    private function maskQuery(?string $query): ?string
    {
        if ($query === null || trim($query) === '') {
            return null;
        }

        $query = trim($query);
        $length = mb_strlen($query, 'UTF-8');

        if ($length <= 2) {
            return mb_substr($query, 0, 1, 'UTF-8').'*';
        }

        return mb_substr($query, 0, 1, 'UTF-8')
            .str_repeat('*', min(12, $length - 2))
            .mb_substr($query, -1, 1, 'UTF-8');
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
