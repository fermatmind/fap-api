<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class SeoConversionFunnelReadService
{
    private const TABLE = 'analytics_seo_conversion_daily';

    /**
     * @var list<string>
     */
    private const METRICS = [
        'landing_pv_count',
        'article_to_test_click_count',
        'start_test_count',
        'complete_test_count',
        'view_result_count',
    ];

    /**
     * @var list<string>
     */
    private const PRIVATE_PATH_SEGMENTS = [
        'result',
        'results',
        'order',
        'orders',
        'share',
        'shares',
        'pay',
        'payment',
        'payments',
        'history',
    ];

    /**
     * @return array<string,mixed>
     */
    public function read(array $filters = [], int $limit = 25): array
    {
        $groupBy = $this->normalizeGroupBy($filters['group_by'] ?? null);
        $limit = max(1, min($limit, 100));

        if (! SchemaBaseline::hasTable(self::TABLE)) {
            return $this->emptyPayload($groupBy, ['analytics_seo_conversion_daily_missing']);
        }

        $groupColumns = $this->groupColumns($groupBy);
        $query = DB::table(self::TABLE)->select($groupColumns);

        foreach (self::METRICS as $metric) {
            $query->selectRaw(sprintf('SUM(%s) AS %s', $metric, $metric));
        }

        $this->applyFilters($query, $filters);

        $query->groupBy($groupColumns)
            ->orderByDesc('landing_pv_count')
            ->orderByDesc('start_test_count')
            ->limit($limit);

        $rows = [];
        foreach ($query->get() as $row) {
            $mapped = $this->mapRow($row, $groupBy);
            if ($mapped === null) {
                continue;
            }

            $rows[] = $mapped;
        }

        return [
            'source_table' => self::TABLE,
            'group_by' => $groupBy,
            'filters' => $this->safeFilters($filters),
            'privacy' => $this->privacyStatus(),
            'totals' => $this->totals($rows),
            'recent_rows' => $rows,
            'warnings' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPayload(string $groupBy, array $warnings): array
    {
        return [
            'source_table' => self::TABLE,
            'group_by' => $groupBy,
            'filters' => [],
            'privacy' => $this->privacyStatus(),
            'totals' => [
                'landing_pv_count' => 0,
                'article_to_test_click_count' => 0,
                'start_test_count' => 0,
                'complete_test_count' => 0,
                'view_result_count' => 0,
            ],
            'recent_rows' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string,string|bool>
     */
    private function privacyStatus(): array
    {
        return [
            'raw_session_id_exposed' => false,
            'session_dimension' => 'sha256_hash_only',
            'query_policy' => 'query_and_fragment_stripped_before_daily_storage',
            'private_path_policy' => 'result_order_share_pay_history_excluded',
            'raw_business_identifier_policy' => 'business_identifiers_rejected_before_daily_storage',
        ];
    }

    /**
     * @return list<string>
     */
    private function groupColumns(string $groupBy): array
    {
        return match ($groupBy) {
            'article' => ['source_article', 'lang', 'page_type', 'scale_id', 'form_id'],
            'test' => ['target_test', 'lang', 'scale_id', 'form_id'],
            'session' => ['session_id_hash', 'lang', 'source_article', 'target_test', 'scale_id', 'form_id'],
            default => ['url', 'lang', 'page_type', 'source_article', 'target_test', 'scale_id', 'form_id'],
        };
    }

    private function applyFilters(\Illuminate\Database\Query\Builder $query, array $filters): void
    {
        foreach ([
            'lang',
            'page_type',
            'source_article',
            'scale_id',
            'form_id',
            'session_id_hash',
        ] as $field) {
            $value = $this->normalizeText($filters[$field] ?? null, 160);
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        foreach (['url', 'source_url', 'target_test'] as $field) {
            $value = $this->normalizePublicPathFilter($filters[$field] ?? null);
            if ($value !== '') {
                $query->where(function (\Illuminate\Database\Query\Builder $nested) use ($field, $value): void {
                    $nested->where($field, $value)
                        ->orWhere($field, 'like', '%'.$value);
                });
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function safeFilters(array $filters): array
    {
        $safe = [];
        foreach ([
            'group_by',
            'lang',
            'page_type',
            'source_article',
            'scale_id',
            'form_id',
            'session_id_hash',
        ] as $field) {
            $value = $this->normalizeText($filters[$field] ?? null, 160);
            if ($value !== '') {
                $safe[$field] = $value;
            }
        }

        foreach (['url', 'source_url', 'target_test'] as $field) {
            $value = $this->normalizePublicPathFilter($filters[$field] ?? null);
            if ($value !== '') {
                $safe[$field] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function mapRow(object $row, string $groupBy): ?array
    {
        $urlPath = $this->safePath($row->url ?? null);
        $sourcePath = $this->safePath($row->source_url ?? null);
        $targetPath = $this->safePath($row->target_test ?? null);

        if ($urlPath !== null && $this->isPrivatePath($urlPath)) {
            return null;
        }

        $metrics = [];
        foreach (self::METRICS as $metric) {
            $metrics[$metric] = (int) ($row->{$metric} ?? 0);
        }

        return [
            'group_key' => $this->groupKey($row, $groupBy, $urlPath, $targetPath),
            'url_path' => $urlPath,
            'lang' => (string) ($row->lang ?? ''),
            'page_type' => (string) ($row->page_type ?? ''),
            'source_url_path' => $sourcePath,
            'source_article' => (string) ($row->source_article ?? ''),
            'target_test_path' => $targetPath,
            'scale_id' => (string) ($row->scale_id ?? ''),
            'form_id' => (string) ($row->form_id ?? ''),
            'session_id_hash' => (string) ($row->session_id_hash ?? ''),
            'referrer_host' => (string) ($row->referrer_host ?? ''),
            'metrics' => $metrics,
            'privacy' => [
                'raw_session_id_exposed' => false,
                'private_path_excluded' => true,
                'query_stripped' => true,
            ],
        ];
    }

    private function groupKey(object $row, string $groupBy, ?string $urlPath, ?string $targetPath): string
    {
        return match ($groupBy) {
            'article' => (string) ($row->source_article ?? ''),
            'test' => $targetPath ?? '',
            'session' => (string) ($row->session_id_hash ?? ''),
            default => $urlPath ?? '',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function totals(array $rows): array
    {
        $totals = array_fill_keys(self::METRICS, 0);

        foreach ($rows as $row) {
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            foreach (self::METRICS as $metric) {
                $totals[$metric] += (int) ($metrics[$metric] ?? 0);
            }
        }

        return $totals;
    }

    private function normalizeGroupBy(mixed $value): string
    {
        $candidate = $this->normalizeText($value, 32);

        return in_array($candidate, ['url', 'article', 'test', 'session'], true) ? $candidate : 'url';
    }

    private function normalizeText(mixed $value, int $maxLength): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', '', $normalized) ?? '';

        return mb_substr($normalized, 0, $maxLength);
    }

    private function normalizePublicPathFilter(mixed $value): string
    {
        $path = $this->safePath($value);
        if ($path === null || $this->isPrivatePath($path)) {
            return '';
        }

        return $path;
    }

    private function safePath(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }

        $path = parse_url($candidate, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return '/';
        }

        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function isPrivatePath(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', strtolower($path)), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return false;
        }

        $firstContentSegment = in_array($segments[0], ['en', 'zh', 'zh-cn', 'zh-tw'], true)
            ? ($segments[1] ?? '')
            : $segments[0];

        return in_array($firstContentSegment, self::PRIVATE_PATH_SEGMENTS, true);
    }
}
