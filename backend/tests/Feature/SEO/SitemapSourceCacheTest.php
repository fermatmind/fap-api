<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SitemapSourceCacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_empty_cache_returns_safe_fallback_without_http_regeneration(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk()
            ->assertHeader('X-Fermat-Cache', 'fallback')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('source', 'backend_sitemap_generator_fallback');

        $locs = collect($response->json('items'))->pluck('loc')->all();

        $this->assertGreaterThan(10, $response->json('count'));
        $this->assertContains('https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types', $locs);
        $this->assertContains('https://fermatmind.com/zh/tests/holland-career-interest-test-riasec', $locs);
        $this->assertNull(Cache::get('seo:sitemap-source:v1:fresh'));
        $this->assertNull(Cache::get('seo:sitemap-source:v1:stale'));

        foreach ($locs as $loc) {
            $this->assertDoesNotMatchRegularExpression(
                '#/(result|results|orders?|share|pay|payment|history)(/|$)|/tests/[^/]+/take(/|$)#i',
                parse_url($loc, PHP_URL_PATH) ?: '',
                "Fallback URL must not expose private route family: {$loc}"
            );
        }
    }

    public function test_cache_hit_returns_cached_payload_without_regenerating(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('hit-test-slug', 'Hit Test'),
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('hit-test-slug', 'en'),
            $this->projectionItem('hit-test-slug', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk()
            ->assertHeader('X-Fermat-Cache', 'hit');
    }

    public function test_stale_fallback_returns_stale_when_fresh_expired(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('stale-test-slug', 'Stale Test'),
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('stale-test-slug', 'en'),
            $this->projectionItem('stale-test-slug', 'zh'),
        ]);

        $stalePayload = [
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => 10,
            'items' => [
                ['loc' => 'https://fermatmind.com/en/career/jobs/stale-test-slug', 'lastmod' => '2026-01-01T00:00:00+00:00'],
            ],
        ];
        Cache::put('seo:sitemap-source:v1:stale', $stalePayload, 86400);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk()
            ->assertHeader('X-Fermat-Cache', 'stale')
            ->assertJsonPath('count', 10)
            ->assertJsonPath('items.0.loc', 'https://fermatmind.com/en/career/jobs/stale-test-slug');
    }

    public function test_stale_returns_different_cache_control(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $stalePayload = [
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => 0,
            'items' => [],
        ];
        Cache::put('seo:sitemap-source:v1:stale', $stalePayload, 86400);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertHeader('X-Fermat-Cache', 'stale');
        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
    }

    public function test_warm_command_populates_both_cache_layers(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('warm-cmd-test', 'Warm Cmd Test'),
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('warm-cmd-test', 'en'),
            $this->projectionItem('warm-cmd-test', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $fresh = Cache::get('seo:sitemap-source:v1:fresh');
        $stale = Cache::get('seo:sitemap-source:v1:stale');

        $this->assertIsArray($fresh);
        $this->assertIsArray($stale);
        $this->assertTrue($fresh['ok']);
        $this->assertTrue($stale['ok']);
        $this->assertSame($fresh['count'], $stale['count']);
    }

    public function test_warm_command_writes_safe_fallback_when_generator_fails_without_stale_cache(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->mock(SitemapGenerator::class, function ($mock): void {
            $mock->shouldReceive('generateUrls')
                ->once()
                ->andThrow(new \RuntimeException('simulated sitemap generator failure'));
        });

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $fresh = Cache::get('seo:sitemap-source:v1:fresh');
        $stale = Cache::get('seo:sitemap-source:v1:stale');

        $this->assertIsArray($fresh);
        $this->assertIsArray($stale);
        $this->assertSame('backend_sitemap_generator_fallback', $fresh['source']);
        $this->assertSame('backend_sitemap_generator_fallback', $stale['source']);
        $this->assertGreaterThan(10, $fresh['count']);
        $this->assertSame($fresh['items'], $stale['items']);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');
        $response->assertOk()
            ->assertHeader('X-Fermat-Cache', 'hit')
            ->assertJsonPath('source', 'backend_sitemap_generator_fallback');
    }

    public function test_response_shape_remains_compatible(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('shape-test', 'Shape Test'),
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('shape-test', 'en'),
            $this->projectionItem('shape-test', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk()
            ->assertJsonStructure([
                'ok',
                'source',
                'count',
                'items' => [
                    '*' => ['loc', 'lastmod'],
                ],
            ]);

        $data = $response->json();
        $this->assertTrue($data['ok']);
        $this->assertSame('backend_sitemap_generator', $data['source']);
        $this->assertIsInt($data['count']);
        $this->assertCount($data['count'], $data['items']);
    }

    public function test_software_developers_absent_from_cached_response(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('safe-slug', 'Safe Slug'),
        );
        $this->createDisplayAsset(
            $this->createOccupation('software-developers', 'Software Developers'),
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('safe-slug', 'en'),
            $this->projectionItem('safe-slug', 'zh'),
            $this->projectionItem('software-developers', 'en', CareerRuntimePublishProjectionService::STATE_QUARANTINED, [
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                'detail_route_enabled' => false,
                'sitemap_live' => false,
                'robots_indexable' => false,
                'release_gate_pass' => false,
                'canonical_self' => false,
                'canonical_url' => null,
            ]),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $cached = Cache::get('seo:sitemap-source:v1:fresh');
        $this->assertIsArray($cached);

        $locs = collect($cached['items'])->pluck('loc')->all();
        foreach ($locs as $loc) {
            $this->assertStringNotContainsString('software-developers', $loc);
        }
    }

    public function test_forbidden_url_patterns_absent_from_cached_response(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('clean-test', 'Clean Test'),
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('clean-test', 'en'),
            $this->projectionItem('clean-test', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();

        $cached = Cache::get('seo:sitemap-source:v1:fresh');
        $this->assertIsArray($cached);

        $forbiddenPatterns = [
            '#/(result|order|pay|share|take|report|checkout|personalized|private)/#i',
            '#/me/#i',
        ];

        foreach ($cached['items'] as $item) {
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $item['loc'],
                    "Cached URL must not match forbidden pattern: {$pattern} in {$item['loc']}"
                );
            }
        }
    }

    public function test_fresh_hit_response_has_correct_cache_control(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('cc-test', 'CC Test'),
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('cc-test', 'en'),
            $this->projectionItem('cc-test', 'zh'),
        ]);

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->assertSuccessful();
        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertHeader('X-Fermat-Cache', 'hit');
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
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
            'created_at' => Carbon::create(2026, 2, 1, 12, 54, 0),
            'updated_at' => Carbon::create(2026, 2, 1, 12, 54, 0),
        ]);
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
            'created_at' => Carbon::create(2026, 2, 1, 12, 55, 0),
            'updated_at' => Carbon::create(2026, 2, 1, 12, 55, 0),
        ], $overrides));
    }

    private function writeProjectionArtifact(array $items): void
    {
        $timestamp = str_replace('.', '', sprintf('%.6F', microtime(true)));
        $directory = storage_path('app/private/career_runtime_publish_projection/zzzzzzzz-sitemap-source-cache-test-'.$timestamp.'-'.strtolower(str()->random(8)));

        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME, json_encode([
            'projection_kind' => CareerRuntimePublishProjectionService::PROJECTION_KIND,
            'projection_version' => CareerRuntimePublishProjectionService::PROJECTION_VERSION,
            'source_authority' => 'CareerFullReleaseLedger',
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
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
}
