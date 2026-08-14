<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final class Career1046DisplayAssetReplacementFailure extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        ?Throwable $previous = null,
        public readonly string $writeCommitState = 'confirmed_zero_write',
    ) {
        parent::__construct($safeCode, 0, $previous);
    }
}

final class Career1046DisplayAssetReplacement
{
    public const CONTRACT_VERSION = 'career.1046.display_asset_replacement.v2';

    public const PACKAGE_CONTRACT_VERSION = 'career.workbuddy_1046_display_asset_package.v2';

    public const EXPECTED_CAREERS = 1046;

    public const EXPECTED_LOCALE_ROWS = 2092;

    public const EXPECTED_BLOCKS = 4184;

    public const PACKAGE_RELATIVE_PATH = 'content_assets/career/workbuddy-1046-display-v1';

    public const MISSING_BASE_PACKAGE_CONTRACT_VERSION = 'career.missing_12_display_asset_package.v1';

    public const MISSING_BASE_PACKAGE_RELATIVE_PATH = 'content_assets/career/missing-12-display-v1';

    public const EXPECTED_INSERTS = 12;

    private const SURFACE_VERSION = 'display.surface.v1';

    private const ASSET_VERSION = 'v4.2';

    private const ASSET_TYPE = 'career_job_public_display';

    private const ASSET_ROLE = 'formal_pilot_master';

    private const READY_STATUS = 'ready_for_pilot';

