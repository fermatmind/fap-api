<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\RangeIa;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** @review-surface personality_public_content_asset */
final class BigFiveEnLegacyRangeRetirement
{
    public const REVIEW_STATE = 'legacy_redirect_retired';

    public const EXPECTED_CANONICAL_COUNT = 52;

    /**
     * @return array<string,mixed>
     */
    public function run(bool $execute, int $operatorAdminUserId): array
    {
        $this->assertSchema();
        if ($execute && $operatorAdminUserId < 1) {
            throw new RuntimeException('A positive operator admin user id is required for execute mode.');
        }

        $before = $this->inspect(lockForUpdate: false);
        if (! $execute) {
            return $this->summary($before, false, 0, true);
        }

        return DB::transaction(function () use ($operatorAdminUserId): array {
            $locked = $this->inspect(lockForUpdate: true);
            $nonTargetFingerprint = $this->nonTargetFingerprint((array) $locked['target_ids']);
            $revisionFingerprint = $this->revisionFingerprint((array) $locked['target_ids']);
            $updatedCount = 0;

            foreach ($locked['rows'] as $row) {
                if (! $row instanceof PersonalityPublicContentAsset || $this->isRetired($row)) {
                    continue;
                }

                $alias = (string) $row->entity_key;
                $target = BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS[$alias];
                $sourcePath = '/en/personality/big-five/'.$alias;
                $targetPath = '/en/personality/big-five/'.$target;

                DB::table('personality_public_content_assets')
                    ->where('id', (int) $row->id)
                    ->update([
                        'canonical_json' => json_encode([
                            'path' => $targetPath,
                            'redirect_from' => $sourcePath,
                            'redirect_status' => 301,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'hreflang_json' => json_encode([], JSON_THROW_ON_ERROR),
                        'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
                        'is_public' => false,
                        'index_eligible' => false,
                        'sitemap_eligible' => false,
                        'llms_eligible' => false,
                        'launch_state' => PersonalityPublicContentAsset::LAUNCH_ARCHIVED,
                        'review_state' => self::REVIEW_STATE,
                        'updated_by_admin_user_id' => $operatorAdminUserId,
                        'updated_at' => now(),
                    ]);
                $updatedCount++;
            }

            $after = $this->inspect(lockForUpdate: true);
            $nonTargetUnchanged = hash_equals(
                $nonTargetFingerprint,
                $this->nonTargetFingerprint((array) $after['target_ids']),
            );
            $revisionsUnchanged = hash_equals(
                $revisionFingerprint,
                $this->revisionFingerprint((array) $after['target_ids']),
            );
            if (! $nonTargetUnchanged || ! $revisionsUnchanged) {
                throw new RuntimeException('Legacy range retirement crossed its exact ten-row authority boundary.');
            }

            return $this->summary($after, true, $updatedCount, $nonTargetUnchanged && $revisionsUnchanged);
        });
    }

    /**
     * @return array{rows:Collection<int,PersonalityPublicContentAsset>,target_ids:list<int>,active_count:int,retired_count:int,canonical_count:int,target_fingerprint_sha256:string}
     */
    private function inspect(bool $lockForUpdate): array
    {
        $query = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('locale', 'en')
            ->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $allEnglishRows = $query->get();
        $aliasShapedRows = $allEnglishRows
            ->filter(fn (PersonalityPublicContentAsset $row): bool => $this->isAliasShaped($row))
            ->values();

        $expectedAliases = array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS);
        if ($aliasShapedRows->count() !== count($expectedAliases)) {
            throw new RuntimeException('English legacy range inventory must contain exactly 10 rows.');
        }

        foreach ($expectedAliases as $alias) {
            $matches = $aliasShapedRows->filter(
                static fn (PersonalityPublicContentAsset $row): bool => (string) $row->entity_key === $alias,
            );
            if ($matches->count() !== 1) {
                throw new RuntimeException('English legacy range identity is missing, duplicated, or unknown: '.$alias.'.');
            }
            $this->assertAliasState($matches->first(), $alias);
        }

        $canonicalRows = $allEnglishRows->reject(
            static fn (PersonalityPublicContentAsset $row): bool => in_array(
                (string) $row->entity_key,
                $expectedAliases,
                true,
            ),
        );
        $this->assertCanonicalBoundary($canonicalRows);

        $targetIds = $aliasShapedRows
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        return [
            'rows' => $aliasShapedRows,
            'target_ids' => $targetIds,
            'active_count' => $aliasShapedRows->filter(fn (PersonalityPublicContentAsset $row): bool => $this->isActive($row))->count(),
            'retired_count' => $aliasShapedRows->filter(fn (PersonalityPublicContentAsset $row): bool => $this->isRetired($row))->count(),
            'canonical_count' => $canonicalRows->count(),
            'target_fingerprint_sha256' => $this->targetFingerprint($targetIds),
        ];
    }

    private function assertAliasState(mixed $row, string $alias): void
    {
        if (! $row instanceof PersonalityPublicContentAsset
            || (string) $row->entity_type !== PersonalityPublicContentAsset::ENTITY_POLARITY
            || (string) $row->slug !== 'big-five/'.$alias
            || (! $this->isActive($row) && ! $this->isRetired($row))) {
            throw new RuntimeException('English legacy range row has unsafe identity or lifecycle state: '.$alias.'.');
        }
    }

    private function isActive(PersonalityPublicContentAsset $row): bool
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

    private function isRetired(PersonalityPublicContentAsset $row): bool
    {
        $alias = (string) $row->entity_key;
        $target = BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS[$alias] ?? null;

        return is_string($target)
            && (string) data_get($row->canonical_json, 'path', '') === '/en/personality/big-five/'.$target
            && (string) data_get($row->canonical_json, 'redirect_from', '') === '/en/personality/big-five/'.$alias
            && (int) data_get($row->canonical_json, 'redirect_status', 0) === 301
            && (array) $row->hreflang_json === []
            && (string) $row->launch_state === PersonalityPublicContentAsset::LAUNCH_ARCHIVED
            && (string) $row->review_state === self::REVIEW_STATE
            && ! (bool) $row->is_public
            && ! (bool) $row->index_eligible
            && ! (bool) $row->sitemap_eligible
            && ! (bool) $row->llms_eligible
            && (string) $row->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW;
    }

    /** @param Collection<int,PersonalityPublicContentAsset> $rows */
    private function assertCanonicalBoundary(Collection $rows): void
    {
        $expected = collect(BigFiveCanonicalRouteCatalog::canonicalEntries('en'))
            ->keyBy(static fn (array $entry): string => $entry['entity_type'].':'.$entry['entity_key']);
        if ($rows->count() !== self::EXPECTED_CANONICAL_COUNT || $expected->count() !== self::EXPECTED_CANONICAL_COUNT) {
            throw new RuntimeException('English canonical Big Five boundary must contain exactly 52 rows.');
        }

        foreach ($rows as $row) {
            $identity = (string) $row->entity_type.':'.(string) $row->entity_key;
            $entry = $expected->get($identity);
            if (! is_array($entry)
                || (string) data_get($row->canonical_json, 'path', '') !== $entry['path']) {
                throw new RuntimeException('Unexpected English canonical Big Five identity or path: '.$identity.'.');
            }
        }
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

    /** @param list<int> $targetIds */
    private function targetFingerprint(array $targetIds): string
    {
        return $this->fingerprint([
            'assets' => DB::table('personality_public_content_assets')->whereIn('id', $targetIds)->orderBy('id')->get(),
            'revisions' => DB::table('personality_public_content_asset_revisions')->whereIn('asset_id', $targetIds)->orderBy('id')->get(),
        ]);
    }

    /** @param list<int> $targetIds */
    private function nonTargetFingerprint(array $targetIds): string
    {
        return $this->fingerprint([
            'assets' => DB::table('personality_public_content_assets')->whereNotIn('id', $targetIds)->orderBy('id')->get(),
        ]);
    }

    /** @param list<int> $targetIds */
    private function revisionFingerprint(array $targetIds): string
    {
        return $this->fingerprint([
            'revisions' => DB::table('personality_public_content_asset_revisions')->whereIn('asset_id', $targetIds)->orderBy('id')->get(),
        ]);
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{rows:Collection<int,PersonalityPublicContentAsset>,target_ids:list<int>,active_count:int,retired_count:int,canonical_count:int,target_fingerprint_sha256:string}  $inspection
     * @return array<string,mixed>
     */
    private function summary(array $inspection, bool $executed, int $updatedCount, bool $boundaryUnchanged): array
    {
        return [
            'schema_version' => 'big-five-en-legacy-range-retirement.v1',
            'status' => $inspection['retired_count'] === 10 ? 'PASS_RETIRED' : 'READY_TO_RETIRE',
            'locale' => 'en',
            'alias_expected_count' => 10,
            'alias_active_count' => $inspection['active_count'],
            'alias_retired_count' => $inspection['retired_count'],
            'canonical_preserved_count' => $inspection['canonical_count'],
            'updated_count' => $updatedCount,
            'writes_committed' => $executed && $updatedCount > 0,
            'idempotent_noop' => $executed && $updatedCount === 0,
            'non_target_boundary_unchanged' => $boundaryUnchanged,
            'target_fingerprint_sha256' => $inspection['target_fingerprint_sha256'],
            'english_canonical_write_count' => 0,
            'chinese_write_count' => 0,
            'revision_write_count' => 0,
            'media_library_write_count' => 0,
            'search_submission_write_count' => 0,
            'errors' => [],
        ];
    }

    private function assertSchema(): void
    {
        foreach (['personality_public_content_assets', 'personality_public_content_asset_revisions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Required table is missing: '.$table.'.');
            }
        }
    }
}
