<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class PublicCanonicalConsumerSnapshot
{
    public const SCHEMA_VERSION = 'seo.public-canonical-consumer-snapshot.v1';

    public const POINTER_CACHE_KEY = 'seo:url-truth-consumers:v1:active';

    public const FRESH_CACHE_KEY = 'seo:url-truth-consumers:v1:fresh';

    private const SNAPSHOT_CACHE_PREFIX = 'seo:url-truth-consumers:v1:snapshot:';

    private const LOCK_CACHE_KEY = 'seo:url-truth-consumers:v1:publish-lock';

    private const FRESH_TTL_SECONDS = 600;

    /**
     * @return array{schema_version:string,fingerprint:string,items:list<array{loc:string,locale:string,page_family:string,page_entity_type:string,lastmod:?string}>}
     */
    public function read(): array
    {
        try {
            return Cache::lock(self::LOCK_CACHE_KEY, 30)->block(10, function (): array {
                $active = $this->readActiveSnapshot();
                if ($active !== null && Cache::get(self::FRESH_CACHE_KEY) === $active['fingerprint']) {
                    return $active;
                }

                try {
                    $candidate = $this->buildCandidate();
                    $this->publish($candidate);

                    return $candidate;
                } catch (Throwable $throwable) {
                    if ($active !== null) {
                        return $active;
                    }

                    throw $throwable;
                }
            });
        } catch (Throwable $throwable) {
            $active = $this->readActiveSnapshot();
            if ($active !== null) {
                return $active;
            }

            throw new RuntimeException('No readable URL Truth consumer snapshot or LKG is available.', 0, $throwable);
        }
    }

    /** @return list<array{loc:string,locale:string,page_family:string,page_entity_type:string,lastmod:?string}> */
    public function items(): array
    {
        return $this->read()['items'];
    }

    public function renderSitemapXml(): string
    {
        $rows = $this->items();
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($rows as $row) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($row['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
            if ($row['lastmod'] !== null) {
                $lines[] = '    <lastmod>'.$row['lastmod'].'</lastmod>';
            }
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    public function renderLlmsText(bool $full): string
    {
        $items = $this->items();
        $baseUrl = rtrim((string) config('seo_intel.public_canonical_host', config('app.frontend_url', '')), '/');
        $title = $full ? 'llms-full.txt' : 'llms.txt';
        $lines = [
            "# FermatMind {$title}",
            "Site: {$baseUrl}",
            'Languages: en, zh',
            'Total indexable entries: '.count($items),
            '',
            $full ? '## Public Canonical URLs' : 'Public Canonical URLs:',
        ];

        foreach ($items as $item) {
            $suffix = $item['lastmod'] === null ? '' : ' | Last-Modified: '.$item['lastmod'];
            $lines[] = '- '.$item['loc'].$suffix;
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<string, mixed> */
    public function closeoutReceipt(): array
    {
        $receipt = Cache::lock(self::LOCK_CACHE_KEY, 30)->block(10, function (): array {
            $previous = $this->readActiveSnapshot();
            if ($previous === null) {
                $this->publish($this->buildCandidate());
                $previous = $this->readActiveSnapshot();
            }
            if ($previous === null) {
                throw new RuntimeException('A readable closeout baseline snapshot is required.');
            }

            $candidate = $this->buildCandidate();
            $this->publish($candidate);
            $first = $this->readActiveSnapshot();
            $pointer = Cache::get(self::POINTER_CACHE_KEY);
            $second = $this->readActiveSnapshot();
            $previousStored = Cache::get(self::SNAPSHOT_CACHE_PREFIX.$previous['fingerprint']);
            $previousReadable = is_array($previousStored) && $this->isValidSnapshot($previousStored);
            if ($first === null || $second === null) {
                throw new RuntimeException('The activated public-consumer snapshot is unreadable.');
            }

            return compact('first', 'second', 'pointer', 'previousReadable');
        });
        $first = $receipt['first'];
        $second = $receipt['second'];
        $pointer = $receipt['pointer'];
        $previousReadable = $receipt['previousReadable'];
        $locales = array_count_values(array_column($first['items'], 'locale'));
        ksort($locales, SORT_STRING);
        $withLastmod = count(array_filter($first['items'], static fn (array $item): bool => $item['lastmod'] !== null));
        $pointerBound = is_string($pointer)
            && preg_match('/\A[a-f0-9]{64}\z/', $pointer) === 1
            && hash_equals($first['fingerprint'], $pointer)
            && hash_equals($first['fingerprint'], $second['fingerprint']);

        return [
            'schema_version' => 'seo-platform-10-consumer-closeout.v1',
            'status' => $pointerBound && $previousReadable ? 'success' : 'blocked',
            'snapshot_fingerprint' => $first['fingerprint'],
            'repeat_fingerprint' => $second['fingerprint'],
            'url_count' => count($first['items']),
            'locale_counts' => $locales,
            'with_material_lastmod' => $withLastmod,
            'without_material_lastmod' => count($first['items']) - $withLastmod,
            'lkg' => [
                'active_pointer_bound' => $pointerBound,
                'immutable_snapshot_readable' => $previousReadable,
                'recovery_ready_without_destructive_probe' => $previousReadable,
            ],
            'boundaries' => [
                'raw_urls_emitted' => false,
                'private_content_emitted' => false,
                'search_submission_allowed' => false,
                'destructive_probe_performed' => false,
            ],
        ];
    }

    /**
     * @return array{schema_version:string,fingerprint:string,items:list<array{loc:string,locale:string,page_family:string,page_entity_type:string,lastmod:?string}>}
     */
    private function buildCandidate(): array
    {
        $connectionName = (string) config('seo_intel.connection', 'seo_intel');
        $schema = Schema::connection($connectionName);
        foreach (['seo_urls', 'seo_url_entities'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("URL Truth table {$table} is unavailable.");
            }
        }
        foreach (['material_lastmod_at', 'material_authority_state'] as $column) {
            if (! $schema->hasColumn('seo_urls', $column)) {
                throw new RuntimeException("URL Truth column seo_urls.{$column} is unavailable.");
            }
        }
        foreach (['binding_status', 'current_binding_key'] as $column) {
            if (! $schema->hasColumn('seo_url_entities', $column)) {
                throw new RuntimeException("URL Truth column seo_url_entities.{$column} is unavailable.");
            }
        }

        $rows = DB::connection($connectionName)
            ->table('seo_urls as urls')
            ->join('seo_url_entities as entities', function ($join): void {
                $join->on('entities.canonical_url_hash', '=', 'urls.canonical_url_hash')
                    ->on('entities.locale', '=', 'urls.locale')
                    ->on('entities.page_entity_type', '=', 'urls.page_entity_type')
                    ->on('entities.entity_id_or_slug', '=', 'urls.entity_id_or_slug');
            })
            ->where('urls.indexability_state', 'indexable')
            ->where('urls.is_private_flow', false)
            ->where('entities.binding_status', 'current')
            ->whereNotNull('entities.current_binding_key')
            ->whereIn('entities.authority_status', ['active', 'published', 'published_approved'])
            ->select([
                'urls.canonical_url',
                'urls.canonical_url_hash',
                'urls.locale',
                'urls.page_family',
                'urls.page_entity_type',
                'urls.material_lastmod_at',
                'urls.material_authority_state',
                'urls.metadata_json',
                'entities.attributes_json',
            ])
            ->orderBy('urls.canonical_url')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $metadata = $this->jsonObject($row->metadata_json ?? null);
            $attributes = $this->jsonObject($row->attributes_json ?? null);
            if ($this->isExcluded($metadata) || $this->isExcluded($attributes)) {
                continue;
            }

            $loc = trim((string) $row->canonical_url);
            if (! $this->isOwnedCanonical($loc, (string) $row->canonical_url_hash)) {
                continue;
            }

            $lastmod = null;
            if ((string) $row->material_authority_state === 'trusted' && $row->material_lastmod_at !== null) {
                $lastmod = Carbon::parse((string) $row->material_lastmod_at, 'UTC')->utc()->toAtomString();
            }

            $items[$loc] = [
                'loc' => $loc,
                'locale' => (string) $row->locale,
                'page_family' => (string) ($row->page_family ?: $row->page_entity_type),
                'page_entity_type' => (string) $row->page_entity_type,
                'lastmod' => $lastmod,
            ];
        }

        ksort($items, SORT_STRING);
        $items = array_values($items);
        if ($items === []) {
            throw new RuntimeException('URL Truth produced an empty public canonical candidate.');
        }

        $fingerprint = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $snapshot = ['schema_version' => self::SCHEMA_VERSION, 'fingerprint' => $fingerprint, 'items' => $items];
        if (! $this->isValidSnapshot($snapshot)) {
            throw new RuntimeException('URL Truth consumer candidate validation failed.');
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private function publish(array $snapshot): void
    {
        $fingerprint = (string) $snapshot['fingerprint'];
        $snapshotKey = self::SNAPSHOT_CACHE_PREFIX.$fingerprint;
        if (! Cache::forever($snapshotKey, $snapshot)) {
            throw new RuntimeException('URL Truth consumer candidate could not be stored.');
        }
        $readback = Cache::get($snapshotKey);
        if (! is_array($readback) || ! $this->isValidSnapshot($readback) || $readback['fingerprint'] !== $fingerprint) {
            throw new RuntimeException('URL Truth consumer candidate readback failed.');
        }
        if (! Cache::forever(self::POINTER_CACHE_KEY, $fingerprint)) {
            throw new RuntimeException('URL Truth consumer active pointer could not be switched.');
        }
        Cache::put(self::FRESH_CACHE_KEY, $fingerprint, self::FRESH_TTL_SECONDS);
    }

    /** @return array{schema_version:string,fingerprint:string,items:list<array{loc:string,locale:string,page_family:string,page_entity_type:string,lastmod:?string}>}|null */
    private function readActiveSnapshot(): ?array
    {
        $fingerprint = Cache::get(self::POINTER_CACHE_KEY);
        if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            return null;
        }
        $snapshot = Cache::get(self::SNAPSHOT_CACHE_PREFIX.$fingerprint);

        return is_array($snapshot) && $this->isValidSnapshot($snapshot) ? $snapshot : null;
    }

    /** @param array<string,mixed> $snapshot */
    private function isValidSnapshot(array $snapshot): bool
    {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION || ! is_array($snapshot['items'] ?? null)) {
            return false;
        }
        $fingerprint = (string) ($snapshot['fingerprint'] ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1 || $snapshot['items'] === []) {
            return false;
        }
        $locs = [];
        foreach ($snapshot['items'] as $item) {
            if (! is_array($item) || ! $this->isOwnedCanonical((string) ($item['loc'] ?? ''), null)) {
                return false;
            }
            $locs[] = (string) $item['loc'];
        }

        $sortedLocs = $locs;
        sort($sortedLocs, SORT_STRING);

        return $locs === array_values(array_unique($locs))
            && $locs === $sortedLocs
            && hash_equals($fingerprint, hash('sha256', json_encode($snapshot['items'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
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

    /** @param array<string,mixed> $data */
    private function isExcluded(array $data): bool
    {
        return ($data['private_flow'] ?? false) === true
            || ($data['redirect_only'] ?? false) === true
            || ($data['canonical_self'] ?? true) === false
            || str_contains(strtolower((string) ($data['robots'] ?? 'index')), 'noindex');
    }

    private function isOwnedCanonical(string $url, ?string $expectedHash): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $configured = parse_url((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'));
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (! is_array($configured) || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($configured['host'] ?? ''))
            || isset($parts['query']) || isset($parts['fragment']) || $path === ''
            || ($path !== '/' && (str_ends_with($path, '/') || str_contains($path, '//')))) {
            return false;
        }

        return $expectedHash === null || hash_equals($expectedHash, hash('sha256', rtrim($url, '/')));
    }
}