    /** @var list<string> */
    private const LOCALES = ['en', 'zh-CN'];

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $backendRoot, string $expectedPackageSha256): array
    {
        $package = $this->loadPackage($backendRoot, $expectedPackageSha256);
        $missingBasePackage = $this->loadMissingBasePackage($backendRoot);
        $plan = $this->buildPlan(
            $package['rows'],
            $missingBasePackage['rows'],
            $package['summary'],
            $missingBasePackage['summary'],
        );

        if ($plan['state'] === 'applied') {
            $this->assertDatabaseReadback($plan['after_rows']);
            $cache = $this->assertActiveCacheReadback($package['slugs'], $plan['expected_blocks']);
            $this->assertCacheAggregates($cache, $package['summary']);

            return $this->result(
                $package['summary'],
                $missingBasePackage['summary'],
                $plan['summary'],
                $cache,
                self::zeroWriteCounts(),
                true,
            );
        }

        // The internal plan is deliberately complete and zero-write. Cache
        // readiness is also established before opening the database transaction.
        $this->cachePreflight($package['slugs']);

        $prepared = [];
        $databaseCommitted = false;
        $pointersActivated = false;
        $rollbackSnapshots = [];
        $databaseUpdateCount = 0;
        $databaseInsertCount = 0;
        try {
            DB::transaction(function () use ($plan, &$databaseUpdateCount, &$databaseInsertCount): void {
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
                            'metadata_json' => $update['after_metadata_json'],
                            'updated_at' => now(),
                        ]);
                    if ($affected !== 1) {
                        throw new Career1046DisplayAssetReplacementFailure('DATABASE_TARGET_UPDATE_FAILED');
                    }
                    $databaseUpdateCount++;
                }

                foreach ($plan['inserts'] as $insert) {
                    $conflict = CareerJobDisplayAsset::query()
                        ->whereKey($insert['id'])
                        ->orWhere(function ($query) use ($insert): void {
                            $query->where('canonical_slug', $insert['slug'])
                                ->where('asset_version', self::ASSET_VERSION);
                        })
                        ->lockForUpdate()
                        ->exists();
                    if ($conflict) {
                        throw new Career1046DisplayAssetReplacementFailure('DATABASE_INSERT_TARGET_STATE_DRIFT');
                    }

                    CareerJobDisplayAsset::query()->create($insert['attributes']);
                    $databaseInsertCount++;
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
                        $plan['expected_blocks'][$slug][$locale],
                    );
                    $prepared[] = $entry;
                }
            }
            if (count($prepared) !== self::EXPECTED_LOCALE_ROWS) {
                throw new Career1046DisplayAssetReplacementFailure('CACHE_CANDIDATE_COUNT_MISMATCH');
            }

            $activation = $this->responseCache->activatePreparedJobDetailPayloadsForExposure($prepared, true);
            if (($activation['status'] ?? null) !== 'pass'
                || count((array) ($activation['entries'] ?? [])) !== self::EXPECTED_LOCALE_ROWS
                || ($activation['failures'] ?? []) !== []) {
                throw new Career1046DisplayAssetReplacementFailure('CACHE_POINTER_ACTIVATION_FAILED');
            }
            $pointersActivated = true;
            $rollbackSnapshots = (array) ($activation['rollback_snapshots'] ?? []);

            $this->assertDatabaseReadback($plan['after_rows']);
            $cacheAfter = $this->assertActiveCacheReadback($package['slugs'], $plan['expected_blocks']);
            $this->assertCacheAggregates($cacheAfter, $package['summary']);

            return $this->result(
                $package['summary'],
                $missingBasePackage['summary'],
                $plan['summary'],
                $cacheAfter,
                [
                    'database_update_count' => $databaseUpdateCount,
                    'database_insert_count' => $databaseInsertCount,
                    'database_delete_count' => 0,
                    'cache_candidate_write_count' => self::EXPECTED_LOCALE_ROWS * 2,
                    'cache_pointer_activation_count' => self::EXPECTED_LOCALE_ROWS,
                    'cms_write_count' => 0,
                    'sitemap_write_count' => 0,
                    'llms_write_count' => 0,
                    'search_submission_count' => 0,
                    'generation_pointer_write_count' => 0,
                ],
                false,
            );
        } catch (Throwable $throwable) {
            $compensationFailure = null;
            try {
                if ($pointersActivated) {
                    $this->responseCache->restorePreparedJobDetailExposurePointers($prepared, $rollbackSnapshots);
                }
            } catch (Throwable $restoreFailure) {
                $compensationFailure = $restoreFailure;
            }
            try {
                $this->responseCache->forgetPreparedJobDetailCandidates($prepared);
            } catch (Throwable $cleanupFailure) {
                $compensationFailure ??= $cleanupFailure;
            }
            if ($databaseCommitted) {
                try {
                    $this->restoreDatabaseRows($plan['before_rows'], $plan['inserts'], $plan['after_rows']);
                } catch (Throwable $databaseRestoreFailure) {
                    $compensationFailure ??= $databaseRestoreFailure;
                }
            }
            if ($compensationFailure instanceof Throwable) {
                throw new Career1046DisplayAssetReplacementFailure(
                    'REPLACEMENT_COMPENSATION_FAILED',
                    $compensationFailure,
                    'ambiguous',
                );
            }

            throw new Career1046DisplayAssetReplacementFailure(
                $throwable instanceof Career1046DisplayAssetReplacementFailure
                    ? $throwable->safeCode
                    : 'DISPLAY_ASSET_REPLACEMENT_FAILED',
                $throwable,
                ($databaseCommitted || $prepared !== []) ? 'rolled_back' : 'confirmed_zero_write',
            );
        }
    }

    /** @return array<string, mixed> */
    private function result(
        array $package,
        array $missingPackage,
        array $authority,
        array $cache,
        array $writeCounts,
        bool $idempotentNoop,
    ): array {
        return [
            'package' => $package,
            'missing_base_package' => $missingPackage,
            'authority' => $authority,
            'cache' => $cache,
            'idempotent_noop' => $idempotentNoop,
            'state_sha256' => self::hashValue([
                'package_sha256' => $package['package_sha256'],
                'authority_sha256' => $authority['after_state_sha256'],
                'cache_sha256' => $cache['state_sha256'],
            ]),
            'write_counts' => $writeCounts,
        ];
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
     * @param  array<string, array<string, mixed>>  $missingBaseRows
     * @return array<string, mixed>
     */
    private function buildPlan(
        array $packageRows,
        array $missingBaseRows,
        array $packageSummary,
        array $missingBaseSummary,
    ): array {
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

        $assetSlugs = $assets->pluck('canonical_slug')->map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
        )->all();
        sort($assetSlugs, SORT_STRING);
        $missingSlugs = array_values(array_diff($slugs, $assetSlugs));
        $authorizedInsertSlugs = array_keys($missingBaseRows);
        sort($authorizedInsertSlugs, SORT_STRING);
        if ($missingSlugs !== [] && $missingSlugs !== $authorizedInsertSlugs) {
            throw new Career1046DisplayAssetReplacementFailure('DISPLAY_ASSET_TARGET_SET_MISMATCH');
        }
        $legacyCount = $assets->filter(static fn (CareerJobDisplayAsset $asset): bool => array_values((array) $asset->component_order_json) === CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER)->count();
        $currentCount = $assets->filter(static fn (CareerJobDisplayAsset $asset): bool => array_values((array) $asset->component_order_json) === CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER)->count();
        $authorityState = $this->classifyAuthorityState(
            $assets->count(),
            $missingSlugs,
            $authorizedInsertSlugs,
            $legacyCount,
            $currentCount,
        );
        $initialState = $authorityState === 'initial';
        $appliedState = $authorityState === 'applied';

        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $missingSlugs)
            ->with('crosswalks')
            ->get()
            ->keyBy(static fn (Occupation $occupation): string => strtolower((string) $occupation->canonical_slug));
        if ($occupations->count() !== count($missingSlugs)) {
            throw new Career1046DisplayAssetReplacementFailure('DISPLAY_INSERT_OCCUPATION_MISSING');
        }

        $beforeRows = [];
        $beforeStates = [];
        $afterRows = [];
        $expectedBlocks = [];
        $updates = [];
        $inserts = [];
        foreach ($assets as $asset) {
            $slug = strtolower(trim((string) $asset->canonical_slug));
            $order = is_array($asset->component_order_json) ? array_values($asset->component_order_json) : [];
            if (! CareerDisplayAssetComponentContract::supports($order)) {
                throw new Career1046DisplayAssetReplacementFailure('DISPLAY_COMPONENT_ORDER_UNSUPPORTED');
            }

            $pagePayload = is_array($asset->page_payload_json) ? $asset->page_payload_json : [];
            $afterPagePayload = $this->mergeLocalizedBlocks($pagePayload, $packageRows[$slug]);
            $afterMetadata = $this->metadataWithReplacementLineage(
                is_array($asset->metadata_json) ? $asset->metadata_json : [],
                $packageRows[$slug],
                $packageSummary,
            );
            $before = $this->rowSnapshot($asset, $order, $pagePayload);
            $beforeState = $this->rowStateSnapshot($asset, $order, $pagePayload);
            $after = $this->rowStateSnapshot(
                $asset,
                CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
                $afterPagePayload,
                $afterMetadata,
            );
            $beforeRows[$slug] = $before;
            $beforeStates[$slug] = $beforeState;
            $afterRows[$slug] = $after;
            $expectedBlocks[$slug] = $this->localizedBlocksForCache($afterPagePayload);

            if ($order !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
                || ! hash_equals($beforeState['page_payload_sha256'], $after['page_payload_sha256'])
                || ! hash_equals($beforeState['metadata_sha256'], $after['metadata_sha256'])) {
                $updates[] = [
                    'id' => (string) $asset->id,
                    'slug' => $slug,
                    'before_state' => $beforeState,
                    'after_page_payload_json' => self::encodeJson($afterPagePayload),
                    'after_metadata_json' => self::encodeJson($afterMetadata),
                ];
            }
        }

        foreach ($missingSlugs as $slug) {
            /** @var Occupation $occupation */
            $occupation = $occupations->get($slug);
            $this->assertOccupationCrosswalks($occupation, $missingBaseRows[$slug]);
            $attributes = $this->insertAttributes(
                $slug,
                $occupation,
                $missingBaseRows[$slug],
                $packageRows[$slug],
                $packageSummary,
                $missingBaseSummary,
            );
            $candidate = new CareerJobDisplayAsset($attributes);
            $afterRows[$slug] = $this->rowStateSnapshot(
                $candidate,
                CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
                (array) $candidate->page_payload_json,
            );
            $expectedBlocks[$slug] = $this->localizedBlocksForCache((array) $candidate->page_payload_json);
            $beforeStates[$slug] = [
                'slug' => $slug,
                'absent' => true,
                'occupation_id' => (string) $occupation->id,
            ];
            $inserts[] = [
                'id' => (string) $attributes['id'],
                'slug' => $slug,
                'attributes' => $attributes,
                'after_state' => $afterRows[$slug],
            ];
        }

        ksort($beforeStates, SORT_STRING);
        ksort($afterRows, SORT_STRING);
        ksort($expectedBlocks, SORT_STRING);
        $changedCount = count($updates) + count($inserts);
        if ($initialState && (count($updates) !== self::EXPECTED_CAREERS - self::EXPECTED_INSERTS || count($inserts) !== self::EXPECTED_INSERTS)) {
            throw new Career1046DisplayAssetReplacementFailure('INITIAL_REPLACEMENT_PLAN_MISMATCH');
        }
        if ($appliedState && $changedCount !== 0) {
            throw new Career1046DisplayAssetReplacementFailure('APPLIED_REPLACEMENT_STATE_HASH_MISMATCH');
        }

        return [
            'state' => $appliedState ? 'applied' : 'initial',
            'before_rows' => $beforeRows,
            'after_rows' => $afterRows,
            'expected_blocks' => $expectedBlocks,
            'updates' => $updates,
            'inserts' => $inserts,
            'summary' => [
                'target_count' => count($afterRows),
                'existing_target_count' => count($assets),
                'changed_count' => $changedCount,
                'unchanged_count' => self::EXPECTED_CAREERS - $changedCount,
                'before_state_sha256' => self::hashValue($beforeStates),
                'after_state_sha256' => self::hashValue($afterRows),
                'component_order_before_counts' => [
                    '24' => $legacyCount,
                    '26' => $currentCount,
                    'missing' => count($missingSlugs),
                ],
                'component_order_after_count' => count($afterRows),
                'insert_count' => count($inserts),
                'insert_slug_set_sha256' => self::setHash($missingSlugs),
                'delete_count' => 0,
                'outside_target_count' => 0,
            ],
        ];
    }

    /** @param list<string> $missingSlugs @param list<string> $authorizedInsertSlugs */
    private function classifyAuthorityState(
        int $assetCount,
        array $missingSlugs,
        array $authorizedInsertSlugs,
        int $legacyCount,
        int $currentCount,
    ): string {
        if ($assetCount === self::EXPECTED_CAREERS - self::EXPECTED_INSERTS
            && $missingSlugs === $authorizedInsertSlugs
            && count($missingSlugs) === self::EXPECTED_INSERTS
            && $legacyCount === self::EXPECTED_CAREERS - self::EXPECTED_INSERTS
            && $currentCount === 0) {
            return 'initial';
        }
        if ($assetCount === self::EXPECTED_CAREERS
            && $missingSlugs === []
            && $legacyCount === 0
            && $currentCount === self::EXPECTED_CAREERS) {
            return 'applied';
        }

        throw new Career1046DisplayAssetReplacementFailure('DISPLAY_ASSET_TARGET_STATE_INVALID');
    }

    /**
     * @param  array<string, mixed>  $pagePayload
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function localizedBlocksForCache(array $pagePayload): array
    {
        $pages = is_array($pagePayload['page'] ?? null) ? $pagePayload['page'] : $pagePayload;
        $blocks = [];
        foreach (self::LOCALES as $locale) {
            $pageKey = $locale === 'zh-CN' ? 'zh' : 'en';
            $page = $pages[$pageKey] ?? null;
            if (! is_array($page)
                || ! is_array($page['career_ai_description_block'] ?? null)
                || ! is_array($page['career_path_block'] ?? null)) {
                throw new Career1046DisplayAssetReplacementFailure('DISPLAY_RECONCILED_BLOCKS_INVALID');
            }
            $blocks[$locale] = [
                'career_ai_description_block' => $page['career_ai_description_block'],
                'career_path_block' => $page['career_path_block'],
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $baseRow
     * @param  array<string, array<string, mixed>>  $localizedRows
     * @return array<string, mixed>
     */
    private function insertAttributes(
        string $slug,
        Occupation $occupation,
        array $baseRow,
        array $localizedRows,
        array $packageSummary,
        array $missingBaseSummary,
    ): array {
        $payload = $baseRow['asset_payload'] ?? null;
        if (! is_array($payload)
            || array_values((array) ($payload['component_order_json'] ?? [])) !== CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER
            || ! is_array($payload['page_payload_json'] ?? null)) {
            throw new Career1046DisplayAssetReplacementFailure('DISPLAY_INSERT_BASE_ASSET_INVALID');
        }
        $pagePayload = $this->mergeLocalizedBlocks($payload['page_payload_json'], $localizedRows);

        return [
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://fermatmind.com/authority/career/display/v4.2/'.$slug)->toString(),
            'occupation_id' => (string) $occupation->id,
            'canonical_slug' => $slug,
            'surface_version' => self::SURFACE_VERSION,
            'asset_version' => self::ASSET_VERSION,
            'template_version' => self::ASSET_VERSION,
            'asset_type' => self::ASSET_TYPE,
            'asset_role' => self::ASSET_ROLE,
            'status' => self::READY_STATUS,
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'page_payload_json' => $pagePayload,
            'seo_payload_json' => $payload['seo_payload_json'] ?? null,
            'sources_json' => $payload['sources_json'] ?? null,
            'structured_data_json' => $payload['structured_data_json'] ?? null,
            'implementation_contract_json' => $payload['implementation_contract_json'] ?? null,
            'metadata_json' => $this->metadataWithReplacementLineage([
                'authority_package' => 'career-missing-12-display-v1',
                'workbook_sha256' => $missingBaseSummary['source_workbook_sha256'],
                'workbook_basename' => $missingBaseSummary['source_workbook_basename'],
                'row_number' => $baseRow['source_workbook_row_number'] ?? null,
                'command' => 'career:import-selected-display-assets',
                'mapper_version' => $missingBaseSummary['mapper_version'],
                'validator_version' => 'career_selected_display_asset_import_v0.1',
                'source_workbook_row_number' => $baseRow['source_workbook_row_number'] ?? null,
                'source_workbook_row_sha256' => $baseRow['source_workbook_row_sha256'] ?? null,
                'normalized_workbook_row_sha256' => $baseRow['normalized_workbook_row_sha256'] ?? null,
                'asset_payload_sha256' => $baseRow['asset_payload_sha256'] ?? null,
                'content_generated' => false,
                'discoverability_changed' => false,
            ], $localizedRows, $packageSummary),
            'import_run_id' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function metadataWithReplacementLineage(
        array $metadata,
        array $localizedRows,
        array $packageSummary,
    ): array {
        $metadata['replacement_lineage'] = [
            'contract_version' => self::CONTRACT_VERSION,
            'package_id' => 'career-workbuddy-1046-display-v1',
            'package_sha256' => $packageSummary['package_sha256'],
            'manifest_sha256' => $packageSummary['manifest_sha256'],
            'delivery_report_sha256' => $packageSummary['delivery_report_sha256'],
            'source_file_chain_sha256' => $packageSummary['source_file_chain_sha256'],
            'localized_asset_sha256' => self::hashValue($localizedRows),
        ];

        return $metadata;
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
            $aiBlock = $blocks['career_ai_description_block'];
            if (preg_match(
                '/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u',
                self::encodeJson($aiBlock),
            ) === 1) {
                throw new Career1046DisplayAssetReplacementFailure('AI_NUMERIC_RATING_RESIDUE');
            }
            $pages[$pageKey]['career_ai_description_block'] = $aiBlock;
            $pages[$pageKey]['career_path_block'] = $blocks['career_path_block'];
        }

        if ($wrapped) {
            $pagePayload['page'] = $pages;

            return $pagePayload;
        }

        return $pages;
    }

    /** @param array<string, mixed> $baseRow */
    private function assertOccupationCrosswalks(Occupation $occupation, array $baseRow): void
    {
        $expectedSoc = (string) ($baseRow['expected_soc'] ?? '');
        $expectedOnet = (string) ($baseRow['expected_onet'] ?? '');
        $socValid = $occupation->crosswalks->contains(
            static fn (OccupationCrosswalk $crosswalk): bool => $crosswalk->source_system === 'us_soc'
                && $crosswalk->source_code === $expectedSoc,
        );
        $onetValid = $occupation->crosswalks->contains(
            static fn (OccupationCrosswalk $crosswalk): bool => $crosswalk->source_system === 'onet_soc_2019'
                && $crosswalk->source_code === $expectedOnet,
        );
        if (! $socValid || ! $onetValid) {
            throw new Career1046DisplayAssetReplacementFailure('DISPLAY_INSERT_OCCUPATION_CROSSWALK_MISMATCH');
        }
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
     * @param  list<string>  $slugs
     * @param  array<string, array<string, array<string, mixed>>>  $expectedBlocks
     * @return array<string, mixed>
     */
    private function assertActiveCacheReadback(array $slugs, array $expectedBlocks): array
    {
        $versions = [];
        $aiHashes = [];
        $pathHashes = [];
        foreach (array_chunk($slugs, 50) as $slugChunk) {
            $snapshot = $this->responseCache->jobDetailPublicationSnapshot($slugChunk, self::LOCALES);
            foreach ($slugChunk as $slug) {
                foreach (self::LOCALES as $locale) {
                    $item = $snapshot[$slug][$locale] ?? null;
                    $surface = is_array($item) ? data_get($item, 'payload.display_surface_v1') : null;
                    $content = is_array($surface) ? data_get($surface, 'page.content') : null;
                    $aiBlock = is_array($content) ? ($content['career_ai_description_block'] ?? null) : null;
                    $pathBlock = is_array($content) ? ($content['career_path_block'] ?? null) : null;
                    if (! is_array($item)
                        || ($item['published'] ?? false) !== true
                        || ($item['classification'] ?? null) !== 'ready_active'
                        || ! is_string($item['version'] ?? null)
                        || data_get($surface, 'component_order') !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
                        || ! is_array($aiBlock)
                        || ! is_array($pathBlock)
                        || self::hashValue($aiBlock) !== self::hashValue($expectedBlocks[$slug][$locale]['career_ai_description_block'] ?? null)
                        || self::hashValue($pathBlock) !== self::hashValue($expectedBlocks[$slug][$locale]['career_path_block'] ?? null)) {
                        throw new Career1046DisplayAssetReplacementFailure('ACTIVE_CACHE_FULL_READBACK_MISMATCH');
                    }
                    $identity = $slug.'|'.$locale;
                    $versions[] = $identity.'|'.$item['version'];
                    $aiHashes[] = $identity.'|'.self::hashValue($aiBlock);
                    $pathHashes[] = $identity.'|'.self::hashValue($pathBlock);
                }
            }
        }
        if (count($versions) !== self::EXPECTED_LOCALE_ROWS) {
            throw new Career1046DisplayAssetReplacementFailure('ACTIVE_CACHE_FULL_READBACK_COUNT_MISMATCH');
        }

        return [
            'ready_active_count' => count($versions),
            'component_26_count' => count($versions),
            'content_match_count' => count($versions),
            'career_ai_description_block_sha256' => self::setHash($aiHashes),
            'career_path_block_sha256' => self::setHash($pathHashes),
            'display_block_aggregate_sha256' => self::setHash(array_merge($aiHashes, $pathHashes)),
            'state_sha256' => self::setHash($versions),
        ];
    }

    private function assertCacheAggregates(array $cache, array $package): void
    {
        foreach ([
            'career_ai_description_block_sha256',
            'career_path_block_sha256',
            'display_block_aggregate_sha256',
        ] as $key) {
            if (! is_string($cache[$key] ?? null)
                || ! is_string($package[$key] ?? null)
                || ! hash_equals($package[$key], $cache[$key])) {
                throw new Career1046DisplayAssetReplacementFailure('ACTIVE_CACHE_AGGREGATE_HASH_MISMATCH');
            }
        }
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

    /**
     * @param  array<string, array<string, mixed>>  $beforeRows
     * @param  list<array<string, mixed>>  $inserts
     * @param  array<string, array<string, mixed>>  $afterRows
     */
    private function restoreDatabaseRows(array $beforeRows, array $inserts, array $afterRows): void
    {
        DB::transaction(function () use ($beforeRows, $inserts, $afterRows): void {
            foreach ($inserts as $insert) {
                $current = CareerJobDisplayAsset::query()->whereKey($insert['id'])->lockForUpdate()->first();
                if (! $current instanceof CareerJobDisplayAsset
                    || $this->rowStateSnapshot(
                        $current,
                        array_values((array) $current->component_order_json),
                        (array) $current->page_payload_json,
                    ) !== $insert['after_state']) {
                    throw new Career1046DisplayAssetReplacementFailure('DATABASE_COMPENSATION_INSERT_STATE_DRIFT');
                }
                if (CareerJobDisplayAsset::query()->whereKey($insert['id'])->delete() !== 1) {
                    throw new Career1046DisplayAssetReplacementFailure('DATABASE_COMPENSATION_INSERT_DELETE_FAILED');
                }
            }

            foreach ($beforeRows as $slug => $row) {
                $current = CareerJobDisplayAsset::query()->whereKey($row['id'])->lockForUpdate()->first();
                if (! $current instanceof CareerJobDisplayAsset
                    || $this->rowStateSnapshot(
                        $current,
                        array_values((array) $current->component_order_json),
                        (array) $current->page_payload_json,
                    ) !== ($afterRows[$slug] ?? null)) {
                    throw new Career1046DisplayAssetReplacementFailure('DATABASE_COMPENSATION_UPDATE_STATE_DRIFT');
                }
                $affected = DB::table('career_job_display_assets')
                    ->where('id', $row['id'])
                    ->update([
                        'component_order_json' => self::encodeJson($row['component_order']),
                        'page_payload_json' => $row['page_payload_json'],
                        'metadata_json' => $row['metadata_json'],
                        'updated_at' => $row['updated_at'],
                    ]);
                if ($affected !== 1) {
                    throw new Career1046DisplayAssetReplacementFailure('DATABASE_COMPENSATION_UPDATE_FAILED');
                }
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
            'metadata_json' => self::encodeJson(is_array($asset->metadata_json) ? $asset->metadata_json : []),
            'updated_at' => $asset->getRawOriginal('updated_at'),
        ];
    }

    /**
     * @param  list<mixed>  $order
     * @param  array<string, mixed>  $pagePayload
     * @return array<string, mixed>
     */
    private function rowStateSnapshot(
        CareerJobDisplayAsset $asset,
        array $order,
        array $pagePayload,
        ?array $metadataOverride = null,
    ): array {
        $metadata = $metadataOverride ?? (is_array($asset->metadata_json) ? $asset->metadata_json : []);
        $metadataWithoutReplacement = $metadata;
        unset($metadataWithoutReplacement['replacement_lineage']);

        return [
            'id' => (string) $asset->id,
            'occupation_id' => (string) $asset->occupation_id,
            'slug' => strtolower((string) $asset->canonical_slug),
            'component_order' => $order,
            'page_payload_sha256' => self::hashValue($pagePayload),
            'metadata_sha256' => self::hashValue($metadata),
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
                'metadata_json' => $metadataWithoutReplacement,
                'import_run_id' => $asset->import_run_id,
            ]),
        ];
    }

    /** @return array{rows: array<string, array<string, mixed>>, summary: array<string, mixed>} */
    private function loadMissingBasePackage(string $backendRoot): array
    {
        $root = rtrim($backendRoot, '/').'/'.self::MISSING_BASE_PACKAGE_RELATIVE_PATH;
        $manifestPath = $root.'/manifest.json';
        $assetsPath = $root.'/assets.jsonl';
        if (! is_file($manifestPath) || ! is_file($assetsPath)) {
            throw new Career1046DisplayAssetReplacementFailure('MISSING_BASE_PACKAGE_FILE_MISSING');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $assetsSha256 = hash_file('sha256', $assetsPath);
        if (! is_array($manifest)
            || ($manifest['contract_version'] ?? null) !== self::MISSING_BASE_PACKAGE_CONTRACT_VERSION
            || data_get($manifest, 'counts.assets') !== self::EXPECTED_INSERTS
            || data_get($manifest, 'counts.localized_pages') !== self::EXPECTED_INSERTS * count(self::LOCALES)
            || data_get($manifest, 'counts.component_count_per_asset') !== count(CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER)
            || data_get($manifest, 'normalization.content_generation') !== false
            || preg_match('/\A[a-f0-9]{64}\z/', (string) data_get($manifest, 'source.workbook_sha256')) !== 1
            || trim((string) data_get($manifest, 'source.workbook_filename')) === ''
            || trim((string) data_get($manifest, 'source.mapper_version')) === ''
            || data_get($manifest, 'negative_guarantees.discoverability_change') !== false
            || data_get($manifest, 'negative_guarantees.search_submission') !== false
            || data_get($manifest, 'files.0.sha256') !== $assetsSha256) {
            throw new Career1046DisplayAssetReplacementFailure('MISSING_BASE_PACKAGE_MANIFEST_INVALID');
        }

        $rows = [];
        $payloadHashes = [];
        $handle = fopen($assetsPath, 'rb');
        if ($handle === false) {
            throw new Career1046DisplayAssetReplacementFailure('MISSING_BASE_PACKAGE_UNREADABLE');
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            $slug = is_array($row) ? strtolower(trim((string) ($row['slug'] ?? ''))) : '';
            $payload = is_array($row) ? ($row['asset_payload'] ?? null) : null;
            $payloadHash = is_array($row) ? (string) ($row['asset_payload_sha256'] ?? '') : '';
            $pages = is_array($payload) ? data_get($payload, 'page_payload_json.page') : null;
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                || isset($rows[$slug])
                || preg_match('/\A[0-9]{2}-[0-9]{4}\z/', (string) ($row['expected_soc'] ?? '')) !== 1
                || preg_match('/\A[0-9]{2}-[0-9]{4}\.[0-9]{2}\z/', (string) ($row['expected_onet'] ?? '')) !== 1
                || ! is_int($row['source_workbook_row_number'] ?? null)
                || $row['source_workbook_row_number'] < 1
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($row['source_workbook_row_sha256'] ?? '')) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($row['normalized_workbook_row_sha256'] ?? '')) !== 1
                || ! is_array($payload)
                || ! hash_equals($payloadHash, self::hashValue($payload))
                || array_values((array) ($payload['component_order_json'] ?? [])) !== CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER
                || ! is_array($pages)
                || ! $this->basePackageLocalizedPagesComplete($pages)) {
                fclose($handle);
                throw new Career1046DisplayAssetReplacementFailure('MISSING_BASE_PACKAGE_ROW_INVALID');
            }
            $rows[$slug] = $row;
            $payloadHashes[] = $payloadHash;
        }
        fclose($handle);
        ksort($rows, SORT_STRING);
        if (count($rows) !== self::EXPECTED_INSERTS
            || ! hash_equals((string) data_get($manifest, 'sets.slug_set_sha256'), self::setHash(array_keys($rows)))
            || ! hash_equals((string) data_get($manifest, 'sets.asset_payload_set_sha256'), self::setHash($payloadHashes))) {
            throw new Career1046DisplayAssetReplacementFailure('MISSING_BASE_PACKAGE_SET_INVALID');
        }

        return [
            'rows' => $rows,
            'summary' => [
                'package_sha256' => $assetsSha256,
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'asset_count' => self::EXPECTED_INSERTS,
                'localized_page_count' => self::EXPECTED_INSERTS * count(self::LOCALES),
                'slug_set_sha256' => self::setHash(array_keys($rows)),
                'source_workbook_sha256' => (string) data_get($manifest, 'source.workbook_sha256'),
                'source_workbook_basename' => (string) data_get($manifest, 'source.workbook_filename'),
                'mapper_version' => (string) data_get($manifest, 'source.mapper_version'),
                'content_generation' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $pages */
    private function basePackageLocalizedPagesComplete(array $pages): bool
    {
        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            if (! is_array($page)) {
                return false;
            }
            foreach (['hero', 'definition_block', 'responsibilities_block', 'market_signal_card', 'faq_block'] as $component) {
                if (! array_key_exists($component, $page) || $page[$component] === null || $page[$component] === [] || $page[$component] === '') {
                    return false;
                }
            }
        }

        return true;
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
        $deliveryReportPath = $root.'/w12_s3_delivery_report.json';
        if (! is_file($manifestPath) || ! is_file($assetsPath) || ! is_file($deliveryReportPath)) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_FILE_MISSING');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $deliveryReportSha256 = hash_file('sha256', $deliveryReportPath);
        if (! is_array($manifest)
            || ($manifest['contract_version'] ?? null) !== self::PACKAGE_CONTRACT_VERSION
            || data_get($manifest, 'counts.careers') !== self::EXPECTED_CAREERS
            || data_get($manifest, 'counts.locale_rows') !== self::EXPECTED_LOCALE_ROWS
            || data_get($manifest, 'counts.content_blocks') !== self::EXPECTED_BLOCKS
            || data_get($manifest, 'counts.locales') !== self::LOCALES
            || count((array) ($manifest['files'] ?? [])) !== 2
            || data_get($manifest, 'files.0.path') !== 'assets.jsonl'
            || data_get($manifest, 'files.0.sha256') !== $expectedPackageSha256
            || data_get($manifest, 'files.0.row_count') !== self::EXPECTED_LOCALE_ROWS
            || data_get($manifest, 'files.1.path') !== 'w12_s3_delivery_report.json'
            || data_get($manifest, 'files.1.sha256') !== $deliveryReportSha256
            || data_get($manifest, 'source_delivery_report.sha256') !== $deliveryReportSha256
            || data_get($manifest, 'source_delivery_report.path') !== 'w12_s3_delivery_report.json'
            || data_get($manifest, 'mapping.numeric_rating_authority') !== 'existing_ai_impact_table'
            || data_get($manifest, 'mapping.numeric_rating_statement_residue_count') !== 0
            || data_get($manifest, 'negative_guarantees.content_regeneration') !== false
            || data_get($manifest, 'negative_guarantees.seo_payload_change') !== false
            || data_get($manifest, 'negative_guarantees.structured_data_change') !== false
            || data_get($manifest, 'negative_guarantees.discoverability_change') !== false
            || data_get($manifest, 'negative_guarantees.search_submission') !== false
            || ! hash_equals($expectedPackageSha256, hash_file('sha256', $assetsPath))) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_MANIFEST_INVALID');
        }

        $rows = [];
        $identities = [];
        $sourceFiles = [];
        $sourcePaths = [];
        $blockHashes = [
            'career_ai_description_block' => [],
            'career_path_block' => [],
        ];
        $handle = fopen($assetsPath, 'rb');
        if ($handle === false) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_UNREADABLE');
        }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            $slug = is_array($row) ? strtolower(trim((string) ($row['slug'] ?? ''))) : '';
            $locale = is_array($row) ? trim((string) ($row['locale'] ?? '')) : '';
            $blocks = is_array($row) ? ($row['blocks'] ?? null) : null;
            $sources = is_array($row) ? ($row['sources'] ?? null) : null;
            $identity = $slug.'|'.$locale;
            $rowKeys = is_array($row) ? array_keys($row) : [];
            sort($rowKeys, SORT_STRING);
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                || ! in_array($locale, self::LOCALES, true)
                || isset($rows[$slug][$locale])
                || isset($identities[$identity])
                || $rowKeys !== ['blocks', 'locale', 'slug', 'sources']
                || ! is_array($blocks)
                || ! is_array($sources)) {
                fclose($handle);
                throw new Career1046DisplayAssetReplacementFailure('PACKAGE_ROW_INVALID_OR_DUPLICATE');
            }

            ksort($blocks, SORT_STRING);
            ksort($sources, SORT_STRING);
            if (array_keys($blocks) !== ['career_ai_description_block', 'career_path_block']
                || array_keys($sources) !== ['career_ai_description_block', 'career_path_block']
                || ! $this->packageAiBlockValid($blocks['career_ai_description_block'])
                || ! $this->packagePathBlockValid($blocks['career_path_block'])) {
                fclose($handle);
                throw new Career1046DisplayAssetReplacementFailure('PACKAGE_BLOCK_SCHEMA_INVALID');
            }
            foreach (array_keys($blocks) as $blockKey) {
                $source = $sources[$blockKey];
                $relativePath = is_array($source) ? trim((string) ($source['relative_path'] ?? '')) : '';
                $sha256 = is_array($source) ? trim((string) ($source['sha256'] ?? '')) : '';
                $sourceKeys = is_array($source) ? array_keys($source) : [];
                sort($sourceKeys, SORT_STRING);
                $sourceBlock = $blockKey === 'career_ai_description_block' ? '2a' : '2b';
                if ($relativePath === ''
                    || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
                    || $sourceKeys !== ['relative_path', 'sha256']
                    || preg_match('/\Aw(?:1|2|3|6|7|8|9|10|11)-s3-output\/'.preg_quote($slug, '/').'_'.$sourceBlock.'_'.preg_quote($locale, '/').'\.json\z/', $relativePath) !== 1
                    || isset($sourcePaths[$relativePath])) {
                    fclose($handle);
                    throw new Career1046DisplayAssetReplacementFailure('PACKAGE_SOURCE_CHAIN_INVALID');
                }
                $sourcePaths[$relativePath] = true;
                $sourceFiles[] = $relativePath.'|'.$sha256;
                $blockHashes[$blockKey][] = $identity.'|'.self::hashValue($blocks[$blockKey]);
            }
            $row['blocks'] = $blocks;
            $row['sources'] = $sources;
            $rows[$slug][$locale] = $row;
            $identities[$identity] = true;
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
        if (count($identities) !== self::EXPECTED_LOCALE_ROWS
            || count($sourcePaths) !== self::EXPECTED_BLOCKS
            || ! hash_equals((string) data_get($manifest, 'sets.slug_set_sha256'), self::setHash($slugs))
            || ! hash_equals((string) data_get($manifest, 'sets.identity_set_sha256'), self::setHash(array_keys($identities)))
            || ! hash_equals((string) data_get($manifest, 'sets.source_file_chain_sha256'), self::setHash($sourceFiles))
            || ! hash_equals((string) data_get($manifest, 'sets.career_ai_description_block_sha256'), self::setHash($blockHashes['career_ai_description_block']))
            || ! hash_equals((string) data_get($manifest, 'sets.career_path_block_sha256'), self::setHash($blockHashes['career_path_block']))
            || ! hash_equals((string) data_get($manifest, 'sets.display_block_aggregate_sha256'), self::setHash(array_merge(
                $blockHashes['career_ai_description_block'],
                $blockHashes['career_path_block'],
            )))) {
            throw new Career1046DisplayAssetReplacementFailure('PACKAGE_SET_OR_SOURCE_CHAIN_MISMATCH');
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
                'source_file_chain_sha256' => (string) data_get($manifest, 'sets.source_file_chain_sha256'),
                'delivery_report_sha256' => $deliveryReportSha256,
                'career_ai_description_block_sha256' => (string) data_get($manifest, 'sets.career_ai_description_block_sha256'),
                'career_path_block_sha256' => (string) data_get($manifest, 'sets.career_path_block_sha256'),
                'display_block_aggregate_sha256' => (string) data_get($manifest, 'sets.display_block_aggregate_sha256'),
                'numeric_rating_statement_residue_count' => 0,
            ],
        ];
    }

    /** @param array<string, mixed> $block */
    private function packageAiBlockValid(array $block): bool
    {
        $keys = array_keys($block);
        sort($keys, SORT_STRING);
        if (! in_array($keys, [
            ['body', 'component', 'heading', 'intro'],
            ['body', 'component', 'heading', 'intro', 'source_key'],
        ], true)
            || ($block['component'] ?? null) !== 'CareerAiDescriptionBlock'
            || trim((string) ($block['heading'] ?? '')) === ''
            || (array_key_exists('source_key', $block) && trim((string) $block['source_key']) === '')
            || ! is_array($block['body'] ?? null)
            || $block['body'] === []) {
            return false;
        }
        foreach ($block['body'] as $body) {
            if (! is_string($body)
                || trim($body) === ''
                || preg_match('/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u', $body) === 1) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $block */
    private function packagePathBlockValid(array $block): bool
    {
        $keys = array_keys($block);
        sort($keys, SORT_STRING);
        if (! in_array($keys, [
            ['caveat', 'component', 'heading', 'rows', 'source_key'],
            ['caveat', 'component', 'heading', 'intro', 'rows', 'source_key'],
        ], true)
            || ($block['component'] ?? null) !== 'CareerPathBlock'
            || trim((string) ($block['heading'] ?? '')) === ''
            || trim((string) ($block['caveat'] ?? '')) === ''
            || trim((string) ($block['source_key'] ?? '')) === ''
            || ! is_array($block['rows'] ?? null)
            || count($block['rows']) !== 4) {
            return false;
        }
        foreach ($block['rows'] as $row) {
            if (! is_array($row) || count($row) !== 4) {
                return false;
            }
            foreach ($row as $cell) {
                if (! is_string($cell) || trim($cell) === '') {
                    return false;
                }
            }
        }

        return true;
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
