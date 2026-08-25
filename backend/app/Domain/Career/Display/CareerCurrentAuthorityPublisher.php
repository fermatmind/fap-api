<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerCurrentAuthorityPublisherFailure extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        ?Throwable $previous = null,
        public readonly string $writeCommitState = 'confirmed_zero_write',
    ) {
        parent::__construct($safeCode, 0, $previous);
    }
}

final class CareerCurrentAuthorityPublisher
{
    public const CONTRACT_VERSION = 'career.current_authority_publish.v1';

    private const MANUAL_HOLD_SLUGS = ['software-developers'];

    private const HEALTH_SAMPLE_SLUGS = [
        'accountants-and-auditors',
        'actors',
        'registered-nurses',
        'writers-and-authors',
    ];

    private const JSON_FIELDS = [
        'component_order_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'structured_data_json',
        'implementation_contract_json',
        'metadata_json',
    ];

    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerCurrentAuthorityPackageLoader $loader,
        private readonly CareerCurrentAuthorityCacheGateway $cache,
        private readonly CareerJobDetailReaderSafeReviewProjector $readerSafeProjector,
        private readonly CareerJobDisplaySurfaceBuilder $displaySurfaceBuilder,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $backendRoot, bool $fullScan = false): array
    {
        $databaseCommitted = false;
        $pointersActivated = false;
        $prepared = [];
        $rollbackSnapshots = [];
        $plan = null;

        try {
            $authority = $this->loader->loadShardedForPublish($backendRoot);
            $this->assertAccountantsBoundaryNotice($authority['rows']);
            $plan = DB::transaction(fn (): array => $this->applyDatabasePlan($authority['rows'], $fullScan), 1);
            $databaseCommitted = ($plan['write_counts']['database_update_count']
                + $plan['write_counts']['database_insert_count']
                + $plan['write_counts']['database_delete_count']) > 0;
            DB::connection()->useWriteConnectionWhenReading();

            $candidatePairs = [];
            foreach ($plan['changed_slugs'] as $slug) {
                if ($this->isManualHoldSlug($slug)) {
                    continue;
                }
                foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                    $candidatePairs[$slug.'|'.$locale] = [$slug, $locale];
                }
            }
            if ($fullScan) {
                foreach ($this->staleCachePairs($authority['slugs'], $authority['rows']) as [$slug, $locale]) {
                    $candidatePairs[$slug.'|'.$locale] = [$slug, $locale];
                }
            }

            foreach ($candidatePairs as [$slug, $locale]) {
                try {
                    $entry = $this->cache->prepare($slug, $locale);
                } catch (Throwable $throwable) {
                    throw new CareerCurrentAuthorityPublisherFailure(
                        $this->cacheCandidatePreparationFailureCode($throwable),
                        $throwable,
                    );
                }
                if (($entry['status'] ?? null) !== 'ready' || ($entry['classification'] ?? null) !== 'ready_staged') {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_CANDIDATE_PREPARATION_FAILED');
                }
                $prepared[] = $entry;
                $this->assertCachedPayload($entry, $authority['rows'][$slug], $locale, true);
            }

            if ($prepared !== []) {
                $activation = $this->cache->activate($prepared);
                if (($activation['status'] ?? null) !== 'pass'
                    || count((array) ($activation['entries'] ?? [])) !== count($prepared)
                    || ($activation['failures'] ?? []) !== []) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_POINTER_ACTIVATION_FAILED');
                }
                $pointersActivated = true;
                $rollbackSnapshots = (array) ($activation['rollback_snapshots'] ?? []);
            }

            $verificationSlugs = $fullScan
                ? $this->publicSlugs($authority['slugs'])
                : $this->verificationSlugs($plan['changed_slugs'], $authority['slugs']);
            $readback = $this->assertPublicReadback($verificationSlugs, $authority['rows']);
            $this->assertManualHold();

            $writeCounts = $plan['write_counts'] + [
                'cache_candidate_write_count' => count($prepared) * 2,
                'cache_pointer_activation_count' => count($prepared),
                'occupation_write_count' => 0,
                'generation_write_count' => 0,
                'discoverability_write_count' => 0,
                'cms_write_count' => 0,
                'sitemap_write_count' => 0,
                'llms_write_count' => 0,
                'search_submission_count' => 0,
            ];
            $noop = array_sum($writeCounts) === 0;

            return [
                'package' => $authority['summary'],
                'authority' => [
                    'target_count' => count($authority['rows']),
                    'unique_slug_count' => count($authority['rows']),
                    'component_28_count' => count($authority['rows']),
                    'changed_slug_count' => count($plan['changed_slugs']),
                    'changed_slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue($plan['changed_slugs']),
                    'first_governance_cleanup' => $fullScan,
                    'before_state_sha256' => $plan['before_state_sha256'],
                    'after_state_sha256' => $plan['after_state_sha256'],
                ],
                'public_readback' => $readback,
                'manual_hold_verified' => true,
                'idempotent_noop' => $noop,
                'write_counts' => $writeCounts,
                'state_sha256' => CareerCurrentAuthorityPackage::hashValue([
                    'versionless_projection_sha256' => $authority['summary']['versionless_projection_sha256'],
                    'database_sha256' => $plan['after_state_sha256'],
                    'public_readback_sha256' => $readback['aggregate_sha256'],
                ]),
            ];
        } catch (Throwable $throwable) {
            $compensationFailure = null;
            $pointerRestoreFailed = false;
            try {
                if ($pointersActivated) {
                    $this->cache->restore($prepared, $rollbackSnapshots);
                }
            } catch (Throwable $failure) {
                $compensationFailure = $failure;
                $pointerRestoreFailed = true;
            }
            if (! $pointerRestoreFailed) {
                try {
                    $this->cache->forget($prepared);
                } catch (Throwable $failure) {
                    $compensationFailure ??= $failure;
                }
                if ($databaseCommitted && is_array($plan)) {
                    try {
                        $this->restoreDatabase($plan);
                    } catch (Throwable $failure) {
                        $compensationFailure ??= $failure;
                    }
                }
            }

            if ($compensationFailure instanceof Throwable) {
                throw new CareerCurrentAuthorityPublisherFailure(
                    'CURRENT_PUBLISH_COMPENSATION_FAILED',
                    $compensationFailure,
                    'ambiguous',
                );
            }

            throw new CareerCurrentAuthorityPublisherFailure(
                $throwable instanceof CareerCurrentAuthorityPublisherFailure
                    ? $throwable->safeCode
                    : ($throwable instanceof CareerCurrentAuthorityPackageFailure
                        ? $throwable->safeCode
                        : 'CURRENT_PUBLISH_FAILED'),
                $throwable,
                ($databaseCommitted || $prepared !== []) ? 'rolled_back' : 'confirmed_zero_write',
            );
        }
    }

    private function cacheCandidatePreparationFailureCode(Throwable $throwable): string
    {
        for ($candidate = $throwable; $candidate instanceof Throwable; $candidate = $candidate->getPrevious()) {
            $message = strtolower($candidate->getMessage());
            if (str_contains($message, 'oom command not allowed') || str_contains($message, 'maxmemory')) {
                return 'CURRENT_CACHE_CAPACITY_EXHAUSTED';
            }
            $class = strtolower($candidate::class);
            if (str_contains($class, 'redis') || str_contains($class, 'predis')) {
                return 'CURRENT_CACHE_BACKEND_PREPARATION_FAILED';
            }
            if ($candidate instanceof \Illuminate\Database\QueryException || $candidate instanceof \PDOException) {
                return 'CURRENT_CACHE_DATABASE_DEPENDENCY_FAILED';
            }
            if ($candidate instanceof \TypeError || $candidate instanceof \ValueError || $candidate instanceof \JsonException) {
                return 'CURRENT_CACHE_PAYLOAD_BUILD_FAILED';
            }
        }

        return 'CURRENT_CACHE_PREPARATION_RUNTIME_FAILED';
    }

    /**
     * @param  array<string,array<string,mixed>>  $targetRows
     * @return array<string,mixed>
     */
    private function applyDatabasePlan(array $targetRows, bool $forceRewrite): array
    {
        $assets = CareerJobDisplayAsset::query()->runtimeColumns()->orderBy('id')->lockForUpdate()->get();
        $beforeStateSha256 = $this->snapshotModelsHash($assets);
        $bySlug = $assets->groupBy(static fn (CareerJobDisplayAsset $asset): string => strtolower(trim((string) $asset->canonical_slug)));
        if ($bySlug->contains(static fn (Collection $rows): bool => $rows->count() > 1)) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_DISPLAY_SLUG_NOT_UNIQUE');
        }
        $selectedIds = [];
        $updates = [];
        $changedSlugs = [];

        foreach ($targetRows as $slug => $row) {
            /** @var Collection<int,CareerJobDisplayAsset> $slugRows */
            $slugRows = $bySlug->get($slug, collect());
            $formalRows = $slugRows->filter(static fn (CareerJobDisplayAsset $asset): bool => (string) $asset->asset_type === CareerCurrentAuthorityPackage::ASSET_TYPE
                && (string) $asset->asset_role === CareerCurrentAuthorityPackage::ASSET_ROLE
                && (string) $asset->status === CareerCurrentAuthorityPackage::READY_STATUS
            )->values();
            if ($formalRows->count() > 1) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_FORMAL_DISPLAY_ROW_NOT_UNIQUE');
            }

            $desired = $this->package->databaseAttributes($row);
            $asset = $formalRows->first();
            if ($asset instanceof CareerJobDisplayAsset) {
                $selectedIds[(string) $asset->id] = true;
                if ($forceRewrite || ! $this->matchesTarget($asset, $desired)) {
                    $updates[] = [
                        'id' => (string) $asset->id,
                        'slug' => $slug,
                        'before' => $this->snapshot($asset),
                        'attributes' => $desired,
                    ];
                    $changedSlugs[] = $slug;
                }

                continue;
            }

            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPATIBILITY_ROW_MISSING');
        }

        $deletes = $assets
            ->reject(static fn (CareerJobDisplayAsset $asset): bool => isset($selectedIds[(string) $asset->id]))
            ->map(fn (CareerJobDisplayAsset $asset): array => $this->snapshot($asset))
            ->values()
            ->all();

        if ($deletes !== []) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPATIBILITY_ROW_UNEXPECTED');
        }
        foreach ($updates as $update) {
            $affected = DB::table('career_job_display_assets')->where('id', $update['id'])->update(
                $this->databaseValues($update['attributes']) + ['import_run_id' => null, 'updated_at' => now()],
            );
            if ($affected !== 1) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_DATABASE_UPDATE_FAILED');
            }
        }
        $afterStateSha256 = $this->assertDatabaseReadback($targetRows, true);
        sort($changedSlugs, SORT_STRING);

        return [
            'changed_slugs' => array_values(array_unique($changedSlugs)),
            'updates' => $updates,
            'inserts' => [],
            'deletes' => $deletes,
            'before_state_sha256' => $beforeStateSha256,
            'after_state_sha256' => $afterStateSha256,
            'write_counts' => [
                'database_update_count' => count($updates),
                'database_insert_count' => 0,
                'database_delete_count' => 0,
            ],
        ];
    }

    /** @param array<string,array<string,mixed>> $targetRows */
    private function assertDatabaseReadback(array $targetRows, bool $lockForUpdate = false): string
    {
        $query = CareerJobDisplayAsset::query()->runtimeColumns()->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $assets = $query->get();
        if ($assets->count() !== count($targetRows)) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_DATABASE_READBACK_COUNT_MISMATCH');
        }
        $seen = [];
        $hashContext = hash_init('sha256');
        hash_update($hashContext, '[');
        $index = 0;
        foreach ($assets as $asset) {
            $slug = strtolower(trim((string) $asset->canonical_slug));
            if (isset($seen[$slug]) || ! isset($targetRows[$slug])
                || ! $this->matchesTarget($asset, $this->package->databaseAttributes($targetRows[$slug]))) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_DATABASE_READBACK_STATE_MISMATCH');
            }
            $seen[$slug] = true;
            hash_update(
                $hashContext,
                ($index === 0 ? '' : ',').CareerCurrentAuthorityPackage::encodeCanonical($this->snapshot($asset)),
            );
            $index++;
        }
        if (count($seen) !== count($targetRows)) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_DATABASE_READBACK_SLUG_MISMATCH');
        }

        hash_update($hashContext, ']');

        return hash_final($hashContext);
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $row */
    private function assertCachedPayload(array $entry, array $row, string $locale, bool $prepared): void
    {
        $payload = $prepared
            ? $this->cache->preparedPayload($entry)
            : ($entry['payload'] ?? null);
        if (! is_array($payload) || ! is_array(data_get($payload, 'display_surface_v1'))) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_PAYLOAD_MISSING');
        }
        $mismatchCode = $this->cachedPayloadMismatchCode($payload, $row, $locale);
        if ($mismatchCode !== null) {
            // Resolve only a content-free, field-specific eligibility code before compensation.
            // Unavailable component fields are storage-order independent at this boundary;
            // public copy and raw payload values never enter the publish receipt.
            $displayFailureCode = $this->displaySurfaceBuilder->diagnosticFailureCodeForSlug(
                (string) ($row['canonical_slug'] ?? ''),
                $locale,
            );
            if ($displayFailureCode !== null) {
                throw new CareerCurrentAuthorityPublisherFailure($displayFailureCode);
            }
            throw new CareerCurrentAuthorityPublisherFailure($mismatchCode);
        }
    }

    /** @param array<string,mixed>|null $payload @param array<string,mixed> $row */
    private function cachedPayloadMatches(?array $payload, array $row, string $locale): bool
    {
        return $this->cachedPayloadMismatchCode($payload, $row, $locale) === null;
    }

    /** @param array<string,mixed>|null $payload @param array<string,mixed> $row */
    private function cachedPayloadMismatchCode(?array $payload, array $row, string $locale): ?string
    {
        if (is_array($payload) && $this->containsVersionDiscriminator($payload)) {
            return 'CURRENT_CACHE_VERSION_FIELD_FORBIDDEN';
        }
        $surface = is_array($payload) ? data_get($payload, 'display_surface_v1') : null;
        if (! is_array($surface)) {
            return 'CURRENT_CACHE_CONTENT_MISMATCH';
        }
        try {
            $expected = $this->readerSafeProjector->project($this->package->publicProjection($row, $locale));
            $actual = $this->readerSafeProjector->project($this->package->displayOwnedProjection($surface));
            if (hash_equals(
                CareerCurrentAuthorityPackage::hashValue($expected),
                CareerCurrentAuthorityPackage::hashValue($actual),
            )) {
                return null;
            }
            foreach (array_keys($expected) as $field) {
                if (! array_key_exists($field, $actual)
                    || ! hash_equals(
                        CareerCurrentAuthorityPackage::hashValue($expected[$field]),
                        CareerCurrentAuthorityPackage::hashValue($actual[$field]),
                    )) {
                    return match ($field) {
                        'surface_version' => 'CURRENT_CACHE_SURFACE_VERSION_MISMATCH',
                        'available_locales' => 'CURRENT_CACHE_AVAILABLE_LOCALES_MISMATCH',
                        'page' => $this->pageMismatchCode((array) $expected[$field], (array) ($actual[$field] ?? [])),
                        'component_order' => 'CURRENT_CACHE_COMPONENT_ORDER_MISMATCH',
                        'sources' => 'CURRENT_CACHE_SOURCES_MISMATCH',
                        'structured_data_from_visible_content' => 'CURRENT_CACHE_STRUCTURED_DATA_MISMATCH',
                        'implementation_contract' => 'CURRENT_CACHE_IMPLEMENTATION_CONTRACT_MISMATCH',
                        'presentation_v1' => 'CURRENT_CACHE_PRESENTATION_MISMATCH',
                        default => 'CURRENT_CACHE_CONTENT_MISMATCH',
                    };
                }
            }

            return 'CURRENT_CACHE_CONTENT_MISMATCH';
        } catch (CareerCurrentAuthorityPackageFailure) {
            return 'CURRENT_CACHE_CONTENT_MISMATCH';
        }
    }

    /** @param array<mixed> $value */
    private function containsVersionDiscriminator(array $value): bool
    {
        foreach ($value as $key => $item) {
            if ($key === 'asset_version' || $key === 'template_version') {
                return true;
            }
            if (is_array($item) && $this->containsVersionDiscriminator($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function pageMismatchCode(array $expected, array $actual): string
    {
        if (($expected['locale'] ?? null) !== ($actual['locale'] ?? null)) {
            return 'CURRENT_CACHE_PAGE_LOCALE_MISMATCH';
        }
        $expectedContent = is_array($expected['content'] ?? null) ? $expected['content'] : [];
        $actualContent = is_array($actual['content'] ?? null) ? $actual['content'] : [];
        foreach ($expectedContent as $componentId => $component) {
            if (! array_key_exists($componentId, $actualContent)
                || ! hash_equals(
                    CareerCurrentAuthorityPackage::hashValue($component),
                    CareerCurrentAuthorityPackage::hashValue($actualContent[$componentId]),
                )) {
                return match ($componentId) {
                    'career_quick_answers_block' => 'CURRENT_CACHE_QUICK_ANSWERS_MISMATCH',
                    'onet_structured_fields_block' => 'CURRENT_CACHE_ONET_STRUCTURED_FIELDS_MISMATCH',
                    default => 'CURRENT_CACHE_PAGE_CONTENT_MISMATCH',
                };
            }
        }

        return 'CURRENT_CACHE_PAGE_CONTENT_MISMATCH';
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string,array<string,mixed>>  $targetRows
     * @return list<array{string,string}>
     */
    private function staleCachePairs(array $slugs, array $targetRows): array
    {
        $pairs = [];
        foreach (array_chunk($slugs, 50) as $chunk) {
            $cache = $this->cache->publicationSnapshot($chunk, CareerCurrentAuthorityPackage::LOCALES);
            foreach ($chunk as $slug) {
                if ($this->isManualHoldSlug($slug)) {
                    continue;
                }
                foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                    $entry = $cache[$slug][$locale] ?? null;
                    if (! is_array($entry)
                        || ($entry['published'] ?? null) !== true
                        || ($entry['classification'] ?? null) !== 'ready_active'
                        || ! $this->cachedPayloadMatches(
                            is_array($entry['payload'] ?? null) ? $entry['payload'] : null,
                            $targetRows[$slug],
                            $locale,
                        )) {
                        $pairs[] = [$slug, $locale];
                    }
                }
            }
        }

        return $pairs;
    }

    /** @param list<string> $slugs @param array<string,array<string,mixed>> $targetRows @return array<string,mixed> */
    private function assertPublicReadback(array $slugs, array $targetRows): array
    {
        $hashes = [];
        foreach (array_chunk($slugs, 50) as $chunk) {
            $cache = $this->cache->publicationSnapshot($chunk, CareerCurrentAuthorityPackage::LOCALES);
            foreach ($chunk as $slug) {
                foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                    $entry = $cache[$slug][$locale] ?? null;
                    if (! is_array($entry)
                        || ($entry['published'] ?? null) !== true
                        || ($entry['classification'] ?? null) !== 'ready_active') {
                        throw new CareerCurrentAuthorityPublisherFailure('CURRENT_ACTIVE_CACHE_READBACK_FAILED');
                    }
                    $this->assertCachedPayload($entry, $targetRows[$slug], $locale, false);
                    $api = $this->cache->verifyOnlyRead($slug, $locale);
                    if (($api['state'] ?? null) !== 'fresh' || ! is_array($api['payload'] ?? null)) {
                        throw new CareerCurrentAuthorityPublisherFailure('CURRENT_API_READBACK_FAILED');
                    }
                    $this->assertCachedPayload(['payload' => $api['payload']], $targetRows[$slug], $locale, false);
                    $hashes[] = $this->package->publicContentHash($targetRows[$slug], $locale);
                }
            }
        }
        sort($hashes, SORT_STRING);

        return [
            'verified_slug_count' => count($slugs),
            'verified_locale_page_count' => count($hashes),
            'cache_content_match_count' => count($hashes),
            'api_content_match_count' => count($hashes),
            'aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($hashes),
        ];
    }

    private function assertManualHold(): void
    {
        foreach (self::MANUAL_HOLD_SLUGS as $slug) {
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $read = $this->cache->verifyOnlyRead($slug, $locale);
                if (($read['state'] ?? null) !== 'not_found' || ($read['payload'] ?? null) !== null) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_MANUAL_HOLD_PUBLIC_DRIFT');
                }
            }
        }
    }

    /** @param array<string,array<string,mixed>> $rows */
    private function assertAccountantsBoundaryNotice(array $rows): void
    {
        $row = $rows['accountants-and-auditors'] ?? null;
        if (! is_array($row)) {
            return;
        }
        $pages = $row['page_payload_json']['page'] ?? $row['page_payload_json'] ?? null;
        foreach (['en', 'zh'] as $locale) {
            $notices = is_array($pages) && is_array($pages[$locale] ?? null)
                ? $pages[$locale]['boundary_notice'] ?? null
                : null;
            if (! is_array($notices) || ! array_is_list($notices) || $notices === []) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_ACCOUNTANTS_BOUNDARY_READBACK_INVALID');
            }
            foreach ($notices as $notice) {
                if (! is_string($notice) || trim($notice) === '') {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_ACCOUNTANTS_BOUNDARY_READBACK_INVALID');
                }
            }
        }
    }

    /** @param list<string> $changedSlugs @param list<string> $targetSlugs @return list<string> */
    private function verificationSlugs(array $changedSlugs, array $targetSlugs): array
    {
        $publicTargetSlugs = $this->publicSlugs($targetSlugs);
        $samples = array_values(array_intersect(self::HEALTH_SAMPLE_SLUGS, $publicTargetSlugs));
        if ($samples === [] && $publicTargetSlugs !== []) {
            $samples[] = $publicTargetSlugs[0];
        }
        $slugs = array_values(array_unique(array_merge($this->publicSlugs($changedSlugs), $samples)));
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /** @param list<string> $slugs @return list<string> */
    private function publicSlugs(array $slugs): array
    {
        return array_values(array_filter(
            $slugs,
            fn (string $slug): bool => ! $this->isManualHoldSlug($slug),
        ));
    }

    private function isManualHoldSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::MANUAL_HOLD_SLUGS, true);
    }

    /** @param array<string,mixed> $desired */
    private function matchesTarget(CareerJobDisplayAsset $asset, array $desired): bool
    {
        if ($asset->import_run_id !== null) {
            return false;
        }
        foreach ($desired as $field => $value) {
            $actual = $asset->{$field};
            if (in_array($field, self::JSON_FIELDS, true)) {
                if (! hash_equals(
                    CareerCurrentAuthorityPackage::hashValue($value),
                    CareerCurrentAuthorityPackage::hashValue($actual),
                )) {
                    return false;
                }
            } elseif ((string) $actual !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function snapshot(CareerJobDisplayAsset $asset): array
    {
        return [
            'id' => (string) $asset->id,
            'occupation_id' => (string) $asset->occupation_id,
            'canonical_slug' => (string) $asset->canonical_slug,
            'surface_version' => (string) $asset->surface_version,
            'asset_type' => (string) $asset->asset_type,
            'asset_role' => (string) $asset->asset_role,
            'status' => (string) $asset->status,
            'component_order_json' => $asset->component_order_json,
            'page_payload_json' => $asset->page_payload_json,
            'seo_payload_json' => $asset->seo_payload_json,
            'sources_json' => $asset->sources_json,
            'structured_data_json' => $asset->structured_data_json,
            'implementation_contract_json' => $asset->implementation_contract_json,
            'metadata_json' => $asset->metadata_json,
            'import_run_id' => $asset->import_run_id,
            'created_at' => $asset->getRawOriginal('created_at'),
            'updated_at' => $asset->getRawOriginal('updated_at'),
        ];
    }

    /** @param Collection<int,CareerJobDisplayAsset> $assets */
    private function snapshotModelsHash(Collection $assets): string
    {
        // Stream deterministic compensation hashes without retaining a second copy of every JSON payload.
        $context = hash_init('sha256');
        hash_update($context, '[');
        foreach ($assets->values() as $index => $asset) {
            hash_update(
                $context,
                ($index === 0 ? '' : ',').CareerCurrentAuthorityPackage::encodeCanonical($this->snapshot($asset)),
            );
        }
        hash_update($context, ']');

        return hash_final($context);
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function databaseValues(array $values): array
    {
        foreach (self::JSON_FIELDS as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = $values[$field] === null
                    ? null
                    : CareerCurrentAuthorityPackage::encodeCanonical($values[$field]);
            }
        }

        return $values;
    }

    /** @param array<string,mixed> $plan */
    private function restoreDatabase(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $current = CareerJobDisplayAsset::query()->runtimeColumns()->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($plan['after_state_sha256'], $this->snapshotModelsHash($current))) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPENSATION_STATE_DRIFT');
            }
            foreach ($plan['updates'] as $update) {
                $before = $update['before'];
                $id = $before['id'];
                unset($before['id']);
                if (DB::table('career_job_display_assets')->where('id', $id)->update($this->databaseValues($before)) !== 1) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPENSATION_UPDATE_FAILED');
                }
            }
            $restored = CareerJobDisplayAsset::query()->runtimeColumns()->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($plan['before_state_sha256'], $this->snapshotModelsHash($restored))) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPENSATION_READBACK_FAILED');
            }
        }, 1);
    }
}
