<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Career\CareerGenerationAuthorityFixture;
use Tests\TestCase;

class SitemapSourceCacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_staging_current_owned_urls_match_the_cache_environment_without_accepting_foreign_hosts(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);
        $generator = app(SitemapGenerator::class);
        // Read the actual Current descriptors; legacy DB fixture mode must not hide this boundary.
        $urls = (new \ReflectionMethod($generator, 'getCurrentMbtiUrls'))->invoke($generator);
        self::assertNotEmpty($urls);
        $controller = app(\App\Http\Controllers\API\V0_5\SEO\SitemapSourceController::class);
        $projection = app(\App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup::class);
        $payload = $controller->buildPayloadFromAuthorityUrls($urls, $projection);
        foreach ($payload['items'] as $item) {
            self::assertSame('staging.fermatmind.com', parse_url($item['loc'], PHP_URL_HOST));
        }
        $controller->storeCache($payload);
        self::assertSame($payload, \App\Support\PublicProjectionCache::get($controller::CACHE_KEY_FRESH));

        $foreign = $controller->buildPayloadFromAuthorityUrls([
            ['loc' => 'https://foreign.example/zh/personality/intj', 'lastmod' => '2026-10-05T00:00:00Z'],
        ], $projection);
        $this->expectException(\RuntimeException::class);
        $controller->storeCache($foreign);
    }

    public function test_old_scheduler_writes_cannot_replace_the_current_sitemap_candidate(): void
    {
        $controller = app(\App\Http\Controllers\API\V0_5\SEO\SitemapSourceController::class);
        $candidate = ['ok' => true, 'source' => 'backend_sitemap_generator', 'count' => 1,
            'items' => [['loc' => 'https://fermatmind.com/zh/career/jobs/nurse-practitioners',
                'lastmod' => '2026-10-05T00:00:00Z']]];
        $controller->storeCache($candidate);

        // Historical v1 workers cannot replace v2's serving copy.
        Cache::put('seo:sitemap-source:v1:fresh', [...$candidate, 'count' => 0, 'items' => []], 600);
        Cache::forever('seo:sitemap-source:warm-fingerprint:v1', ['old' => true]);

        $this->getJson('/api/v0.5/seo/sitemap-source')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.loc', $candidate['items'][0]['loc']);
        $this->assertTrue(\App\Support\PublicProjectionCache::selected($controller::CACHE_KEY_FRESH));
        $this->assertTrue(\App\Support\PublicProjectionCache::selected($controller::CACHE_KEY_STALE));
        $fingerprintKey = \App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY;
        $this->assertTrue(\App\Support\PublicProjectionCache::selected($fingerprintKey));
        $this->assertNull(Cache::get($fingerprintKey));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        File::deleteDirectory(storage_path('app/private/career_generation_authority'));
        app(PublicCareerAuthorityResponseCache::class)->warm();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        File::deleteDirectory(storage_path('app/private/career_generation_authority'));
        parent::tearDown();
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
        $this->assertNull(Cache::get('seo:sitemap-source:v2:fresh'));
        $this->assertNull(Cache::get('seo:sitemap-source:v2:stale'));

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

    public function test_stale_dynamic_payload_is_not_served_when_fresh_expired(): void
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
        Cache::put('seo:sitemap-source:v2:stale', $stalePayload, 86400);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk()
            ->assertHeader('X-Fermat-Cache', 'fallback')
            ->assertJsonPath('source', 'backend_sitemap_generator_fallback');

        $locs = collect($response->json('items'))->pluck('loc')->all();
        $this->assertNotContains('https://fermatmind.com/en/career/jobs/stale-test-slug', $locs);
        $this->assertNull(Cache::get('seo:sitemap-source:v2:stale'));
    }

    public function test_stale_cache_miss_uses_short_fallback_cache_control(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);

        $stalePayload = [
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => 0,
            'items' => [],
        ];
        Cache::put('seo:sitemap-source:v2:stale', $stalePayload, 86400);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertHeader('X-Fermat-Cache', 'fallback');
        $this->assertStringContainsString('max-age=30', (string) $response->headers->get('Cache-Control'));
    }

    public function test_warm_command_populates_only_the_bounded_fresh_cache(): void
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

        $fresh = Cache::get('seo:sitemap-source:v2:fresh');
        $stale = Cache::get('seo:sitemap-source:v2:stale');

        $this->assertIsArray($fresh);
        $this->assertTrue($fresh['ok']);
        $this->assertNull($stale);
    }

    public function test_deployment_refresh_waits_for_the_actual_scheduler_lock_then_validates_its_own_payload(): void
    {
        $owner = Cache::lock('seo:sitemap-source:v2:lock', 120);
        self::assertTrue($owner->get());
        $this->mockSimpleSitemapAuthority();
        Sleep::fake();
        Sleep::whenFakingSleep(static function () use ($owner): void {
            self::assertTrue($owner->isOwnedByCurrentProcess());
            self::assertTrue($owner->release());
        });

        $this->runRefreshIfChanged('rebuilt');
        Sleep::assertSleptTimes(1);
        self::assertSame('https://fermatmind.com/zh/tests', Cache::get('seo:sitemap-source:v2:fresh')['items'][0]['loc']);
        self::assertIsArray(Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY));
        $next = Cache::lock('seo:sitemap-source:v2:lock', 120);
        self::assertTrue($next->get());
        self::assertTrue($next->release());
    }

    public function test_scheduler_busy_and_deployment_wait_timeout_preserve_the_live_owner_and_previous_payload(): void
    {
        Carbon::setTestNow('2026-10-10 18:00:00 UTC');
        $original = $this->originalSitemapPayload();
        Cache::put('seo:sitemap-source:v2:fresh', $original, 600);
        $owner = Cache::lock('seo:sitemap-source:v2:lock', 300);
        self::assertTrue($owner->get());
        $this->mock(SitemapGenerator::class)->shouldNotReceive('generateSitemapUrls');

        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->expectsOutputToContain('"status":"locked"')->assertFailed();
        self::assertTrue($owner->isOwnedByCurrentProcess());
        Sleep::fake();
        Sleep::whenFakingSleep(static fn () => Carbon::setTestNow(now()->addSeconds(121)));
        $this->artisan('seo:warm-sitemap-source-cache --refresh-if-changed --json')
            ->expectsOutputToContain('"status":"locked"')->assertFailed();
        Sleep::assertSleptTimes(1);
        self::assertSame($original, Cache::get('seo:sitemap-source:v2:fresh'));
        self::assertTrue($owner->isOwnedByCurrentProcess());
        self::assertNull(Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY));
        self::assertTrue($owner->release());
    }

    public function test_expired_build_cannot_publish_or_release_a_successor_warmers_lock(): void
    {
        Carbon::setTestNow('2026-10-10 18:00:00 UTC');
        $original = $this->originalSitemapPayload();
        Cache::put('seo:sitemap-source:v2:fresh', $original, 600);
        $successor = Cache::lock('seo:sitemap-source:v2:lock', 120);
        $this->mock(SitemapGenerator::class)->shouldReceive('generateSitemapUrls')->once()
            ->andReturnUsing(static function () use ($successor): array {
                Carbon::setTestNow(now()->addSeconds(121));
                self::assertTrue($successor->get());

                return [['loc' => 'https://fermatmind.com/zh/tests', 'lastmod' => '2026-10-10T18:00:00Z']];
            });
        $this->artisan('seo:warm-sitemap-source-cache --refresh-if-changed --json')
            ->expectsOutputToContain('"status":"failed"')->assertFailed();
        self::assertSame($original, Cache::get('seo:sitemap-source:v2:fresh'));
        self::assertNull(Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY));
        self::assertTrue($successor->isOwnedByCurrentProcess());
        self::assertTrue($successor->release());
    }

    public function test_expired_lease_without_a_successor_still_fails_before_publication(): void
    {
        Carbon::setTestNow('2026-10-10 18:00:00 UTC');
        $original = $this->originalSitemapPayload();
        Cache::put('seo:sitemap-source:v2:fresh', $original, 600);
        $this->mock(SitemapGenerator::class)->shouldReceive('generateSitemapUrls')->once()
            ->andReturnUsing(static function (): array {
                Carbon::setTestNow(now()->addSeconds(121));

                return [['loc' => 'https://fermatmind.com/zh/tests', 'lastmod' => '2026-10-10T18:00:00Z']];
            });
        $this->artisan('seo:warm-sitemap-source-cache --json')
            ->expectsOutputToContain('"status":"failed"')->assertFailed();
        self::assertSame($original, Cache::get('seo:sitemap-source:v2:fresh'));
    }

    public function test_deployment_refresh_coordinates_with_a_real_independent_file_lock_owner(): void
    {
        $directory = sys_get_temp_dir().'/sitemap-lock-'.bin2hex(random_bytes(8));
        config(['cache.default' => 'file', 'cache.stores.file.path' => $directory, 'cache.stores.file.lock_path' => $directory]);
        app('cache')->forgetDriver('file');
        $owner = Cache::lock('seo:sitemap-source:v2:lock', 120);
        self::assertTrue($owner->get());
        $this->mockSimpleSitemapAuthority();
        $child = new Process([PHP_BINARY, '-r',
            'require $argv[1]; $store=new Illuminate\\Cache\\FileStore(new Illuminate\\Filesystem\\Filesystem,$argv[2]); usleep(400000); exit($store->restoreLock("seo:sitemap-source:v2:lock",$argv[3])->release()?0:1);',
            base_path('vendor/autoload.php'), $directory, $owner->owner(),
        ]);
        $child->setTimeout(10);
        try {
            $child->start();
            $this->runRefreshIfChanged('rebuilt');
            self::assertSame(0, $child->wait());
            self::assertSame('backend_sitemap_generator', Cache::get('seo:sitemap-source:v2:fresh')['source']);
            $next = Cache::lock('seo:sitemap-source:v2:lock', 120);
            self::assertTrue($next->get());
            self::assertTrue($next->release());
        } finally {
            if ($child->isRunning()) {
                $child->stop();
            }
            File::deleteDirectory($directory);
        }
    }

    private function mockSimpleSitemapAuthority(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->mock(SitemapGenerator::class)->shouldReceive('generateSitemapUrls')->once()
            ->andReturn([['loc' => 'https://fermatmind.com/zh/tests', 'lastmod' => '2026-10-10T18:00:00Z']]);
    }

    private function originalSitemapPayload(): array
    {
        return ['ok' => true, 'source' => 'backend_sitemap_generator', 'count' => 1,
            'items' => [['loc' => 'https://fermatmind.com/en/tests', 'lastmod' => '2026-10-10T17:00:00Z']]];
    }

    public function test_isolated_projection_warm_coordinates_through_the_default_mutex_without_accepting_legacy_payload(): void
    {
        $statePath = \App\Support\PublicProjectionCache::statePath();
        $originalState = is_file($statePath) ? file_get_contents($statePath) : null;
        config(['cache.stores.public_projection' => ['driver' => 'array', 'serialize' => false]]);
        app('cache')->forgetDriver('public_projection');
        $legacy = $this->originalSitemapPayload();
        Cache::put('seo:sitemap-source:v2:fresh', $legacy, 600);
        $owner = Cache::lock('seo:sitemap-source:v2:lock', 120);
        self::assertTrue($owner->get());
        $this->mockSimpleSitemapAuthority();
        Sleep::fake();
        Sleep::whenFakingSleep(static function () use ($owner): void {
            self::assertTrue($owner->release());
        });
        try {
            \App\Support\PublicProjectionCache::writeState(['version' => 1, 'mode' => 'isolated']);
            $this->runRefreshIfChanged('rebuilt');
            Sleep::assertSleptTimes(1);
            self::assertSame($legacy, Cache::get('seo:sitemap-source:v2:fresh'));
            self::assertSame('https://fermatmind.com/zh/tests', \App\Support\PublicProjectionCache::get('seo:sitemap-source:v2:fresh')['items'][0]['loc']);
            self::assertNull(Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY));
            self::assertIsArray(\App\Support\PublicProjectionCache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY));
            $next = Cache::lock('seo:sitemap-source:v2:lock', 120);
            self::assertTrue($next->get());
            self::assertTrue($next->release());
        } finally {
            $owner->release();
            if ($originalState === null) {
                File::delete($statePath);
            } else {
                file_put_contents($statePath, $originalState);
            }
        }
    }

    public function test_refresh_if_changed_rebuilds_once_then_verifies_unchanged_authority(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);
        Carbon::setTestNow('2026-07-29 10:00:00 UTC');
        $this->seedFingerprintAuthority('fingerprint-unchanged');

        $this->runRefreshIfChanged('rebuilt');

        $receipt = Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY);
        $fresh = Cache::get('seo:sitemap-source:v2:fresh');
        $this->assertIsArray($receipt);
        $this->assertIsArray($fresh);

        Carbon::setTestNow('2026-07-29 10:05:00 UTC');
        $this->runRefreshIfChanged('verified_unchanged');

        $this->assertSame(
            $receipt,
            Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY),
        );
        $this->assertSame($fresh, Cache::get('seo:sitemap-source:v2:fresh'));
    }

    public function test_refresh_if_changed_rebuilds_when_published_indexable_authority_changes(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com', 'app.url' => 'https://fermatmind.com']);
        $attributes = ['org_id' => 0, 'locale' => 'en', 'title' => 'Authority test', 'content_md' => 'Published fixture',
            'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'sitemap_eligible' => true,
            'published_at' => now()->subMinute()];
        $createPublishedArticle = static function (string $slug) use ($attributes): \App\Models\Article {
            $article = \App\Models\Article::query()->create([...$attributes, 'slug' => $slug]);
            $revision = \App\Models\ArticleTranslationRevision::query()->create([
                'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
                'translation_group_id' => $article->translation_group_id,
                'locale' => 'en', 'source_locale' => 'en', 'revision_number' => 1,
                'revision_status' => \App\Models\ArticleTranslationRevision::STATUS_PUBLISHED,
                'title' => $article->title, 'content_md' => $article->content_md,
                'source_version_hash' => $article->source_version_hash, 'published_at' => now()->subMinute(),
            ]);
            $article->forceFill(['published_revision_id' => $revision->id])->saveQuietly();
            self::assertTrue(\App\Models\Article::query()->withoutGlobalScopes()->whereKey($article->id)->publiclySitemapEligible()->exists());

            return $article;
        };
        $beforeArticle = $createPublishedArticle('mutex-authority-before');
        $this->runRefreshIfChanged('rebuilt');
        $before = Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY);
        self::assertContains('https://fermatmind.com/en/articles/mutex-authority-before', array_column(Cache::get('seo:sitemap-source:v2:fresh')['items'], 'loc'));

        $owner = Cache::lock('seo:sitemap-source:v2:lock', 120);
        self::assertTrue($owner->get());
        Sleep::fake();
        Sleep::whenFakingSleep(static function () use ($owner, $beforeArticle, $createPublishedArticle): void {
            // Real publication qualification can change while deployment waits.
            $beforeArticle->forceFill(['is_public' => false])->saveQuietly();
            $createPublishedArticle('mutex-authority-after');
            self::assertTrue($owner->release());
        });
        $this->runRefreshIfChanged('rebuilt');
        Sleep::assertSleptTimes(1);
        $after = Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY);
        $fresh = Cache::get('seo:sitemap-source:v2:fresh');
        self::assertNotSame($before['fingerprint_sha256'], $after['fingerprint_sha256']);
        self::assertSame('backend_sitemap_generator', $fresh['source']);
        self::assertContains('https://fermatmind.com/en/articles/mutex-authority-after', array_column($fresh['items'], 'loc'));
        self::assertNotContains('https://fermatmind.com/en/articles/mutex-authority-before', array_column($fresh['items'], 'loc'));
    }

    public function test_refresh_if_changed_fails_safe_for_corrupt_schema_or_code_receipts(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);
        $this->seedFingerprintAuthority('fingerprint-fail-safe');

        $this->runRefreshIfChanged('rebuilt');

        Cache::forever(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY, 'corrupt');
        $this->runRefreshIfChanged('rebuilt');

        foreach (['cache_schema_version', 'code_fingerprint_sha256'] as $field) {
            $receipt = Cache::get(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY);
            $this->assertIsArray($receipt);
            $receipt[$field] = str_repeat('0', 64);
            Cache::forever(\App\Console\Commands\WarmSitemapSourceCacheCommand::FINGERPRINT_CACHE_KEY, $receipt);

            $this->runRefreshIfChanged('rebuilt');
        }

        Cache::put('seo:sitemap-source:v2:fresh', ['ok' => true, 'count' => 0], 600);
        $this->runRefreshIfChanged('rebuilt');
    }

    public function test_failed_warm_does_not_publish_fallback_or_overwrite_authority(): void
    {
        $original = ['ok' => true, 'source' => 'backend_sitemap_generator', 'count' => 1,
            'items' => [['loc' => 'https://fermatmind.com/zh/tests', 'lastmod' => '2026-01-01T00:00:00Z']]];
        Cache::put('seo:sitemap-source:v2:fresh', $original, 600);
        $this->mock(SitemapGenerator::class, function ($mock): void {
            $mock->shouldReceive('generateSitemapUrls')->twice()->andThrow(new \RuntimeException('OOM simulated'));
        });
        $this->artisan('seo:warm-sitemap-source-cache --json')->assertFailed();
        $this->assertSame($original, Cache::get('seo:sitemap-source:v2:fresh'));
        $next = Cache::lock('seo:sitemap-source:v2:lock', 120);
        self::assertTrue($next->get());
        self::assertTrue($next->release());
        Cache::forget('seo:sitemap-source:v2:fresh');
        $this->artisan('seo:warm-sitemap-source-cache --json')->assertFailed();
        $this->assertNull(Cache::get('seo:sitemap-source:v2:fresh'));
        self::assertTrue($next->get());
        self::assertTrue($next->release());
        $this->getJson('/api/v0.5/seo/sitemap-source')->assertHeader('X-Fermat-Cache', 'fallback');
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

        $cached = Cache::get('seo:sitemap-source:v2:fresh');
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

        $cached = Cache::get('seo:sitemap-source:v2:fresh');
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
        CareerGenerationAuthorityFixture::write($items);

        app(PublicCareerAuthorityResponseCache::class)->warm();
    }

    private function seedFingerprintAuthority(string $slug): void
    {
        $this->createDisplayAsset($this->createOccupation($slug, ucwords(str_replace('-', ' ', $slug))));
        $this->writeProjectionArtifact([
            $this->projectionItem($slug, 'en'),
            $this->projectionItem($slug, 'zh'),
        ]);
    }

    private function runRefreshIfChanged(string $expectedStatus): void
    {
        $this->artisan('seo:warm-sitemap-source-cache --refresh-if-changed --json --no-ansi')
            ->expectsOutputToContain('"status":"'.$expectedStatus.'"')
            ->assertSuccessful();
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
