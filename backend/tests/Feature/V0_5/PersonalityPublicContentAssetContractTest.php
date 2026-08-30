<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Console\Commands\PersonalityPublicAssetsRetireMediaFields;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PersonalityPublicContentAssetContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fap.personality_current_authority_enabled' => false]);
        app(PublicCareerAuthorityResponseCache::class)->warm();
    }

    public function test_import_dry_run_validates_big_five_seed_without_writing(): void
    {
        $this->assertFalse(Schema::hasColumn('personality_public_content_assets', 'media_json'));

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

    public function test_retire_media_fields_command_is_dry_run_by_default_and_requires_exact_write_confirmation(): void
    {
        $asset = PersonalityPublicContentAsset::query()->create($this->assetAttributes());
        DB::table('personality_public_content_assets')->where('id', $asset->id)->update([
            'seo_json' => json_encode([
                'title' => 'Big Five Personality',
                'og_image_url' => 'https://assets.fermatmind.com/personality/big-five/legacy.webp',
                'twitter_card' => ['image_url' => 'https://assets.fermatmind.com/personality/big-five/twitter.webp'],
            ], JSON_THROW_ON_ERROR),
            'authority_json' => json_encode([
                'media_deferred_by_operator' => true,
                'media_authority' => ['hero' => null],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->artisan('personality-public-assets:retire-media-fields')
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('cleanup_required_count=1')
            ->expectsOutputToContain('updated_count=0')
            ->assertExitCode(0);
        $this->assertArrayHasKey('og_image_url', $asset->fresh()->seo_json);

        $this->artisan('personality-public-assets:retire-media-fields', ['--write' => true])
            ->expectsOutputToContain('Exact --confirm=')
            ->assertExitCode(1);

        $this->artisan('personality-public-assets:retire-media-fields', [
            '--write' => true,
            '--confirm' => PersonalityPublicAssetsRetireMediaFields::CONFIRMATION,
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('updated_count=1')
            ->assertExitCode(0);

        $asset = $asset->fresh();
        $this->assertArrayNotHasKey('og_image_url', $asset->seo_json);
        $this->assertArrayNotHasKey('image_url', $asset->seo_json['twitter_card']);
        $this->assertArrayNotHasKey('media_deferred_by_operator', $asset->authority_json);
        $this->assertArrayNotHasKey('media_authority', $asset->authority_json);
    }

    public function test_contract_rejects_forbidden_media_fields_even_when_their_values_are_empty(): void
    {
        $contract = app(PersonalityPublicContentAssetContract::class);
        $cases = [
            'media' => [$this->contractPayload(['media' => []]), 'media'],
            'media_json' => [$this->contractPayload(['media_json' => []]), 'media_json'],
            'media_authority' => [$this->contractPayload(['media_authority' => []]), 'media_authority'],
            'seo.og_image_url' => [$this->contractPayload([
                'seo' => [
                    'title' => 'Big Five Personality',
                    'description' => 'Contract fixture.',
                    'og_image_url' => null,
                ],
            ]), 'seo.og_image_url'],
            'authority.media' => [$this->contractPayload([
                'authority' => ['media' => []],
            ]), 'authority.media'],
            'authority.media_authority' => [$this->contractPayload([
                'authority' => ['media_authority' => []],
            ]), 'authority.media_authority'],
            'authority.media_deferred_by_operator' => [$this->contractPayload([
                'authority' => ['media_deferred_by_operator' => null],
            ]), 'authority.media_deferred_by_operator'],
        ];

        foreach ($cases as $case => [$payload, $expectedErrorKey]) {
            try {
                $contract->validateAsset($payload);
                $this->fail("Expected forbidden media field presence failure for {$case}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($expectedErrorKey, $exception->errors(), $case);
            }
        }
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
        $this->assertSame(0, PersonalityPublicContentAsset::query()
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_FACET)
            ->count());
        $this->assertSame(60, PersonalityPublicContentAsset::query()
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_FACET_DETAIL)
            ->count());

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
            ->assertJsonPath('pagination.total', 47)
            ->assertJsonCount(47, 'items')
            ->assertJsonPath('items.0.index_eligible', false)
            ->assertJsonPath('items.0.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('pagination.total', 47)
            ->assertJsonCount(47, 'items');

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&locale=en&entity_type=facet')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');

        $routes = $this->app['router']->getRoutes();
        $this->assertSame(
            'showByCode',
            $routes->match(\Illuminate\Http\Request::create('/api/v0.5/personality-content-assets/big_five/domain/openness'))->getActionMethod()
        );
        $this->assertSame(
            'showByCode',
            $routes->match(\Illuminate\Http\Request::create('/api/v0.5/personality-content-assets/enneagram/center/gut'))->getActionMethod()
        );
        $this->assertSame(
            'show',
            $routes->match(\Illuminate\Http\Request::create('/api/v0.5/personality-content-assets/big_five/big-five/openness'))->getActionMethod()
        );

        $this->getJson('/api/v0.5/personality-content-assets/big_five/domain/openness?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.code', 'openness')
            ->assertJsonPath('personality_public_content_asset_v1.entity_type', PersonalityPublicContentAsset::ENTITY_DOMAIN)
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)
            ->assertJsonPath('personality_public_content_asset_v1.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/facet/imagination?locale=en')
            ->assertNotFound();

        $this->getJson('/api/v0.5/personality-content-assets/big_five/facet_detail/imagination?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.entity_type', PersonalityPublicContentAsset::ENTITY_FACET_DETAIL)
            ->assertJsonPath('personality_public_content_asset_v1.code', 'imagination');

        $sitemapLocs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->implode("\n");

        $this->assertStringNotContainsString('/personality/big-five', $sitemapLocs);
    }

    public function test_import_preflights_persisted_slug_collision_before_any_write(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_HUB,
            'entity_key' => 'facets',
            'slug' => 'big-five/facets',
            'locale' => 'zh-CN',
            'title' => 'Existing Facet Hub',
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/zh/personality/big-five/facets'],
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
        ]));
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_DETAIL,
            'entity_key' => 'imagination',
            'slug' => 'big-five/facets/imagination',
            'locale' => 'zh-CN',
            'title' => 'Existing Imagination Detail',
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/zh/personality/big-five/facets/imagination'],
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
        ]));

        $source = $this->temporaryImportPackage([
            $this->contractPayload([
                'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_HUB,
                'code' => 'facets',
                'entity_key' => 'facets',
                'slug' => 'big-five/facets',
                'locale' => 'zh-CN',
                'title' => 'Updated Facet Hub',
                'canonical' => ['path' => '/zh/personality/big-five/facets'],
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            ]),
            $this->contractPayload([
                'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET,
                'code' => 'imagination',
                'entity_key' => 'imagination',
                'slug' => 'big-five/facets/imagination',
                'locale' => 'zh-CN',
                'title' => 'Incoming Imagination Facet',
                'canonical' => ['path' => '/zh/personality/big-five/facets/imagination'],
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            ]),
        ]);

        try {
            $this->artisan('personality-public-assets:import', ['--source' => $source])
                ->expectsOutputToContain('Persistence slug conflict')
                ->assertExitCode(1);
            $this->artisan('personality-public-assets:import', [
                '--source' => $source,
                '--write' => true,
            ])
                ->expectsOutputToContain('Persistence slug conflict')
                ->assertExitCode(1);
        } finally {
            @unlink($source);
        }

        $this->assertSame(2, PersonalityPublicContentAsset::query()->count());
        $this->assertSame(
            'Existing Facet Hub',
            PersonalityPublicContentAsset::query()->where('entity_key', 'facets')->value('title')
        );
        $this->assertSame(
            'Existing Imagination Detail',
            PersonalityPublicContentAsset::query()->where('entity_key', 'imagination')->value('title')
        );
    }

    public function test_public_asset_routes_disambiguate_typed_codes_from_bounded_slugs(): void
    {
        $bigFive = PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'published_at' => now(),
        ]));
        $enneagram = PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CENTER,
            'entity_key' => 'gut',
            'slug' => 'enneagram/centers/gut',
            'title' => 'Gut Center',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'published_at' => now(),
        ]));

        $this->getJson('/api/v0.5/personality-content-assets/big_five/domain/openness?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.id', $bigFive->id);
        $this->getJson('/api/v0.5/personality-content-assets/enneagram/center/gut?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.id', $enneagram->id);
        $this->getJson('/api/v0.5/personality-content-assets/big_five/big-five/openness?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.id', $bigFive->id)
            ->assertJsonPath('personality_public_content_asset_v1.slug', 'big-five/openness');

        $this->getJson('/api/v0.5/personality-content-assets/unknown/big-five/openness?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/personality-content-assets/big_five/big.five/openness?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/personality-content-assets/big_five/'.str_repeat('a', 161).'?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/personality-content-assets/big_five/not-an-entity/missing?locale=en')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss');
    }

    public function test_write_import_rolls_back_the_entire_package_when_a_later_write_fails(): void
    {
        $source = $this->temporaryImportPackage([
            $this->contractPayload([
                'code' => 'atomic-first',
                'entity_key' => 'atomic-first',
                'slug' => 'big-five/atomic-first',
                'title' => 'Atomic First',
                'canonical' => ['path' => '/en/personality/big-five/atomic-first'],
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            ]),
            $this->contractPayload([
                'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
                'code' => 'atomic-second',
                'entity_key' => 'atomic-second',
                'slug' => 'big-five/atomic-second',
                'title' => 'Atomic Second',
                'canonical' => ['path' => '/en/personality/big-five/atomic-second'],
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            ]),
        ]);
        $event = 'eloquent.creating: '.PersonalityPublicContentAsset::class;
        Event::listen($event, static function (PersonalityPublicContentAsset $asset): void {
            if ($asset->entity_key === 'atomic-second') {
                throw new \RuntimeException('forced second write failure');
            }
        });

        try {
            $this->artisan('personality-public-assets:import', [
                '--source' => $source,
                '--write' => true,
            ])
                ->expectsOutputToContain('forced second write failure')
                ->assertExitCode(1);
        } finally {
            Event::forget($event);
            @unlink($source);
        }

        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_import_does_not_persist_valid_subset_when_package_has_validation_errors(): void
    {
        $source = $this->temporaryImportPackage([
            $this->contractPayload([
                'code' => 'valid-subset',
                'entity_key' => 'valid-subset',
                'slug' => 'big-five/valid-subset',
                'title' => 'Valid Subset',
                'canonical' => ['path' => '/en/personality/big-five/valid-subset'],
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            ]),
            $this->contractPayload([
                'code' => 'invalid-subset',
                'entity_key' => 'invalid-subset',
                'slug' => 'big-five/invalid-subset',
                'title' => '',
                'canonical' => ['path' => '/en/personality/big-five/invalid-subset'],
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            ]),
        ]);

        try {
            $this->artisan('personality-public-assets:import', [
                '--source' => $source,
                '--write' => true,
            ])
                ->expectsOutputToContain('valid_count=1')
                ->expectsOutputToContain('errors_count=1')
                ->expectsOutputToContain('will_create=0')
                ->expectsOutputToContain('validation_errors=')
                ->assertExitCode(1);
        } finally {
            @unlink($source);
        }

        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_big_five_seed_has_expected_counts_parity_and_indexability(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('content_assets/personality_public/big_five_v1_seed.json')), true);
        $assets = collect(is_array($payload['assets'] ?? null) ? $payload['assets'] : []);

        $this->assertSame(154, $assets->count());
        $this->assertSame(['en' => 77, 'zh-CN' => 77], $assets->countBy('locale')->sortKeys()->all());
        $this->assertSame([
            'domain' => 10,
            'facet' => 60,
            'facet_detail' => 60,
            'facet_hub' => 2,
            'hub' => 2,
            'polarity' => 20,
        ], $assets->countBy('entity_type')->sortKeys()->all());

        $renderCandidates = $assets->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)
            ->whereNotIn('entity_type', [PersonalityPublicContentAsset::ENTITY_FACET_DETAIL])
            ->values();
        $facetStubs = $assets->where('entity_type', PersonalityPublicContentAsset::ENTITY_FACET)->values();
        $facetDetails = $assets->where('entity_type', PersonalityPublicContentAsset::ENTITY_FACET_DETAIL)->values();

        $this->assertSame(34, $renderCandidates->count());
        $this->assertSame(60, $facetStubs->count());
        $this->assertSame(60, $facetDetails->count());
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

        // facet_detail-specific assertions
        $this->assertTrue($facetDetails->every(fn (array $asset): bool => $asset['launch_state'] === PersonalityPublicContentAsset::LAUNCH_CONTENT_READY));
        $this->assertTrue($facetDetails->every(fn (array $asset): bool => $asset['robots'] === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW));
        $this->assertTrue($facetDetails->every(fn (array $asset): bool => $asset['index_eligible'] === false && $asset['sitemap_eligible'] === false && $asset['llms_eligible'] === false));
        $this->assertTrue($facetDetails->every(fn (array $asset): bool => count($asset['sections'] ?? []) >= 3));
        $this->assertTrue($facetDetails->every(fn (array $asset): bool => count($asset['faq'] ?? []) >= 1));
        $this->assertTrue($facetDetails->every(fn (array $asset): bool => count($asset['internal_links'] ?? []) >= 3));
        $this->assertTrue($facetDetails->every(function (array $asset): bool {
            $canonicalPath = (string) data_get($asset, 'canonical.path', '');
            $code = (string) ($asset['code'] ?? '');

            return $asset['locale'] === 'zh-CN'
                ? str_starts_with($canonicalPath, '/zh/personality/big-five/facets/')
                    && str_ends_with($canonicalPath, '/'.$code)
                : str_starts_with($canonicalPath, '/en/personality/big-five/facets/')
                    && str_ends_with($canonicalPath, '/'.$code);
        }));
        $this->assertTrue($facetDetails->every(fn (array $asset): bool => str_starts_with((string) ($asset['slug'] ?? ''), 'big-five/facets/')
            && str_ends_with((string) ($asset['slug'] ?? ''), (string) ($asset['code'] ?? ''))
        ));
        $facetDetailEnCodes = $facetDetails->where('locale', 'en')->pluck('code')->sort()->values()->all();
        $facetDetailZhCodes = $facetDetails->where('locale', 'zh-CN')->pluck('code')->sort()->values()->all();
        $this->assertSame($facetDetailEnCodes, $facetDetailZhCodes);
        $this->assertCount(30, $facetDetailEnCodes);

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

        $publicFacingText = $assets
            ->map(function (array $asset): string {
                $sections = collect((array) ($asset['sections'] ?? []))
                    ->flatMap(static fn (array $section): array => [
                        (string) ($section['title'] ?? ''),
                        (string) ($section['body'] ?? ''),
                        (string) ($section['body_md'] ?? ''),
                    ]);
                $faq = collect((array) ($asset['faq'] ?? []))
                    ->flatMap(static fn (array $entry): array => [
                        (string) ($entry['question'] ?? ''),
                        (string) ($entry['answer'] ?? ''),
                    ]);
                $evidenceNotes = collect((array) ($asset['evidence_notes'] ?? []))
                    ->map(static fn (mixed $entry): string => is_array($entry)
                        ? (string) ($entry['note'] ?? '')
                        : (string) $entry);

                return collect([
                    (string) ($asset['title'] ?? ''),
                    (string) ($asset['summary'] ?? ''),
                    (string) data_get($asset, 'seo.title', ''),
                    (string) data_get($asset, 'seo.description', ''),
                    (string) data_get($asset, 'media.alt', ''),
                    (string) data_get($asset, 'method_boundary.summary', ''),
                ])
                    ->merge($sections)
                    ->merge($faq)
                    ->merge($evidenceNotes)
                    ->implode("\n");
            })
            ->implode("\n");

        foreach ([
            '公共内容包',
            'content package',
            'CMS',
            'seed',
            'render candidate',
        ] as $forbiddenPublicTerm) {
            $this->assertStringNotContainsString($forbiddenPublicTerm, $publicFacingText);
        }

        $tokenize = static function (array $asset): array {
            $sections = collect((array) ($asset['sections'] ?? []))
                ->flatMap(static fn (array $section): array => [
                    (string) ($section['title'] ?? ''),
                    (string) ($section['body'] ?? ''),
                    (string) ($section['body_md'] ?? ''),
                ]);
            $faq = collect((array) ($asset['faq'] ?? []))
                ->flatMap(static fn (array $entry): array => [
                    (string) ($entry['question'] ?? ''),
                    (string) ($entry['answer'] ?? ''),
                ]);
            $text = collect([
                (string) ($asset['title'] ?? ''),
                (string) ($asset['summary'] ?? ''),
                (string) data_get($asset, 'seo.title', ''),
                (string) data_get($asset, 'seo.description', ''),
            ])
                ->merge($sections)
                ->merge($faq)
                ->implode(' ');

            $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
            $stopWords = [
                'the' => true,
                'and' => true,
                'for' => true,
                'with' => true,
                'this' => true,
                'that' => true,
                'not' => true,
                'can' => true,
                'you' => true,
                'are' => true,
                'big' => true,
                'five' => true,
                '人格' => true,
                '大五' => true,
                '继续' => true,
                '查看' => true,
                '本页' => true,
                '解释' => true,
            ];

            return collect($parts)
                ->filter(static fn (string $part): bool => mb_strlen($part) > 2 && ! isset($stopWords[$part]))
                ->unique()
                ->values()
                ->all();
        };

        $jaccard = static function (array $left, array $right): float {
            $leftSet = array_fill_keys($left, true);
            $rightSet = array_fill_keys($right, true);
            $intersection = count(array_intersect_key($leftSet, $rightSet));
            $union = count($leftSet + $rightSet);

            return $union > 0 ? $intersection / $union : 0.0;
        };

        foreach (['en', 'zh-CN'] as $locale) {
            $reviewable = $renderCandidates
                ->where('locale', $locale)
                ->whereIn('entity_type', [
                    PersonalityPublicContentAsset::ENTITY_DOMAIN,
                    PersonalityPublicContentAsset::ENTITY_POLARITY,
                ])
                ->values();

            for ($left = 0; $left < $reviewable->count(); $left++) {
                for ($right = $left + 1; $right < $reviewable->count(); $right++) {
                    $leftAsset = $reviewable[$left];
                    $rightAsset = $reviewable[$right];
                    $similarity = $jaccard($tokenize($leftAsset), $tokenize($rightAsset));

                    $this->assertLessThan(
                        0.72,
                        $similarity,
                        sprintf(
                            'Big Five public content duplicate risk too high for %s %s vs %s: %.3f',
                            $locale,
                            (string) ($leftAsset['code'] ?? ''),
                            (string) ($rightAsset['code'] ?? ''),
                            $similarity
                        )
                    );
                }
            }
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

    public function test_published_indexable_big_five_asset_enters_sitemap_only_with_all_discoverability_flags(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subMinute(),
            'schema_json' => [
                '@type' => 'WebPage',
                'name' => 'Published indexable Big Five page',
            ],
        ]));

        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'entity_key' => 'big-five-zh',
            'locale' => 'zh-CN',
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => [
                'path' => '/zh/personality/big-five',
            ],
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'index_eligible' => false,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
        ]));

        $publishedAsset = PersonalityPublicContentAsset::query()
            ->where('locale', 'en')
            ->firstOrFail();
        $draftReviewAsset = PersonalityPublicContentAsset::query()
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertTrue((bool) $publishedAsset->sitemap_eligible);
        $this->assertTrue((bool) $publishedAsset->llms_eligible);
        $this->assertFalse((bool) $draftReviewAsset->sitemap_eligible);
        $this->assertFalse((bool) $draftReviewAsset->llms_eligible);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.index_eligible', true)
            ->assertJsonPath('personality_public_content_asset_v1.sitemap_eligible', true)
            ->assertJsonPath('personality_public_content_asset_v1.llms_eligible', true)
            ->assertJsonPath('personality_public_content_asset_v1.schema_runtime_eligible', true);

        $sitemapLocs = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->implode("\n");

        $this->assertStringContainsString('https://fermatmind.com/en/personality/big-five', $sitemapLocs);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/personality/big-five', $sitemapLocs);
    }

    public function test_noindex_content_ready_big_five_asset_suppresses_runtime_schema(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'index_eligible' => false,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'schema_json' => [
                '@type' => 'WebPage',
                'name' => 'Draft Big Five page',
            ],
        ]));

        $asset = PersonalityPublicContentAsset::query()->firstOrFail();
        $this->assertFalse((bool) $asset->sitemap_eligible);
        $this->assertFalse((bool) $asset->llms_eligible);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)
            ->assertJsonPath('personality_public_content_asset_v1.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)
            ->assertJsonPath('personality_public_content_asset_v1.index_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.sitemap_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.llms_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.schema_runtime_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.schema', []);
    }

    public function test_public_api_normalizes_enneagram_alias_fields_and_rejects_unsafe_links(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'enneagram',
            'slug' => 'enneagram',
            'title' => 'Enneagram',
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/en/personality/enneagram'],
            'faq_json' => [
                ['q' => 'Alias question?', 'a' => 'Alias answer.'],
                ['question' => 'Canonical question?', 'answer' => 'Canonical answer.'],
                ['q' => '', 'a' => 'Missing question.'],
                ['q' => 'Missing answer?', 'a' => ''],
                'invalid',
            ],
            'internal_links_json' => [
                ['label' => 'Alias link', 'url' => '/en/personality/enneagram/type-1'],
                ['label' => 'Canonical link', 'href' => 'https://www.fermatmind.com/en/personality/enneagram/type-2?source=cms#overview'],
                ['label' => 'Alias fallback', 'href' => '', 'url' => '/en/personality/enneagram/type-3'],
                ['label' => 'Page section', 'href' => '#overview'],
                ['label' => 'External link', 'url' => 'https://example.com/not-internal'],
                ['label' => 'Unsafe scheme', 'url' => 'javascript:alert(1)'],
                ['label' => 'Protocol relative', 'url' => '//example.com/path'],
                ['label' => 'Backslash', 'url' => '/en\\personality\\enneagram'],
                ['label' => 'Invalid anchor', 'url' => '#bad anchor'],
                ['label' => '', 'url' => '/en/personality/enneagram/type-3'],
                'invalid',
            ],
            'is_public' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
        ]));

        $response = $this->getJson('/api/v0.5/personality-content-assets/enneagram/hub/enneagram?locale=en')
            ->assertOk();

        $this->assertSame([
            ['question' => 'Alias question?', 'answer' => 'Alias answer.'],
            ['question' => 'Canonical question?', 'answer' => 'Canonical answer.'],
        ], $response->json('personality_public_content_asset_v1.faq'));
        $this->assertSame([
            ['label' => 'Alias link', 'href' => '/en/personality/enneagram/type-1'],
            ['label' => 'Canonical link', 'href' => '/en/personality/enneagram/type-2?source=cms#overview'],
            ['label' => 'Alias fallback', 'href' => '/en/personality/enneagram/type-3'],
            ['label' => 'Page section', 'href' => '#overview'],
        ], $response->json('personality_public_content_asset_v1.internal_links'));
    }

    public function test_public_api_preserves_canonical_big_five_faq_and_internal_link_contract(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'faq_json' => [
                ['question' => 'What does Big Five describe?', 'answer' => 'It describes broad trait dimensions.'],
            ],
            'internal_links_json' => [
                [
                    'label' => 'Openness',
                    'href' => '/en/personality/big-five/openness',
                    'relationship' => 'dimension',
                    'target_code' => 'openness',
                ],
            ],
            'is_public' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
        ]));

        $response = $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=en')
            ->assertOk();

        $this->assertSame([
            ['question' => 'What does Big Five describe?', 'answer' => 'It describes broad trait dimensions.'],
        ], $response->json('personality_public_content_asset_v1.faq'));
        $this->assertSame([
            [
                'label' => 'Openness',
                'href' => '/en/personality/big-five/openness',
                'relationship' => 'dimension',
                'target_code' => 'openness',
            ],
        ], $response->json('personality_public_content_asset_v1.internal_links'));
    }

    public function test_public_api_removes_trailing_brand_from_big_five_metadata_only(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'title' => 'Big Five range | FermatMind',
            'seo_json' => [
                'title' => 'Big Five range | FermatMind',
                'description' => 'A dimensional Big Five range guide.',
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'is_public' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
        ]));

        $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.title', 'Big Five range')
            ->assertJsonPath('personality_public_content_asset_v1.seo.title', 'Big Five range');
    }

    public function test_big_five_v2_public_contract_projects_structured_visible_evidence_and_keeps_v1(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved',
            'index_eligible' => true,
            'published_at' => now()->subDay(),
            'last_reviewed_at' => now()->subHour(),
            'seo_json' => [
                'title' => 'Big Five Personality',
                'description' => 'Published fixture.',
                'og_image_url' => 'https://assets.fermatmind.com/personality/big-five/legacy-og.webp',
                'twitter' => ['image' => 'https://assets.fermatmind.com/personality/big-five/legacy-twitter.webp'],
            ],
            'authority_json' => [
                'sources' => [[
                    'id' => 'bfi2-2017',
                    'title' => 'The Big Five Inventory–2',
                    'author_or_organization' => 'Soto and John',
                    'year' => 2017,
                    'source_type' => 'peer_reviewed_research',
                    'doi' => '10.1037/pspp0000096',
                    'public_url' => 'https://doi.org/10.1037/pspp0000096',
                    'accessed_at' => '2026-07-14',
                    'claim_ids' => ['claim.big_five_dimensions'],
                    'limitation' => 'This source does not make an individual diagnosis.',
                ], [
                    'id' => 'private-source-projection-guard',
                    'title' => 'Stored source requiring read-path sanitization',
                    'author_or_organization' => 'Contract fixture',
                    'year' => 2026,
                    'source_type' => 'other_public_source',
                    'doi' => null,
                    'public_url' => 'https://127.0.0.1/private',
                    'accessed_at' => '2026-07-14',
                    'claim_ids' => ['claim.projection_guard'],
                    'limitation' => 'Synthetic fixture; not publishable evidence.',
                ]],
                'claim_mapping' => [[
                    'claim_id' => 'claim.big_five_dimensions',
                    'source_ids' => ['bfi2-2017'],
                    'limitation' => 'Trait dimensions are descriptive, not deterministic.',
                ]],
                'limitations' => ['Public evidence supports model framing, not individual outcome prediction.'],
                'author' => [
                    'name' => 'FermatMind Editorial Team',
                    'organization' => 'FermatMind',
                    'role' => 'Author',
                ],
                'reviewer' => [
                    'name' => 'Named Reviewer',
                    'organization' => 'Independent Review',
                    'role' => 'Reviewer',
                ],
                'visible_evidence_eligible' => true,
                'schema_eligible' => true,
            ],
            'schema_json' => [
                '@type' => 'WebPage',
                'name' => 'Big Five Personality',
            ],
        ]));

        $response = $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.contract_version', PersonalityPublicContentAsset::CONTRACT_VERSION_V1)
            ->assertJsonPath('personality_public_content_asset_v2.contract_version', PersonalityPublicContentAsset::CONTRACT_VERSION_V2)
            ->assertJsonPath('personality_public_content_asset_v2.compatible_v1_contract_version', PersonalityPublicContentAsset::CONTRACT_VERSION_V1)
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.eligible', true)
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.sources.0.id', 'bfi2-2017')
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.sources.0.doi', '10.1037/pspp0000096')
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.sources.1.public_url', null)
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.claim_mapping.0.claim_id', 'claim.big_five_dimensions')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.author.name', 'FermatMind Editorial Team')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.review_state', 'approved')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null)
            ->assertJsonPath('personality_public_content_asset_v2.schema_eligible', true);

        $this->assertArrayNotHasKey('visible_evidence', $response->json('personality_public_content_asset_v1'));
        $this->assertArrayNotHasKey('media', $response->json('personality_public_content_asset_v1'));
        $this->assertArrayNotHasKey('media_authority', $response->json('personality_public_content_asset_v2'));
        $this->assertArrayNotHasKey('og_image_url', $response->json('personality_public_content_asset_v1.seo'));
        $this->assertArrayNotHasKey('image', $response->json('personality_public_content_asset_v1.seo.twitter'));
        $this->assertStringNotContainsString('Named Reviewer', $response->getContent());
        $this->assertStringNotContainsString('Independent Review', $response->getContent());
    }

    public function test_detail_cache_version_and_response_boundary_redact_legacy_reviewer_projections(): void
    {
        $asset = PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'human_review_approved',
            'is_public' => true,
            'published_at' => now()->subDay(),
            'last_reviewed_at' => now()->subHour(),
        ]));
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $baseVersion = $cache->versionFor($asset);
        $legacyPayload = [
            'ok' => true,
            'personality_public_content_asset_v1' => [
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'framework' => 'big_five',
                'entity_type' => 'hub',
                'code' => 'big-five',
                'locale' => 'en',
                'review_state' => 'human_review_approved',
                'last_reviewed_at' => $asset->last_reviewed_at?->toISOString(),
            ],
            'personality_public_content_asset_v2' => [
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
                'compatible_v1_contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'visible_evidence' => [],
                'editorial_authority' => [
                    'review_state' => 'human_review_approved',
                    'last_reviewed_at' => $asset->last_reviewed_at?->toISOString(),
                    'reviewer' => [
                        'name' => 'Legacy Cached Reviewer',
                        'organization' => 'Private Review Organization',
                        'role' => 'Reviewer',
                    ],
                ],
                'schema_eligible' => false,
            ],
        ];

        $cache->put('detail-code', 'big_five', 'hub', 'big-five', 'en', 0, $baseVersion, $legacyPayload);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.review_state', 'approved')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null);

        $cache->put(
            'detail-code',
            'big_five',
            'hub',
            'big-five',
            'en',
            0,
            $baseVersion.':projection:public-review-contract-v1',
            $legacyPayload,
        );

        $response = $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh')
            ->assertJsonPath('personality_public_content_asset_v1.review_state', 'approved')
            ->assertJsonPath('personality_public_content_asset_v1.reviewer', null)
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.review_state', 'approved')
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null);

        $this->assertStringNotContainsString('Legacy Cached Reviewer', $response->getContent());
        $this->assertStringNotContainsString('Private Review Organization', $response->getContent());
    }

    public function test_index_cache_version_and_response_boundary_redact_legacy_review_projections(): void
    {
        $asset = PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'human_review_approved',
            'is_public' => true,
            'published_at' => now()->subDay(),
            'last_reviewed_at' => now()->subHour(),
        ]));
        $cache = app(PersonalityPublicAssetReadModelCache::class);
        $pagination = [
            'current_page' => 1,
            'per_page' => 20,
            'total' => 1,
            'last_page' => 1,
        ];
        $baseVersion = $cache->collectionVersion([$asset], $pagination);
        $legacyPayload = [
            'ok' => true,
            'items' => [[
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'framework' => 'big_five',
                'entity_type' => 'hub',
                'code' => 'big-five',
                'locale' => 'en',
                'review_state' => 'human_review_approved',
                'last_reviewed_at' => $asset->last_reviewed_at?->toISOString(),
                'reviewer' => ['name' => 'Legacy Cached Index Reviewer'],
            ]],
            'pagination' => $pagination,
        ];

        $cache->put('index', 'big_five', 'hub', 'page:1:per-page:20', 'en', 0, $baseVersion, $legacyPayload);

        $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&entity_type=hub&locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'miss')
            ->assertJsonPath('items.0.review_state', 'approved')
            ->assertJsonPath('items.0.reviewer', null);

        $cache->put(
            'index',
            'big_five',
            'hub',
            'page:1:per-page:20',
            'en',
            0,
            $baseVersion.':projection:public-review-contract-v1',
            $legacyPayload,
        );

        $response = $this->getJson('/api/v0.5/personality-content-assets?framework=big_five&entity_type=hub&locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh')
            ->assertJsonPath('items.0.review_state', 'approved')
            ->assertJsonPath('items.0.reviewer', null);

        $this->assertStringNotContainsString('Legacy Cached Index Reviewer', $response->getContent());
    }

    public function test_big_five_v2_projection_fails_closed_for_legacy_authority_and_does_not_fabricate_people(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'evidence_notes_json' => ['Legacy narrative evidence note.'],
            'authority_json' => [],
        ]));

        $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.sources', [])
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.claim_mapping', [])
            ->assertJsonPath('personality_public_content_asset_v2.visible_evidence.eligible', false)
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.author', null)
            ->assertJsonPath('personality_public_content_asset_v2.editorial_authority.reviewer', null)
            ->assertJsonPath('personality_public_content_asset_v2.schema_eligible', false);
    }

    public function test_enneagram_detail_contract_preserves_v1_and_adds_fail_closed_v2(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_key' => 'enneagram',
            'slug' => 'enneagram',
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/en/personality/enneagram'],
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
        ]));

        $response = $this->getJson('/api/v0.5/personality-content-assets/enneagram/hub/enneagram?locale=en')
            ->assertOk();

        $this->assertArrayHasKey('personality_public_content_asset_v1', $response->json());
        $this->assertSame(
            PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            $response->json('personality_public_content_asset_v1.contract_version')
        );
        $this->assertSame(
            PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            $response->json('personality_public_content_asset_v2.contract_version')
        );
        $this->assertFalse((bool) $response->json('personality_public_content_asset_v2.visible_evidence.eligible'));
        $this->assertNull($response->json('personality_public_content_asset_v2.editorial_authority.reviewer'));
        $this->assertFalse((bool) $response->json('personality_public_content_asset_v2.schema_eligible'));
    }

    public function test_v2_contract_validates_structured_authority_without_enabling_gates_implicitly(): void
    {
        $contract = app(PersonalityPublicContentAssetContract::class);
        $data = $contract->validateAsset($this->contractPayload([
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'authority' => [
                'sources' => [[
                    'id' => 'ipip-public',
                    'title' => 'International Personality Item Pool',
                    'author_or_organization' => 'IPIP',
                    'year' => 2026,
                    'source_type' => 'official_documentation',
                    'doi' => null,
                    'public_url' => 'https://ipip.ori.org/',
                    'accessed_at' => '2026-07-14',
                    'claim_ids' => ['claim.item_pool'],
                    'limitation' => null,
                ]],
                'claim_mapping' => [[
                    'claim_id' => 'claim.item_pool',
                    'source_ids' => ['ipip-public'],
                    'limitation' => null,
                ]],
                'limitations' => [],
                'author' => null,
                'reviewer' => null,
                'visible_evidence_eligible' => false,
                'schema_eligible' => false,
            ],
        ]));

        $this->assertSame(PersonalityPublicContentAsset::CONTRACT_VERSION_V2, $data->contractVersion);
        $this->assertSame('ipip-public', data_get($data->toModelAttributes(), 'authority_json.sources.0.id'));
        $this->assertFalse((bool) data_get($data->toModelAttributes(), 'authority_json.visible_evidence_eligible'));
        $this->assertFalse((bool) data_get($data->toModelAttributes(), 'authority_json.schema_eligible'));
    }

    public function test_v2_contract_rejects_unmapped_visible_evidence_unsupported_media_and_v1_authority(): void
    {
        $contract = app(PersonalityPublicContentAssetContract::class);

        foreach ([
            $this->contractPayload([
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'authority' => ['sources' => []],
            ]),
            $this->contractPayload([
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
                'authority' => [
                    'sources' => [],
                    'claim_mapping' => [],
                    'visible_evidence_eligible' => true,
                    'schema_eligible' => false,
                ],
            ]),
            $this->contractPayload([
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
                'authority' => [
                    'sources' => [[
                        'id' => 'unsafe-source',
                        'title' => 'Unsafe source',
                        'author_or_organization' => 'Unknown',
                        'year' => 2026,
                        'source_type' => 'other_public_source',
                        'public_url' => 'http://127.0.0.1/private',
                        'claim_ids' => ['claim.unsafe'],
                    ]],
                    'claim_mapping' => [[
                        'claim_id' => 'claim.unsafe',
                        'source_ids' => ['missing-source'],
                    ]],
                    'visible_evidence_eligible' => false,
                    'schema_eligible' => false,
                ],
                'media' => [
                    'hero' => [
                        'url' => 'https://example.com/unapproved.webp',
                        'alt' => 'Unsafe host.',
                    ],
                ],
            ]),
            $this->contractPayload([
                'seo' => [
                    'title' => 'Big Five Personality',
                    'description' => 'Unsupported social image field.',
                    'og_image_url' => 'https://assets.fermatmind.com/personality/big-five/og.webp',
                ],
            ]),
            $this->contractPayload([
                'content_sections' => [[
                    'key' => 'unsupported_markdown_image',
                    'body_md' => '![Unsupported](https://assets.fermatmind.com/personality/big-five/inline.webp)',
                ]],
            ]),
            $this->contractPayload([
                'content_sections' => [[
                    'key' => 'unsupported_html_image',
                    'body_html' => '<p>Copy</p><img src="https://assets.fermatmind.com/personality/big-five/inline.webp" alt="Unsupported">',
                ]],
            ]),
        ] as $payload) {
            try {
                $contract->validateAsset($payload);
                $this->fail('Expected V2 authority validation failure.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_published_non_indexable_big_five_asset_suppresses_runtime_schema(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'index_eligible' => false,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subMinute(),
            'schema_json' => [
                '@type' => 'WebPage',
                'name' => 'Published but noindex Big Five page',
            ],
        ]));

        $asset = PersonalityPublicContentAsset::query()->firstOrFail();
        $this->assertFalse((bool) $asset->sitemap_eligible);
        $this->assertFalse((bool) $asset->llms_eligible);

        $this->getJson('/api/v0.5/personality-content-assets/big_five/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)
            ->assertJsonPath('personality_public_content_asset_v1.robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)
            ->assertJsonPath('personality_public_content_asset_v1.index_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.sitemap_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.llms_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.schema_runtime_eligible', false)
            ->assertJsonPath('personality_public_content_asset_v1.schema', []);
    }

    public function test_published_indexable_big_five_asset_can_emit_runtime_schema(): void
    {
        PersonalityPublicContentAsset::query()->create($this->assetAttributes([
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'index_eligible' => true,
            'published_at' => now()->subMinute(),
            'schema_json' => [
                '@type' => 'WebPage',
                'name' => 'Published indexable Big Five page',
            ],
        ]));

        $this->getJson('/api/v0.5/personality-content-assets/big_five/big-five?locale=en')
            ->assertOk()
            ->assertJsonPath('personality_public_content_asset_v1.launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)
            ->assertJsonPath('personality_public_content_asset_v1.robots', PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW)
            ->assertJsonPath('personality_public_content_asset_v1.index_eligible', true)
            ->assertJsonPath('personality_public_content_asset_v1.schema_runtime_eligible', true)
            ->assertJsonPath('personality_public_content_asset_v1.schema.@type', 'WebPage')
            ->assertJsonPath('personality_public_content_asset_v1.schema.name', 'Published indexable Big Five page');
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
            'authority_json' => [],
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

    /**
     * @param  list<array<string,mixed>>  $assets
     */
    private function temporaryImportPackage(array $assets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'personality-public-assets-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary personality asset package.');
        }

        file_put_contents($path, json_encode([
            'package' => 'personality-public-assets-atomicity-test',
            'assets' => $assets,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }
}
