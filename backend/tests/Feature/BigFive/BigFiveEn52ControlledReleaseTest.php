<?php

declare(strict_types=1);

namespace Tests\Feature\BigFive;

use App\Models\PersonalityPublicContentAsset;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52ProductionEvidence;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52Publisher;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class BigFiveEn52ControlledReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVED_SHA = '110749a534183b5ab28108c027a14842ec06b860';

    private const RELEASE_NAME = '20260719T071900Z-en52';

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
        $this->packagePath = dirname(__DIR__, 4).'/generated/big-five-en52-release/release-package.json';
        $decoded = json_decode((string) file_get_contents($this->packagePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->package = $decoded;
        config(['seo_intel.connection' => config('database.default')]);
        config(['fap.testing_personality_legacy_public_db_fixture' => true]);
    }

    public function test_compiled_fixture_is_exact_deterministic_text_only_release(): void
    {
        $this->assertSame(BigFiveEn52Publisher::PACKAGE_FILE_SHA256, hash_file('sha256', $this->packagePath));
        $this->assertSame(BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256, data_get($this->package, 'input_hashes.source_content_sha256'));
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
        $this->assertSame($payloadSha, hash('sha256', app(BigFiveEn52PackageCompiler::class)->stableJson($base)));

        $canonicals = [];
        $claimMappings = 0;
        foreach ($this->package['assets'] as $entry) {
            $asset = $entry['asset'];
            $expectedCanonical = BigFiveCanonicalRouteCatalog::expectedPath(
                'en',
                (string) $asset['entity_type'],
                (string) $asset['entity_key'],
            );
            $canonicals[] = data_get($asset, 'canonical.path');
            $this->assertSame($expectedCanonical, data_get($asset, 'canonical.path'));
            $this->assertSame('en', $asset['locale']);
            $this->assertSame('big_five', $asset['framework']);
            $this->assertNotEmpty($asset['content_sections']);
            $this->assertNotEmpty($asset['faq']);
            $this->assertNotEmpty(data_get($asset, 'authority.sources'));
            $claimMappings += count(data_get($asset, 'authority.claim_mapping', []));
            $this->assertFalse(data_get($asset, 'authority.schema_eligible'));
            $this->assertSame([], $asset['schema']);
            $this->assertSame('FermatMind Editorial', data_get($asset, 'authority.author.name'));
            $this->assertSame('FermatMind Editorial', data_get($asset, 'authority.reviewer.name'));
            $this->assertSame('2026-07-19', $asset['last_reviewed_at']);
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
                $this->assertStringContainsString((string) $claim['visible_claim'], $searchableBody);
                foreach ((array) $claim['source_ids'] as $sourceId) {
                    $this->assertContains($sourceId, $visibleSourceIds);
                }
            }
        }
        $this->assertCount(52, array_unique($canonicals));
        $this->assertSame(170, $claimMappings);
    }

    public function test_compiler_serialization_is_byte_stable(): void
    {
        $compiler = app(BigFiveEn52PackageCompiler::class);
        $first = $compiler->stableJson($this->package);
        $second = $compiler->stableJson($this->package);
        $this->assertSame($first, $second);
        $this->assertSame((string) file_get_contents($this->packagePath), $first);

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
            app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
            $this->fail('Preflight should fail closed when the 52 CMS rows are absent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must contain exactly 52 existing canonical rows', $exception->getMessage());
        }

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
    }

    public function test_preflight_is_read_only_after_exact_rows_exist(): void
    {
        $this->seedExactAuthorityRows();

        $result = app(BigFiveEn52Publisher::class)->preflight($this->packagePath);

        $this->assertTrue($result['ok']);
        $this->assertSame('read_only_preflight', $result['mode']);
        $this->assertSame(52, $result['asset_count']);
        $this->assertFalse($result['writes_committed']);
        $this->assertTrue($result['database_snapshot_unchanged']);
        $this->assertSame($result['database_snapshot_before_sha256'], $result['database_snapshot_after_sha256']);
        $this->assertSame(0, $result['created_revision_count']);
        $this->assertSame(0, $result['chinese_write_count']);
        $this->assertSame(0, $result['non_personality_write_count']);
        $this->assertSame(20, $result['alias_expected_count']);
        $this->assertSame(20, $result['alias_safe_count']);
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
            ->where('framework', 'big_five')->where('locale', 'en')->count());
    }

    public function test_preflight_requires_alias_database_records_to_remain_absent(): void
    {
        $this->seedExactAuthorityRows();
        $this->seedLegacyRedirectAlias('en', 'high-openness');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be physically absent');
        app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
    }

    public function test_preflight_rejects_zh_cn_alias_database_records(): void
    {
        $this->seedExactAuthorityRows();
        $this->seedLegacyRedirectAlias('zh-CN', 'low-openness');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be physically absent');
        app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
    }

    public function test_preflight_rejects_extra_target_row_and_hreflang_drift(): void
    {
        $rows = $this->seedExactAuthorityRows();
        $this->seedBoundaryRow('big_five', 'domain', 'extra', 'big-five/extra', 'en');

        try {
            app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
            $this->fail('The extra English row must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly 52 existing canonical rows', $exception->getMessage());
        }

        PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('entity_key', 'extra')->delete();
        $rows[0]->forceFill(['hreflang_json' => ['en' => '/en/personality/big-five']])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact en/zh-CN canonical pair');
        app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
    }

    public function test_preflight_rejects_missing_or_unreadable_zh_cn_counterpart(): void
    {
        $this->seedExactAuthorityRows();
        $counterpart = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')
            ->where('locale', 'zh-CN')
            ->firstOrFail();
        $counterpart->delete();

        try {
            app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
            $this->fail('A missing zh-CN counterpart must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly 52 canonical counterpart rows', $exception->getMessage());
        }

        $this->seedExactZhCounterpart((string) $counterpart->entity_type, (string) $counterpart->entity_key, (string) $counterpart->slug);
        $restored = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')
            ->where('locale', 'zh-CN')
            ->where('entity_type', $counterpart->entity_type)
            ->where('entity_key', $counterpart->entity_key)
            ->firstOrFail();
        $restored->forceFill(['published_at' => now()->addDay()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not publicly readable');
        app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
    }

    public function test_preflight_rejects_future_english_published_at(): void
    {
        $rows = $this->seedExactAuthorityRows();
        $rows[0]->forceFill(['published_at' => now()->addDay()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('future published_at');
        app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
    }

    public function test_preflight_rejects_zh_cn_counterpart_canonical_or_hreflang_drift(): void
    {
        $this->seedExactAuthorityRows();
        $counterpart = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')
            ->where('locale', 'zh-CN')
            ->firstOrFail();
        $counterpart->forceFill(['hreflang_json' => ['zh-CN' => '/zh/personality/big-five']])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical or hreflang drifted');
        app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
    }

    public function test_database_and_preflight_reject_slug_and_canonical_collisions(): void
    {
        $rows = $this->seedExactAuthorityRows();
        try {
            $this->seedBoundaryRow('big_five', 'domain', 'duplicate-slug', (string) $rows[1]->slug, 'en');
            $this->fail('The database slug uniqueness boundary must fail closed.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        $rows[0]->forceFill([
            'canonical_json' => ['path' => data_get($this->package, 'assets.1.asset.canonical.path')],
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical does not match');
        app(BigFiveEn52Publisher::class)->preflight($this->packagePath);
    }

    public function test_publish_is_atomic_exact_and_idempotent_without_touching_other_authority_rows(): void
    {
        $this->seedExactAuthorityRows();
        $zhBoundary = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('entity_type', 'hub')->where('entity_key', 'big-five')
            ->where('locale', 'zh-CN')->firstOrFail();
        $enneagram = $this->seedBoundaryRow('enneagram', 'hub', 'enneagram', 'enneagram', 'zh-CN');
        $zhBoundaryBefore = $zhBoundary->fresh()->getAttributes();
        $enneagramBefore = $enneagram->fresh()->getAttributes();

        $publisher = app(BigFiveEn52Publisher::class);
        $first = $this->publishWithVerifiedBackup($publisher);

        $this->assertTrue($first['ok']);
        $this->assertTrue($first['cache_invalidation_ok']);
        $this->assertSame(52, $first['created_revision_count']);
        $this->assertSame(0, $first['idempotent_unchanged_count']);
        $this->assertTrue($first['transaction_readback_ok']);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 52);
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')
            ->whereColumn('working_revision_id', 'published_revision_id')
            ->whereNotNull('published_revision_id')->count());
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')
            ->where('robots', 'index,follow')
            ->where('is_public', true)
            ->where('index_eligible', true)
            ->where('sitemap_eligible', true)
            ->where('llms_eligible', true)
            ->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)
            ->count());
        $this->assertSame($zhBoundaryBefore, $zhBoundary->fresh()->getAttributes());
        $this->assertSame($enneagramBefore, $enneagram->fresh()->getAttributes());
        $this->assertTrue($first['alias_boundary_unchanged']);

        $targetUpdatedAt = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')
            ->orderBy('id')->get()->mapWithKeys(static fn (PersonalityPublicContentAsset $asset): array => [
                $asset->id => $asset->updated_at?->toAtomString(),
            ])->all();
        $second = $this->publishWithVerifiedBackup($publisher);

        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['created_revision_count']);
        $this->assertSame(52, $second['idempotent_unchanged_count']);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 52);
        $this->assertSame($targetUpdatedAt, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')
            ->orderBy('id')->get()->mapWithKeys(static fn (PersonalityPublicContentAsset $asset): array => [
                $asset->id => $asset->updated_at?->toAtomString(),
            ])->all());
    }

    public function test_published_hub_projects_v1_content_and_complete_v2_authority(): void
    {
        $this->seedExactAuthorityRows();
        $this->publishWithVerifiedBackup();
        $hub = collect($this->package['assets'])->firstWhere('authority_asset_key', 'big-five-hub');
        $this->assertIsArray($hub);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100')
            ->assertOk()
            ->assertJsonPath('pagination.total', 52)
            ->assertJsonCount(52, 'items');

        $response = $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=en');
        $response->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.title', data_get($hub, 'asset.title'))
            ->assertJsonPath('personality_public_content_asset_v1.canonical_path', '/en/personality/big-five')
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
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.review_state', 'unknown')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null)
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.last_reviewed_at', '2026-07-19T00:00:00.000000Z')
            ->assertJsonPath('personality_public_content_asset_v2.schema_eligible', false);

    }

    public function test_release_enumerates_all_52_canonicals_once_and_no_redirect_alias(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->seedExactAuthorityRows();
        $this->publishWithVerifiedBackup();
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
            $this->assertNotContains('https://fermatmind.com/en/personality/big-five/'.$alias, $urls->all());
        }
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')
            ->where('llms_eligible', true)->count());
    }

    public function test_transaction_rolls_back_every_revision_and_primary_write_on_late_failure(): void
    {
        $this->seedExactAuthorityRows();
        PersonalityPublicContentAsset::saving(static function (PersonalityPublicContentAsset $asset): void {
            if ($asset->entity_key === 'vulnerability' && $asset->source_package === BigFiveEn52PackageCompiler::RELEASE_ID) {
                throw new RuntimeException('synthetic late write failure');
            }
        });

        try {
            $this->publishWithVerifiedBackup();
            $this->fail('The synthetic late write failure should abort the release.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic late write failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')
            ->where('source_package', 'test-existing-authority')->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')
            ->whereNotNull('published_revision_id')->count());
    }

    public function test_locked_target_fingerprint_rejects_post_preflight_drift(): void
    {
        $row = $this->seedExactAuthorityRows()[0];
        $publisher = app(BigFiveEn52Publisher::class);
        $fingerprintMethod = new ReflectionMethod($publisher, 'runtimeFingerprint');
        $preflightFingerprint = $fingerprintMethod->invoke($publisher, $row);
        $row->forceFill(['summary' => 'concurrent editorial drift'])->save();

        $assertionMethod = new ReflectionMethod($publisher, 'assertLockedTargetMatchesPlan');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('drifted after preflight');
        $assertionMethod->invoke($publisher, $row->fresh(), [
            'authority_asset_key' => 'synthetic-target',
            'target_preflight_fingerprint' => $preflightFingerprint,
        ]);
    }

    public function test_locked_zh_cn_counterpart_fingerprint_rejects_post_preflight_drift(): void
    {
        $this->seedExactAuthorityRows();
        $counterpart = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')
            ->where('locale', 'zh-CN')
            ->firstOrFail();
        $publisher = app(BigFiveEn52Publisher::class);
        $fingerprintMethod = new ReflectionMethod($publisher, 'runtimeFingerprint');
        $preflightFingerprint = $fingerprintMethod->invoke($publisher, $counterpart);
        $counterpart->forceFill(['summary' => 'concurrent zh-CN editorial drift'])->save();

        $assertionMethod = new ReflectionMethod($publisher, 'assertLockedZhCounterpartMatchesPlan');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zh-CN authority row drifted after preflight');
        $assertionMethod->invoke($publisher, $counterpart->fresh(), [
            'authority_asset_key' => 'synthetic-target',
            'zh_counterpart_id' => (int) $counterpart->id,
            'zh_counterpart_preflight_fingerprint' => $preflightFingerprint,
        ]);
    }

    public function test_non_target_boundary_reads_search_tables_from_seo_intel_connection(): void
    {
        config([
            'seo_intel.connection' => 'seo_boundary_test',
            'database.connections.seo_boundary_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('seo_boundary_test');
        Schema::connection('seo_boundary_test')->create('seo_issue_queue', function ($table): void {
            $table->id();
            $table->string('payload');
        });
        DB::connection('seo_boundary_test')->table('seo_issue_queue')->insert(['payload' => 'before']);
        $this->seedExactAuthorityRows();
        $mutated = false;
        PersonalityPublicContentAsset::saving(static function () use (&$mutated): void {
            if (! $mutated) {
                $mutated = true;
                DB::connection('seo_boundary_test')->table('seo_issue_queue')->insert(['payload' => 'during']);
            }
        });

        try {
            $this->publishWithVerifiedBackup();
            $this->fail('A search-boundary mutation on seo_intel must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('non-target authority boundary changed', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
        $this->assertSame(2, DB::connection('seo_boundary_test')->table('seo_issue_queue')->count());
    }

    public function test_partial_locked_revision_state_fails_closed(): void
    {
        $this->seedExactAuthorityRows();
        $publisher = app(BigFiveEn52Publisher::class);
        $this->publishWithVerifiedBackup($publisher);

        $revision = \App\Models\PersonalityPublicContentAssetRevision::query()->firstOrFail();
        $revision->delete();
        try {
            $publisher->preflight($this->packagePath);
            $this->fail('Partial revision state must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Partial revision state', $exception->getMessage());
        }
    }

    public function test_mixed_locked_revision_state_fails_closed(): void
    {
        $this->seedExactAuthorityRows();
        $publisher = app(BigFiveEn52Publisher::class);
        $this->publishWithVerifiedBackup($publisher);

        $revision = \App\Models\PersonalityPublicContentAssetRevision::query()->firstOrFail();
        $replacement = $revision->getAttributes();
        unset($replacement['id'], $replacement['created_at'], $replacement['updated_at']);
        $revision->delete();
        $replacement['authority_asset_key'] = 'mixed-package-state';
        $replacement['revision_no'] = 99;
        \App\Models\PersonalityPublicContentAssetRevision::query()->create($replacement);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixed revision state');
        $publisher->preflight($this->packagePath);
    }

    public function test_cache_invalidation_failure_reports_committed_with_warning(): void
    {
        $this->seedExactAuthorityRows();
        Cache::shouldReceive('lock')->andThrow(new RuntimeException('synthetic cache failure'));

        $result = $this->publishWithVerifiedBackup();

        $this->assertTrue($result['writes_committed']);
        $this->assertFalse($result['cache_invalidation_ok']);
        $this->assertSame('COMMITTED_WITH_WARNING_BIG_FIVE_EN52_52_PAGE_PUBLISH', $result['status']);
        $this->assertSame('PUBLIC_CACHE_INVALIDATION_FAILED_AFTER_COMMIT', $result['cache_invalidation_warning']);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 52);
    }

    public function test_production_evidence_and_backup_bind_exact_live_rows_and_baselines(): void
    {
        $this->seedExactAuthorityRows();
        $evidence = app(BigFiveEn52ProductionEvidence::class);

        $inspection = $evidence->inspect($this->packagePath);
        $this->assertTrue($inspection['ok']);
        $this->assertSame(52, $inspection['en_canonical_count']);
        $this->assertSame(52, $inspection['zh_canonical_count']);
        $this->assertSame(0, $inspection['legacy_alias_count']);
        $this->assertSame(0, $inspection['source_hash_match_count']);
        $this->assertFalse($inspection['writes_committed']);
        $this->assertTrue($inspection['database_snapshot_unchanged']);
        foreach ($inspection['baseline_fingerprints'] as $fingerprint) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
        }

        $backup = $this->createVerifiedBackup();
        $this->assertFileExists($backup['manifest_path']);
        $this->assertFileExists($backup['artifact_path']);
        $this->assertSame(0600, fileperms($backup['manifest_path']) & 0777);
        $this->assertSame(0600, fileperms($backup['artifact_path']) & 0777);
        $this->assertSame(52, data_get($backup, 'result.tables.personality_public_content_assets.row_count'));

        PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('framework', 'big_five')->where('locale', 'en')->firstOrFail()
            ->forceFill(['summary' => 'post-backup drift'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer matches the locked live cohort');
        app(BigFiveEn52Publisher::class)->publish(
            $this->packagePath,
            1,
            self::APPROVED_SHA,
            self::RELEASE_NAME,
            $backup['manifest_path'],
            $backup['manifest_sha256'],
            $this->releaseIdentityForTests(),
        );
    }

    public function test_production_backup_requires_alias_absence_and_exact_release_identity(): void
    {
        $this->seedExactAuthorityRows();
        $this->seedLegacyRedirectAlias('en', 'high-openness');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires all twenty legacy alias rows to be absent');
        $this->createVerifiedBackup();
    }

    public function test_evidence_and_backup_commands_keep_read_only_and_execute_authorities_separate(): void
    {
        $this->seedExactAuthorityRows();
        $this->artisan('personality:big-five-en52-production-evidence', [
            '--package' => $this->packagePath,
            '--json' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('PASS_BIG_FIVE_EN52_PRODUCTION_EVIDENCE');

        $directory = storage_path('framework/testing/en52-command-backups/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        $base = [
            '--package' => $this->packagePath,
            '--execute' => true,
            '--allow-testing' => true,
            '--output-dir' => $directory,
            '--approved-sha' => self::APPROVED_SHA,
            '--release-name' => self::RELEASE_NAME,
            '--operator-admin-user-id' => 1,
            '--json' => true,
        ];
        $this->artisan('personality:big-five-en52-production-backup', $base)->assertFailed();
        $this->assertSame([], glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: []);

        $this->artisan('personality:big-five-en52-production-backup', [
            ...$base,
            '--confirm' => BigFiveEn52ProductionEvidence::BACKUP_CONFIRMATION,
        ])->assertSuccessful()
            ->expectsOutputToContain('PASS_BIG_FIVE_EN52_PRODUCTION_BACKUP_CREATED');
        $this->assertCount(2, glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: []);
        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
    }

    public function test_execute_command_fails_closed_without_exact_hash_confirmations(): void
    {
        $this->seedExactAuthorityRows();

        $this->artisan('personality:big-five-en52-content-publish', [
            '--execute' => true,
            '--allow-testing' => true,
            '--operator-admin-user-id' => 1,
            '--json' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
    }

    public function test_execute_command_rejects_wrong_hash_admin_and_environment(): void
    {
        $this->seedExactAuthorityRows();
        $base = [
            '--package' => $this->packagePath,
            '--execute' => true,
            '--confirm-content-sha256' => BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256,
            '--confirm-cohort-sha256' => BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256,
            '--confirm-package-sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            '--operator-admin-user-id' => 1,
            '--json' => true,
        ];

        $this->artisan('personality:big-five-en52-content-publish', $base)->assertFailed();
        $this->artisan('personality:big-five-en52-content-publish', [
            ...$base,
            '--allow-testing' => true,
            '--confirm-content-sha256' => str_repeat('0', 64),
        ])->assertFailed();
        $this->artisan('personality:big-five-en52-content-publish', [
            ...$base,
            '--allow-testing' => true,
            '--operator-admin-user-id' => 2,
        ])->assertFailed();

        $this->assertDatabaseCount('personality_public_content_asset_revisions', 0);
    }

    public function test_execute_command_accepts_only_the_exact_locked_confirmations(): void
    {
        $this->seedExactAuthorityRows();
        $backup = $this->createVerifiedBackup();

        $command = $this->artisan('personality:big-five-en52-content-publish', [
            '--package' => $this->packagePath,
            '--execute' => true,
            '--allow-testing' => true,
            '--confirm-content-sha256' => BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256,
            '--confirm-cohort-sha256' => BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256,
            '--confirm-package-sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            '--approved-sha' => self::APPROVED_SHA,
            '--release-name' => self::RELEASE_NAME,
            '--backup-manifest' => $backup['manifest_path'],
            '--backup-sha256' => $backup['manifest_sha256'],
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

    /** @return array<string,mixed> */
    private function publishWithVerifiedBackup(?BigFiveEn52Publisher $publisher = null): array
    {
        $backup = $this->createVerifiedBackup();

        return ($publisher ?? app(BigFiveEn52Publisher::class))->publish(
            $this->packagePath,
            1,
            self::APPROVED_SHA,
            self::RELEASE_NAME,
            $backup['manifest_path'],
            $backup['manifest_sha256'],
            $this->releaseIdentityForTests(),
        );
    }

    /** @return array{result:array<string,mixed>,manifest_path:string,artifact_path:string,manifest_sha256:string} */
    private function createVerifiedBackup(): array
    {
        $directory = storage_path('framework/testing/en52-backups/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory);
        $result = app(BigFiveEn52ProductionEvidence::class)->createBackup(
            $this->packagePath,
            $directory,
            1,
            self::APPROVED_SHA,
            self::RELEASE_NAME,
            $this->releaseIdentityForTests(),
        );

        return [
            'result' => $result,
            'manifest_path' => $directory.DIRECTORY_SEPARATOR.$result['manifest_file'],
            'artifact_path' => $directory.DIRECTORY_SEPARATOR.$result['artifact_file'],
            'manifest_sha256' => $result['backup_manifest_sha256'],
        ];
    }

    /** @return array{sha:string,name:string} */
    private function releaseIdentityForTests(): array
    {
        return ['sha' => self::APPROVED_SHA, 'name' => self::RELEASE_NAME];
    }

    /** @return list<PersonalityPublicContentAsset> */
    private function seedExactAuthorityRows(): array
    {
        $rows = [];
        foreach ($this->package['assets'] as $entry) {
            $asset = $entry['asset'];
            $row = $this->seedBoundaryRow(
                (string) $asset['framework'],
                (string) $asset['entity_type'],
                (string) $asset['entity_key'],
                (string) $asset['slug'],
                (string) $asset['locale'],
                [
                    'en' => BigFiveCanonicalRouteCatalog::expectedPath('en', (string) $asset['entity_type'], (string) $asset['entity_key']),
                    'zh-CN' => BigFiveCanonicalRouteCatalog::expectedPath('zh-CN', (string) $asset['entity_type'], (string) $asset['entity_key']),
                ],
            );
            $row->forceFill(['canonical_json' => $asset['canonical']])->save();
            $rows[] = $row->fresh();
            $this->seedExactZhCounterpart(
                (string) $asset['entity_type'],
                (string) $asset['entity_key'],
                (string) $asset['slug'],
            );
        }

        return $rows;
    }

    private function seedExactZhCounterpart(string $entityType, string $entityKey, string $slug): PersonalityPublicContentAsset
    {
        $hreflang = [
            'en' => BigFiveCanonicalRouteCatalog::expectedPath('en', $entityType, $entityKey),
            'zh-CN' => BigFiveCanonicalRouteCatalog::expectedPath('zh-CN', $entityType, $entityKey),
        ];
        $row = $this->seedBoundaryRow('big_five', $entityType, $entityKey, $slug, 'zh-CN', $hreflang);
        $row->forceFill([
            'canonical_json' => ['path' => $hreflang['zh-CN']],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'published_at' => now()->subDay(),
        ])->save();

        return $row->fresh();
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
