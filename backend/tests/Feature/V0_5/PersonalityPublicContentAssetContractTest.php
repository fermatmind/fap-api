<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PersonalityPublicContentAssetContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_dry_run_validates_big_five_seed_without_writing(): void
    {
        $this->artisan('personality-public-assets:import')
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('assets_found=94')
            ->expectsOutputToContain('valid_count=94')
            ->expectsOutputToContain('errors_count=0')
            ->expectsOutputToContain('indexable_count=0')
            ->expectsOutputToContain('sitemap_eligible_count=0')
            ->expectsOutputToContain('llms_eligible_count=0')
            ->assertExitCode(0);

        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_import_is_idempotent_and_exposes_only_render_candidates(): void
    {
        $this->artisan('personality-public-assets:import', [
            '--write' => true,
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('will_create=94')
            ->expectsOutputToContain('indexable_count=0')
            ->expectsOutputToContain('sitemap_eligible_count=0')
            ->expectsOutputToContain('llms_eligible_count=0')
            ->assertExitCode(0);

        $this->assertSame(94, PersonalityPublicContentAsset::query()->count());

        $this->artisan('personality-public-assets:import', [
            '--write' => true,
        ])
            ->expectsOutputToContain('will_skip=94')
            ->assertExitCode(0);

        $asset = PersonalityPublicContentAsset::query()
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_HUB)
            ->firstOrFail();

        $this->assertSame(PersonalityPublicContentAsset::LAUNCH_CONTENT_READY, $asset->launch_state);
        $this->assertSame(PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW, $asset->robots);
        $this->assertFalse((bool) $asset->index_eligible);
        $this->assertFalse((bool) $asset->sitemap_eligible);
        $this->assertFalse((bool) $asset->llms_eligible);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 17)
            ->assertJsonCount(17, 'items')
            ->assertJsonPath('items.0.index_eligible', false)
            ->assertJsonPath('items.0.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('pagination.total', 17)
            ->assertJsonCount(17, 'items');

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en&entity_type=facet')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');

        $this->getJson('/api/v0.5/personality-content-assets/big_five/domain/openness?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.code', 'openness')
            ->assertJsonPath('personality_public_content_asset_v1.entity_type', PersonalityPublicContentAsset::ENTITY_DOMAIN)
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)
            ->assertJsonPath('personality_public_content_asset_v1.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/facet/imagination?locale=en')
            ->assertNotFound();

        $sitemapLocs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->implode("\n");

        $this->assertStringNotContainsString('/personality/big-five', $sitemapLocs);
    }

    public function test_big_five_seed_has_expected_counts_parity_and_indexability(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('content_assets/personality_public/big_five_v1_seed.json')), true);
        $assets = collect(is_array($payload['assets'] ?? null) ? $payload['assets'] : []);

        $this->assertSame(94, $assets->count());
        $this->assertSame(['en' => 47, 'zh-CN' => 47], $assets->countBy('locale')->sortKeys()->all());
        $this->assertSame([
            'domain' => 10,
            'facet' => 60,
            'facet_hub' => 2,
            'hub' => 2,
            'polarity' => 20,
        ], $assets->countBy('entity_type')->sortKeys()->all());

        $renderCandidates = $assets->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->values();
        $facetStubs = $assets->where('entity_type', PersonalityPublicContentAsset::ENTITY_FACET)->values();

        $this->assertSame(34, $renderCandidates->count());
        $this->assertSame(60, $facetStubs->count());
        $this->assertTrue($assets->every(fn (array $asset): bool => $asset['robots'] === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW));
        $this->assertTrue($assets->every(fn (array $asset): bool => $asset['index_eligible'] === false && $asset['sitemap_eligible'] === false && $asset['llms_eligible'] === false));
        $this->assertTrue($renderCandidates->every(fn (array $asset): bool => $asset['robots'] === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW));
        $this->assertTrue($renderCandidates->every(fn (array $asset): bool => $asset['index_eligible'] === false && $asset['sitemap_eligible'] === false && $asset['llms_eligible'] === false));
        $this->assertTrue($renderCandidates->every(fn (array $asset): bool => count($asset['sections'] ?? []) >= 10));
        $this->assertTrue($renderCandidates->every(fn (array $asset): bool => count($asset['faq'] ?? []) >= 5));
        $this->assertTrue($renderCandidates->every(fn (array $asset): bool => count($asset['internal_links'] ?? []) >= 5));
        $this->assertTrue($renderCandidates->every(function (array $asset): bool {
            $canonicalPath = (string) data_get($asset, 'canonical.path', '');

            return $asset['locale'] === 'zh-CN'
                ? str_starts_with($canonicalPath, '/zh/personality/big-five')
                : str_starts_with($canonicalPath, '/en/personality/big-five');
        }));
        $this->assertTrue($facetStubs->every(fn (array $asset): bool => $asset['launch_state'] === PersonalityPublicContentAsset::LAUNCH_CONTENT_STUB));
        $this->assertTrue($facetStubs->every(fn (array $asset): bool => $asset['robots'] === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW));
        $this->assertTrue($facetStubs->every(fn (array $asset): bool => $asset['index_eligible'] === false && $asset['sitemap_eligible'] === false && $asset['llms_eligible'] === false));

        $enCodes = $renderCandidates->where('locale', 'en')->pluck('code')->sort()->values()->all();
        $zhCodes = $renderCandidates->where('locale', 'zh-CN')->pluck('code')->sort()->values()->all();
        $this->assertSame($enCodes, $zhCodes);

        $serializedSeed = strtolower((string) json_encode($assets->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ([
            'score',
            'percentile',
            'result id',
            'report engine',
            'payload',
            '你这次结果',
            '当前画像',
            'facet anomaly rules',
            '32 ocean',
            'ocean 32',
            '32型人格',
            '32 型人格',
            '官方32',
        ] as $forbiddenTerm) {
            $this->assertStringNotContainsString($forbiddenTerm, $serializedSeed);
        }
    }

    public function test_import_dry_run_validates_enneagram_placeholder_seed_without_writing(): void
    {
        $this->artisan('personality-public-assets:import', [
            '--source' => 'content_assets/personality_public/enneagram_v1_placeholder_seed.json',
            '--framework' => ['enneagram'],
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('assets_found=26')
            ->expectsOutputToContain('valid_count=26')
            ->expectsOutputToContain('errors_count=0')
            ->expectsOutputToContain('indexable_count=0')
            ->expectsOutputToContain('sitemap_eligible_count=0')
            ->expectsOutputToContain('llms_eligible_count=0')
            ->assertExitCode(0);

        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_enneagram_placeholder_write_import_is_idempotent_and_exposes_v1_candidates(): void
    {
        $this->artisan('personality-public-assets:import', [
            '--source' => 'content_assets/personality_public/enneagram_v1_placeholder_seed.json',
            '--framework' => ['enneagram'],
            '--write' => true,
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('will_create=26')
            ->expectsOutputToContain('indexable_count=0')
            ->expectsOutputToContain('sitemap_eligible_count=0')
            ->expectsOutputToContain('llms_eligible_count=0')
            ->assertExitCode(0);

        $this->assertSame(26, PersonalityPublicContentAsset::query()->count());

        $this->artisan('personality-public-assets:import', [
            '--source' => 'content_assets/personality_public/enneagram_v1_placeholder_seed.json',
            '--framework' => ['enneagram'],
            '--write' => true,
        ])
            ->expectsOutputToContain('will_skip=26')
            ->assertExitCode(0);

        $this->getJson('/api/v0.5/personality-content-assets?framework=enneagram&locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 13)
            ->assertJsonCount(13, 'items')
            ->assertJsonPath('items.0.index_eligible', false)
            ->assertJsonPath('items.0.sitemap_eligible', false)
            ->assertJsonPath('items.0.llms_eligible', false)
            ->assertJsonPath('items.0.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW);

        $this->getJson('/api/v0.5/personality-content-assets?framework=enneagram&locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('pagination.total', 13)
            ->assertJsonCount(13, 'items');

        $this->getJson('/api/v0.5/personality-content-assets?framework=enneagram&locale=en&entity_type=center')
            ->assertOk()
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonCount(3, 'items');

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/hub/enneagram?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.framework', 'enneagram')
            ->assertJsonPath('personality_public_content_asset_v1.entity_type', PersonalityPublicContentAsset::ENTITY_HUB)
            ->assertJsonPath('personality_public_content_asset_v1.code', 'enneagram')
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)
            ->assertJsonPath('personality_public_content_asset_v1.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW);

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/center/gut?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.code', 'gut')
            ->assertJsonPath('personality_public_content_asset_v1.entity_type', PersonalityPublicContentAsset::ENTITY_CENTER);

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/core_type/type-1?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.code', 'type-1')
            ->assertJsonPath('personality_public_content_asset_v1.entity_type', PersonalityPublicContentAsset::ENTITY_CORE_TYPE);

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/wing/5w4?locale=en')
            ->assertNotFound();

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/instinctual_subtype/type-2/self-preservation?locale=en')
            ->assertNotFound();

        $sitemapLocs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->implode("\n");

        $this->assertStringNotContainsString('/personality/enneagram', $sitemapLocs);
    }

    public function test_enneagram_placeholder_seed_has_expected_counts_parity_and_indexability(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('content_assets/personality_public/enneagram_v1_placeholder_seed.json')), true);
        $assets = collect(is_array($payload['assets'] ?? null) ? $payload['assets'] : []);

        $this->assertSame(26, $assets->count());
        $this->assertSame(['en' => 13, 'zh-CN' => 13], $assets->countBy('locale')->sortKeys()->all());
        $this->assertSame([
            'center' => 6,
            'core_type' => 18,
            'hub' => 2,
        ], $assets->countBy('entity_type')->sortKeys()->all());

        $this->assertSame(26, $assets->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
        $this->assertSame(0, $assets->where('entity_type', PersonalityPublicContentAsset::ENTITY_WING)->count());
        $this->assertSame(0, $assets->where('entity_type', PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE)->count());
        $this->assertTrue($assets->every(fn (array $asset): bool => $asset['framework'] === PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM));
        $this->assertTrue($assets->every(fn (array $asset): bool => $asset['robots'] === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW));
        $this->assertTrue($assets->every(fn (array $asset): bool => $asset['index_eligible'] === false && $asset['sitemap_eligible'] === false && $asset['llms_eligible'] === false));

        $enCodes = $assets->where('locale', 'en')->pluck('code')->sort()->values()->all();
        $zhCodes = $assets->where('locale', 'zh-CN')->pluck('code')->sort()->values()->all();
        $this->assertSame($enCodes, $zhCodes);
        $this->assertContains('enneagram', $enCodes);
        $this->assertContains('gut', $enCodes);
        $this->assertContains('heart', $enCodes);
        $this->assertContains('head', $enCodes);
        $this->assertContains('type-9', $enCodes);
    }

    public function test_published_indexable_asset_can_be_read_without_sitemap_or_llms_flags(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'index_eligible' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => now()->subMinute(),
        ]));

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.framework', 'big_five')
            ->assertJsonPath('items.0.entity_type', 'hub')
            ->assertJsonPath('items.0.robots', PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW)
            ->assertJsonPath('items.0.index_eligible', true)
            ->assertJsonPath('items.0.sitemap_eligible', false)
            ->assertJsonPath('items.0.llms_eligible', false);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.slug', 'big-five')
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED);

        $sitemapLocs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->implode("\n");

        $this->assertStringNotContainsString('/personality/big-five', $sitemapLocs);
    }

    public function test_contract_rejects_disallowed_page_families_and_private_result_modules(): void
    {
        $contract = app(PersonalityPublicContentAssetContract::class);

        try {
            $contract->validateAsset($this->contractPayload([
                'entity_type' => 'polarity',
                'entity_key' => 'ocean-32-intense-profile',
                'slug' => 'big-five/ocean-32-intense-profile',
            ]));
            $this->fail('Expected 32 OCEAN validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entity_key', $exception->errors());
        }

        try {
            $contract->validateAsset($this->contractPayload([
                'content_sections' => [
                    [
                        'key' => 'overview',
                        'source' => 'private_result_module',
                        'body_md' => 'Do not reuse private result modules.',
                    ],
                ],
            ]));
            $this->fail('Expected private result module validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content_sections', $exception->errors());
        }

        try {
            $contract->validateAsset($this->contractPayload([
                'framework' => 'enneagram',
                'entity_type' => 'instinctual_subtype',
                'entity_key' => 'tritype-548',
                'slug' => 'enneagram/tritype-548',
            ]));
            $this->fail('Expected Tritype validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entity_key', $exception->errors());
        }
    }

    public function test_contract_requires_published_state_for_indexable_assets(): void
    {
        $this->expectException(ValidationException::class);

        app(PersonalityPublicContentAssetContract::class)->validateAsset($this->contractPayload([
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'index_eligible' => true,
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
        ]));
    }

    public function test_contract_rejects_index_follow_without_published_indexable_asset(): void
    {
        $this->expectException(ValidationException::class);

        app(PersonalityPublicContentAssetContract::class)->validateAsset($this->contractPayload([
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'index_eligible' => false,
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function assetAttributes(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Big Five Personality',
            'summary' => 'Published API fixture.',
            'content_sections_json' => [
                [
                    'key' => 'overview',
                    'body_md' => 'Public CMS-authored body.',
                ],
            ],
            'seo_json' => [
                'title' => 'Big Five Personality',
                'description' => 'Published fixture.',
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => [
                'path' => '/en/personality/big-five',
            ],
            'hreflang_json' => [],
            'faq_json' => [],
            'media_json' => [],
            'schema_json' => [
                '@type' => 'WebPage',
            ],
            'method_boundary_json' => [
                'summary' => 'Dimensional model boundary.',
            ],
            'evidence_notes_json' => [
                [
                    'source_type' => 'fixture',
                    'note' => 'Test fixture.',
                ],
            ],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'draft',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function contractPayload(array $overrides = []): array
    {
        return array_merge([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'code' => 'big-five',
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Big Five Personality',
            'summary' => 'Contract fixture.',
            'content_sections' => [
                [
                    'key' => 'overview',
                    'body_md' => 'CMS-authored body.',
                ],
            ],
            'seo' => [
                'title' => 'Big Five Personality',
                'description' => 'Contract fixture.',
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical' => [
                'path' => '/en/personality/big-five',
            ],
            'hreflang' => [],
            'faq' => [],
            'media' => [],
            'schema' => [
                '@type' => 'WebPage',
            ],
            'method_boundary' => [
                'summary' => 'Big Five is dimensional.',
            ],
            'evidence_notes' => [
                [
                    'source_type' => 'fixture',
                    'note' => 'Contract fixture.',
                ],
            ],
            'internal_links' => [],
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'draft',
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
        ], $overrides);
    }
}
