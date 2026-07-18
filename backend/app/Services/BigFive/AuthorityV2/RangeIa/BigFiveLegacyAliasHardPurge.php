<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\RangeIa;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** @review-surface personality_public_content_asset */
final class BigFiveLegacyAliasHardPurge
{
    public const SCHEMA_VERSION = 'big-five-legacy-alias-hard-purge.v1';

    public const BACKUP_SCHEMA_VERSION = 'big-five-legacy-alias-backup.v1';

    public const EN_RETIRED_REVIEW_STATE = 'legacy_redirect_retired';

    private const EXPECTED_ALIAS_COUNT = 20;

    private const EXPECTED_CANONICAL_COUNT_PER_LOCALE = 52;

    /** @return array<string,mixed> */
    public function run(
        bool $execute,
        int $operatorAdminUserId,
        string $backupManifestPath = '',
        string $backupManifestSha256 = '',
    ): array {
        $this->assertSchema();
        $inspection = $this->inspect(lockForUpdate: false);

        if ($inspection['alias_count'] === 0) {
            return $this->summary($inspection, 'PASS_ALREADY_PURGED', false, true);
        }
        if (! $execute) {
            return $this->summary($inspection, 'READY_TO_PURGE', false, false);
        }
        if ($operatorAdminUserId !== 1) {
            throw new RuntimeException('Legacy alias hard purge is locked to operator admin_user:1.');
        }

        return DB::transaction(function () use (
            $operatorAdminUserId,
            $backupManifestPath,
            $backupManifestSha256,
            $inspection,
        ): array {
            $locked = $this->inspect(lockForUpdate: true);
            if (! hash_equals($inspection['alias_fingerprint_sha256'], $locked['alias_fingerprint_sha256'])) {
                throw new RuntimeException('Legacy alias boundary drifted between preflight and execute lock.');
            }

            $this->assertBackupManifest(
                $locked,
                $operatorAdminUserId,
                $backupManifestPath,
                $backupManifestSha256,
            );

            $aliasIds = $locked['alias_ids'];
            $canonicalBefore = $this->canonicalFingerprint();
            $nonTargetBefore = $this->nonTargetPersonalityFingerprint($aliasIds);
            $deletedAssets = DB::table('personality_public_content_assets')
                ->whereIn('id', $aliasIds)
                ->delete();
            if ($deletedAssets !== self::EXPECTED_ALIAS_COUNT) {
                throw new RuntimeException('Hard purge did not delete the exact twenty locked alias rows.');
            }

            $after = $this->inspect(lockForUpdate: true);
            if ($after['alias_count'] !== 0
                || $after['alias_revision_count'] !== 0
                || $after['alias_revision_review_count'] !== 0) {
                throw new RuntimeException('Legacy alias rows or cascaded revision evidence remain after hard purge.');
            }
            if (! hash_equals($canonicalBefore, $this->canonicalFingerprint())) {
                throw new RuntimeException('Canonical Big Five rows changed during legacy alias hard purge.');
            }
            if (! hash_equals($nonTargetBefore, $this->nonTargetPersonalityFingerprint($aliasIds))) {
                throw new RuntimeException('Non-target Personality rows changed during legacy alias hard purge.');
            }

            return $this->summary(
                $after,
                'PASS_PURGED',
                true,
                false,
                [
                    'deleted_asset_count' => self::EXPECTED_ALIAS_COUNT,
                    'deleted_revision_count' => $locked['alias_revision_count'],
                    'deleted_revision_review_count' => $locked['alias_revision_review_count'],
                    'operator_admin_user_id' => $operatorAdminUserId,
                    'backup_manifest_sha256' => strtolower($backupManifestSha256),
                    'backup_manifest_verified' => true,
                    'verified_backup_tables' => $locked['backup_tables'],
                ],
            );
        }, 1);
    }

