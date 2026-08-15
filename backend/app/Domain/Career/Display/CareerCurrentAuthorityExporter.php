<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerCurrentAuthorityExportFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

/**
 * One-time SELECT-only exporter used to establish career/current authority.
 *
 * This class is deliberately removed with the export workflow after the
 * resulting artifact has been committed as the repository authority.
 */
final class CareerCurrentAuthorityExporter
{
    public const CONTRACT_VERSION = 'career.current_authority_export.v1';

    public const MANIFEST_CONTRACT_VERSION = 'career.current_authority_manifest.v1';

    public const EXPECTED_CAREERS = 1046;

    public const EXPECTED_LOCALE_PAGES = 2092;

    private const SURFACE_VERSION = 'display.surface.v1';

    private const ASSET_VERSION = 'v4.2';

    private const ASSET_TYPE = 'career_job_public_display';

    private const READY_STATUS = 'ready_for_pilot';

    private const LOCALES = ['en', 'zh-CN'];

    private const EXPORTED_FIELDS = [
        'surface_version',
        'asset_version',
        'template_version',
        'asset_type',
        'asset_role',
        'status',
        'component_order_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'structured_data_json',
        'implementation_contract_json',
        'metadata_json',
    ];

    private const PUBLIC_CONTENT_FIELDS = [
        'surface_version',
        'asset_version',
        'template_version',
        'asset_type',
        'asset_role',
        'status',
        'available_locales',
        'claim_permissions',
        'page',
        'component_order',
        'sources',
        'structured_data_from_visible_content',
        'implementation_contract',
    ];

    public function __construct(
        private readonly CareerJobDisplaySurfaceBuilder $surfaceBuilder,
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    /**
     * @return array{assets_jsonl:string,manifest:array<string,mixed>,receipt:array<string,mixed>}
     */
    public function export(string $backendRoot, array $binding): array
    {
        $connection = DB::connection();
        $cache = app('cache.store');
        if (! $cache instanceof CacheRepository) {
            throw new CareerCurrentAuthorityExportFailure('CACHE_AUDIT_UNAVAILABLE');
        }

        $queryVerbs = [];
        $cacheWriteAttempts = 0;
        $databaseDispatcher = new Dispatcher(app());
        $databaseDispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queryVerbs): void {
            $verb = strtolower((string) strtok(ltrim($query->sql), " \t\r\n"));
            $queryVerbs[] = $verb;
            if ($verb !== 'select') {
                throw new CareerCurrentAuthorityExportFailure('DATABASE_WRITE_ATTEMPT');
            }
        });
        $cacheDispatcher = new Dispatcher(app());
        foreach ([WritingKey::class, ForgettingKey::class, CacheFlushing::class] as $event) {
            $cacheDispatcher->listen($event, static function () use (&$cacheWriteAttempts): void {
                $cacheWriteAttempts++;
                throw new CareerCurrentAuthorityExportFailure('CACHE_WRITE_ATTEMPT');
            });
        }

        $originalDatabaseDispatcher = $connection->getEventDispatcher();
        $originalCacheDispatcher = $cache->getEventDispatcher();
        $connection->setEventDispatcher($databaseDispatcher);
        $cache->setEventDispatcher($cacheDispatcher);

