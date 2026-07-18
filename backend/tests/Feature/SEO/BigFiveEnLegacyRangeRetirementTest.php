<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Console\Commands\PersonalityBigFiveEnLegacyRangesRetire;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\BigFive\AuthorityV2\RangeIa\BigFiveEnLegacyRangeRetirement;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class BigFiveEnLegacyRangeRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_finds_exact_ten_active_aliases_without_writes(): void
    {
        $this->seedBoundary();
        $before = DB::table('personality_public_content_assets')->orderBy('id')->get()->toJson();

        $summary = app(BigFiveEnLegacyRangeRetirement::class)->run(false, 0);

        self::assertSame('READY_TO_RETIRE', $summary['status']);
        self::assertSame(10, $summary['alias_active_count']);
        self::assertSame(0, $summary['alias_retired_count']);
        self::assertSame(52, $summary['canonical_preserved_count']);
        self::assertFalse($summary['writes_committed']);
        self::assertSame($before, DB::table('personality_public_content_assets')->orderBy('id')->get()->toJson());
    }

    public function test_execute_archives_only_the_exact_ten_aliases_and_preserves_revisions(): void
    {
        [$alias, $zhSentinel] = $this->seedBoundary();
        $revision = $this->seedRevision($alias);
        $canonicalBefore = $this->canonicalFingerprint();
        $zhBefore = DB::table('personality_public_content_assets')->where('id', $zhSentinel->id)->first();
        $revisionBefore = DB::table('personality_public_content_asset_revisions')->where('id', $revision->id)->first();

        $summary = app(BigFiveEnLegacyRangeRetirement::class)->run(true, 1);

        self::assertSame('PASS_RETIRED', $summary['status']);
        self::assertSame(0, $summary['alias_active_count']);
        self::assertSame(10, $summary['alias_retired_count']);
        self::assertSame(10, $summary['updated_count']);
        self::assertTrue($summary['writes_committed']);
        self::assertTrue($summary['non_target_boundary_unchanged']);
        self::assertSame(0, $summary['english_canonical_write_count']);
        self::assertSame(0, $summary['chinese_write_count']);
        self::assertSame(0, $summary['revision_write_count']);
        self::assertSame($canonicalBefore, $this->canonicalFingerprint());
        self::assertEquals($zhBefore, DB::table('personality_public_content_assets')->where('id', $zhSentinel->id)->first());
        self::assertEquals($revisionBefore, DB::table('personality_public_content_asset_revisions')->where('id', $revision->id)->first());

        foreach (BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS as $aliasKey => $target) {
            $row = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where('locale', 'en')->where('entity_key', $aliasKey)->sole();
            self::assertSame(PersonalityPublicContentAsset::LAUNCH_ARCHIVED, $row->launch_state);
            self::assertSame(BigFiveEnLegacyRangeRetirement::REVIEW_STATE, $row->review_state);
            self::assertFalse($row->is_public);
            self::assertFalse($row->index_eligible);
            self::assertFalse($row->sitemap_eligible);
            self::assertFalse($row->llms_eligible);
            self::assertSame(PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW, $row->robots);
            self::assertSame('/en/personality/big-five/'.$target, data_get($row->canonical_json, 'path'));
            self::assertSame('/en/personality/big-five/'.$aliasKey, data_get($row->canonical_json, 'redirect_from'));
            self::assertSame(301, data_get($row->canonical_json, 'redirect_status'));
            self::assertSame([], $row->hreflang_json);
        }
    }

    public function test_execute_is_idempotent_after_retirement(): void
    {
        $this->seedBoundary();
        app(BigFiveEnLegacyRangeRetirement::class)->run(true, 1);
        $before = DB::table('personality_public_content_assets')->orderBy('id')->get()->toJson();

        $summary = app(BigFiveEnLegacyRangeRetirement::class)->run(true, 1);

        self::assertSame('PASS_RETIRED', $summary['status']);
        self::assertSame(0, $summary['updated_count']);
        self::assertFalse($summary['writes_committed']);
        self::assertTrue($summary['idempotent_noop']);
        self::assertSame($before, DB::table('personality_public_content_assets')->orderBy('id')->get()->toJson());
    }

    public function test_retired_aliases_leave_public_api_with_exactly_52_english_canonicals(): void
    {
        $this->seedBoundary();
        app(BigFiveEnLegacyRangeRetirement::class)->run(true, 1);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en&org_id=0&per_page=100')
            ->assertOk()
            ->assertJsonCount(52, 'items');
        $this->getJson('/api/v0.5/personality-content-assets/big_five/polarity/high-openness?locale=en&org_id=0')
            ->assertNotFound();
        $this->getJson('/api/v0.5/personality-content-assets/big_five/polarity/openness-high?locale=en&org_id=0')
            ->assertOk();
    }

    public function test_preflight_rejects_unknown_alias_and_unsafe_state(): void
    {
        $this->seedBoundary();
        $row = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'en')->where('entity_key', 'high-openness')->sole();
        $row->forceFill(['robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe identity or lifecycle state');
        app(BigFiveEnLegacyRangeRetirement::class)->run(false, 0);
    }

    public function test_command_requires_exact_confirmation_and_positive_operator(): void
    {
        $this->seedBoundary();

        $this->artisan('personality:big-five-en-legacy-ranges-retire', ['--execute' => true])
            ->assertFailed();
        $this->artisan('personality:big-five-en-legacy-ranges-retire', [
            '--execute' => true,
            '--confirm' => PersonalityBigFiveEnLegacyRangesRetire::CONFIRMATION,
        ])->assertFailed();
        $this->artisan('personality:big-five-en-legacy-ranges-retire', [
            '--execute' => true,
            '--confirm' => PersonalityBigFiveEnLegacyRangesRetire::CONFIRMATION,
            '--operator-admin-user-id' => 1,
        ])->assertSuccessful()
            ->expectsOutputToContain('alias_retired_count=10')
            ->expectsOutputToContain('writes_committed=1');
    }

    public function test_sitemap_catalog_never_treats_legacy_aliases_as_canonical(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->seedBoundary();
        app(PublicCareerAuthorityResponseCache::class)->warm();

        $paths = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->filter(static fn (mixed $path): bool => is_string($path) && str_contains($path, '/en/personality/big-five'))
            ->values();

        self::assertCount(52, $paths);
        foreach (BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS as $alias => $target) {
            self::assertNull(BigFiveCanonicalRouteCatalog::expectedPath('en', PersonalityPublicContentAsset::ENTITY_POLARITY, $alias));
            self::assertNotContains('https://fermatmind.com/en/personality/big-five/'.$alias, $paths->all());
            self::assertContains('https://fermatmind.com/en/personality/big-five/'.$target, $paths->all());
        }
    }

    /** @return array{PersonalityPublicContentAsset,PersonalityPublicContentAsset} */
    private function seedBoundary(): array
    {
        foreach (BigFiveCanonicalRouteCatalog::canonicalEntries('en') as $entry) {
            $this->seedAsset(
                locale: 'en',
                entityType: $entry['entity_type'],
                entityKey: $entry['entity_key'],
                slug: $this->canonicalSlug($entry['entity_type'], $entry['entity_key']),
                path: $entry['path'],
            );
        }

        $firstAlias = null;
        foreach (BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS as $alias => $target) {
            $row = $this->seedAsset(
                locale: 'en',
                entityType: PersonalityPublicContentAsset::ENTITY_POLARITY,
                entityKey: $alias,
                slug: 'big-five/'.$alias,
                path: '/en/personality/big-five/'.$alias,
            );
            $firstAlias ??= $row;
        }

        $zhSentinel = $this->seedAsset(
            locale: 'zh-CN',
            entityType: PersonalityPublicContentAsset::ENTITY_HUB,
            entityKey: 'big-five',
            slug: 'big-five',
            path: '/zh/personality/big-five',
        );

        return [$firstAlias, $zhSentinel];
    }

    private function seedAsset(
        string $locale,
        string $entityType,
        string $entityKey,
        string $slug,
        string $path,
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
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'seo_discoverability_released',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'test-authority',
            'source_hash' => str_repeat('a', 64),
        ]);
    }

    private function seedRevision(PersonalityPublicContentAsset $asset): PersonalityPublicContentAssetRevision
    {
        return PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => $asset->id,
            'revision_no' => 1,
            'authority_asset_key' => 'legacy-alias:'.$asset->entity_key,
            'source_package' => 'historical-package',
            'source_hash' => str_repeat('b', 64),
            'authority_package_sha256' => str_repeat('c', 64),
            'workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT,
            'snapshot_json' => ['historical' => true],
            'public_runtime_fingerprint_before' => str_repeat('d', 64),
            'created_by_admin_user_id' => 1,
        ]);
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

    private function canonicalFingerprint(): string
    {
        return hash('sha256', DB::table('personality_public_content_assets')
            ->where('locale', 'en')
            ->whereNotIn('entity_key', array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS))
            ->orderBy('id')->get()->toJson());
    }
}
