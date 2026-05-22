<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

abstract class AbstractSeoDashboardReadService
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TABLES = [
        'seo_urls',
        'seo_url_entities',
        'seo_issue_queue',
        'seo_search_channel_queue_items',
        'seo_search_channel_queue_batches',
        'seo_search_channel_queue_events',
        'seo_crawler_log_daily_aggregates',
    ];

    public function __construct(
        protected readonly ?string $connectionName = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function allowedTables(): array
    {
        return self::ALLOWED_TABLES;
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName ?? (string) config('seo_intel.connection', 'seo_intel'));
    }

    protected function table(string $table): Builder
    {
        if (! in_array($table, self::ALLOWED_TABLES, true)) {
            throw new InvalidArgumentException('Table is outside the Ops SEO dashboard read boundary.');
        }

        return $this->connection()->table($table);
    }

    /**
     * @return list<array{label:string,count:int}>
     */
    protected function groupedCounts(string $table, string $column): array
    {
        return $this->table($table)
            ->select($column)
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(static fn (object $row): array => [
                'label' => (string) ($row->{$column} ?? 'unknown'),
                'count' => (int) ($row->aggregate_count ?? 0),
            ])
            ->all();
    }

    protected function safePath(?string $canonicalUrl): ?string
    {
        if ($canonicalUrl === null || trim($canonicalUrl) === '') {
            return null;
        }

        $path = parse_url($canonicalUrl, PHP_URL_PATH);
        $query = parse_url($canonicalUrl, PHP_URL_QUERY);

        if (! is_string($path) || $path === '') {
            return '/';
        }

        return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }
}
