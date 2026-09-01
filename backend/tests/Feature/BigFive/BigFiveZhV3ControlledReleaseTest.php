<?php

declare(strict_types=1);

namespace Tests\Feature\BigFive;

use App\Models\PersonalityPublicContentAsset;
use App\Services\BigFive\AuthorityV3\Release\BigFiveZhV3PackageCompiler;
use App\Services\BigFive\AuthorityV3\Release\BigFiveZhV3Publisher;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class BigFiveZhV3ControlledReleaseTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    /** @var array<string,mixed> */
    private array $package;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/backend/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = dirname(__DIR__, 4).'/generated/big-five-authority-v3/big5-zh-v3-52-page-release/release-package.json';
        $decoded = json_decode((string) file_get_contents($this->packagePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->package = $decoded;
        config(['fap.testing_personality_legacy_public_db_fixture' => true]);
    }

    public function test_compiled_fixture_is_exact_deterministic_text_only_release(): void
    {
        $this->assertSame(BigFiveZhV3Publisher::PACKAGE_FILE_SHA256, hash_file('sha256', $this->packagePath));
        $this->assertSame(BigFiveZhV3PackageCompiler::SOURCE_CONTENT_SHA256, $this->package['source_content_sha256']);
        $this->assertSame(52, $this->package['asset_count']);
        $this->assertSame(170, $this->package['claims_count']);
        $this->assertSame(261, $this->package['faq_count']);
        $this->assertSame([
            'domain' => 5,
            'facet_detail' => 30,
            'facet_hub' => 1,
            'hub' => 1,
            'polarity' => 15,
        ], $this->package['family_counts']);

        $base = $this->package;
        $payloadSha = (string) $base['package_payload_sha256'];
        unset($base['package_payload_sha256']);
        $this->assertSame($payloadSha, hash('sha256', app(BigFiveZhV3PackageCompiler::class)->stableJson($base)));

        $canonicals = [];
        $claimMappings = 0;
        foreach ($this->package['assets'] as $entry) {
            $asset = $entry['asset'];
            $expectedCanonical = BigFiveCanonicalRouteCatalog::expectedPath(
                'zh-CN',
                (string) $asset['entity_type'],
                (string) $asset['entity_key'],
            );
            $canonicals[] = data_get($asset, 'canonical.path');
            $this->assertSame($expectedCanonical, data_get($asset, 'canonical.path'));
            $this->assertSame('zh-CN', $asset['locale']);
            $this->assertSame('big_five', $asset['framework']);
            $this->assertNotEmpty($asset['content_sections']);
            $this->assertNotEmpty($asset['faq']);
            $this->assertNotEmpty(data_get($asset, 'authority.sources'));
            $claimMappings += count(data_get($asset, 'authority.claim_mapping', []));
            $this->assertFalse(data_get($asset, 'authority.schema_eligible'));
            $this->assertSame([], $asset['schema']);
            $this->assertSame('FermatMind Editorial', data_get($asset, 'authority.author.name'));
            $this->assertSame('FermatMind Editorial', data_get($asset, 'authority.reviewer.name'));
            $this->assertSame('2026-07-18', $asset['last_reviewed_at']);
            $this->assertFalse($this->containsForbiddenMediaKey($asset));
            $visibleSourceIds = collect(data_get($asset, 'authority.sources', []))->pluck('id')->all();
            $searchableBody = collect($asset['content_sections'])->pluck('body')->implode("\n")
                ."\n".collect($asset['faq'])->pluck('answer')->implode("\n");
            $normalizedQuestions = collect($asset['faq'])->pluck('question')->map(
                static fn (string $question): string => preg_replace('/[\s\x{3000}，。！？!?：:；;、“”‘’"\']/u', '', $question) ?: '',
            );
            $this->assertSame($normalizedQuestions->count(), $normalizedQuestions->unique()->count());
            foreach ($asset['content_sections'] as $section) {
                $this->assertArrayHasKey('key', $section);
                $this->assertArrayHasKey('heading', $section);
                $this->assertArrayHasKey('body', $section);
                $this->assertNotSame('常见问题', $section['heading']);
                $this->assertNotSame('参考来源', $section['heading']);
                $this->assertStringNotContainsString('![', $section['body']);
                $this->assertStringNotContainsString('<img', strtolower($section['body']));
            }
            $this->assertCount(count($entry['evidence_claims']), data_get($asset, 'authority.claim_mapping', []));
            foreach ($entry['evidence_claims'] as $claim) {
                $this->assertStringContainsString((string) $claim['exact_claim'], $searchableBody);
                foreach ((array) $claim['source_ids'] as $sourceId) {
                    $this->assertContains($sourceId, $visibleSourceIds);
                }
                if ($claim['support_level'] !== 'not_required') {
                    $this->assertNotEmpty($claim['source_ids']);
                }
            }
        }
        $this->assertCount(52, array_unique($canonicals));
        $this->assertSame(170, $claimMappings);
    }

    public function test_compiler_serialization_is_byte_stable_and_wrong_content_sha_fails_closed(): void
    {
        $compiler = app(BigFiveZhV3PackageCompiler::class);
        $first = $compiler->stableJson($this->package);
        $second = $compiler->stableJson($this->package);
        $this->assertSame($first, $second);
        $this->assertSame((string) file_get_contents($this->packagePath), $first);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected content SHA-256 is not the locked');
        $compiler->compile(dirname(__DIR__, 4), str_repeat('0', 64));
    }

    public function test_contract_rejects_media_and_section_images(): void
    {
        $asset = $this->package['assets'][0]['asset'];
        $asset['media'] = ['hero' => 'https://example.com/hero.png'];
        try {
            app(PersonalityPublicContentAssetContract::class)->validateAsset($asset);
            $this->fail('Media fields must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('media', $exception->errors());
        }

        unset($asset['media']);
        $asset['content_sections'][0]['body'] .= "\n\n![forbidden](https://example.com/image.png)";
        try {
            app(PersonalityPublicContentAssetContract::class)->validateAsset($asset);
            $this->fail('Markdown images must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('content_sections.0.body_md', $exception->errors());
        }
    }

    public function test_preflight_is_read_only_and_requires_all_existing_authority_rows(): void
    {
        try {
            app(BigFiveZhV3Publisher::class)->preflight($this->packagePath);
            $this->fail('Preflight should fail closed when the 52 CMS rows are absent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Required existing CMS authority row is missing', $exception->getMessage());
        }

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
    }

    public function test_preflight_is_read_only_after_exact_rows_exist(): void
    {
        $this->seedExactAuthorityRows();

        $result = app(BigFiveZhV3Publisher::class)->preflight($this->packagePath);

        $this->assertTrue($result['ok']);
        $this->assertSame('read_only_preflight', $result['mode']);
        $this->assertSame(52, $result['asset_count']);
        $this->assertFalse($result['writes_committed']);
        $this->assertTrue($result['database_snapshot_unchanged']);
        $this->assertSame($result['database_snapshot_before_sha256'], $result['database_snapshot_after_sha256']);
        $this->assertSame(0, $result['created_revision_count']);
        $this->assertSame(0, $result['english_write_count']);
        $this->assertSame(0, $result['non_personality_write_count']);
        $this->assertSame(0, $result['alias_expected_count']);
        $this->assertSame(0, $result['alias_safe_count']);
        $this->assertSame(0, $result['alias_database_count']);
        $this->assertTrue($result['alias_absent']);
        $this->assertSame(0, $result['alias_descriptor_overlap_count']);
        $this->assertSame(0, $result['alias_collision_count']);
        $this->assertTrue($result['alias_boundary_unchanged']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['alias_boundary_fingerprint_sha256']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(52, $result['current_revision_ids']);
        $this->assertCount(52, $result['current_public_fingerprints']);
        $this->assertCount(52, $result['planned_source_hashes']);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'zh-CN')->count());
    }

    public function test_preflight_requires_alias_database_records_to_remain_absent(): void
    {
        $this->seedExactAuthorityRows();
        $this->seedLegacyRedirectAlias('zh-CN', 'high-openness');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be physically absent');
        app(BigFiveZhV3Publisher::class)->preflight($this->packagePath);
    }

    public function test_publish_is_atomic_exact_and_idempotent_without_touching_other_authority_rows(): void
    {
        $this->seedExactAuthorityRows();
        $english = $this->seedBoundaryRow('big_five', 'hub', 'big-five', 'big-five', 'en');
        $enneagram = $this->seedBoundaryRow('enneagram', 'hub', 'enneagram', 'enneagram', 'zh-CN');
        $englishBefore = $english->fresh()->getAttributes();
        $enneagramBefore = $enneagram->fresh()->getAttributes();

        $publisher = app(BigFiveZhV3Publisher::class);
        $first = $publisher->publish($this->packagePath, 1);

        $this->assertTrue($first['ok']);
        $this->assertSame(52, $first['created_revision_count']);
        $this->assertSame(0, $first['idempotent_unchanged_count']);
        $this->assertTrue($first['transaction_readback_ok']);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 52);
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'zh-CN')
            ->whereColumn('working_revision_id', 'published_revision_id')
            ->whereNotNull('published_revision_id')->count());
        $this->assertSame($englishBefore, $english->fresh()->getAttributes());
        $this->assertSame($enneagramBefore, $enneagram->fresh()->getAttributes());
        $this->assertTrue($first['alias_boundary_unchanged']);

        $targetUpdatedAt = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'zh-CN')
            ->orderBy('id')->get()->mapWithKeys(static fn (PersonalityPublicContentAsset $asset): array => [
                $asset->id => $asset->updated_at?->toAtomString(),
            ])->all();
        $second = $publisher->publish($this->packagePath, 1);

        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['created_revision_count']);
        $this->assertSame(52, $second['idempotent_unchanged_count']);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 52);
        $this->assertSame($targetUpdatedAt, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'zh-CN')
            ->orderBy('id')->get()->mapWithKeys(static fn (PersonalityPublicContentAsset $asset): array => [
                $asset->id => $asset->updated_at?->toAtomString(),
            ])->all());
    }

    public function test_published_hub_projects_v1_content_and_complete_v2_authority(): void
    {
        $this->seedExactAuthorityRows();
        app(BigFiveZhV3Publisher::class)->publish($this->packagePath, 1);
        $hub = collect($this->package['assets'])->firstWhere('authority_asset_key', 'big-five-hub');
        $this->assertIsArray($hub);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=zh-CN&per_page=100')
            ->assertOk()
            ->assertJsonPath('pagination.total', 52)
            ->assertJsonCount(52, 'items');

        $response = $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN');
        $response->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.title', data_get($hub, 'asset.title'))
            ->assertJsonPath('personality_public_content_asset_v1.canonical_path', '/zh/personality/big-five')
            ->assertJsonCount(count(data_get($hub, 'asset.content_sections')), 'personality_public_content_asset_v1.sections')
            ->assertJsonCount(count(data_get($hub, 'asset.faq')), 'personality_public_content_asset_v1.faq')
            ->assertJsonMissingPath('personality_public_content_asset_v1.media')
            ->assertJsonCount(
                count(data_get($hub, 'asset.authority.sources')),
                'personality_public_content_asset_v2.visible_evidence.sources',
            )
            ->assertJsonCount(
                count($hub['evidence_claims']),
                'personality_public_content_asset_v2.visible_evidence.claim_mapping',
            )
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.author.name', 'FermatMind Editorial')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.review_state', 'approved')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null)
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.last_reviewed_at', '2026-07-18T00:00:00.000000Z')
            ->assertJsonPath('personality_public_content_asset_v2.schema_eligible', false);

    }

    public function test_release_enumerates_all_52_canonicals_once_and_no_redirect_alias(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->seedExactAuthorityRows();
        app(BigFiveZhV3Publisher::class)->publish($this->packagePath, 1);
        app(PublicCareerAuthorityResponseCache::class)->warm();

        $urls = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->filter(static fn (mixed $loc): bool => is_string($loc))
            ->values();
        foreach ($this->package['assets'] as $entry) {
            $expected = 'https://fermatmind.com'.data_get($entry, 'asset.canonical.path');
            $this->assertSame(1, $urls->filter(static fn (string $loc): bool => $loc === $expected)->count());
        }
        foreach (BigFiveCanonicalRouteCatalog::ZH_REDIRECT_ONLY_ALIASES as $alias) {
            $this->assertNotContains('https://fermatmind.com/zh/personality/big-five/'.$alias, $urls->all());
        }
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'zh-CN')
            ->where('llms_eligible', true)->count());
    }

    public function test_transaction_rolls_back_every_revision_and_primary_write_on_late_failure(): void
    {
        $this->seedExactAuthorityRows();
        PersonalityPublicContentAsset::saving(static function (PersonalityPublicContentAsset $asset): void {
            if ($asset->entity_key === 'vulnerability' && $asset->source_package === 'big5-zh-v3-52-page-release') {
                throw new RuntimeException('synthetic late write failure');
            }
        });

        try {
            app(BigFiveZhV3Publisher::class)->publish($this->packagePath, 1);
            $this->fail('The synthetic late write failure should abort the release.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic late write failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'zh-CN')
            ->where('source_package', 'test-existing-authority')->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'zh-CN')
            ->whereNotNull('published_revision_id')->count());
    }

    public function test_execute_command_fails_closed_without_exact_hash_confirmations(): void
    {
        $this->seedExactAuthorityRows();

        $this->artisan('personality:big-five-zh-v3-content-publish', [
            '--execute' => true,
            '--allow-testing' => true,
            '--operator-admin-user-id' => 1,
            '--json' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
    }

    public function test_execute_command_accepts_only_the_exact_locked_confirmations(): void
    {
        $this->seedExactAuthorityRows();

        $command = $this->artisan('personality:big-five-zh-v3-content-publish', [
            '--package' => $this->packagePath,
            '--execute' => true,
            '--allow-testing' => true,
            '--confirm-content-sha256' => BigFiveZhV3PackageCompiler::SOURCE_CONTENT_SHA256,
            '--confirm-package-sha256' => BigFiveZhV3Publisher::PACKAGE_FILE_SHA256,
            '--operator-admin-user-id' => 1,
        ]);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $command->assertFailed()->expectsOutputToContain('writes_committed=0');
            unset($command);
            $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);

            return;
        }

        $command->assertSuccessful()
            ->expectsOutputToContain('asset_count=52')
            ->expectsOutputToContain('alias_database_count=0')
            ->expectsOutputToContain('alias_absent=1')
            ->expectsOutputToContain('created_revision_count=52')
            ->expectsOutputToContain('writes_committed=1');

        unset($command);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 52);
    }

    /** @return list<PersonalityPublicContentAsset> */
    private function seedExactAuthorityRows(): array
    {
        $rows = [];
        foreach ($this->package['assets'] as $entry) {
            $asset = $entry['asset'];
            $rows[] = $this->seedBoundaryRow(
                (string) $asset['framework'],
                (string) $asset['entity_type'],
                (string) $asset['entity_key'],
                (string) $asset['slug'],
                (string) $asset['locale'],
                (array) $asset['hreflang'],
            );
        }

        return $rows;
    }

    private function seedLegacyRedirectAlias(string $locale, string $alias): PersonalityPublicContentAsset
    {
        $row = $this->seedBoundaryRow(
            PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            PersonalityPublicContentAsset::ENTITY_POLARITY,
            $alias,
            'big-five/'.$alias,
            $locale,
        );
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';
        $row->forceFill([
            'canonical_json' => ['path' => '/'.$segment.'/personality/big-five/'.$alias],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
        ])->save();

        return $row->fresh();
    }

    /** @param array<string,mixed> $hreflang */
    private function seedBoundaryRow(
        string $framework,
        string $entityType,
        string $entityKey,
        string $slug,
        string $locale,
        array $hreflang = [],
    ): PersonalityPublicContentAsset {
        return PersonalityPublicContentAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'framework' => $framework,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'existing authority row',
            'summary' => 'existing summary',
            'content_sections_json' => [['key' => 'existing', 'heading' => 'Existing', 'body' => 'Existing body']],
            'seo_json' => ['title' => 'Existing', 'description' => 'Existing'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/'.$locale.'/existing/'.$framework.'/'.$entityKey],
            'hreflang_json' => $hreflang,
            'faq_json' => [],
            'schema_json' => [],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'authority_json' => [],
            'internal_links_json' => [],
            'is_public' => false,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'existing',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'source_package' => 'test-existing-authority',
            'source_hash' => str_repeat('a', 64),
            'published_at' => null,
            'last_reviewed_at' => null,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function containsForbiddenMediaKey(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, ['media', 'media_json', 'media_authority', 'media_deferred_by_operator'], true)) {
                return true;
            }
            if (is_array($value) && $this->containsForbiddenMediaKey($value)) {
                return true;
            }
        }

        return false;
    }
}