        try {
            $documents = $this->readOnlyTransaction(function (): array {
                $assets = CareerJobDisplayAsset::query()
                    ->where('surface_version', self::SURFACE_VERSION)
                    ->where('asset_version', self::ASSET_VERSION)
                    ->where('template_version', self::ASSET_VERSION)
                    ->where('asset_type', self::ASSET_TYPE)
                    ->where('status', self::READY_STATUS)
                    ->orderBy('canonical_slug')
                    ->lazy(25);

                $rows = [];
                $database = [];
                $activeCache = [];
                $api = [];
                foreach ($assets as $asset) {
                    $slug = strtolower(trim((string) $asset->canonical_slug));
                    $occupation = Occupation::query()
                        ->with('crosswalks')
                        ->whereKey($asset->occupation_id)
                        ->first();
                    if ($slug === ''
                        || ! $occupation instanceof Occupation
                        || strtolower(trim((string) $occupation->canonical_slug)) !== $slug
                        || (string) $asset->occupation_id !== (string) $occupation->id) {
                        throw new CareerCurrentAuthorityExportFailure('ASSET_OCCUPATION_IDENTITY_MISMATCH');
                    }

                    $rows[] = $this->exportRow($asset, $slug);
                    foreach (self::LOCALES as $locale) {
                        $identity = $slug.'|'.$locale;
                        $databaseSurface = $this->surfaceBuilder->buildForOccupation($occupation, $locale);
                        $readiness = $this->responseCache->jobDetailCacheReadiness($slug, $locale);
                        $apiRead = $this->responseCache->jobDetailVerifyOnlyRead($slug, $locale);
                        if (! is_array($databaseSurface)
                            || ($readiness['classification'] ?? null) !== 'ready_active'
                            || ! is_array(data_get($readiness, 'payload.display_surface_v1'))
                            || ($apiRead['state'] ?? null) !== 'fresh'
                            || ! is_array(data_get($apiRead, 'payload.display_surface_v1'))) {
                            throw new CareerCurrentAuthorityExportFailure('PUBLIC_CONTENT_PROJECTION_UNAVAILABLE');
                        }
                        $database[$identity] = self::hashValue($this->contentProjection($databaseSurface));
                        $activeCache[$identity] = self::hashValue($this->contentProjection((array) data_get($readiness, 'payload.display_surface_v1')));
                        $api[$identity] = self::hashValue($this->contentProjection((array) data_get($apiRead, 'payload.display_surface_v1')));
                    }
                }

                return $this->buildDocuments($rows, $database, $activeCache, $api);
            });

            if ($queryVerbs === [] || array_values(array_unique($queryVerbs)) !== ['select']) {
                throw new CareerCurrentAuthorityExportFailure('DATABASE_SELECT_ONLY_NOT_PROVEN');
            }
            if ($cacheWriteAttempts !== 0) {
                throw new CareerCurrentAuthorityExportFailure('CACHE_ZERO_WRITE_NOT_PROVEN');
            }

            $documents['receipt'] = array_merge($documents['receipt'], $binding, [
                'database_query_count' => count($queryVerbs),
                'database_query_verbs' => ['select'],
                'database_write_count' => 0,
                'cache_write_count' => 0,
                'pointer_write_count' => 0,
                'discoverability_write_count' => 0,
                'cms_write_count' => 0,
                'sitemap_write_count' => 0,
                'llms_write_count' => 0,
                'search_submission_count' => 0,
                'remote_file_write_count' => 0,
            ]);

            return $documents;
        } finally {
            if ($originalDatabaseDispatcher !== null) {
                $connection->setEventDispatcher($originalDatabaseDispatcher);
            } else {
                $connection->unsetEventDispatcher();
            }
            if ($originalCacheDispatcher !== null) {
                $cache->setEventDispatcher($originalCacheDispatcher);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,array<string,mixed>|string>  $database
     * @param  array<string,array<string,mixed>|string>  $activeCache
     * @param  array<string,array<string,mixed>|string>  $api
     * @return array{assets_jsonl:string,manifest:array<string,mixed>,receipt:array<string,mixed>}
     */
    public function buildDocuments(
        array $rows,
        array $database,
        array $activeCache,
        array $api,
        int $expectedCareers = self::EXPECTED_CAREERS,
    ): array {
        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['canonical_slug'], (string) $right['canonical_slug']));
        $slugs = array_map(static fn (array $row): string => (string) $row['canonical_slug'], $rows);
        if (count($rows) !== $expectedCareers || count(array_unique($slugs)) !== $expectedCareers) {
            throw new CareerCurrentAuthorityExportFailure('CAREER_COUNT_MISMATCH');
        }

        $localePageCount = 0;
        $numericRatingResidueCount = 0;
        foreach ($rows as $row) {
            if (array_values((array) ($row['component_order_json'] ?? [])) !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER) {
                throw new CareerCurrentAuthorityExportFailure('COMPONENT_ORDER_MISMATCH');
            }
            $pages = data_get($row, 'page_payload_json.page');
            if (! is_array($pages)) {
                $pages = $row['page_payload_json'] ?? null;
            }
            if (! is_array($pages) || ! is_array($pages['en'] ?? null) || ! is_array($pages['zh'] ?? null)) {
                throw new CareerCurrentAuthorityExportFailure('LOCALE_PAGE_MISMATCH');
            }
            foreach (['en', 'zh'] as $locale) {
                foreach (CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER as $component) {
                    if (! array_key_exists($component, $pages[$locale])) {
                        throw new CareerCurrentAuthorityExportFailure('PAGE_COMPONENT_MISSING');
                    }
                }
                $localePageCount++;
                $aiBlock = $pages[$locale]['career_ai_description_block'];
                if (preg_match('/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u', self::encodeCanonical($aiBlock)) === 1) {
                    $numericRatingResidueCount++;
                }
            }
        }
        if ($localePageCount !== $expectedCareers * 2 || $numericRatingResidueCount !== 0) {
            throw new CareerCurrentAuthorityExportFailure('LOCALIZED_CONTENT_CONTRACT_MISMATCH');
        }

        $expectedProjectionCount = $expectedCareers * 2;
        $database = self::projectionHashes($database);
        $activeCache = self::projectionHashes($activeCache);
        $api = self::projectionHashes($api);
        if (count($database) !== $expectedProjectionCount
            || array_keys($database) !== array_keys($activeCache)
            || array_keys($database) !== array_keys($api)) {
            throw new CareerCurrentAuthorityExportFailure('PROJECTION_IDENTITY_SET_MISMATCH');
        }
        $databaseHash = self::projectionSetHash($database);
        $cacheHash = self::projectionSetHash($activeCache);
        $apiHash = self::projectionSetHash($api);
        if (! hash_equals($databaseHash, $cacheHash) || ! hash_equals($databaseHash, $apiHash)) {
            throw new CareerCurrentAuthorityExportFailure('PUBLIC_CONTENT_HASH_MISMATCH');
        }

        $lines = array_map(static fn (array $row): string => self::encodeCanonical($row), $rows);
        $assetsJsonl = implode("\n", $lines)."\n";
        $fieldHashes = [];
        foreach (self::EXPORTED_FIELDS as $field) {
            $fieldHashes[$field] = self::hashValue(array_map(
                static fn (array $row): array => [
                    'canonical_slug' => $row['canonical_slug'],
                    'value' => $row[$field] ?? null,
                ],
                $rows,
            ));
        }

        $manifest = [
            'contract_version' => self::MANIFEST_CONTRACT_VERSION,
            'authority_path' => 'backend/content_assets/career/current',
            'counts' => [
                'careers' => $expectedCareers,
                'locale_pages' => $localePageCount,
                'components_per_page' => count(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER),
                'numeric_rating_statement_residue_count' => $numericRatingResidueCount,
            ],
            'files' => [[
                'path' => 'assets.jsonl',
                'sha256' => hash('sha256', $assetsJsonl),
                'row_count' => count($rows),
            ]],
            'set_hashes' => [
                'slug_set_sha256' => self::hashValue($slugs),
                'full_asset_set_sha256' => self::hashValue($rows),
                'public_content_aggregate_sha256' => $databaseHash,
            ],
            'exported_field_set_sha256' => $fieldHashes,
            'excluded_environment_fields' => [
                'id',
                'occupation_id',
                'import_run_id',
                'created_at',
                'updated_at',
            ],
            'structural_contract' => [
                'surface_version' => self::SURFACE_VERSION,
                'asset_version' => self::ASSET_VERSION,
                'template_version' => self::ASSET_VERSION,
                'asset_type' => self::ASSET_TYPE,
                'status' => self::READY_STATUS,
                'component_order' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            ],
        ];

        return [
            'assets_jsonl' => $assetsJsonl,
            'manifest' => $manifest,
            'receipt' => [
                'contract_version' => self::CONTRACT_VERSION,
                'status' => 'PASS_CURRENT_AUTHORITY_EXPORT',
                'production_read_only' => true,
                'counts' => $manifest['counts'],
                'assets_sha256' => $manifest['files'][0]['sha256'],
                'manifest_sha256' => self::hashValue($manifest),
                'database_public_content_sha256' => $databaseHash,
                'active_cache_public_content_sha256' => $cacheHash,
                'api_public_content_sha256' => $apiHash,
                'hashes_match' => true,
                'not_repository_authority' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function exportRow(CareerJobDisplayAsset $asset, string $slug): array
    {
        return [
            'canonical_slug' => $slug,
            'surface_version' => (string) $asset->surface_version,
            'asset_version' => (string) $asset->asset_version,
            'template_version' => (string) $asset->template_version,
            'asset_type' => (string) $asset->asset_type,
            'asset_role' => (string) $asset->asset_role,
            'status' => (string) $asset->status,
            'component_order_json' => array_values((array) $asset->component_order_json),
            'page_payload_json' => (array) $asset->page_payload_json,
            'seo_payload_json' => is_array($asset->seo_payload_json) ? $asset->seo_payload_json : null,
            'sources_json' => is_array($asset->sources_json) ? $asset->sources_json : null,
            'structured_data_json' => is_array($asset->structured_data_json) ? $asset->structured_data_json : null,
            'implementation_contract_json' => is_array($asset->implementation_contract_json) ? $asset->implementation_contract_json : null,
            'metadata_json' => is_array($asset->metadata_json) ? $asset->metadata_json : null,
        ];
    }

    /** @return array<string,mixed> */
    private function contentProjection(array $surface): array
    {
        $projection = [];
        foreach (self::PUBLIC_CONTENT_FIELDS as $field) {
            $projection[$field] = $surface[$field] ?? null;
        }

        return $projection;
    }

    private function readOnlyTransaction(callable $operation): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite' && app()->environment('testing')) {
            return $connection->transaction($operation, 1);
        }
        if ($connection->getDriverName() !== 'mysql') {
            throw new CareerCurrentAuthorityExportFailure('READ_ONLY_TRANSACTION_UNSUPPORTED');
        }

        $pdo = $connection->getPdo();
        if ($pdo->inTransaction()) {
            throw new CareerCurrentAuthorityExportFailure('READ_ONLY_TRANSACTION_ALREADY_ACTIVE');
        }
        $originalReadPdo = $connection->getRawReadPdo();
        $connection->setReadPdo($pdo);
        try {
            $pdo->exec('START TRANSACTION READ ONLY');
            try {
                $result = $operation();
                if (! $pdo->commit()) {
                    throw new CareerCurrentAuthorityExportFailure('READ_ONLY_TRANSACTION_COMMIT_FAILED');
                }

                return $result;
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $failure;
            }
        } finally {
            $connection->setReadPdo($originalReadPdo);
        }
    }

    /** @param array<string,string> $projections */
    private static function projectionSetHash(array $projections): string
    {
        ksort($projections, SORT_STRING);
        $rows = [];
        foreach ($projections as $identity => $sha256) {
            $rows[] = ['identity' => $identity, 'content_sha256' => $sha256];
        }

        return self::hashValue($rows);
    }

    /**
     * @param  array<string,array<string,mixed>|string>  $projections
     * @return array<string,string>
     */
    private static function projectionHashes(array $projections): array
    {
        $hashes = [];
        foreach ($projections as $identity => $projection) {
            if (! is_string($identity) || $identity === '') {
                throw new CareerCurrentAuthorityExportFailure('PROJECTION_IDENTITY_INVALID');
            }
            if (is_string($projection) && preg_match('/\A[0-9a-f]{64}\z/', $projection) === 1) {
                $hashes[$identity] = $projection;
            } elseif (is_array($projection)) {
                $hashes[$identity] = self::hashValue($projection);
            } else {
                throw new CareerCurrentAuthorityExportFailure('PROJECTION_CONTENT_INVALID');
            }
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    private static function hashValue(mixed $value): string
    {
        return hash('sha256', self::encodeCanonical($value));
    }

    private static function encodeCanonical(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
