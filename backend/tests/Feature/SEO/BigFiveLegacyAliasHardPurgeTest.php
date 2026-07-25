<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Console\Commands\PersonalityBigFiveLegacyAliasesPurge;
use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\BigFive\AuthorityV2\RangeIa\BigFiveLegacyAliasHardPurge;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Services\SEO\SitemapCache;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class BigFiveLegacyAliasHardPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://frontend.test/api/content-release/revalidate');
        config()->set('ops.content_release_observability.hmac_revalidation_secret', str_repeat('s', 32));
        Http::fake([
            'https://frontend.test/api/content-release/revalidate' => Http::response([
                'ok' => true,
                'revalidated_paths' => ['/llms.txt', '/llms-full.txt'],
                'rejected_paths' => [],
            ]),
        ]);
    }

    public function test_preflight_finds_exact_twenty_aliases_without_writes(): void
    {
        $this->seedBoundary();
        $before = $this->databaseFingerprint();

        $summary = app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);

        self::assertSame('READY_TO_PURGE', $summary['status']);
        self::assertSame(20, $summary['legacy_alias_asset_count']);
        self::assertSame(52, $summary['zh_canonical_count']);
        self::assertSame(52, $summary['en_canonical_count']);
        self::assertSame('active', $summary['en_alias_state']);
        self::assertFalse($summary['writes_committed']);
        self::assertSame($before, $this->databaseFingerprint());
    }

    public function test_preflight_is_idempotent_after_aliases_are_absent(): void
    {
        $this->seedBoundary(includeAliases: false);

        $summary = app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);

        self::assertSame('PASS_ALREADY_PURGED', $summary['status']);
        self::assertSame(104, $summary['database_big_five_asset_count']);
        self::assertSame(0, $summary['legacy_alias_asset_count']);
        self::assertTrue($summary['idempotent_noop']);
    }

    public function test_preflight_rejects_partial_unknown_and_mixed_alias_inventory(): void
    {
        $this->seedBoundary();
        PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('entity_key', 'high-openness')->delete();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('either zero or exactly twenty');
        app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);
    }

    public function test_preflight_rejects_extra_unknown_alias_shaped_row(): void
    {
        $this->seedBoundary();
        $this->seedAsset(
            'en',
            PersonalityPublicContentAsset::ENTITY_POLARITY,
            'low-curiosity',
            'big-five/low-curiosity',
            '/en/personality/big-five/low-curiosity',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('either zero or exactly twenty');
        app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);
    }

    public function test_preflight_rejects_wrong_identity_and_mixed_english_lifecycle(): void
    {
        $this->seedBoundary();
        $wrong = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'zh-CN')->where('entity_key', 'high-openness')->sole();
        $wrong->forceFill(['slug' => 'big-five/high-openness-wrong'])->save();

        try {
            app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);
            self::fail('Wrong alias identity must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('unsafe org, type, or slug', $exception->getMessage());
        }

        $wrong->forceFill(['slug' => 'big-five/high-openness'])->save();
        $english = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('entity_key', 'high-openness')->sole();
        $this->retireEnglishAlias($english);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mixed state is forbidden');
        app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);
    }

    public function test_preflight_accepts_complete_archived_english_cohort(): void
    {
        $this->seedBoundary();
        PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('locale', 'en')->get()
            ->filter(fn (PersonalityPublicContentAsset $row): bool => isset(
                BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS[(string) $row->entity_key],
            ))
            ->each(fn (PersonalityPublicContentAsset $row) => $this->retireEnglishAlias($row));

        $summary = app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);

        self::assertSame('READY_TO_PURGE', $summary['status']);
        self::assertSame('archived', $summary['en_alias_state']);
    }

    public function test_preflight_rejects_alias_review_attestation_target(): void
    {
        [$alias] = $this->seedBoundary();
        $attestationId = DB::table('review_attestations')->insertGetId([
            'schema_version' => 'review-attestation.v1',
            'review_mode' => 'solo_owner',
            'review_source' => 'operator',
            'scope_type' => 'personality_public_content_asset',
            'scope_identity' => 'big-five-legacy-aliases',
            'decision' => 'approved_all',
            'target_count' => 1,
            'target_set_sha256' => str_repeat('a', 64),
            'package_sha256' => null,
            'exceptions_json' => '[]',
            'statement_version' => 'v1',
            'attested_by_admin_user_id' => 1,
            'attested_at' => now(),
            'evidence_sha256' => str_repeat('b', 64),
            'canonical_evidence_json' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('review_attestation_target_evidences')->insert([
            'review_attestation_id' => $attestationId,
            'target_identity' => 'personality_public_content_asset:'.$alias->id.':'.$alias->locale.':'.$alias->slug,
            'target_sha256' => str_repeat('c', 64),
            'target_decision' => 'approved',
            'exception_json' => null,
            'evidence_sha256' => str_repeat('d', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('review attestation target evidence');
        app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);
    }

    public function test_execute_requires_verified_matching_backup_manifest(): void
    {
        $this->seedBoundary();

        try {
            app(BigFiveLegacyAliasHardPurge::class)->run(true, 1);
            self::fail('Execute without backup must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('requires --backup-manifest', $exception->getMessage());
        }

        [$path, $sha] = $this->writeBackupManifest();
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['tables']['personality_public_content_assets']['row_count'] = 19;
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        $sha = hash_file('sha256', $path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('row counts or checksums');
        app(BigFiveLegacyAliasHardPurge::class)->run(true, 1, $path, (string) $sha);
    }

    public function test_execute_physically_deletes_only_aliases_and_cascades_revisions_and_reviews(): void
    {
        [$firstAlias, $sentinel] = $this->seedBoundary();
        $revision = $this->seedRevision($firstAlias);
        $this->seedRevisionReview($firstAlias, $revision);
        $canonicalBefore = $this->canonicalFingerprint();
        $sentinelBefore = DB::table('personality_public_content_assets')->where('id', $sentinel->id)->first();
        [$path, $sha] = $this->writeBackupManifest();

        $summary = app(BigFiveLegacyAliasHardPurge::class)->run(true, 1, $path, $sha);

        self::assertSame('PASS_PURGED', $summary['status']);
        self::assertSame(20, $summary['deleted_asset_count']);
        self::assertSame(1, $summary['deleted_revision_count']);
        self::assertSame(1, $summary['deleted_revision_review_count']);
        self::assertTrue($summary['writes_committed']);
        self::assertTrue($summary['backup_manifest_verified']);
        self::assertSame(20, $summary['verified_backup_tables']['personality_public_content_assets']['row_count']);
        self::assertSame(104, $summary['database_big_five_asset_count']);
        self::assertSame(0, $summary['canonical_rows_changed']);
        self::assertSame(0, $summary['non_target_personality_rows_changed']);
        self::assertSame(0, $summary['media_library_write_count']);
        self::assertSame(0, $summary['search_submission_write_count']);
        self::assertTrue($summary['cache_closeout_ok']);
        self::assertSame('PASS_CACHE_CLOSEOUT', $summary['cache_closeout_status']);
        self::assertSame(2, $summary['cache_closeout']['personality_collection_cache']['invalidated_locale_count']);
        self::assertSame(20, $summary['cache_closeout']['legacy_alias_detail_cache']['invalidated_target_count']);
        self::assertSame(4, $summary['cache_closeout']['discoverability_cache']['invalidated_key_count']);
        self::assertSame(2, $summary['cache_closeout']['frontend_llms_cache']['accepted_path_count']);
        self::assertDatabaseCount('personality_public_content_asset_revisions', 0);
        self::assertDatabaseCount('personality_public_content_asset_revision_reviews', 0);
        self::assertSame($canonicalBefore, $this->canonicalFingerprint());
        self::assertEquals($sentinelBefore, DB::table('personality_public_content_assets')->where('id', $sentinel->id)->first());

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en&org_id=0&per_page=100')
            ->assertOk()->assertJsonCount(52, 'items');
        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=zh-CN&org_id=0&per_page=100')
            ->assertOk()->assertJsonCount(52, 'items');
    }

    public function test_execute_commits_database_and_reports_partial_closeout_when_frontend_cache_fails(): void
    {
        $this->seedBoundary();
        [$path, $sha] = $this->writeBackupManifest();
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'http://localhost/revalidate');

        $summary = app(BigFiveLegacyAliasHardPurge::class)->run(true, 1, $path, $sha);

        self::assertSame('PARTIAL_CACHE_CLOSEOUT', $summary['status']);
        self::assertTrue($summary['writes_committed']);
        self::assertFalse($summary['cache_closeout_ok']);
        self::assertSame(0, $summary['legacy_alias_asset_count']);
        self::assertDatabaseCount('personality_public_content_assets', 104);
        self::assertNotEmpty($summary['errors']);
    }

    public function test_cache_closeout_only_is_idempotent_and_never_changes_database_or_unrelated_cache(): void
    {
        $this->seedBoundary(includeAliases: false);
        $before = $this->databaseFingerprint();
        Cache::put('unrelated:sentinel', 'keep', 600);

        $first = app(BigFiveLegacyAliasHardPurge::class)->runCacheCloseoutOnly(1);
        $second = app(BigFiveLegacyAliasHardPurge::class)->runCacheCloseoutOnly(1);

        self::assertSame('PASS_CACHE_CLOSEOUT_ONLY', $first['status']);
        self::assertSame('PASS_CACHE_CLOSEOUT_ONLY', $second['status']);
        self::assertFalse($first['writes_committed']);
        self::assertFalse($first['cache_closeout']['database_writes_committed']);
        self::assertSame($before, $this->databaseFingerprint());
        self::assertSame('keep', Cache::get('unrelated:sentinel'));
    }

    public function test_cache_closeout_only_rejects_before_alias_database_rows_are_purged(): void
    {
        $this->seedBoundary();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires all twenty legacy alias rows to be absent');
        app(BigFiveLegacyAliasHardPurge::class)->runCacheCloseoutOnly(1);
    }

    public function test_execute_invalidates_locked_backend_cache_families_and_preserves_unrelated_cache(): void
    {
        $this->seedBoundary();
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $cache->put('index', 'big_five', 'polarity', 'page=1', $locale, 0, 'v1', ['stale' => true]);
            $cache->put('detail-code', 'big_five', 'polarity', 'high-openness', $locale, 0, 'v1', ['stale' => true]);
            $cache->put('detail-slug', 'big_five', 'slug', 'big-five/high-openness', $locale, 0, 'v1', ['stale' => true]);
        }
        Cache::put(SitemapSourceController::CACHE_KEY_FRESH, ['stale' => true], 600);
        Cache::put(SitemapSourceController::CACHE_KEY_STALE, ['stale' => true], 600);
        Cache::put(SitemapCache::XML_CACHE_KEY, '<stale/>', 600);
        Cache::put(SitemapCache::ETAG_CACHE_KEY, 'stale', 600);
        Cache::put('unrelated:sentinel', 'keep', 600);
        [$path, $sha] = $this->writeBackupManifest();

        $summary = app(BigFiveLegacyAliasHardPurge::class)->run(true, 1, $path, $sha);

        self::assertSame('PASS_PURGED', $summary['status']);
        foreach ([SitemapSourceController::CACHE_KEY_FRESH, SitemapSourceController::CACHE_KEY_STALE, SitemapCache::XML_CACHE_KEY, SitemapCache::ETAG_CACHE_KEY] as $key) {
            self::assertNull(Cache::get($key));
        }
        foreach (['en', 'zh-CN'] as $locale) {
            self::assertNull(Cache::get($cache->activeKey('index', 'big_five', 'polarity', 'page=1', $locale)));
            self::assertNull(Cache::get($cache->lkgKey('index', 'big_five', 'polarity', 'page=1', $locale)));
            self::assertNull(Cache::get($cache->activeKey('detail-code', 'big_five', 'polarity', 'high-openness', $locale)));
            self::assertNull(Cache::get($cache->activeKey('detail-slug', 'big_five', 'slug', 'big-five/high-openness', $locale)));
        }
        self::assertSame('keep', Cache::get('unrelated:sentinel'));
        Http::assertSentCount(1);
    }

    public function test_command_requires_exact_confirmation_operator_and_backup(): void
    {
        $this->seedBoundary();
        [$path, $sha] = $this->writeBackupManifest();

        $this->artisan('personality:big-five-legacy-aliases-purge', ['--execute' => true])->assertFailed();
        $this->artisan('personality:big-five-legacy-aliases-purge', [
            '--execute' => true,
            '--confirm' => PersonalityBigFiveLegacyAliasesPurge::CONFIRMATION,
            '--backup-manifest' => $path,
            '--backup-sha256' => $sha,
        ])->assertFailed();
        $this->artisan('personality:big-five-legacy-aliases-purge', [
            '--execute' => true,
            '--confirm' => PersonalityBigFiveLegacyAliasesPurge::CONFIRMATION,
            '--operator-admin-user-id' => 1,
            '--backup-manifest' => $path,
            '--backup-sha256' => $sha,
        ])->assertSuccessful()
            ->expectsOutputToContain('status=PASS_PURGED')
            ->expectsOutputToContain('deleted_asset_count=20');
    }

    public function test_command_cache_closeout_only_requires_exact_confirmation_and_is_read_only(): void
    {
        $this->seedBoundary(includeAliases: false);
        $before = $this->databaseFingerprint();

        $this->artisan('personality:big-five-legacy-aliases-purge', [
            '--cache-closeout-only' => true,
            '--operator-admin-user-id' => 1,
        ])->assertFailed();
        $this->artisan('personality:big-five-legacy-aliases-purge', [
            '--cache-closeout-only' => true,
            '--confirm' => PersonalityBigFiveLegacyAliasesPurge::CACHE_CLOSEOUT_CONFIRMATION,
            '--operator-admin-user-id' => 1,
        ])->assertSuccessful()->expectsOutputToContain('status=PASS_CACHE_CLOSEOUT_ONLY');

        self::assertSame($before, $this->databaseFingerprint());
    }

    public function test_sitemap_keeps_only_104_canonical_big_five_paths(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->seedBoundary(includeAliases: false);
        app(PublicCareerAuthorityResponseCache::class)->warm();

        $paths = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->filter(static fn (mixed $path): bool => is_string($path) && str_contains($path, '/personality/big-five'))
            ->values();

        self::assertCount(104, $paths);
        self::assertSame(104, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->whereIn('locale', ['en', 'zh-CN'])
            ->where('llms_eligible', true)
            ->count());
        foreach (BigFiveCanonicalRouteCatalog::reviewedRedirectPaths() as $alias => $target) {
            self::assertNotContains('https://fermatmind.com'.$alias, $paths->all());
            self::assertContains('https://fermatmind.com'.$target, $paths->all());
        }
    }

    /** @return array{PersonalityPublicContentAsset,PersonalityPublicContentAsset} */
    private function seedBoundary(bool $includeAliases = true): array
    {
        $sentinel = null;
        foreach (['en', 'zh-CN'] as $locale) {
            foreach (BigFiveCanonicalRouteCatalog::canonicalEntries($locale) as $entry) {
                $row = $this->seedAsset(
                    $locale,
                    $entry['entity_type'],
                    $entry['entity_key'],
                    $this->canonicalSlug($entry['entity_type'], $entry['entity_key']),
                    $entry['path'],
                );
                $sentinel ??= $row;
            }
        }

        $firstAlias = null;
        if ($includeAliases) {
            foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $segment) {
                foreach (BigFiveCanonicalRouteCatalog::redirectOnlyAliasTargets($locale) as $alias => $target) {
                    $row = $this->seedAsset(
                        $locale,
                        PersonalityPublicContentAsset::ENTITY_POLARITY,
                        $alias,
                        'big-five/'.$alias,
                        '/'.$segment.'/personality/big-five/'.$alias,
                        $locale === 'zh-CN',
                    );
                    $firstAlias ??= $row;
                }
            }
        }

        return [$firstAlias ?? $sentinel, $sentinel];
    }

    private function seedAsset(
        string $locale,
        string $entityType,
        string $entityKey,
        string $slug,
        string $path,
        bool $chineseAlias = false,
    ): PersonalityPublicContentAsset {
        return PersonalityPublicContentAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $entityKey,
            'summary' => 'summary',
            'content_sections_json' => [['key' => 'body', 'title' => 'Body', 'body_md' => 'Body']],
            'seo_json' => [],
            'canonical_json' => ['path' => $path],
            'hreflang_json' => [$locale => $path],
            'faq_json' => [],
            'schema_json' => [],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'authority_json' => [],
            'internal_links_json' => [],
            'robots' => $chineseAlias
                ? PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW
                : PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'is_public' => true,
            'index_eligible' => ! $chineseAlias,
            'sitemap_eligible' => ! $chineseAlias,
            'llms_eligible' => ! $chineseAlias,
            'launch_state' => $chineseAlias
                ? PersonalityPublicContentAsset::LAUNCH_CONTENT_READY
                : PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'seo_discoverability_released',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'test-authority',
            'source_hash' => str_repeat('a', 64),
        ]);
    }

    private function retireEnglishAlias(PersonalityPublicContentAsset $row): void
    {
        $alias = (string) $row->entity_key;
        $target = BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS[$alias];
        $row->forceFill([
            'canonical_json' => [
                'path' => '/en/personality/big-five/'.$target,
                'redirect_from' => '/en/personality/big-five/'.$alias,
                'redirect_status' => 301,
            ],
            'hreflang_json' => [],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'is_public' => false,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_ARCHIVED,
            'review_state' => BigFiveLegacyAliasHardPurge::EN_RETIRED_REVIEW_STATE,
        ])->save();
    }

    private function seedRevision(PersonalityPublicContentAsset $asset): PersonalityPublicContentAssetRevision
    {
        return PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => $asset->id,
            'revision_no' => 1,
            'authority_asset_key' => 'legacy-alias:'.$asset->locale.':'.$asset->entity_key,
            'source_package' => 'historical-package',
            'source_hash' => str_repeat('b', 64),
            'authority_package_sha256' => str_repeat('c', 64),
            'workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT,
            'snapshot_json' => ['historical' => true],
            'public_runtime_fingerprint_before' => str_repeat('d', 64),
            'created_by_admin_user_id' => 1,
        ]);
    }

    private function seedRevisionReview(PersonalityPublicContentAsset $asset, PersonalityPublicContentAssetRevision $revision): void
    {
        DB::table('personality_public_content_asset_revision_reviews')->insert([
            'revision_id' => $revision->id,
            'asset_id' => $asset->id,
            'authority_asset_key' => $revision->authority_asset_key,
            'source_package' => 'historical-package',
            'asset_sha256' => str_repeat('1', 64),
            'authority_package_sha256' => str_repeat('2', 64),
            'review_register_sha256' => str_repeat('3', 64),
            'reviewer_name' => 'Operator',
            'reviewed_at' => now(),
            'decision' => 'approved',
            'review_source' => 'operator_supplied_human',
            'evidence_sha256' => str_repeat('4', 64),
            'bound_by_admin_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{string,string} */
    private function writeBackupManifest(): array
    {
        $summary = app(BigFiveLegacyAliasHardPurge::class)->run(false, 0);
        $path = storage_path('framework/testing/big-five-legacy-alias-backup-manifest.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'schema_version' => BigFiveLegacyAliasHardPurge::BACKUP_SCHEMA_VERSION,
            'operator_admin_user_id' => 1,
            'created_at' => '2026-07-19T00:00:00Z',
            'backup_artifact_sha256' => str_repeat('e', 64),
            'tables' => $summary['backup_tables'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [$path, (string) hash_file('sha256', $path)];
    }

    private function canonicalSlug(string $entityType, string $entityKey): string
    {
        return match ($entityType) {
            PersonalityPublicContentAsset::ENTITY_HUB => 'big-five',
            PersonalityPublicContentAsset::ENTITY_FACET_HUB => 'big-five/facets',
            PersonalityPublicContentAsset::ENTITY_FACET_DETAIL => 'big-five/facets/'.$entityKey,
            default => 'big-five/'.$entityKey,
        };
    }

    private function databaseFingerprint(): string
    {
        return hash('sha256', json_encode([
            DB::table('personality_public_content_assets')->orderBy('id')->get(),
            DB::table('personality_public_content_asset_revisions')->orderBy('id')->get(),
            DB::table('personality_public_content_asset_revision_reviews')->orderBy('id')->get(),
        ], JSON_THROW_ON_ERROR));
    }

    private function canonicalFingerprint(): string
    {
        return hash('sha256', DB::table('personality_public_content_assets')
            ->whereIn('locale', ['en', 'zh-CN'])
            ->whereNotIn('entity_key', array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS))
            ->orderBy('id')->get()->toJson());
    }
}
