<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
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
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $backendRoot, bool $fullScan = false): array
    {
        $authority = $this->loader->load($backendRoot);
        $databaseCommitted = false;
        $pointersActivated = false;
        $prepared = [];
        $rollbackSnapshots = [];
        $plan = null;

        try {
            $plan = DB::transaction(fn (): array => $this->applyDatabasePlan($authority['rows']), 1);
            $databaseCommitted = ($plan['write_counts']['database_update_count']
                + $plan['write_counts']['database_insert_count']
                + $plan['write_counts']['database_delete_count']) > 0;

            foreach ($plan['changed_slugs'] as $slug) {
                foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                    $entry = $this->cache->prepare($slug, $locale);
                    if (($entry['status'] ?? null) !== 'ready' || ($entry['classification'] ?? null) !== 'ready_staged') {
                        throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_CANDIDATE_PREPARATION_FAILED');
                    }
                    $prepared[] = $entry;
                    $this->assertCachedPayload($entry, $authority['rows'][$slug], $locale, true);
                }
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
                ? $authority['slugs']
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
                    'component_26_count' => count($authority['rows']),
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
                    'assets_sha256' => $authority['summary']['assets_sha256'],
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

    /**
     * @param  array<string,array<string,mixed>>  $targetRows
     * @return array<string,mixed>
     */
    private function applyDatabasePlan(array $targetRows): array
    {
        $assets = CareerJobDisplayAsset::query()->orderBy('id')->lockForUpdate()->get();
        $beforeStateSha256 = $this->snapshotModelsHash($assets);
        $bySlug = $assets->groupBy(static fn (CareerJobDisplayAsset $asset): string => strtolower(trim((string) $asset->canonical_slug)));
        $selectedIds = [];
        $updates = [];
        $inserts = [];
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
                if (! $this->matchesTarget($asset, $desired)) {
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

            $occupations = Occupation::query()->where('canonical_slug', $slug)->lockForUpdate()->get();
            if ($occupations->count() !== 1) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_INSERT_OCCUPATION_NOT_UNIQUE');
            }
            /** @var Occupation $occupation */
            $occupation = $occupations->first();
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://fermatmind.com/authority/career/current/'.$slug)->toString();
            if (CareerJobDisplayAsset::query()->whereKey($id)->exists()) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_INSERT_ID_COLLISION');
            }
            $selectedIds[$id] = true;
            $inserts[] = [
                'id' => $id,
                'slug' => $slug,
                'occupation_id' => (string) $occupation->id,
                'attributes' => $desired,
            ];
            $changedSlugs[] = $slug;
        }

        $deletes = $assets
            ->reject(static fn (CareerJobDisplayAsset $asset): bool => isset($selectedIds[(string) $asset->id]))
            ->map(fn (CareerJobDisplayAsset $asset): array => $this->snapshot($asset))
            ->values()
            ->all();

        if ($deletes !== []) {
            DB::table('career_job_display_assets')->whereIn('id', array_column($deletes, 'id'))->delete();
        }
        foreach ($updates as $update) {
            $affected = DB::table('career_job_display_assets')->where('id', $update['id'])->update(
                $this->databaseValues($update['attributes']) + ['import_run_id' => null, 'updated_at' => now()],
            );
            if ($affected !== 1) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_DATABASE_UPDATE_FAILED');
            }
        }
        foreach ($inserts as $insert) {
            CareerJobDisplayAsset::query()->create($insert['attributes'] + [
                'id' => $insert['id'],
                'occupation_id' => $insert['occupation_id'],
                'canonical_slug' => $insert['slug'],
                'import_run_id' => null,
            ]);
        }

        $afterStateSha256 = $this->assertDatabaseReadback($targetRows, true);
        sort($changedSlugs, SORT_STRING);

        return [
            'changed_slugs' => array_values(array_unique($changedSlugs)),
            'updates' => $updates,
            'inserts' => $inserts,
            'deletes' => $deletes,
            'before_state_sha256' => $beforeStateSha256,
            'after_state_sha256' => $afterStateSha256,
            'write_counts' => [
                'database_update_count' => count($updates),
                'database_insert_count' => count($inserts),
                'database_delete_count' => count($deletes),
            ],
        ];
    }

    /** @param array<string,array<string,mixed>> $targetRows */
    private function assertDatabaseReadback(array $targetRows, bool $lockForUpdate = false): string
    {
        $query = CareerJobDisplayAsset::query()->orderBy('id');
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
        $surface = is_array($payload) ? data_get($payload, 'display_surface_v1') : null;
        if (! is_array($surface)) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_PAYLOAD_MISSING');
        }
        $expected = $this->readerSafeProjector->project($this->package->publicProjection($row, $locale));
        $actual = $this->readerSafeProjector->project($this->package->displayOwnedProjection($surface));
        if (! hash_equals(
            CareerCurrentAuthorityPackage::hashValue($expected),
            CareerCurrentAuthorityPackage::hashValue($actual),
        )) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_CONTENT_MISMATCH');
        }
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

    /** @param list<string> $changedSlugs @param list<string> $targetSlugs @return list<string> */
    private function verificationSlugs(array $changedSlugs, array $targetSlugs): array
    {
        $samples = array_values(array_intersect(self::HEALTH_SAMPLE_SLUGS, $targetSlugs));
        if ($samples === [] && $targetSlugs !== []) {
            $samples[] = $targetSlugs[0];
        }
        $slugs = array_values(array_unique(array_merge($changedSlugs, $samples)));
        sort($slugs, SORT_STRING);

        return $slugs;
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
            'asset_version' => (string) $asset->asset_version,
            'template_version' => (string) $asset->template_version,
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
            $current = CareerJobDisplayAsset::query()->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($plan['after_state_sha256'], $this->snapshotModelsHash($current))) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPENSATION_STATE_DRIFT');
            }
            foreach ($plan['inserts'] as $insert) {
                if (CareerJobDisplayAsset::query()->whereKey($insert['id'])->delete() !== 1) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPENSATION_INSERT_DELETE_FAILED');
                }
            }
            foreach ($plan['updates'] as $update) {
                $before = $update['before'];
                $id = $before['id'];
                unset($before['id']);
                if (DB::table('career_job_display_assets')->where('id', $id)->update($this->databaseValues($before)) !== 1) {
                    throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPENSATION_UPDATE_FAILED');
                }
            }
            foreach ($plan['deletes'] as $deleted) {
                DB::table('career_job_display_assets')->insert($this->databaseValues($deleted));
            }
            $restored = CareerJobDisplayAsset::query()->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($plan['before_state_sha256'], $this->snapshotModelsHash($restored))) {
                throw new CareerCurrentAuthorityPublisherFailure('CURRENT_COMPENSATION_READBACK_FAILED');
            }
        }, 1);
    }
}
