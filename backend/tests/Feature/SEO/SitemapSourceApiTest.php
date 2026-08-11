<?php

namespace Tests\Feature\SEO;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Models\PersonalityProfile;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Career\CareerGenerationAuthorityFixture;
use Tests\TestCase;

class SitemapSourceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        File::deleteDirectory(storage_path('app/private/career_generation_authority'));
        app(PublicCareerAuthorityResponseCache::class)->warm();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/career_generation_authority'));

        parent::tearDown();
    }

    public function test_sitemap_source_api_returns_backend_sitemap_generator_urls(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('agricultural-inspectors', 'Agricultural Inspectors'),
            ['updated_at' => Carbon::create(2026, 1, 31, 12, 55, 0)]
        );
        $this->createDisplayAsset(
            $this->createOccupation('software-developers', 'Software Developers'),
            ['updated_at' => Carbon::create(2026, 1, 31, 12, 56, 0)]
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('agricultural-inspectors', 'en'),
            $this->projectionItem('agricultural-inspectors', 'zh'),
            $this->projectionItem(
                'software-developers',
                'en',
                CareerRuntimePublishProjectionService::STATE_QUARANTINED,
                [
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    'detail_route_enabled' => false,
                    'sitemap_live' => false,
                    'robots_indexable' => false,
                    'release_gate_pass' => false,
                    'canonical_self' => false,
                    'canonical_url' => null,
                ],
            ),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response
            ->assertOk()
            ->assertHeader('X-Fermat-Cache', 'hit')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('source', 'backend_sitemap_generator');

        $locs = collect($response->json('items'))->pluck('loc')->all();

        $this->assertContains('https://staging.fermatmind.com/en/career/jobs/agricultural-inspectors', $locs);
        $this->assertContains('https://staging.fermatmind.com/zh/career/jobs/agricultural-inspectors', $locs);
        $this->assertNotContains('https://staging.fermatmind.com/en/career/jobs/software-developers', $locs);
        $this->assertNotContains('https://staging.fermatmind.com/zh/career/jobs/software-developers', $locs);
        $this->assertSame(count($locs), $response->json('count'));
    }

    public function test_sitemap_source_only_exports_runtime_published_career_job_detail_urls(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $this->createCareerJob('backend-engineer', 'Backend Engineer', 'en');
        $this->createCareerJob('backend-engineer', 'Backend Engineer', 'zh-CN');
        $this->createCareerJob('software-engineer', 'Software Engineer', 'en');
        $this->createCareerJob('software-engineer', 'Software Engineer', 'zh-CN');

        $this->createDisplayAsset(
            $this->createOccupation('data-scientists', 'Data Scientists'),
            ['updated_at' => Carbon::create(2026, 1, 31, 12, 57, 0)]
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('data-scientists', 'en'),
            $this->projectionItem(
                'data-scientists',
                'zh',
                CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
                [
                    'detail_route_enabled' => false,
                    'dataset_visible' => false,
                    'search_visible' => false,
                    'sitemap_live' => false,
                    'llms_live' => false,
                    'llms_full_live' => false,
                ],
            ),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk();
        $locs = collect($response->json('items'))->pluck('loc')->all();

        $this->assertContains('https://fermatmind.com/en/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://www.fermatmind.com/en/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://www.fermatmind.com/zh/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://fermatmind.com/en/career/jobs/backend-engineer', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs/backend-engineer', $locs);
        $this->assertNotContains('https://fermatmind.com/en/career/jobs/software-engineer', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs/software-engineer', $locs);
    }

    public function test_sitemap_source_warm_includes_released_zh_big_five_public_content_assets(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        foreach (['openness', 'openness-high', 'neuroticism-low'] as $slug) {
            $this->createBigFivePublicContentAsset(
                $slug,
                str_contains($slug, '-') ? PersonalityPublicContentAsset::ENTITY_POLARITY : PersonalityPublicContentAsset::ENTITY_DOMAIN,
            );
        }

        $this->createBigFivePublicContentAsset('openness-en', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'entity_key' => 'openness',
            'slug' => 'big-five/openness-en',
            'locale' => 'en',
            'canonical_json' => ['path' => '/en/personality/big-five/openness'],
        ]);
        $this->createBigFivePublicContentAsset('openness-noindex', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'canonical_json' => ['path' => '/zh/personality/big-five/openness-noindex'],
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');
        $response->assertOk();

        $locs = collect($response->json('items'))->pluck('loc')->all();

        $this->assertContains('https://fermatmind.com/zh/personality/big-five/openness', $locs);
        $this->assertContains('https://fermatmind.com/zh/personality/big-five/openness-high', $locs);
        $this->assertContains('https://fermatmind.com/zh/personality/big-five/neuroticism-low', $locs);
        $this->assertContains('https://fermatmind.com/en/personality/big-five/openness', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/personality/big-five/openness-noindex', $locs);
    }

    public function test_sitemap_source_exports_only_canonical_public_mbti_base_routes(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        foreach (['INTJ', 'ENFP'] as $typeCode) {
            foreach (PersonalityProfile::SUPPORTED_LOCALES as $locale) {
                PersonalityProfile::query()->create([
                    'org_id' => 0,
                    'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                    'type_code' => $typeCode,
                    'canonical_type_code' => $typeCode,
                    'slug' => strtolower($typeCode),
                    'locale' => $locale,
                    'title' => $typeCode.' Personality Type',
                    'status' => 'published',
                    'is_public' => true,
                    'is_indexable' => true,
                    'published_at' => now()->subMinute(),
                    'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                ]);
            }
        }

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $locs = collect($this->getJson('/api/v0.5/seo/sitemap-source')
            ->assertOk()
            ->json('items'))
            ->pluck('loc')
            ->filter(static fn (string $location): bool => preg_match('#/(?:en|zh)/personality/[a-z]{4}$#', $location) === 1)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'https://fermatmind.com/en/personality/enfp',
            'https://fermatmind.com/en/personality/intj',
            'https://fermatmind.com/zh/personality/enfp',
            'https://fermatmind.com/zh/personality/intj',
        ], $locs);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeProjectionArtifact(array $items): void
    {
        CareerGenerationAuthorityFixture::write($items);

        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach ($items as $item) {
            if (! is_array($item)
                || ($item['runtime_publish_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED
                || ($item['detail_route_enabled'] ?? false) !== true
                || ($item['release_gate_pass'] ?? false) !== true) {
                continue;
            }

            $cache->warmJobDetailPayload(
                (string) ($item['slug'] ?? ''),
                (string) ($item['locale'] ?? 'en'),
                true,
            );
        }

        $cache->warm();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function projectionItem(
        string $slug,
        string $locale,
        string $state = CareerRuntimePublishProjectionService::STATE_PUBLISHED,
        array $overrides = [],
    ): array {
        $published = $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED;

        return array_merge([
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'runtime_publish_state' => $state,
            'detail_route_enabled' => $published,
            'dataset_visible' => $published,
            'search_visible' => $published,
            'sitemap_live' => $published,
            'llms_live' => $published,
            'llms_full_live' => $published,
            'canonical_url' => $published ? 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug : null,
            'canonical_self' => $published,
            'robots_indexable' => $published,
            'release_gate_pass' => $published,
            'blockers' => [],
        ], $overrides);
    }

    private function createOccupation(string $slug, string $title): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'family-'.$slug,
            'title_en' => $title,
            'title_zh' => $title,
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
            'search_h1_zh' => $title,
            'structural_stability' => null,
            'task_prototype_signature' => [],
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => [],
            'created_at' => Carbon::create(2026, 1, 31, 12, 54, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 54, 0),
        ]);
    }

    private function createCareerJob(string $slug, string $title, string $locale): CareerJob
    {
        return CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => $slug,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'excerpt' => $title,
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 12, 45, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_at' => Carbon::create(2026, 1, 31, 12, 44, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 45, 0),
        ]);
    }

    public function test_sitemap_source_response_contract_matches_fap_web(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('civil-engineers', 'Civil Engineers'),
            ['updated_at' => Carbon::create(2026, 2, 1, 12, 55, 0)]
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('civil-engineers', 'en'),
            $this->projectionItem('civil-engineers', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');
        $response->assertOk();

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
        $this->assertArrayHasKey('source', $data);
        $this->assertSame('backend_sitemap_generator', $data['source']);
        $this->assertArrayHasKey('count', $data);
        $this->assertIsInt($data['count']);
        $this->assertArrayHasKey('items', $data);
        $this->assertIsArray($data['items']);
        $this->assertCount($data['count'], $data['items']);

        foreach ($data['items'] as $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey('loc', $item);
            $this->assertArrayHasKey('lastmod', $item);
            $this->assertIsString($item['loc']);
            $this->assertNotEmpty($item['loc']);
            $this->assertMatchesRegularExpression(
                '#^https?://[^/]+/#',
                $item['loc'],
                "Each loc must be an absolute URL: {$item['loc']}"
            );
        }

        $locs = collect($data['items'])->pluck('loc')->all();
        $this->assertContains('https://fermatmind.com/', $locs);
        $this->assertContains('https://fermatmind.com/en', $locs);
        $this->assertContains('https://fermatmind.com/en/business', $locs);
        $this->assertContains('https://fermatmind.com/en/career', $locs);
        $this->assertContains('https://fermatmind.com/en/career/guides', $locs);
        $this->assertContains('https://fermatmind.com/en/career/recommendations', $locs);
        $this->assertContains('https://fermatmind.com/en/career/tests', $locs);
        $this->assertContains('https://fermatmind.com/en/support', $locs);
        $this->assertContains('https://fermatmind.com/en/tests', $locs);
        $this->assertContains('https://fermatmind.com/en/tests/category/career', $locs);
        $this->assertContains('https://fermatmind.com/en/tests/category/personality', $locs);
        $this->assertContains('https://fermatmind.com/zh/business', $locs);
        $this->assertContains('https://fermatmind.com/zh/career', $locs);
        $this->assertContains('https://fermatmind.com/zh/career/guides', $locs);
        $this->assertContains('https://fermatmind.com/zh/career/recommendations', $locs);
        $this->assertContains('https://fermatmind.com/zh/career/tests', $locs);
        $this->assertContains('https://fermatmind.com/zh/support', $locs);
        $this->assertContains('https://fermatmind.com/zh/tests', $locs);
        $this->assertContains('https://fermatmind.com/zh/tests/category/career', $locs);
        $this->assertContains('https://fermatmind.com/zh/tests/category/personality', $locs);
        $this->assertContains('https://fermatmind.com/en/career/jobs/civil-engineers', $locs);
        $this->assertContains('https://fermatmind.com/zh/career/jobs/civil-engineers', $locs);
        $this->assertNotContains('https://fermatmind.com/datasets/occupations', $locs);
        $this->assertNotContains('https://fermatmind.com/datasets/occupations/method', $locs);
        $this->assertNotContains('https://fermatmind.com/en/career/jobs', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs', $locs);
    }

    public function test_sitemap_source_excludes_forbidden_url_patterns(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('mechanical-engineers', 'Mechanical Engineers'),
            ['updated_at' => Carbon::create(2026, 2, 1, 12, 55, 0)]
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('mechanical-engineers', 'en'),
            $this->projectionItem('mechanical-engineers', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');
        $response->assertOk();

        $locs = collect($response->json('items'))->pluck('loc')->all();

        $forbiddenPatterns = [
            '#/(result|order|pay|share|take|report|checkout|personalized|private)/#i',
            '#/me/#i',
        ];

        foreach ($locs as $loc) {
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $loc,
                    "Sitemap source URL must not match forbidden pattern: {$pattern} in {$loc}"
                );
            }
        }
    }

    public function test_sitemap_source_excludes_software_developers_explicitly(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('electrical-engineers', 'Electrical Engineers'),
            ['updated_at' => Carbon::create(2026, 2, 1, 12, 55, 0)]
        );
        $this->createDisplayAsset(
            $this->createOccupation('software-developers', 'Software Developers'),
            ['updated_at' => Carbon::create(2026, 2, 1, 12, 56, 0)]
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('electrical-engineers', 'en'),
            $this->projectionItem('electrical-engineers', 'zh'),
            $this->projectionItem(
                'software-developers',
                'en',
                CareerRuntimePublishProjectionService::STATE_QUARANTINED,
                [
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    'detail_route_enabled' => false,
                    'sitemap_live' => false,
                    'robots_indexable' => false,
                    'release_gate_pass' => false,
                    'canonical_self' => false,
                    'canonical_url' => null,
                ],
            ),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');
        $response->assertOk();

        $locs = collect($response->json('items'))->pluck('loc')->all();

        $this->assertContains('https://fermatmind.com/en/career/jobs/electrical-engineers', $locs);
        $this->assertNotContains('https://fermatmind.com/en/career/jobs/software-developers', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs/software-developers', $locs);

        foreach ($locs as $loc) {
            $this->assertStringNotContainsString('software-developers', $loc);
        }
    }

    public function test_sitemap_source_excludes_clinical_depression_held_slugs(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('chemical-engineers', 'Chemical Engineers'),
            ['updated_at' => Carbon::create(2026, 2, 1, 12, 55, 0)]
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('chemical-engineers', 'en'),
            $this->projectionItem('chemical-engineers', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');
        $response->assertOk();

        $locs = collect($response->json('items'))->pluck('loc')->all();

        $heldSlugs = [
            'clinical-depression',
            'clinical-depression-anxiety-assessment-professional-edition',
            'depression-screening',
            'depression-screening-test-standard-edition',
        ];

        $careerJobDetailLocs = array_filter($locs, static fn (string $loc): bool => (bool) preg_match(
            '#^https://[^/]+/(en|zh)/career/jobs/#',
            $loc,
        ));

        foreach ($careerJobDetailLocs as $loc) {
            foreach ($heldSlugs as $heldSlug) {
                $this->assertStringNotContainsString(
                    $heldSlug,
                    $loc,
                    "Sitemap source career job detail URL must not expose held slug: {$heldSlug} in {$loc}"
                );
            }
        }
    }

    public function test_sitemap_source_route_is_named(): void
    {
        $route = app('router')->getRoutes()->getByName('seo.sitemap-source');
        $this->assertNotNull($route, 'Route seo.sitemap-source must be named');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    private function createBigFivePublicContentAsset(string $entityKey, string $entityType, array $overrides = []): PersonalityPublicContentAsset
    {
        /** @var PersonalityPublicContentAsset */
        return PersonalityPublicContentAsset::query()->create(array_merge([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => 'big-five/'.$entityKey,
            'locale' => 'zh-CN',
            'title' => 'Big Five '.$entityKey,
            'summary' => 'Big Five public content asset.',
            'content_sections_json' => [
                ['id' => 'overview', 'heading' => 'Overview', 'body' => 'Visible reviewed body.'],
            ],
            'seo_json' => [
                'title' => 'Big Five '.$entityKey,
                'description' => 'Big Five SEO description.',
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => ['path' => "/zh/personality/big-five/{$entityKey}"],
            'hreflang_json' => [],
            'faq_json' => [
                ['question' => 'What is this page?', 'answer' => 'A reviewed Big Five content asset.'],
            ],
            'media_json' => [],
            'schema_json' => ['runtime_jsonld_enabled' => true],
            'method_boundary_json' => ['non_diagnostic' => true],
            'evidence_notes_json' => ['status' => 'reviewed'],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'seo_discoverability_released',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'test-package',
            'source_hash' => str_repeat('b', 64),
            'published_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'last_reviewed_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 7, 2, 8, 0, 0, 'UTC'),
        ], $overrides));
    }

    private function createDisplayAsset(Occupation $occupation, array $overrides = []): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()->create(array_merge([
            'occupation_id' => $occupation->id,
            'canonical_slug' => (string) $occupation->canonical_slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => range(1, 24),
            'page_payload_json' => [
                'zh' => ['hero' => ['title' => $occupation->canonical_title_zh]],
                'en' => ['hero' => ['title' => $occupation->canonical_title_en]],
            ],
            'seo_payload_json' => [
                'indexability_state' => 'index',
                'robots_policy' => 'index,follow',
            ],
            'sources_json' => [],
            'structured_data_json' => [],
            'implementation_contract_json' => [],
            'metadata_json' => [],
            'created_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
        ], $overrides));
    }
}
