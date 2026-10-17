<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use App\Domain\Career\Display\CareerPageProjector;
use App\Services\Career\CareerFilePageReader;
use App\Support\PublicProjectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CareerFilePageReaderTest extends TestCase
{
    use RefreshDatabase;

    private function publication(bool $published): void
    {
        $this->app->instance(
            \App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility::class,
            new \Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: $published),
        );
    }

    public function test_file_content_and_seo_work_with_no_legacy_display_rows(): void
    {
        $this->publication(true);
        DB::enableQueryLog();
        $reader = app(CareerFilePageReader::class);
        $bundle = $reader->read('actors', 'zh-CN');
        self::assertSame('actors', $bundle['identity']['canonical_slug']);
        self::assertSame('29.05美元/小时', $bundle['career_page']['hero']['metrics'][0]['fact']['display_value']);
        self::assertArrayNotHasKey('display_surface_v1', $bundle);
        self::assertArrayNotHasKey('truth_layer', $bundle);
        self::assertSame($bundle['career_page']['seo']['title']['text'], $reader->seo($bundle)['meta']['title']);
        $this->travel(2)->days();
        $stored = PublicProjectionCache::get(
            CareerFilePageReader::cacheKey($bundle['career_page']),
        );
        $codec = app(CareerJobDetailCanonicalCacheReader::class);
        self::assertTrue($codec->isSupportedEnvelope($stored));
        self::assertSame($bundle['career_page'], $codec->decode($stored));
        self::assertLessThan(strlen(serialize($bundle['career_page'])), strlen(serialize($stored)));
        self::assertSame($bundle, $reader->read('actors', 'zh-CN'));
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('career_job_display_assets', $query['query']);
            self::assertStringNotContainsString('career_jobs', $query['query']);
        }
    }

    public function test_published_english_editorial_content_is_indexable(): void
    {
        $this->publication(true);
        $bundle = app(CareerFilePageReader::class)->read('health-educators', 'en');
        self::assertNotEmpty($bundle['career_page']['content']['blocks']);
        self::assertTrue($bundle['seo_contract']['index_eligible']);
        self::assertSame('index,follow', $bundle['seo_contract']['robots_policy']);
        $seo = app(CareerFilePageReader::class)->seo($bundle);
        self::assertSame('index,follow', $seo['meta']['robots']);
        self::assertSame('/en/career/jobs/health-educators', $seo['meta']['hreflang']['en']);
        self::assertSame('included', $seo['seo_surface_v1']['sitemap_state']);
        self::assertSame('/en/career/jobs/health-educators', $seo['meta']['canonical']);
    }

    public function test_body_qualification_preserves_missing_metrics_and_excludes_empty_language_pairs(): void
    {
        $this->publication(true);
        $reader = app(CareerFilePageReader::class);
        $middle = $reader->read('middle-school-teachers', 'zh-CN');
        self::assertNotEmpty($middle['career_page']['content']['blocks']);
        self::assertTrue($middle['seo_contract']['index_eligible']);
        self::assertSame('missing', $middle['career_page']['hero']['metrics'][4]['availability']);
        self::assertSame('/en/career/jobs/middle-school-teachers', $reader->seo($middle)['meta']['hreflang']['en']);
        $accountant = $reader->read('accountants-and-auditors', 'zh-CN');
        self::assertSame([
            'en' => '/en/career/jobs/accountants-and-auditors',
            'zh-CN' => '/zh/career/jobs/accountants-and-auditors',
        ], $reader->seo($accountant)['meta']['hreflang']);
    }

    public function test_unpublished_identity_does_not_gain_a_public_page(): void
    {
        $this->publication(false);
        self::assertNull(app(CareerFilePageReader::class)->read('actors', 'en'));
    }

    public function test_existing_uncompressed_page_cache_remains_readable(): void
    {
        $this->publication(true);
        $page = app(CareerPageProjector::class)->read('actors', 'en');
        $key = CareerFilePageReader::cacheKey($page);
        PublicProjectionCache::forever($key, $page);
        self::assertSame($page, app(CareerFilePageReader::class)->read('actors', 'en')['career_page']);
        self::assertSame($page, PublicProjectionCache::get($key));
    }

    public function test_old_compressed_and_uncompressed_cache_hits_receive_current_link_normalization(): void
    {
        $this->publication(true);
        $page = app(CareerPageProjector::class)->read('actors', 'en');
        $key = CareerFilePageReader::cacheKey($page);
        $old = $page;
        $old['display']['components']['personality_fit_block']['assessments'][0]['cta_href'] = '/zh/tests/holland-career-interest-test-riasec';
        $old['display']['components']['market_signal_card']['transitions'][0]['target_href'] = '/zh/career/jobs/'.$old['display']['components']['market_signal_card']['transitions'][0]['target_slug'];
        $codec = app(CareerJobDetailCanonicalCacheReader::class);
        foreach ([$old, $codec->encode($old)] as $stored) {
            PublicProjectionCache::forever($key, $stored);
            self::assertSame($page, app(CareerFilePageReader::class)->read('actors', 'en')['career_page']);
            self::assertSame($stored, PublicProjectionCache::get($key));
        }
    }

    public function test_corrupt_unknown_or_misbound_compressed_cache_falls_back_to_the_file(): void
    {
        $this->publication(true);
        $codec = app(CareerJobDetailCanonicalCacheReader::class);
        $page = app(CareerPageProjector::class)->read('actors', 'en');
        $key = CareerFilePageReader::cacheKey($page);
        $wrongIdentity = $page;
        $wrongIdentity['subject']['canonical_slug'] = 'wrong-role';
        $wrongHash = $page;
        $wrongHash['source_content_sha256'] = str_repeat('0', 64);
        $stored = $codec->encode($page);
        foreach ([
            array_replace($stored, ['sha256' => str_repeat('0', 64)]),
            array_replace($stored, ['codec' => 'unsupported']),
            $codec->encode($wrongIdentity),
            $codec->encode($wrongHash),
        ] as $invalid) {
            PublicProjectionCache::forever($key, $invalid);
            self::assertSame($page, app(CareerFilePageReader::class)->read('actors', 'en')['career_page']);
            self::assertSame($page, $codec->decode(PublicProjectionCache::get($key)));
        }
    }

    public function test_presence_projection_is_small_and_skips_full_detail_queries_and_cache(): void
    {
        $this->publication(true);
        DB::enableQueryLog();
        foreach (['zh-CN', 'en'] as $locale) {
            $response = $this->getJson("/api/v0.5/career/jobs/Actors?locale={$locale}&projection_contract=career.detail.presence.v1")->assertOk();
            $this->assertNoStore($response);
            $response->assertExactJson([
                'bundle_kind' => 'career_job_presence',
                'bundle_version' => 'career.detail.presence.v1',
                'identity' => ['canonical_slug' => 'actors'],
            ]);
            self::assertLessThan(200, strlen($response->getContent()));
            $page = app(\App\Domain\Career\Display\CareerPageProjector::class)->read('actors', $locale);
            self::assertNull(\App\Support\PublicProjectionCache::get(CareerFilePageReader::cacheKey($page)));
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('occupations', $query['query']);
            self::assertStringNotContainsString('occupation_aliases', $query['query']);
            self::assertStringNotContainsString('occupation_crosswalks', $query['query']);
        }
        // Existing callers still receive the original full page contract.
        $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN&projection_contract=career.detail.page.v1')
            ->assertOk()->assertJsonPath('career_page.subject.canonical_slug', 'actors');
    }

    public function test_presence_projection_preserves_publication_and_missing_file_failures(): void
    {
        $uri = '/api/v0.5/career/jobs/actors?locale=zh-CN&projection_contract=career.detail.presence.v1';
        $this->publication(false);
        $this->assertNoStore($this->getJson($uri)->assertNotFound());
        $this->publication(true);
        $this->assertNoStore($this->getJson('/api/v0.5/career/jobs/missing-file-role?locale=en&projection_contract=career.detail.presence.v1')
            ->assertStatus(503)->assertJsonPath('error', 'CAREER_PAGE_UNAVAILABLE'));
    }

    public function test_http_cache_loss_and_poison_cannot_replace_the_file_or_dispatch_a_warm_job(): void
    {
        $this->publication(true);
        \Illuminate\Support\Facades\Queue::fake();
        $page = app(\App\Domain\Career\Display\CareerPageProjector::class)->read('actors', 'zh-CN');
        $key = CareerFilePageReader::cacheKey($page);
        \App\Support\PublicProjectionCache::put($key, ['poison' => 'old database prose'], 60);
        $times = [];
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $response = $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN');
            $times[] = (microtime(true) - $start) * 1000;
            $response->assertOk()
                ->assertJsonPath('career_page.subject.canonical_slug', 'actors')
                ->assertJsonMissing(['poison' => 'old database prose']);
        }
        sort($times);
        self::assertLessThan(400, $times[8]);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_file_read_survives_optional_cache_write_failure(): void
    {
        $this->publication(true);
        $repository = \Illuminate\Support\Facades\Cache::store();
        $store = \Mockery::mock($repository)->makePartial();
        $store->shouldReceive('forever')->andThrow(new \RuntimeException('OOM cache write'));
        \Illuminate\Support\Facades\Cache::swap($store);
        $response = $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN');
        $response->assertOk()->assertJsonPath('career_page.subject.canonical_slug', 'actors');
        self::assertNotEmpty($response->json('career_page.content.blocks'));
    }

    public function test_signed_verification_reads_files_without_creating_derived_cache(): void
    {
        $this->publication(true);
        $uri = '/api/v0.5/career/jobs/actors?locale=zh-CN';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "GET\n{$uri}\n{$timestamp}", config('app.key'));
        $page = app(\App\Domain\Career\Display\CareerPageProjector::class)->read('actors', 'zh-CN');
        $key = CareerFilePageReader::cacheKey($page);
        $this->withHeaders(['X-Fermat-Career-Verify-Only' => '1', 'X-Fermat-Career-Verify-Timestamp' => $timestamp, 'X-Fermat-Career-Verify-Signature' => $signature])
            ->getJson($uri)->assertOk();
        self::assertNull(\App\Support\PublicProjectionCache::get($key));
    }

    public function test_file_detail_and_seo_publication_decisions_are_never_http_cached(): void
    {
        foreach (['zh-CN', 'en'] as $locale) {
            foreach (["/api/v0.5/career/jobs/actors?locale={$locale}", "/api/v0.5/career-jobs/actors/seo?locale={$locale}"] as $uri) {
                $this->publication(true);
                $response = $this->getJson($uri)->assertOk();
                $this->assertNoStore($response);
                $this->publication(false);
                $this->assertNoStore($this->getJson($uri)->assertNotFound());
            }
        }
        $this->assertNoStore($this->getJson('/api/v0.5/career-jobs/actors/seo?locale=invalid')->assertStatus(422));
    }

    private function assertNoStore(\Illuminate\Testing\TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
        self::assertStringNotContainsString('stale-while-revalidate', $cacheControl);
    }

    public function test_absent_authoritative_file_returns_explicit_503_instead_of_old_body(): void
    {
        $this->publication(true);
        $this->assertNoStore($this->getJson('/api/v0.5/career/jobs/missing-file-role?locale=en')->assertStatus(503)
            ->assertJsonPath('error', 'CAREER_PAGE_UNAVAILABLE'));
        $this->assertNoStore($this->getJson('/api/v0.5/career-jobs/missing-file-role/seo?locale=en')->assertStatus(503)
            ->assertJsonPath('error', 'CAREER_PAGE_UNAVAILABLE'));
    }
}
