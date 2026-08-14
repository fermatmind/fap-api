<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class Career1046DisplayAssetReplacementFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode, ?Throwable $previous = null)
    {
        parent::__construct($safeCode, 0, $previous);
    }
}

final class Career1046DisplayAssetReplacement
{
    public const CONTRACT_VERSION = 'career.1046.display_asset_replacement.v1';

    public const PACKAGE_CONTRACT_VERSION = 'career.workbuddy_1046_display_asset_package.v1';

    public const EXPECTED_CAREERS = 1046;

    public const EXPECTED_LOCALE_ROWS = 2092;

    public const EXPECTED_BLOCKS = 4184;

    public const PACKAGE_RELATIVE_PATH = 'content_assets/career/workbuddy-1046-display-v1';

    private const SURFACE_VERSION = 'display.surface.v1';

    private const ASSET_VERSION = 'v4.2';

    private const ASSET_TYPE = 'career_job_public_display';

    private const READY_STATUS = 'ready_for_pilot';

    /** @var list<string> */
    private const LOCALES = ['en', 'zh-CN'];

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    /** @return array<string, mixed> */
    public function preflight(string $backendRoot, string $expectedPackageSha256): array
    {
        $package = $this->loadPackage($backendRoot, $expectedPackageSha256);
        $plan = $this->buildPlan($package['rows']);
        $cache = $this->cachePreflight($package['slugs']);

        return [
            'package' => $package['summary'],
            'authority' => $plan['summary'],
            'cache' => $cache,
            'state_sha256' => self::hashValue([
                'package_sha256' => $package['summary']['package_sha256'],
                'before_state_sha256' => $plan['summary']['before_state_sha256'],
                'after_state_sha256' => $plan['summary']['after_state_sha256'],
                'cache_state_sha256' => $cache['state_sha256'],
            ]),
            'write_counts' => self::zeroWriteCounts(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(
        string $backendRoot,
        string $expectedPackageSha256,
        string $expectedPreflightStateSha256,
    ): array {
        $package = $this->loadPackage($backendRoot, $expectedPackageSha256);
        $plan = $this->buildPlan($package['rows']);
        $cacheBefore = $this->cachePreflight($package['slugs']);
        $currentState = self::hashValue([
            'package_sha256' => $package['summary']['package_sha256'],
            'before_state_sha256' => $plan['summary']['before_state_sha256'],
            'after_state_sha256' => $plan['summary']['after_state_sha256'],
            'cache_state_sha256' => $cacheBefore['state_sha256'],
        ]);
        if (! hash_equals($expectedPreflightStateSha256, $currentState)) {
            throw new Career1046DisplayAssetReplacementFailure('PREFLIGHT_STATE_DRIFT');
        }

        $prepared = [];
        $databaseCommitted = false;
        $pointersActivated = false;
        $databaseUpdateCount = 0;
        try {
            DB::transaction(function () use ($plan, &$databaseUpdateCount): void {
                foreach ($plan['updates'] as $update) {
                    $current = CareerJobDisplayAsset::query()
                        ->whereKey($update['id'])
                        ->lockForUpdate()
                        ->first();
                    if (! $current instanceof CareerJobDisplayAsset) {
                        throw new Career1046DisplayAssetReplacementFailure('DATABASE_TARGET_STATE_DRIFT');
                    }
                    $currentState = $this->rowStateSnapshot(
                        $current,
                        array_values((array) $current->component_order_json),
                        (array) $current->page_payload_json,
                    );
                    if ($currentState !== $update['before_state']) {
                        throw new Career1046DisplayAssetReplacementFailure('DATABASE_TARGET_STATE_DRIFT');
                    }

                    $affected = DB::table('career_job_display_assets')
                        ->where('id', $update['id'])
                        ->where('asset_version', self::ASSET_VERSION)
                        ->update([
                            'component_order_json' => self::encodeJson(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER),
                            'page_payload_json' => $update['after_page_payload_json'],
                            'updated_at' => now(),
                        ]);
                    if ($affected !== 1) {
                        throw new Career1046DisplayAssetReplacementFailure('DATABASE_TARGET_UPDATE_FAILED');
                    }
                    $databaseUpdateCount++;
                }

                $this->assertDatabaseReadback($plan['after_rows']);
            }, 1);
            $databaseCommitted = true;

            foreach ($package['slugs'] as $slug) {
                foreach (self::LOCALES as $locale) {
                    $entry = $this->responseCache->preparePublishedJobDetailReplacement($slug, $locale);
                    if (($entry['status'] ?? null) !== 'ready' || ($entry['classification'] ?? null) !== 'ready_staged') {
                        throw new Career1046DisplayAssetReplacementFailure('CACHE_CANDIDATE_PREPARATION_FAILED');
                    }
                    $this->assertPreparedCachePayload(
                        $entry,
                        $package['rows'][$slug][$locale]['blocks'],
                    );
                    $prepared[] = $entry;
                }
            }
            if (count($prepared) !== self::EXPECTED_LOCALE_ROWS) {
                throw new Career1046DisplayAssetReplacementFailure('CACHE_CANDIDATE_COUNT_MISMATCH');
            }

            $activation = $this->responseCache->activatePreparedJobDetailPayloadsForExposure($prepared);
            if (($activation['status'] ?? null) !== 'pass'
                || count((array) ($activation['entries'] ?? [])) !== self::EXPECTED_LOCALE_ROWS
                || ($activation['failures'] ?? []) !== []) {
                throw new Career1046DisplayAssetReplacementFailure('CACHE_POINTER_ACTIVATION_FAILED');
            }
            $pointersActivated = true;

            // Every candidate payload was content-verified before activation, and
            // the activation primitive verifies each active pointer immediately.
            // Build the receipt from those committed entries so a transient second
            // cache read cannot turn a successful public commit into an ambiguous
            // failure after the rollback boundary has passed.
            $cacheAfter = [
                'ready_active_count' => count($activation['entries']),
                'component_26_count' => count($activation['entries']),
                'content_match_count' => count($prepared),
                'state_sha256' => self::setHash(array_map(
                    static fn (array $entry): string => $entry['slug'].'|'.$entry['locale'].'|'.$entry['version'],
                    $prepared,
                )),
            ];

            return [
                'package' => $package['summary'],
                'authority' => $plan['summary'],
                'cache' => $cacheAfter,
                'state_sha256' => $currentState,
                'write_counts' => [
                    'database_update_count' => $databaseUpdateCount,
                    'database_insert_count' => 0,
                    'database_delete_count' => 0,
                    'cache_candidate_write_count' => self::EXPECTED_LOCALE_ROWS * 2,
                    'cache_pointer_activation_count' => self::EXPECTED_LOCALE_ROWS,
                    'cms_write_count' => 0,
                    'sitemap_write_count' => 0,
                    'llms_write_count' => 0,
                    'search_submission_count' => 0,
                    'generation_pointer_write_count' => 0,
                ],
            ];
        } catch (Throwable $throwable) {
            if (! $pointersActivated) {
                $this->responseCache->forgetPreparedJobDetailExposureProjectionSnapshots($prepared);
            }
            if ($databaseCommitted && ! $pointersActivated) {
                try {
                    $this->restoreDatabaseRows($plan['before_rows']);
                } catch (Throwable $restoreFailure) {
                    throw new Career1046DisplayAssetReplacementFailure(
                        'DATABASE_COMPENSATION_FAILED',
                        $restoreFailure,
                    );
                }
            }

            if ($throwable instanceof Career1046DisplayAssetReplacementFailure) {
                throw $throwable;
            }

            throw new Career1046DisplayAssetReplacementFailure('DISPLAY_ASSET_REPLACEMENT_FAILED', $throwable);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $expectedBlocks
     */
    private function assertPreparedCachePayload(array $entry, array $expectedBlocks): void
    {
        $payload = $this->responseCache->preparedJobDetailReplacementPayload($entry);
        $surface = is_array($payload) ? data_get($payload, 'display_surface_v1') : null;
        $content = is_array($surface) ? data_get($surface, 'page.content') : null;
        if (! is_array($content)
            || data_get($surface, 'component_order') !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
            || self::hashValue($content['career_ai_description_block'] ?? null) !== self::hashValue($expectedBlocks['career_ai_description_block'] ?? null)
            || self::hashValue($content['career_path_block'] ?? null) !== self::hashValue($expectedBlocks['career_path_block'] ?? null)) {
            throw new Career1046DisplayAssetReplacementFailure('CACHE_CANDIDATE_CONTENT_MISMATCH');
        }
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $packageRows
     * @return array<string, mixed>
     */
    private function buildPlan(array $packageRows): array
    {
        $slugs = array_keys($packageRows);
        sort($slugs, SORT_STRING);
        $assets = CareerJobDisplayAsset::query()
            ->whereIn('canonical_slug', $slugs)
            ->where('surface_version', self::SURFACE_VERSION)
            ->where('asset_version', self::ASSET_VERSION)
            ->where('template_version', self::ASSET_VERSION)
            ->where('status', self::READY_STATUS)
            ->where('asset_type', self::ASSET_TYPE)
            ->orderBy('canonical_slug')
            ->get();
        if ($assets->count() !== self::EXPECTED_CAREERS) {
            throw new Career1046DisplayAssetReplacementFailure('DISPLAY_ASSET_TARGET_COUNT_MISMATCH');
        }

        $assetSlugs = $assets->pluck('canonical_slug')->map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
        )->all();
        sort($assetSlugs, SORT_STRING);
        if ($assetSlugs !== $slugs) {
            throw new Career1046DisplayAssetReplacementFailure('DISPLAY_ASSET_TARGET_SET_MISMATCH');
        }

        $beforeRows = [];
        $beforeStates = [];
        $afterRows = [];
        $updates = [];
        foreach ($assets as $asset) {
            $slug = strtolower(trim((string) $asset->canonical_slug));
            $order = is_array($asset->component_order_json) ? array_values($asset->component_order_json) : [];
            if (! CareerDisplayAssetComponentContract::supports($order)) {
                throw new Career1046DisplayAssetReplacementFailure('DISPLAY_COMPONENT_ORDER_UNSUPPORTED');
            }

            $pagePayload = is_array($asset->page_payload_json) ? $asset->page_payload_json : [];
            $afterPagePayload = $this->mergeLocalizedBlocks($pagePayload, $packageRows[$slug]);
            $before = $this->rowSnapshot($asset, $order, $pagePayload);
            $beforeState = $this->rowStateSnapshot($asset, $order, $pagePayload);
            $after = $this->rowStateSnapshot(
                $asset,
                CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
                $afterPagePayload,
            );
            $beforeRows[$slug] = $before;
            $beforeStates[$slug] = $beforeState;
            $afterRows[$slug] = $after;

            if ($order !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
                || ! hash_equals($beforeState['page_payload_sha256'], $after['page_payload_sha256'])) {
                $updates[] = [
                    'id' => (string) $asset->id,
                    'slug' => $slug,
                    'before_state' => $beforeState,
                    'after_page_payload_json' => self::encodeJson($afterPagePayload),
                ];
            }
        }

        return [
            'before_rows' => $beforeRows,
            'after_rows' => $afterRows,
            'updates' => $updates,
            'summary' => [
                'target_count' => count($assets),
                'changed_count' => count($updates),
                'unchanged_count' => count($assets) - count($updates),
                'before_state_sha256' => self::hashValue($beforeStates),
                'after_state_sha256' => self::hashValue($afterRows),
                'component_order_before_counts' => [
                    '24' => $assets->filter(static fn (CareerJobDisplayAsset $asset): bool => array_values((array) $asset->component_order_json) === CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER)->count(),
                    '26' => $assets->filter(static fn (CareerJobDisplayAsset $asset): bool => array_values((array) $asset->component_order_json) === CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER)->count(),
                ],
                'component_order_after_count' => count($assets),
                'insert_count' => 0,
                'delete_count' => 0,
                'outside_target_count' => 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $pagePayload
     * @param  array<string, array<string, mixed>>  $localizedRows
     * @return array<string, mixed>
     */
    private function mergeLocalizedBlocks(array $pagePayload, array $localizedRows): array
    {
        $wrapped = is_array($pagePayload['page'] ?? null);
        $pages = $wrapped ? $pagePayload['page'] : $pagePayload;
        foreach (self::LOCALES as $locale) {
            $pageKey = $locale === 'zh-CN' ? 'zh' : 'en';
            if (! is_array($pages[$pageKey] ?? null) || ! isset($localizedRows[$locale])) {
                throw new Career1046DisplayAssetReplacementFailure('DISPLAY_LOCALIZED_PAGE_MISSING');
            }
            $blocks = $localizedRows[$locale]['blocks'] ?? null;
            if (! is_array($blocks)
                || ! is_array($blocks['career_ai_description_block'] ?? null)
                || ! is_array($blocks['career_path_block'] ?? null)) {
                throw new Career1046DisplayAssetReplacementFailure('PACKAGE_LOCALIZED_BLOCKS_INVALID');
            }
            $pages[$pageKey]['career_ai_description_block'] = $blocks['career_ai_description_block'];
            $pages[$pageKey]['career_path_block'] = $blocks['career_path_block'];
        }

        if ($wrapped) {
            $pagePayload['page'] = $pages;

            return $pagePayload;
        }

        return $pages;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function cachePreflight(array $slugs): array
    {
        $rows = [];
        foreach (array_chunk($slugs, 50) as $slugChunk) {
            $snapshot = $this->responseCache->jobDetailPublicationSnapshot($slugChunk, self::LOCALES);
            foreach ($slugChunk as $slug) {
                foreach (self::LOCALES as $locale) {
                    $item = $snapshot[$slug][$locale] ?? null;
                    if (! is_array($item)
                        || ($item['published'] ?? false) !== true
                        || ($item['classification'] ?? null) !== 'ready_active'
                        || ! is_string($item['version'] ?? null)
                        || ! is_array($item['payload'] ?? null)) {
                        throw new Career1046DisplayAssetReplacementFailure('ACTIVE_DETAIL_CACHE_NOT_READY');
                    }
                    $rows[] = $slug.'|'.$locale.'|'.$item['version'];
                }
            }
        }

        return [
            'ready_active_count' => count($rows),
            'state_sha256' => self::setHash($rows),
            'candidate_write_count' => 0,
            'pointer_write_count' => 0,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $expectedRows
     */
    private function assertDatabaseReadback(array $expectedRows): void
    {
        $assets = CareerJobDisplayAsset::query()
            ->whereIn('canonical_slug', array_keys($expectedRows))
            ->where('asset_version', self::ASSET_VERSION)
            ->get();
        if ($assets->count() !== self::EXPECTED_CAREERS) {
            throw new Career1046DisplayAssetReplacementFailure('DATABASE_READBACK_COUNT_MISMATCH');
        }
        foreach ($assets as $asset) {
            $slug = strtolower((string) $asset->canonical_slug);
            $actual = $this->rowStateSnapshot(
                $asset,
                array_values((array) $asset->component_order_json),
                (array) $asset->page_payload_json,
            );
            if (! isset($expectedRows[$slug]) || $actual !== $expectedRows[$slug]) {
                throw new Career1046DisplayAssetReplacementFailure('DATABASE_READBACK_STATE_MISMATCH');
            }
        }
    }

    /** @param array<string, array<string, mixed>> $beforeRows */
    private function restoreDatabaseRows(array $beforeRows): void
    {
        DB::transaction(function () use ($beforeRows): void {
            foreach ($beforeRows as $row) {
                DB::table('career_job_display_assets')
                    ->where('id', $row['id'])
                    ->update([
                        'component_order_json' => self::encodeJson($row['component_order']),
                        'page_payload_json' => $row['page_payload_json'],
                        'updated_at' => $row['updated_at'],
                    ]);
            }
        }, 1);
    }

    /**
     * @param  list<mixed>  $order
     * @param  array<string, mixed>  $pagePayload
     * @return array<string, mixed>
     */
    private function rowSnapshot(CareerJobDisplayAsset $asset, array $order, array $pagePayload): array
    {
        return $this->rowStateSnapshot($asset, $order, $pagePayload) + [
            'page_payload_json' => self::encodeJson($pagePayload),
            'updated_at' => $asset->getRawOriginal('updated_at'),
        ];
    }

    /**
     * @param  list<mixed>  $order
     * @param  array<string, mixed>  $pagePayload
     * @return array<string, mixed>
     */
    private function rowStateSnapshot(CareerJobDisplayAsset $asset, array $order, array $pagePayload): array
    {
        return [
            'id' => (string) $asset->id,
            'occupation_id' => (string) $asset->occupation_id,
            'slug' => strtolower((string) $asset->canonical_slug),
            'component_order' => $order,
            'page_payload_sha256' => self::hashValue($pagePayload),
            'unchanged_fields_sha256' => self::hashValue([
                'surface_version' => $asset->surface_version,
                'asset_version' => $asset->asset_version,
                'template_version' => $asset->template_version,
                'asset_type' => $asset->asset_type,
                'asset_role' => $asset->asset_role,
                'status' => $asset->status,
                'seo_payload_json' => $asset->seo_payload_json,
                'sources_json' => $asset->sources_json,
                'structured_data_json' => $asset->structured_data_json,
                'implementation_contract_json' => $asset->implementation_contract_json,
                'metadata_json' => $asset->metadata_json,
                'import_run_id' => $asset->import_run_id,
            ]),
        ];
    }

    /** @return array{rows: array<string, array<string, array<string, mixed>>>, slugs: list<string>, summary: array<string, mixed>} */
    private function loadPackage(string $backendRoot, string $expectedPackageSha256): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedPackageSha256) !== 1) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_SHA256_INVALID');
        }
        $root = rtrim($backendRoot, '/').'/'.self::PACKAGE_RELATIVE_PATH;
        $manifestPath = $root.'/manifest.json';
        $assetsPath = $root.'/assets.jsonl';
        if (! is_file($manifestPath) || ! is_file($assetsPath)) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_FILE_MISSING');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)
            || ($manifest['contract_version'] ?? null) !== self::PACKAGE_CONTRACT_VERSION
            || data_get($manifest, 'counts.careers') !== self::EXPECTED_CAREERS
            || data_get($manifest, 'counts.locale_rows') !== self::EXPECTED_LOCALE_ROWS
            || data_get($manifest, 'counts.content_blocks') !== self::EXPECTED_BLOCKS
            || data_get($manifest, 'files.0.sha256') !== $expectedPackageSha256
            || ! hash_equals($expectedPackageSha256, hash_file('sha256', $assetsPath))) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_MANIFEST_INVALID');
        }

        $rows = [];
        $handle = fopen($assetsPath, 'rb');
        if ($handle === false) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_UNREADABLE');
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            $slug = is_array($row) ? strtolower(trim((string) ($row['slug'] ?? ''))) : '';
            $locale = is_array($row) ? trim((string) ($row['locale'] ?? '')) : '';
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                || ! in_array($locale, self::LOCALES, true)
                || isset($rows[$slug][$locale])
                || ! is_array($row['blocks'] ?? null)) {
                fclose($handle);
                throw new Career1046DisplayAssetReplacementFailure('PACKAGE_ROW_INVALID_OR_DUPLICATE');
            }
            $rows[$slug][$locale] = $row;
        }
        fclose($handle);
        ksort($rows, SORT_STRING);
        foreach ($rows as &$localized) {
            ksort($localized, SORT_STRING);
            if (array_keys($localized) !== self::LOCALES) {
                throw new Career1046DisplayAssetReplacementFailure('PACKAGE_LOCALE_PAIR_INCOMPLETE');
            }
        }
        unset($localized);
        if (count($rows) !== self::EXPECTED_CAREERS) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_CAREER_COUNT_MISMATCH');
        }

        $slugs = array_keys($rows);
        if (! hash_equals((string) data_get($manifest, 'sets.slug_set_sha256'), self::setHash($slugs))) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_SLUG_SET_MISMATCH');
        }

        return [
            'rows' => $rows,
            'slugs' => $slugs,
            'summary' => [
                'package_sha256' => $expectedPackageSha256,
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'career_count' => self::EXPECTED_CAREERS,
                'locale_row_count' => self::EXPECTED_LOCALE_ROWS,
                'content_block_count' => self::EXPECTED_BLOCKS,
                'slug_set_sha256' => self::setHash($slugs),
                'identity_set_sha256' => (string) data_get($manifest, 'sets.identity_set_sha256'),
            ],
        ];
    }

    /** @return array<string, int> */
    private static function zeroWriteCounts(): array
    {
        return [
            'database_update_count' => 0,
            'database_insert_count' => 0,
            'database_delete_count' => 0,
            'cache_candidate_write_count' => 0,
            'cache_pointer_activation_count' => 0,
            'cms_write_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
            'generation_pointer_write_count' => 0,
        ];
    }

    /** @param list<string> $values */
    private static function setHash(array $values): string
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_STRING);

        return hash('sha256', implode("\n", $values)."\n");
    }

    private static function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function hashValue(mixed $value): string
    {
        return hash('sha256', self::encodeJson(self::canonicalize($value)));
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
