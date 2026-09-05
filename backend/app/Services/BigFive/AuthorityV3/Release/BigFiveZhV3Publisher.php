<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\Release;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class BigFiveZhV3Publisher
{
    public const PACKAGE_FILE_SHA256 = '83536987f7edc73d668f481942c94f6bf549abf23a0e498941f47bc56726490d';

    public const OPERATOR_ADMIN_USER_ID = 1;

    public const WORKFLOW_STATE = 'published_content_override';

    private const REQUIRED_ASSET_COLUMNS = [
        'id', 'org_id', 'framework', 'entity_type', 'entity_key', 'slug', 'locale',
        'title', 'summary', 'content_sections_json', 'seo_json', 'robots', 'canonical_json',
        'hreflang_json', 'faq_json', 'schema_json', 'method_boundary_json',
        'evidence_notes_json', 'authority_json', 'internal_links_json', 'is_public',
        'index_eligible', 'sitemap_eligible', 'llms_eligible', 'launch_state', 'review_state',
        'contract_version', 'source_package', 'source_hash', 'working_revision_id',
        'published_revision_id', 'published_at', 'last_reviewed_at',
        'created_by_admin_user_id', 'updated_by_admin_user_id', 'created_at', 'updated_at',
    ];

    private const REQUIRED_REVISION_COLUMNS = [
        'id', 'asset_id', 'revision_no', 'authority_asset_key', 'source_package',
        'source_hash', 'authority_package_sha256', 'workflow_state', 'snapshot_json',
        'public_runtime_fingerprint_before', 'created_by_admin_user_id',
    ];

    public function __construct(
        private readonly PersonalityPublicContentAssetContract $contract,
        private readonly PersonalityPublicAssetReadModelCache $personalityCache,
        private readonly SeoDiscoverabilityCacheInvalidator $discoverabilityCache,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(string $packagePath): array
    {
        $snapshotBefore = $this->databaseSnapshotFingerprint();
        $plan = $this->buildPlan($packagePath);
        $aliasBoundaryAfter = $this->assertRedirectAliasesAbsent($plan['descriptors']);
        $snapshotAfter = $this->databaseSnapshotFingerprint();
        if (! hash_equals($snapshotBefore, $snapshotAfter)) {
            throw new RuntimeException('Read-only preflight changed the database snapshot.');
        }
        if (! hash_equals(
            (string) $plan['alias_boundary']['fingerprint_sha256'],
            (string) $aliasBoundaryAfter['fingerprint_sha256'],
        )) {
            throw new RuntimeException('Read-only preflight changed the redirect alias boundary.');
        }

        return [
            ...$this->publicPlan($plan, 'read_only_preflight'),
            'database_snapshot_before_sha256' => $snapshotBefore,
            'database_snapshot_after_sha256' => $snapshotAfter,
            'database_snapshot_unchanged' => true,
            'alias_boundary_unchanged' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function publish(string $packagePath, int $operatorAdminUserId): array
    {
        if ($operatorAdminUserId !== self::OPERATOR_ADMIN_USER_ID) {
            throw new RuntimeException('The locked Big Five zh-CN V3 operator is admin_user:1.');
        }

        $plan = $this->buildPlan($packagePath);
        $beforeBoundary = $this->nonTargetBoundaryFingerprint();
        $result = DB::transaction(function () use ($plan, $operatorAdminUserId, $beforeBoundary): array {
            $lockedAliasBoundary = $this->assertRedirectAliasesAbsent($plan['descriptors'], true);
            if (! hash_equals(
                (string) $plan['alias_boundary']['fingerprint_sha256'],
                (string) $lockedAliasBoundary['fingerprint_sha256'],
            )) {
                throw new RuntimeException('Redirect alias boundary drifted before the 52-page transaction.');
            }

            $writes = [];
            $createdRevisionCount = 0;
            $unchangedCount = 0;
            foreach ($plan['descriptors'] as $descriptor) {
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                    ->whereKey($descriptor['asset_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $asset instanceof PersonalityPublicContentAsset) {
                    throw new RuntimeException('Target asset disappeared during transaction: '.$descriptor['authority_asset_key'].'.');
                }

                $existingRevision = PersonalityPublicContentAssetRevision::query()
                    ->where('authority_package_sha256', self::PACKAGE_FILE_SHA256)
                    ->where('authority_asset_key', $descriptor['authority_asset_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existingRevision instanceof PersonalityPublicContentAssetRevision) {
                    $this->assertExistingRevision($existingRevision, $asset, $descriptor);
                    $unchangedCount++;

                    continue;
                }

                $fingerprintBefore = $this->runtimeFingerprint($asset);
                $revision = PersonalityPublicContentAssetRevision::query()->create([
                    'asset_id' => (int) $asset->id,
                    'revision_no' => ((int) PersonalityPublicContentAssetRevision::query()
                        ->where('asset_id', $asset->id)
                        ->max('revision_no')) + 1,
                    'authority_asset_key' => $descriptor['authority_asset_key'],
                    'source_package' => 'big5-zh-v3-52-page-release',
                    'source_hash' => $descriptor['source_hash'],
                    'authority_package_sha256' => self::PACKAGE_FILE_SHA256,
                    'workflow_state' => self::WORKFLOW_STATE,
                    'snapshot_json' => $descriptor['snapshot'],
                    'public_runtime_fingerprint_before' => $fingerprintBefore,
                    'created_by_admin_user_id' => $operatorAdminUserId,
                ]);

                $effectivePublishedAt = $asset->published_at ?? now();
                $asset->forceFill([
                    ...$descriptor['attributes'],
                    'published_at' => $effectivePublishedAt,
                    'working_revision_id' => (int) $revision->id,
                    'published_revision_id' => (int) $revision->id,
                    'updated_by_admin_user_id' => $operatorAdminUserId,
                ])->save();

                $asset->refresh();
                $this->assertRuntimeReadback($asset, $revision, $descriptor);
                $createdRevisionCount++;
                $writes[] = [
                    'asset_id' => (int) $asset->id,
                    'revision_id' => (int) $revision->id,
                    'authority_asset_key' => $descriptor['authority_asset_key'],
                    'canonical_path' => data_get($descriptor, 'attributes.canonical_json.path'),
                ];
            }

            $this->assertAllReadback($plan['descriptors']);
            $afterBoundary = $this->nonTargetBoundaryFingerprint();
            if (! hash_equals($beforeBoundary, $afterBoundary)) {
                throw new RuntimeException('English or non-target authority boundary changed during the 52-page transaction.');
            }
            $afterAliasBoundary = $this->assertRedirectAliasesAbsent($plan['descriptors']);
            if (! hash_equals(
                (string) $lockedAliasBoundary['fingerprint_sha256'],
                (string) $afterAliasBoundary['fingerprint_sha256'],
            )) {
                throw new RuntimeException('Redirect alias row, revision, or pointer changed during the 52-page transaction.');
            }

            return [
                ...$this->publicPlan($plan, 'controlled_publish'),
                'ok' => true,
                'status' => 'PASS_BIG_FIVE_ZH_V3_52_PAGE_PUBLISH',
                'writes_committed' => true,
                'created_revision_count' => $createdRevisionCount,
                'idempotent_unchanged_count' => $unchangedCount,
                'public_release_count' => BigFiveZhV3PackageCompiler::ASSET_COUNT,
                'writes' => $writes,
                'transaction_readback_ok' => true,
                'non_target_boundary_unchanged' => true,
                'alias_boundary_unchanged' => true,
            ];
        }, 1);

        $result['cache_invalidation_ok'] = true;
        $result['cache_invalidation_warning'] = null;
        try {
            $this->flushPublicCaches($plan['descriptors']);
        } catch (Throwable) {
            $result['cache_invalidation_ok'] = false;
            $result['cache_invalidation_warning'] = 'PUBLIC_CACHE_INVALIDATION_FAILED_AFTER_COMMIT';
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function buildPlan(string $packagePath): array
    {
        $this->assertSchema();
        [$package, $resolvedPath, $fileSha] = $this->readPackage($packagePath);
        $this->assertPackage($package, $fileSha);

        $descriptors = [];
        $identitySeen = [];
        $canonicalSeen = [];
        $familyCounts = [];
        $currentRevisionIds = [];
        $currentPublicFingerprints = [];
        $plannedSourceHashes = [];
        $currentPublicCount = 0;
        foreach ($package['assets'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['asset'] ?? null)) {
                throw new RuntimeException('Release package contains an invalid asset descriptor.');
            }
            $assetPayload = $entry['asset'];
            $data = $this->contract->validateAsset($assetPayload);
            $attributes = $data->toModelAttributes();
            $authorityAssetKey = trim((string) ($entry['authority_asset_key'] ?? ''));
            $sourceHash = trim((string) ($entry['runtime_projection_sha256'] ?? ''));
            if ($authorityAssetKey === '' || ! preg_match('/^[a-f0-9]{64}$/', $sourceHash)) {
                throw new RuntimeException('Release descriptor authority identity or source hash is invalid.');
            }
            if (! hash_equals($sourceHash, (string) ($attributes['source_hash'] ?? ''))) {
                throw new RuntimeException('Release descriptor/runtime source hash mismatch for '.$authorityAssetKey.'.');
            }
            $canonical = (string) data_get($attributes, 'canonical_json.path', '');
            if (in_array($data->entityKey, BigFiveCanonicalRouteCatalog::ZH_REDIRECT_ONLY_ALIASES, true)) {
                throw new RuntimeException('Redirect-only alias is forbidden in the V3 release: '.$data->entityKey.'.');
            }
            if (isset($identitySeen[$authorityAssetKey]) || isset($canonicalSeen[$canonical])) {
                throw new RuntimeException('Release package authority identity or canonical collision.');
            }
            $identitySeen[$authorityAssetKey] = true;
            $canonicalSeen[$canonical] = true;

            $row = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                ->where('entity_type', $data->entityType)
                ->where('entity_key', $data->entityKey)
                ->where('locale', 'zh-CN')
                ->first();
            if (! $row instanceof PersonalityPublicContentAsset) {
                throw new RuntimeException('Required existing CMS authority row is missing for '.$authorityAssetKey.'.');
            }
            $slugCollision = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                ->where('slug', $data->slug)
                ->where('locale', 'zh-CN')
                ->whereKeyNot($row->id)
                ->exists();
            if ($slugCollision) {
                throw new RuntimeException('Target slug collides with another CMS row for '.$authorityAssetKey.'.');
            }
            $canonicalCollision = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                ->where('locale', 'zh-CN')
                ->whereKeyNot($row->id)
                ->get(['id', 'canonical_json'])
                ->contains(static fn (PersonalityPublicContentAsset $candidate): bool => (
                    (string) data_get($candidate->canonical_json, 'path', '') === $canonical
                ));
            if ($canonicalCollision) {
                throw new RuntimeException('Target canonical collides with another CMS row for '.$authorityAssetKey.'.');
            }

            // Hreflang remains owned by the existing CMS row and is never invented by this package.
            $attributes['hreflang_json'] = is_array($row->hreflang_json) ? $row->hreflang_json : [];
            $attributes['created_by_admin_user_id'] = self::OPERATOR_ADMIN_USER_ID;
            $attributes['updated_by_admin_user_id'] = self::OPERATOR_ADMIN_USER_ID;
            $snapshot = [
                'schema_version' => BigFiveZhV3PackageCompiler::SCHEMA_VERSION,
                'release_id' => BigFiveZhV3PackageCompiler::RELEASE_ID,
                'authority_asset_key' => $authorityAssetKey,
                'source_content_sha256' => BigFiveZhV3PackageCompiler::SOURCE_CONTENT_SHA256,
                'package_file_sha256' => self::PACKAGE_FILE_SHA256,
                'attributes' => $attributes,
                'evidence_claims' => array_values(is_array($entry['evidence_claims'] ?? null) ? $entry['evidence_claims'] : []),
            ];
            $descriptors[] = [
                'asset_id' => (int) $row->id,
                'authority_asset_key' => $authorityAssetKey,
                'source_hash' => $sourceHash,
                'attributes' => $attributes,
                'snapshot' => $snapshot,
            ];
            $currentRevisionIds[$authorityAssetKey] = [
                'working_revision_id' => $row->working_revision_id === null ? null : (int) $row->working_revision_id,
                'published_revision_id' => $row->published_revision_id === null ? null : (int) $row->published_revision_id,
            ];
            $currentPublicFingerprints[$authorityAssetKey] = $this->runtimeFingerprint($row);
            $plannedSourceHashes[$authorityAssetKey] = $sourceHash;
            if ((bool) $row->is_public && (string) $row->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED) {
                $currentPublicCount++;
            }
            $familyCounts[$data->entityType] = ($familyCounts[$data->entityType] ?? 0) + 1;
        }

        usort($descriptors, static fn (array $left, array $right): int => strcmp(
            (string) data_get($left, 'attributes.canonical_json.path'),
            (string) data_get($right, 'attributes.canonical_json.path'),
        ));
        ksort($familyCounts);
        $expectedCounts = BigFiveZhV3PackageCompiler::FAMILY_COUNTS;
        ksort($expectedCounts);
        if (count($descriptors) !== BigFiveZhV3PackageCompiler::ASSET_COUNT || $familyCounts !== $expectedCounts) {
            throw new RuntimeException('Publisher target inventory is not exactly 1/5/15/1/30.');
        }
        $aliasBoundary = $this->assertRedirectAliasesAbsent($descriptors);

        $existingRevisions = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', self::PACKAGE_FILE_SHA256)
            ->count();
        if (! in_array($existingRevisions, [0, BigFiveZhV3PackageCompiler::ASSET_COUNT], true)) {
            throw new RuntimeException('Partial revision state exists for the locked V3 package.');
        }

        return [
            'release_id' => (string) $package['release_id'],
            'release_package_path' => $resolvedPath,
            'release_package_sha256' => $fileSha,
            'package_payload_sha256' => (string) $package['package_payload_sha256'],
            'source_content_sha256' => (string) $package['source_content_sha256'],
            'descriptors' => $descriptors,
            'family_counts' => $familyCounts,
            'claims_count' => (int) $package['claims_count'],
            'faq_count' => (int) $package['faq_count'],
            'existing_release_revision_count' => $existingRevisions,
            'current_public_count' => $currentPublicCount,
            'current_revision_ids' => $currentRevisionIds,
            'current_public_fingerprints' => $currentPublicFingerprints,
            'planned_source_hashes' => $plannedSourceHashes,
            'alias_boundary' => $aliasBoundary,
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function publicPlan(array $plan, string $mode): array
    {
        return [
            'ok' => true,
            'status' => $mode === 'read_only_preflight'
                ? 'PASS_BIG_FIVE_ZH_V3_52_PAGE_PREFLIGHT'
                : 'PASS_BIG_FIVE_ZH_V3_52_PAGE_PUBLISH',
            'mode' => $mode,
            'release_id' => $plan['release_id'],
            'release_package_path' => $plan['release_package_path'],
            'release_package_sha256' => $plan['release_package_sha256'],
            'compiled_package_sha256' => $plan['release_package_sha256'],
            'package_payload_sha256' => $plan['package_payload_sha256'],
            'source_content_sha256' => $plan['source_content_sha256'],
            'asset_count' => count($plan['descriptors']),
            'family_counts' => $plan['family_counts'],
            'claims_count' => $plan['claims_count'],
            'faq_count' => $plan['faq_count'],
            'operator_admin_user_id' => self::OPERATOR_ADMIN_USER_ID,
            'existing_release_revision_count' => $plan['existing_release_revision_count'],
            'current_public_count' => $plan['current_public_count'],
            'current_revision_ids' => $plan['current_revision_ids'],
            'current_public_fingerprints' => $plan['current_public_fingerprints'],
            'planned_source_hashes' => $plan['planned_source_hashes'],
            'created_revision_count' => 0,
            'reused_revision_count' => $plan['existing_release_revision_count'],
            'media_supported' => false,
            'media_library_write_count' => 0,
            'english_write_count' => 0,
            'non_personality_write_count' => 0,
            'package_drift_count' => 0,
            'canonical_collision_count' => 0,
            'alias_collision_count' => 0,
            'alias_expected_count' => $plan['alias_boundary']['expected_count'],
            'alias_safe_count' => $plan['alias_boundary']['safe_count'],
            'alias_database_count' => $plan['alias_boundary']['database_count'],
            'alias_absent' => $plan['alias_boundary']['database_count'] === 0,
            'alias_descriptor_overlap_count' => $plan['alias_boundary']['descriptor_overlap_count'],
            'alias_boundary_fingerprint_sha256' => $plan['alias_boundary']['fingerprint_sha256'],
            'search_submit_allowed' => false,
            'writes_committed' => $mode !== 'read_only_preflight',
            'errors' => [],
            'warnings' => [],
        ];
    }

    /** @return array{0:array<string,mixed>,1:string,2:string} */
    private function readPackage(string $path): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Compiled release package does not exist: '.$resolved.'.');
        }
        $raw = File::get($resolved);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Compiled release package must be a JSON object.');
        }

        return [$decoded, $resolved, hash('sha256', $raw)];
    }

    /** @param array<string,mixed> $package */
    private function assertPackage(array $package, string $fileSha): void
    {
        if (! hash_equals(self::PACKAGE_FILE_SHA256, $fileSha)) {
            throw new RuntimeException('COMPILED_PACKAGE_DRIFT: release package file SHA-256 mismatch.');
        }
        $expected = [
            'schema_version' => BigFiveZhV3PackageCompiler::SCHEMA_VERSION,
            'release_id' => BigFiveZhV3PackageCompiler::RELEASE_ID,
            'source_content_sha256' => BigFiveZhV3PackageCompiler::SOURCE_CONTENT_SHA256,
            'asset_count' => BigFiveZhV3PackageCompiler::ASSET_COUNT,
            'claims_count' => BigFiveZhV3PackageCompiler::CLAIM_COUNT,
            'faq_count' => BigFiveZhV3PackageCompiler::FAQ_COUNT,
            'locale' => 'zh-CN',
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'org_id' => 0,
            'operator_admin_user_id' => self::OPERATOR_ADMIN_USER_ID,
            'media_supported' => false,
            'search_submit_allowed' => false,
        ];
        foreach ($expected as $field => $value) {
            if (($package[$field] ?? null) !== $value) {
                throw new RuntimeException('Compiled release field '.$field.' is not locked.');
            }
        }
        if (! is_array($package['assets'] ?? null) || count($package['assets']) !== BigFiveZhV3PackageCompiler::ASSET_COUNT) {
            throw new RuntimeException('Compiled release asset inventory must contain exactly 52 pages.');
        }
        $payloadSha = trim((string) ($package['package_payload_sha256'] ?? ''));
        $base = $package;
        unset($base['package_payload_sha256']);
        if (! preg_match('/^[a-f0-9]{64}$/', $payloadSha)
            || ! hash_equals($payloadSha, hash('sha256', $this->stableJson($base)))) {
            throw new RuntimeException('Compiled release payload SHA-256 mismatch.');
        }
    }

    private function assertSchema(): void
    {
        foreach (self::REQUIRED_ASSET_COLUMNS as $column) {
            if (! SchemaBaseline::hasColumn('personality_public_content_assets', $column)) {
                throw new RuntimeException('Required CMS asset column is missing: '.$column.'.');
            }
        }
        foreach (self::REQUIRED_REVISION_COLUMNS as $column) {
            if (! SchemaBaseline::hasColumn('personality_public_content_asset_revisions', $column)) {
                throw new RuntimeException('Required CMS revision column is missing: '.$column.'.');
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $descriptors
     * @return array{expected_count:int,safe_count:int,database_count:int,descriptor_overlap_count:int,fingerprint_sha256:string}
     */
    private function assertRedirectAliasesAbsent(array $descriptors, bool $lockForUpdate = false): array
    {
        $expectedAliases = array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS);
        $expectedPaths = array_keys(BigFiveCanonicalRouteCatalog::reviewedRedirectPaths());

        $descriptorOverlapCount = 0;
        foreach ($descriptors as $descriptor) {
            $entityKey = (string) data_get($descriptor, 'attributes.entity_key', '');
            $slug = (string) data_get($descriptor, 'attributes.slug', '');
            $canonicalPath = (string) data_get($descriptor, 'attributes.canonical_json.path', '');
            if (in_array($entityKey, $expectedAliases, true)
                || in_array($slug, array_map(static fn (string $alias): string => 'big-five/'.$alias, $expectedAliases), true)
                || in_array($canonicalPath, $expectedPaths, true)) {
                $descriptorOverlapCount++;
            }
        }
        if ($descriptorOverlapCount !== 0) {
            throw new RuntimeException('Redirect-only alias is forbidden in the 52-page write plan.');
        }

        $aliasSlugs = array_map(
            static fn (string $alias): string => 'big-five/'.$alias,
            $expectedAliases,
        );
        $query = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where(function ($query) use ($aliasSlugs, $expectedAliases): void {
                $query->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                    ->orWhere(function ($query) use ($expectedAliases): void {
                        $query->where('entity_type', PersonalityPublicContentAsset::ENTITY_POLARITY)
                            ->whereIn('entity_key', $expectedAliases);
                    })
                    ->orWhereIn('slug', $aliasSlugs);
            })
            ->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $aliases = $query->get()->filter(function (PersonalityPublicContentAsset $asset) use ($expectedPaths): bool {
            $keys = [
                (string) $asset->entity_key,
                basename((string) $asset->slug),
                basename((string) data_get($asset->canonical_json, 'path', '')),
                basename((string) data_get($asset->canonical_json, 'redirect_from', '')),
            ];

            return collect($keys)->contains(static fn (string $value): bool => (
                $value === 'emotional-stability'
                || preg_match('/^(?:high|low)-[a-z0-9-]+$/', $value) === 1
            )) || in_array((string) data_get($asset->canonical_json, 'path', ''), $expectedPaths, true);
        })->values();

        if ($aliases->isNotEmpty()) {
            throw new RuntimeException('Legacy redirect alias database records must be physically absent before the 52-page publisher can run.');
        }

        return [
            'expected_count' => 0,
            'safe_count' => 0,
            'database_count' => 0,
            'descriptor_overlap_count' => $descriptorOverlapCount,
            'fingerprint_sha256' => hash('sha256', $this->stableJson([
                'assets' => [],
                'revisions' => [],
            ])),
        ];
    }

    /** @param array<string,mixed> $descriptor */
    private function assertExistingRevision(
        PersonalityPublicContentAssetRevision $revision,
        PersonalityPublicContentAsset $asset,
        array $descriptor,
    ): void {
        if ((int) $revision->asset_id !== (int) $asset->id
            || (string) $revision->source_package !== 'big5-zh-v3-52-page-release'
            || ! hash_equals((string) $revision->source_hash, (string) $descriptor['source_hash'])
            || (string) $revision->workflow_state !== self::WORKFLOW_STATE
            || (int) $revision->created_by_admin_user_id !== self::OPERATOR_ADMIN_USER_ID
            || $this->stableJson((array) $revision->snapshot_json) !== $this->stableJson($descriptor['snapshot'])) {
            throw new RuntimeException('Existing release revision drift for '.$descriptor['authority_asset_key'].'.');
        }
        $this->assertRuntimeReadback($asset, $revision, $descriptor);
    }

    /** @param array<string,mixed> $descriptor */
    private function assertRuntimeReadback(
        PersonalityPublicContentAsset $asset,
        PersonalityPublicContentAssetRevision $revision,
        array $descriptor,
    ): void {
        foreach ($descriptor['attributes'] as $key => $expected) {
            $actual = $asset->getAttribute($key);
            if ($this->stableJson($this->comparable($actual)) !== $this->stableJson($this->comparable($expected))) {
                throw new RuntimeException('Runtime readback mismatch for '.$descriptor['authority_asset_key'].' field '.$key.'.');
            }
        }
        if ((int) $asset->working_revision_id !== (int) $revision->id
            || (int) $asset->published_revision_id !== (int) $revision->id
            || $asset->published_at === null) {
            throw new RuntimeException('Runtime revision pointer readback mismatch for '.$descriptor['authority_asset_key'].'.');
        }
    }

    /** @param list<array<string,mixed>> $descriptors */
    private function assertAllReadback(array $descriptors): void
    {
        foreach ($descriptors as $descriptor) {
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->find($descriptor['asset_id']);
            $revision = PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', self::PACKAGE_FILE_SHA256)
                ->where('authority_asset_key', $descriptor['authority_asset_key'])
                ->first();
            if (! $asset instanceof PersonalityPublicContentAsset || ! $revision instanceof PersonalityPublicContentAssetRevision) {
                throw new RuntimeException('Transaction-wide readback row missing for '.$descriptor['authority_asset_key'].'.');
            }
            $this->assertRuntimeReadback($asset, $revision, $descriptor);
        }
    }

    private function runtimeFingerprint(PersonalityPublicContentAsset $asset): string
    {
        return hash('sha256', $this->stableJson([
            'attributes' => $this->runtimeProjection($asset),
            'working_revision_id' => $asset->working_revision_id,
            'published_revision_id' => $asset->published_revision_id,
            'published_at' => $asset->published_at?->toAtomString(),
        ]));
    }

    /** @return array<string,mixed> */
    private function runtimeProjection(PersonalityPublicContentAsset $asset): array
    {
        return [
            'org_id' => (int) $asset->org_id,
            'framework' => (string) $asset->framework,
            'entity_type' => (string) $asset->entity_type,
            'entity_key' => (string) $asset->entity_key,
            'slug' => (string) $asset->slug,
            'locale' => (string) $asset->locale,
            'title' => (string) $asset->title,
            'summary' => $asset->summary,
            'content_sections_json' => $asset->content_sections_json,
            'seo_json' => $asset->seo_json,
            'robots' => (string) $asset->robots,
            'canonical_json' => $asset->canonical_json,
            'hreflang_json' => $asset->hreflang_json,
            'faq_json' => $asset->faq_json,
            'schema_json' => $asset->schema_json,
            'method_boundary_json' => $asset->method_boundary_json,
            'evidence_notes_json' => $asset->evidence_notes_json,
            'authority_json' => $asset->authority_json,
            'internal_links_json' => $asset->internal_links_json,
            'is_public' => (bool) $asset->is_public,
            'index_eligible' => (bool) $asset->index_eligible,
            'sitemap_eligible' => (bool) $asset->sitemap_eligible,
            'llms_eligible' => (bool) $asset->llms_eligible,
            'launch_state' => (string) $asset->launch_state,
            'review_state' => (string) $asset->review_state,
            'contract_version' => (string) $asset->contract_version,
            'source_package' => $asset->source_package,
            'source_hash' => $asset->source_hash,
            'last_reviewed_at' => $asset->last_reviewed_at?->format('Y-m-d'),
            'created_by_admin_user_id' => $asset->created_by_admin_user_id,
            'updated_by_admin_user_id' => $asset->updated_by_admin_user_id,
        ];
    }

    private function nonTargetBoundaryFingerprint(): string
    {
        $personalityRows = DB::table('personality_public_content_assets')
            ->where(static function ($query): void {
                $query->where('locale', '!=', 'zh-CN')
                    ->orWhere('framework', '!=', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE);
            })
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $nonPersonality = [];
        foreach (['articles', 'topic_profiles', 'landing_surfaces', 'content_pages'] as $table) {
            $nonPersonality[$table] = SchemaBaseline::tableExists($table)
                ? DB::table($table)->orderBy('id')->get()
                    ->map(static fn (object $row): array => (array) $row)->all()
                : [];
        }

        return hash('sha256', $this->stableJson([
            'non_target_personality' => $personalityRows,
            'non_personality_authority' => $nonPersonality,
        ]));
    }

    private function databaseSnapshotFingerprint(): string
    {
        $personalityRows = SchemaBaseline::tableExists('personality_public_content_assets')
            ? DB::table('personality_public_content_assets')->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all()
            : [];
        $revisionRows = SchemaBaseline::tableExists('personality_public_content_asset_revisions')
            ? DB::table('personality_public_content_asset_revisions')->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all()
            : [];

        return hash('sha256', $this->stableJson([
            'personality_public_content_assets' => $personalityRows,
            'personality_public_content_asset_revisions' => $revisionRows,
            'non_target_boundary_sha256' => $this->nonTargetBoundaryFingerprint(),
        ]));
    }

    /** @param list<array<string,mixed>> $descriptors */
    private function flushPublicCaches(array $descriptors): void
    {
        foreach ($descriptors as $descriptor) {
            $attributes = $descriptor['attributes'];
            $assetOk = $this->personalityCache->invalidateAsset(
                (string) $attributes['framework'],
                (string) $attributes['entity_type'],
                (string) $attributes['entity_key'],
                (string) $attributes['slug'],
                (string) $attributes['locale'],
                (int) $attributes['org_id'],
                false,
            );
            $collectionOk = $this->personalityCache->invalidateCollections(
                (string) $attributes['framework'],
                (string) $attributes['entity_type'],
                (string) $attributes['locale'],
                (int) $attributes['org_id'],
                false,
            );
            if (! $assetOk || ! $collectionOk) {
                throw new RuntimeException('Personality public cache invalidation failed.');
            }
        }
        $this->discoverabilityCache->flushPersonalityPublicContentDiscoverabilityCaches();
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }

    private function stableJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return json_encode(
            $normalize($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
