<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Support\Career\CareerVerifyOnlyRequestAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\UsesCareerDetailCacheFixture;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerDirectoryAuthorityApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesCareerDetailCacheFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installCareerDetailCacheFixture();

        Cache::flush();
    }

    public function test_current_aliases_resolve_detail_and_search_but_are_not_directory_members(): void
    {
        $target = 'librarians';
        $alias = 'librarians-and-media-collections-specialists';
        $this->createDirectoryOccupation($target, 'Librarians', '图书馆员', 'education', 'Education');
        $this->createDirectoryOccupation($alias, 'Librarians and Media Collections Specialists', '图书管理员及媒体资料专员', 'education', 'Education');
        $this->publishRuntimeProjection([$target, $alias]);
        $this->warmDirectoryAuthority();
        foreach (['en', 'zh-CN'] as $locale) {
            $this->getJson('/api/v0.5/career/jobs/'.$alias.'?locale='.$locale)
                ->assertOk()->assertJsonPath('identity.canonical_slug', $target);
            $this->getJson('/api/v0.5/career/directory?locale='.$locale)
                ->assertOk()->assertJsonPath('pagination.total', 1)
                ->assertJsonPath('items.0.slug', $target);
        }
        $this->getJson('/api/v0.5/career/directory?locale=en&q='.urlencode('图书管理员及媒体资料专员'))
            ->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.slug', $target);
    }

    public function test_alias_never_serves_its_placeholder_when_target_is_not_published(): void
    {
        $alias = 'librarians-and-media-collections-specialists';
        $this->createDirectoryOccupation($alias, 'Old librarian', '旧图书馆员', 'education', 'Education');
        $this->publishRuntimeProjection([$alias]);
        $this->getJson('/api/v0.5/career/jobs/'.$alias.'?locale=zh-CN')->assertNotFound();
    }

    public function test_current_combination_names_reach_detail_directory_and_name_search(): void
    {
        $slug = 'drywall-and-ceiling-tile-installers-and-tapers';
        $title = '石膏板与吊顶板安装工及接缝处理工';
        $this->createDirectoryOccupation($slug, 'Old installer', '旧安装工', 'construction', 'Construction');
        $this->publishRuntimeProjection([$slug]);
        $this->warmDirectoryAuthority();
        $this->getJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
            ->assertOk()->assertJsonPath('titles.canonical_zh', $title)
            ->assertJsonPath('ontology.crosswalks.2.source_code', '47-2082.00');
        $this->getJson('/api/v0.5/career/directory?locale=zh-CN&q='.urlencode($title))
            ->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.title', $title);
    }

    public function test_current_source_scope_survives_legacy_transport_cleanup(): void
    {
        $slug = 'insulation-workers-mechanical';
        $expected = app(\App\Domain\Career\Display\CareerContentV3CanonicalReader::class)->page($slug, 'zh-CN');
        self::assertStringContainsString('industry_proxy', json_encode($expected));
        $controller = app(\App\Http\Controllers\API\V0_5\Career\CareerJobDetailController::class);
        $project = new \ReflectionMethod($controller, 'projectReaderSafePayload');
        $public = $project->invoke($controller, [
            'display_surface_v1' => ['content_v3' => $expected],
            'legacy_label' => 'industry_proxy',
            'audit_fields' => ['trace' => 'private'],
        ]);
        self::assertSame($expected, $public['display_surface_v1']['content_v3']);
        self::assertArrayNotHasKey('audit_fields', $public);
        self::assertSame('recruitment-market reference', $public['legacy_label']);
    }

    public function test_it_returns_paginated_lightweight_directory_authority(): void
    {
        $this->createDirectoryOccupation('accountants-and-auditors', 'Accountants and Auditors', '会计师与审计师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actors', 'Actors', '演员', 'arts-media', 'Arts and Media');
        $this->publishRuntimeProjection(['accountants-and-auditors', 'actuaries', 'actors']);
        $this->assertTrue(app(PublicCareerAuthorityResponseCache::class)->jobDetailCacheIsReady('accountants-and-auditors', 'en'));
        $this->assertTrue(app(PublicCareerAuthorityResponseCache::class)->jobDetailCacheIsReady('actuaries', 'en'));
        $this->assertTrue(app(PublicCareerAuthorityResponseCache::class)->jobDetailCacheIsReady('actors', 'en'));
        $warmSummary = app(PublicCareerAuthorityResponseCache::class)->warm();
        $this->assertSame(3, data_get($warmSummary, 'job_index_en.member_count'));

        $response = $this->getJson('/api/v0.5/career/directory?locale=en&page=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('authority_version', 'career.directory_authority.v1')
            ->assertJsonPath('bundle_kind', 'career_directory')
            ->assertJsonPath('public_truth.public_detail_indexable_count', 3)
            ->assertJsonPath('public_truth.directory_member_count', 3)
            ->assertJsonPath('public_truth.future_scale_ready', true)
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('pagination.total_pages', 2)
            ->assertJsonPath('pagination.has_next_page', true)
            ->assertJsonPath('filters.locale', 'en')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.canonical_path', '/en/career/jobs/accountants-and-auditors')
            ->assertJsonPath('items.0.detail_ready', true)
            ->assertJsonPath('items.0.indexable', true)
            ->assertJsonPath('items.0.family.slug', 'business-finance')
            ->assertJsonMissingPath('items.0.truth_summary')
            ->assertJsonMissingPath('items.0.score_summary')
            ->assertJsonMissingPath('items.0.provenance_meta');

        $this->assertSame(
            ['arts-media', 'business-finance'],
            collect($response->json('facets.families'))->pluck('slug')->all(),
        );
    }

    public function test_signed_verify_only_directory_read_does_not_write_cache_state_log(): void
    {
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->publishRuntimeProjection(['actuaries']);
        $this->warmDirectoryAuthority();
        Log::spy();
        $requestUri = '/api/v0.5/career/directory?locale=en&per_page=100';

        $this->withHeaders($this->verifyOnlyHeaders($requestUri))
            ->getJson($requestUri)
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        Log::shouldNotHaveReceived('info');
    }

    public function test_signed_verify_only_directory_failure_returns_bounded_503_without_logging(): void
    {
        Log::spy();
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache unavailable'));
        $requestUri = '/api/v0.5/career/directory?locale=en&per_page=100';

        $this->withHeaders($this->verifyOnlyHeaders($requestUri))
            ->getJson($requestUri)
            ->assertStatus(503)
            ->assertExactJson(['message' => 'career verify-only read unavailable.']);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    public function test_it_filters_by_family_and_query_without_exposing_full_job_index_fields(): void
    {
        $this->createDirectoryOccupation('accountants-and-auditors', 'Accountants and Auditors', '会计师与审计师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actors', 'Actors', '演员', 'arts-media', 'Arts and Media');
        $this->publishRuntimeProjection(['accountants-and-auditors', 'actuaries', 'actors']);
        $this->warmDirectoryAuthority();

        $this->getJson('/api/v0.5/career/directory?locale=zh-CN&family=business-finance&q=actuar&page=1&per_page=50')
            ->assertOk()
            ->assertJsonPath('filters.locale', 'zh-CN')
            ->assertJsonPath('filters.family', 'business-finance')
            ->assertJsonPath('filters.q', 'actuar')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'actuaries')
            ->assertJsonPath('items.0.title', '精算师')
            ->assertJsonPath('items.0.canonical_path', '/zh/career/jobs/actuaries');
    }

    public function test_it_excludes_held_noindex_and_stub_only_entries(): void
    {
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('software-developers', 'Software Developers', '软件开发人员', 'software', 'Software');
        $this->createDirectoryOccupation('private-investigators', 'Private Investigators', '私家侦探', 'protective-service', 'Protective Service');
        $this->publishRuntimeProjection([
            'actuaries',
            'software-developers',
            'private-investigators' => [
                'robots_indexable' => false,
                'runtime_publish_state' => 'blocked',
                'detail_route_enabled' => false,
                'release_gate_pass' => false,
            ],
        ]);
        $this->warmDirectoryAuthority();

        $slugs = collect($this->getJson('/api/v0.5/career/directory?locale=en&per_page=100')->assertOk()->json('items'))
            ->pluck('slug')
            ->all();

        $this->assertSame(['actuaries'], $slugs);
    }

    public function test_transient_detail_cache_loss_preserves_internal_authority_but_removes_the_public_link(): void
    {
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->publishRuntimeProjection(['actuaries']);
        $this->warmDirectoryAuthority();

        $this->getJson('/api/v0.5/career/directory?locale=en&per_page=100')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.indexable', true)
            ->assertJsonPath('items.0.detail_ready', true);

        $responseCache = app(PublicCareerAuthorityResponseCache::class);
        $responseCache->forgetJobDetailPayload('actuaries', 'en');
        $responseCache->warmDirectoryReadModels(['en']);

        $internalReadModel = $responseCache->directoryReadModelPayload('en');
        $this->assertSame(1, $internalReadModel['public_count']);
        $this->assertFalse($internalReadModel['items'][0]['detail_ready']);

        $this->getJson('/api/v0.5/career/directory?locale=en&per_page=100')
            ->assertOk()
            ->assertJsonPath('public_truth.public_detail_indexable_count', 0)
            ->assertJsonPath('public_truth.directory_member_count', 0)
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'facets.families')
            ->assertJsonCount(0, 'items');

        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'actuaries']);
    }

    public function test_full_warm_keeps_the_job_index_complete_when_detail_caches_are_cold(): void
    {
        $this->createDirectoryOccupation('accountants-and-auditors', 'Accountants and Auditors', '会计师与审计师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->publishRuntimeProjection(['accountants-and-auditors', 'actuaries']);

        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['accountants-and-auditors', 'actuaries'] as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $cache->forgetJobDetailPayload($slug, $locale);
                $this->assertFalse($cache->jobDetailCacheIsReady($slug, $locale));
            }
        }

        $summary = $cache->warm();

        $this->assertSame(2, data_get($summary, 'job_index_en.member_count'));
        $this->assertSame(2, data_get($summary, 'job_index_zh_cn.member_count'));
        $this->assertSame(
            ['accountants-and-auditors', 'actuaries'],
            collect($cache->jobIndexPayload('en')['items'])->pluck('identity.canonical_slug')->all(),
        );
        $this->assertSame(
            ['accountants-and-auditors', 'actuaries'],
            collect($cache->jobIndexPayload('zh-CN')['items'])->pluck('identity.canonical_slug')->all(),
        );
    }

    private function createDirectoryOccupation(
        string $slug,
        string $titleEn,
        string $titleZh,
        string $familySlug,
        string $familyTitle
    ): Occupation {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => $familySlug],
            [
                'title_en' => $familyTitle,
                'title_zh' => $familyTitle,
            ],
        );

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => $titleEn,
            'canonical_title_zh' => $titleZh,
            'search_h1_zh' => $titleZh,
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

        foreach ([
            ['source_system' => 'us_soc', 'source_code' => '15-1252'],
            ['source_system' => 'onet_soc_2019', 'source_code' => '15-1252.00'],
        ] as $crosswalk) {
            OccupationCrosswalk::query()->create([
                'occupation_id' => $occupation->id,
                'source_system' => $crosswalk['source_system'],
                'source_code' => $crosswalk['source_code'],
                'source_title' => $titleEn,
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]);
        }

        CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => range(1, 24),
            'page_payload_json' => [
                'zh' => ['hero' => ['title' => $titleZh]],
                'en' => ['hero' => ['title' => $titleEn]],
            ],
            'seo_payload_json' => [
                'indexability_state' => 'index',
                'robots_policy' => 'index,follow',
            ],
            'sources_json' => [
                'primary' => [
                    ['label' => 'Fixture source', 'url' => 'https://example.test/career'],
                ],
            ],
            'structured_data_json' => [],
            'implementation_contract_json' => [],
            'metadata_json' => [],
            'created_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
        ]);

        return $occupation;
    }

    /** @return array<string, string> */
    private function verifyOnlyHeaders(string $requestUri): array
    {
        $timestamp = (string) time();

        return [
            CareerVerifyOnlyRequestAuthorizer::MARKER_HEADER => '1',
            CareerVerifyOnlyRequestAuthorizer::TIMESTAMP_HEADER => $timestamp,
            CareerVerifyOnlyRequestAuthorizer::SIGNATURE_HEADER => hash_hmac(
                'sha256',
                CareerVerifyOnlyRequestAuthorizer::signaturePayload($requestUri, $timestamp),
                (string) config('app.key'),
            ),
        ];
    }

    private function warmDirectoryAuthority(): void
    {
        app(PublicCareerAuthorityResponseCache::class)->warm();
    }

    /**
     * @param  list<string>|array<string, array<string, mixed>>  $slugs
     */
    private function publishRuntimeProjection(array $slugs): void
    {
        $items = [];
        foreach ($slugs as $key => $value) {
            $slug = is_int($key) ? (string) $value : (string) $key;
            $overrides = is_array($value) ? $value : [];
            $published = ($overrides['runtime_publish_state'] ?? 'published') === 'published';

            $items[$slug.'|en'] = array_merge([
                'slug' => $slug,
                'locale' => 'en',
                'dataset_visible' => $published,
                'search_visible' => $published,
                'detail_route_enabled' => $published,
                'robots_indexable' => $published,
                'release_gate_pass' => $published,
                'runtime_publish_state' => $published ? 'published' : 'blocked',
            ], $overrides);
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultDatasetVisible: false,
                defaultSearchVisible: false,
                defaultDetailRouteEnabled: false,
                defaultRobotsIndexable: false,
                defaultReleaseGatePass: false,
                items: $items,
            ),
        );

        Cache::flush();
        $responseCache = app(PublicCareerAuthorityResponseCache::class);
        foreach ($items as $item) {
            if (($item['runtime_publish_state'] ?? null) !== 'published') {
                continue;
            }

            foreach (['en', 'zh-CN'] as $locale) {
                $responseCache->publishJobDetailReadModel($item['slug'], $locale, $this->detailCacheFixture([
                    'identity' => ['canonical_slug' => $item['slug']],
                    'titles' => ['canonical_en' => 'Fixture title', 'canonical_zh' => '测试旧名称'],
                    'ontology' => ['crosswalks' => []],
                    'locale' => $locale,
                    'fixture' => true,
                ], $item['slug'], $locale));
            }
        }
    }
}