    /**
     * @return array{
     *   rows:Collection<int,PersonalityPublicContentAsset>,alias_ids:list<int>,alias_count:int,
     *   alias_revision_count:int,alias_revision_review_count:int,alias_attestation_target_count:int,
     *   zh_canonical_count:int,en_canonical_count:int,en_alias_state:string,
     *   alias_fingerprint_sha256:string,backup_tables:array<string,array{row_count:int,checksum_sha256:string}>
     * }
     */
    private function inspect(bool $lockForUpdate): array
    {
        $aliasSlugs = array_map(
            static fn (string $alias): string => 'big-five/'.$alias,
            array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS),
        );
        $query = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where(function ($query) use ($aliasSlugs): void {
                $query->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                    ->orWhere(function ($query): void {
                        $query->where('entity_type', PersonalityPublicContentAsset::ENTITY_POLARITY)
                            ->whereIn('entity_key', array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS));
                    })
                    ->orWhereIn('slug', $aliasSlugs);
            })
            ->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $allBigFiveRows = $query->get();
        $aliases = $allBigFiveRows->filter(fn (PersonalityPublicContentAsset $row): bool => $this->isAliasShaped($row))->values();

        $this->assertCanonicalBoundary($allBigFiveRows, $aliases);
        if ($aliases->isNotEmpty()) {
            $this->assertExactAliasInventory($aliases);
        }

        $aliasIds = $aliases->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $assetRows = $this->rows('personality_public_content_assets', 'id', $aliasIds);
        $revisionRows = $this->rows('personality_public_content_asset_revisions', 'asset_id', $aliasIds);
        $revisionIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $revisionRows));
        $reviewRows = $this->rows('personality_public_content_asset_revision_reviews', 'asset_id', $aliasIds);
        $attestationTargetCount = $this->aliasAttestationTargetCount($aliases, $revisionRows);
        if ($attestationTargetCount !== 0) {
            throw new RuntimeException('Legacy aliases are referenced by review attestation target evidence.');
        }

        $backupTables = [
            'personality_public_content_assets' => $this->tableEvidence($assetRows),
            'personality_public_content_asset_revisions' => $this->tableEvidence($revisionRows),
            'personality_public_content_asset_revision_reviews' => $this->tableEvidence($reviewRows),
        ];

        return [
            'rows' => $aliases,
            'alias_ids' => $aliasIds,
            'alias_count' => count($assetRows),
            'alias_revision_count' => count($revisionRows),
            'alias_revision_review_count' => count($reviewRows),
            'alias_attestation_target_count' => $attestationTargetCount,
            'zh_canonical_count' => self::EXPECTED_CANONICAL_COUNT_PER_LOCALE,
            'en_canonical_count' => self::EXPECTED_CANONICAL_COUNT_PER_LOCALE,
            'en_alias_state' => $this->englishAliasState($aliases),
            'alias_fingerprint_sha256' => $this->fingerprint([
                'assets' => $assetRows,
                'revisions' => $revisionRows,
                'revision_reviews' => $reviewRows,
                'revision_ids' => $revisionIds,
            ]),
            'backup_tables' => $backupTables,
        ];
    }

    /** @param Collection<int,PersonalityPublicContentAsset> $allRows @param Collection<int,PersonalityPublicContentAsset> $aliases */
    private function assertCanonicalBoundary(Collection $allRows, Collection $aliases): void
    {
        $aliasIds = $aliases->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        foreach (['en', 'zh-CN'] as $locale) {
            $rows = $allRows
                ->where('org_id', 0)
                ->where('locale', $locale)
                ->reject(static fn (PersonalityPublicContentAsset $row): bool => in_array((int) $row->id, $aliasIds, true))
                ->values();
            $expected = collect(BigFiveCanonicalRouteCatalog::canonicalEntries($locale))
                ->keyBy(static fn (array $entry): string => $entry['entity_type'].':'.$entry['entity_key']);
            if ($rows->count() !== self::EXPECTED_CANONICAL_COUNT_PER_LOCALE
                || $expected->count() !== self::EXPECTED_CANONICAL_COUNT_PER_LOCALE) {
                throw new RuntimeException($locale.' canonical Big Five boundary must contain exactly 52 rows.');
            }
            $actualIdentities = [];
            foreach ($rows as $row) {
                $identity = (string) $row->entity_type.':'.(string) $row->entity_key;
                $actualIdentities[] = $identity;
                $entry = $expected->get($identity);
                if (! is_array($entry)
                    || (string) data_get($row->canonical_json, 'path', '') !== (string) $entry['path']) {
                    throw new RuntimeException('Unexpected '.$locale.' canonical Big Five identity or path: '.$identity.'.');
                }
            }
            sort($actualIdentities);
            $expectedIdentities = $expected->keys()->sort()->values()->all();
            if ($actualIdentities !== $expectedIdentities) {
                throw new RuntimeException($locale.' canonical Big Five identities are missing or duplicated.');
            }
        }
    }

    /** @param Collection<int,PersonalityPublicContentAsset> $aliases */
    private function assertExactAliasInventory(Collection $aliases): void
    {
        if ($aliases->count() !== self::EXPECTED_ALIAS_COUNT) {
            throw new RuntimeException('Legacy alias inventory must be either zero or exactly twenty rows.');
        }

        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $segment) {
            foreach (array_keys(BigFiveCanonicalRouteCatalog::redirectOnlyAliasTargets($locale)) as $alias) {
                $matches = $aliases->filter(static fn (PersonalityPublicContentAsset $row): bool => (
                    (string) $row->locale === $locale && (string) $row->entity_key === $alias
                ));
                if ($matches->count() !== 1) {
                    throw new RuntimeException('Legacy alias identity is missing, duplicated, or unknown for '.$locale.':'.$alias.'.');
                }
                $row = $matches->first();
                if (! $row instanceof PersonalityPublicContentAsset
                    || (int) $row->org_id !== 0
                    || (string) $row->framework !== PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE
                    || (string) $row->entity_type !== PersonalityPublicContentAsset::ENTITY_POLARITY
                    || (string) $row->slug !== 'big-five/'.$alias) {
                    throw new RuntimeException('Legacy alias has unsafe org, type, or slug for '.$locale.':'.$alias.'.');
                }
                if ($locale === 'zh-CN') {
                    $this->assertChineseState($row, $alias);
                }
            }
        }

        $english = $aliases->where('locale', 'en')->values();
        $active = $english->filter(fn (PersonalityPublicContentAsset $row): bool => $this->isEnglishActive($row))->count();
        $retired = $english->filter(fn (PersonalityPublicContentAsset $row): bool => $this->isEnglishRetired($row))->count();
        if (! (($active === 10 && $retired === 0) || ($active === 0 && $retired === 10))) {
            throw new RuntimeException('English legacy aliases must be one complete active or archived cohort; mixed state is forbidden.');
        }
    }

    private function assertChineseState(PersonalityPublicContentAsset $row, string $alias): void
    {
        if ((string) data_get($row->canonical_json, 'path', '') !== '/zh/personality/big-five/'.$alias
            || (string) $row->launch_state !== PersonalityPublicContentAsset::LAUNCH_CONTENT_READY
            || ! (bool) $row->is_public
            || (bool) $row->index_eligible
            || (bool) $row->sitemap_eligible
            || (bool) $row->llms_eligible
            || (string) $row->robots !== PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW) {
            throw new RuntimeException('Chinese legacy alias is not in the locked content_ready/public/noindex state: '.$alias.'.');
        }
    }

    private function isEnglishActive(PersonalityPublicContentAsset $row): bool
    {
        $alias = (string) $row->entity_key;

        return isset(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS[$alias])
            && (string) data_get($row->canonical_json, 'path', '') === '/en/personality/big-five/'.$alias
            && (string) $row->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && (bool) $row->is_public
            && (bool) $row->index_eligible
            && (bool) $row->sitemap_eligible
            && (bool) $row->llms_eligible
            && (string) $row->robots === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW;
    }

    private function isEnglishRetired(PersonalityPublicContentAsset $row): bool
    {
        $alias = (string) $row->entity_key;
        $target = BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS[$alias] ?? null;

        return is_string($target)
            && (string) data_get($row->canonical_json, 'path', '') === '/en/personality/big-five/'.$target
            && (string) data_get($row->canonical_json, 'redirect_from', '') === '/en/personality/big-five/'.$alias
            && (int) data_get($row->canonical_json, 'redirect_status', 0) === 301
            && (array) $row->hreflang_json === []
            && (string) $row->launch_state === PersonalityPublicContentAsset::LAUNCH_ARCHIVED
            && (string) $row->review_state === self::EN_RETIRED_REVIEW_STATE
            && ! (bool) $row->is_public
            && ! (bool) $row->index_eligible
            && ! (bool) $row->sitemap_eligible
            && ! (bool) $row->llms_eligible
            && (string) $row->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW;
    }

    private function isAliasShaped(PersonalityPublicContentAsset $row): bool
    {
        $values = [
            (string) $row->entity_key,
            basename((string) $row->slug),
            basename((string) data_get($row->canonical_json, 'path', '')),
            basename((string) data_get($row->canonical_json, 'redirect_from', '')),
        ];

        return collect($values)->contains(static fn (string $value): bool => (
            $value === 'emotional-stability'
            || preg_match('/^(?:high|low)-[a-z0-9-]+$/', $value) === 1
        ));
    }

    /** @param Collection<int,PersonalityPublicContentAsset> $aliases @param list<array<string,mixed>> $revisionRows */
    private function aliasAttestationTargetCount(Collection $aliases, array $revisionRows): int
    {
        if (! Schema::hasTable('review_attestation_target_evidences')) {
            return 0;
        }
        $tokens = [];
        foreach (array_keys(BigFiveCanonicalRouteCatalog::reviewedRedirectPaths()) as $aliasPath) {
            $tokens[] = $aliasPath;
            $locale = str_starts_with($aliasPath, '/zh/') ? 'zh-CN' : 'en';
            $tokens[] = 'legacy-alias:'.$locale.':'.basename($aliasPath);
        }
        foreach ($aliases as $row) {
            $tokens[] = 'personality_public_content_asset:'.(int) $row->id.':'.(string) $row->locale.':'.(string) $row->slug;
            $segment = (string) $row->locale === 'zh-CN' ? 'zh' : 'en';
            $tokens[] = '/'.$segment.'/personality/big-five/'.(string) $row->entity_key;
        }
        foreach ($revisionRows as $row) {
            $tokens[] = (string) ($row['authority_asset_key'] ?? '');
        }
        $tokens = array_values(array_unique(array_filter($tokens)));

        return DB::table('review_attestation_target_evidences')
            ->pluck('target_identity')
            ->filter(static function (mixed $identity) use ($tokens): bool {
                $identity = (string) $identity;

                return collect($tokens)->contains(
                    static fn (string $token): bool => $token !== '' && str_contains($identity, $token),
                );
            })
            ->count();
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function rows(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)->whereIn($column, $ids)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @param list<array<string,mixed>> $rows @return array{row_count:int,checksum_sha256:string} */
    private function tableEvidence(array $rows): array
    {
        return [
            'row_count' => count($rows),
            'checksum_sha256' => $this->fingerprint($rows),
        ];
    }

    /** @param array<string,mixed> $inspection */
    private function assertBackupManifest(
        array $inspection,
        int $operatorAdminUserId,
        string $path,
        string $expectedSha256,
    ): void {
        if ($path === '' || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw new RuntimeException('Execute mode requires --backup-manifest and a lowercase 64-character --backup-sha256.');
        }
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Verified backup manifest file does not exist.');
        }
        $raw = File::get($resolved);
        if (! hash_equals($expectedSha256, hash('sha256', $raw))) {
            throw new RuntimeException('Backup manifest SHA-256 does not match the exact supplied file.');
        }
        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== self::BACKUP_SCHEMA_VERSION
            || (int) ($manifest['operator_admin_user_id'] ?? 0) !== $operatorAdminUserId
            || preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['backup_artifact_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('Backup manifest identity, operator, or artifact SHA-256 is invalid.');
        }
        try {
            $createdAt = CarbonImmutable::parse((string) ($manifest['created_at'] ?? ''));
        } catch (\Throwable $throwable) {
            throw new RuntimeException('Backup manifest created_at must be a valid timestamp.', 0, $throwable);
        }
        if ($createdAt->utc()->format('Y-m-d\TH:i:s\Z') !== (string) $manifest['created_at']) {
            throw new RuntimeException('Backup manifest created_at must be a normalized UTC timestamp.');
        }

        $tables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];
        if (array_values(array_diff(array_keys($tables), array_keys($inspection['backup_tables']))) !== []
            || array_values(array_diff(array_keys($inspection['backup_tables']), array_keys($tables))) !== []) {
            throw new RuntimeException('Backup manifest table inventory does not match the locked live alias cohort.');
        }
        foreach ($inspection['backup_tables'] as $table => $expected) {
            if ((int) data_get($tables, $table.'.row_count', -1) !== $expected['row_count']
                || ! hash_equals(
                    $expected['checksum_sha256'],
                    (string) data_get($tables, $table.'.checksum_sha256', ''),
                )) {
                throw new RuntimeException('Backup manifest row counts or checksums do not match the locked live alias cohort.');
            }
        }
    }

    private function canonicalFingerprint(): string
    {
        $identities = collect(['en', 'zh-CN'])->flatMap(static fn (string $locale): array => array_map(
            static fn (array $entry): string => $locale.':'.$entry['entity_type'].':'.$entry['entity_key'],
            BigFiveCanonicalRouteCatalog::canonicalEntries($locale),
        ))->all();
        $assetRows = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->whereIn('locale', ['en', 'zh-CN'])->orderBy('id')->get()
            ->filter(static fn (PersonalityPublicContentAsset $row): bool => in_array(
                (string) $row->locale.':'.(string) $row->entity_type.':'.(string) $row->entity_key,
                $identities,
                true,
            ));
        $ids = $assetRows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return $this->fingerprint([
            'assets' => $this->rows('personality_public_content_assets', 'id', $ids),
            'revisions' => $this->rows('personality_public_content_asset_revisions', 'asset_id', $ids),
            'revision_reviews' => $this->rows('personality_public_content_asset_revision_reviews', 'asset_id', $ids),
        ]);
    }

    /** @param list<int> $excludedAssetIds */
    private function nonTargetPersonalityFingerprint(array $excludedAssetIds): string
    {
        $query = DB::table('personality_public_content_assets')->orderBy('id');
        if ($excludedAssetIds !== []) {
            $query->whereNotIn('id', $excludedAssetIds);
        }
        $assets = $query->get()->map(static fn (object $row): array => (array) $row)->all();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $assets);

        return $this->fingerprint([
            'assets' => $assets,
            'revisions' => $this->rows('personality_public_content_asset_revisions', 'asset_id', $ids),
            'revision_reviews' => $this->rows('personality_public_content_asset_revision_reviews', 'asset_id', $ids),
        ]);
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(
            $this->sortRecursively($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }

    /** @param Collection<int,PersonalityPublicContentAsset> $aliases */
    private function englishAliasState(Collection $aliases): string
    {
        if ($aliases->isEmpty()) {
            return 'absent';
        }

        return $aliases->where('locale', 'en')->every(fn (PersonalityPublicContentAsset $row): bool => $this->isEnglishRetired($row))
            ? 'archived'
            : 'active';
    }

    /** @param array<string,mixed> $inspection @param array<string,mixed> $extra @return array<string,mixed> */
    private function summary(
        array $inspection,
        string $status,
        bool $writesCommitted,
        bool $idempotentNoop,
        array $extra = [],
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'alias_expected_count' => self::EXPECTED_ALIAS_COUNT,
            'legacy_alias_asset_count' => $inspection['alias_count'],
            'legacy_alias_revision_count' => $inspection['alias_revision_count'],
            'legacy_alias_revision_review_count' => $inspection['alias_revision_review_count'],
            'legacy_alias_attestation_target_count' => $inspection['alias_attestation_target_count'],
            'zh_canonical_count' => $inspection['zh_canonical_count'],
            'en_canonical_count' => $inspection['en_canonical_count'],
            'database_big_five_asset_count' => $inspection['zh_canonical_count'] + $inspection['en_canonical_count'] + $inspection['alias_count'],
            'en_alias_state' => $inspection['en_alias_state'],
            'alias_fingerprint_sha256' => $inspection['alias_fingerprint_sha256'],
            'backup_tables' => $inspection['backup_tables'],
            'backup_required' => $status === 'READY_TO_PURGE',
            'deleted_asset_count' => 0,
            'deleted_revision_count' => 0,
            'deleted_revision_review_count' => 0,
            'canonical_rows_changed' => 0,
            'non_target_personality_rows_changed' => 0,
            'media_library_write_count' => 0,
            'search_submission_write_count' => 0,
            'writes_committed' => $writesCommitted,
            'idempotent_noop' => $idempotentNoop,
            'errors' => [],
            ...$extra,
        ];
    }

    private function assertSchema(): void
    {
        foreach ([
            'personality_public_content_assets',
            'personality_public_content_asset_revisions',
            'personality_public_content_asset_revision_reviews',
            'review_attestation_target_evidences',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Required table is missing: '.$table.'.');
            }
        }
    }
}
