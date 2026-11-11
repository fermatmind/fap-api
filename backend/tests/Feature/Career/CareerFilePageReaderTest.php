<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use App\Domain\Career\Display\CareerPageProjector;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\CareerFilePageReader;
use App\Support\PublicProjectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    public function test_current_bilingual_occupation_classification_overrides_stale_crosswalks_and_labels_combinations(): void
    {
        $this->publication(true);
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'test-classification', 'title_en' => 'Test classification', 'title_zh' => '测试分类',
        ]);
        foreach (['industrial-designers', 'electrical-and-electronics-engineers', 'actors'] as $slug) {
            $occupation = Occupation::query()->create([
                'family_id' => $family->id, 'canonical_slug' => $slug, 'canonical_title_en' => $slug,
                'canonical_title_zh' => '测试职业', 'search_h1_zh' => '测试职业', 'entity_level' => 'market_child',
                'truth_market' => 'US', 'display_market' => 'US', 'crosswalk_mode' => 'global_standard',
            ]);
            $occupation->crosswalks()->create([
                'source_system' => 'us_soc', 'source_code' => '17-2112', 'source_title' => 'Industrial Engineers',
                'mapping_type' => 'direct_match', 'confidence_score' => 1,
            ]);
        }

        $expected = [
            'diesel-service-technicians-and-mechanics' => ['49-3031.00'],
            'electrical-and-electronics-engineers' => ['17-2071.00', '17-2072.00'],
            'gaming-services-occupations' => ['39-3011.00', '39-3012.00', '39-3019.00'],
            'general-maintenance-and-repair-workers' => ['49-9071.00'],
            'grounds-maintenance-workers' => ['37-3011.00'],
            'industrial-designers' => ['27-1021.00'],
            'logging-workers' => ['45-4029.00'],
            'material-recording-clerks' => ['43-5071.00'],
            'medical-scientists' => ['19-1041.00', '19-1042.00'],
            'retail-sales-workers' => ['41-2022.00', '41-2031.00'],
        ];
        foreach (['en', 'zh-CN'] as $locale) {
            foreach ($expected as $slug => $codes) {
                $payload = $this->getJson('/api/v0.5/career/jobs/'.$slug.'?locale='.$locale.'&projection_contract=career.detail.page.v1')
                    ->assertOk()->assertJsonPath('identity.canonical_slug', $slug)->json();
                $rows = collect($payload['ontology']['crosswalks']);
                $combined = count($codes) > 1;
                self::assertSame($codes, $rows->where('source_system', 'onet_soc_2019')->pluck('source_code')->all());
                self::assertSame(array_map(static fn (string $code): string => substr($code, 0, 7), $codes),
                    $rows->where('source_system', 'us_soc')->pluck('source_code')->all());
                self::assertSame([$combined ? 'combined_member' : 'direct_match'], $rows->pluck('mapping_type')->unique()->values()->all());
                if ($combined) {
                    $scope = collect($payload['career_page']['hero']['badges'])
                        ->first(static fn (string $badge): bool => str_starts_with($badge, $locale === 'en' ? 'U.S. occupational ' : '美国职业'));
                    self::assertIsString($scope);
                    foreach ($codes as $code) {
                        self::assertStringContainsString($code, $scope);
                    }
                }
            }
        }
        $this->getJson('/api/v0.5/career/jobs/actors?locale=en')
            ->assertOk()->assertJsonPath('ontology.crosswalks.0.source_code', '17-2112');
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

    public function test_bilingual_package_does_not_publish_a_traditional_detail_fallback(): void
    {
        $backendRoot = sys_get_temp_dir().'/career-bilingual-'.bin2hex(random_bytes(8));
        try {
            $this->installLocaleFixture($backendRoot, CareerCurrentAuthorityPackage::LOCALES);
            $this->publication(true);
            $reader = app(CareerFilePageReader::class);

            self::assertNull($reader->read('actors', 'zh-TW'));
            self::assertNull($reader->traditionalTitle('actors'));
            self::assertNull($reader->read('actors', 'zh-tw', presenceOnly: true));
            self::assertSame(['en', 'zh-CN'], $reader->read('actors', 'zh-CN')['locale_policy']['available_locales']);
            $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-TW')->assertNotFound();
            $this->getJson('/api/v0.5/career-jobs/actors/seo?org_id=0&locale=zh-TW')->assertNotFound();
        } finally {
            File::deleteDirectory($backendRoot);
        }
    }

    public function test_installed_traditional_page_serves_exact_content_with_an_isolated_cache(): void
    {
        $backendRoot = sys_get_temp_dir().'/career-trilingual-'.bin2hex(random_bytes(8));
        try {
            $this->installLocaleFixture($backendRoot, CareerContentV3Contract::LOCALES);
            $this->publication(true);

            self::assertSame('演員', app(CareerFilePageReader::class)->traditionalTitle('actors'));
            $traditionalUri = '/api/v0.5/career/jobs/actors?locale=zh-TW';
            $traditional = $this->getJson($traditionalUri)->assertOk()
                ->assertJsonPath('identity.canonical_slug', 'actors')
                ->assertJsonPath('titles.canonical_zh_tw', '演員')
                ->assertJsonPath('career_page.locale', 'zh-TW')
                ->assertJsonPath('career_page.display.path', '/zh-TW/career/jobs/actors')
                ->assertJsonPath('seo_contract.robots_policy', 'noindex,follow');
            $simplified = $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN')->assertOk()
                ->assertJsonPath('career_page.locale', 'zh-CN')
                ->assertJsonPath('seo_contract.robots_policy', 'index,follow');
            $this->assertNoStore($traditional);
            self::assertNotSame(
                CareerFilePageReader::cacheKey($traditional->json('career_page')),
                CareerFilePageReader::cacheKey($simplified->json('career_page')),
            );
            $this->getJson('/api/v0.5/career-jobs/actors/seo?org_id=0&locale=zh-TW')->assertOk()
                ->assertJsonPath('meta.canonical', '/zh-TW/career/jobs/actors')
                ->assertJsonPath('meta.robots', 'noindex,follow')
                ->assertJsonPath('meta.hreflang', []);
            $this->getJson($traditionalUri.'&projection_contract=career.detail.presence.v1')->assertOk()
                ->assertExactJson([
                    'bundle_kind' => 'career_job_presence',
                    'bundle_version' => 'career.detail.presence.v1',
                    'identity' => ['canonical_slug' => 'actors'],
                ]);
            $this->publication(false);
            $this->getJson($traditionalUri)->assertNotFound();
            self::assertNull(app(CareerFilePageReader::class)->traditionalTitle('actors'));
        } finally {
            File::deleteDirectory($backendRoot);
        }
    }

    private function installLocaleFixture(string $backendRoot, array $locales): void
    {
        $relative = CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $root = $backendRoot.'/'.$relative;
        $source = base_path($relative);
        mkdir($root.'/careers/actors', 0700, true);
        $localeCount = count($locales);
        $files = [];
        foreach ($locales as $locale) {
            $path = 'careers/actors/'.$locale.'.json';
            $bytes = (string) file_get_contents($source.'/careers/actors/'.($locale === 'zh-TW' ? 'zh-CN' : $locale).'.json');
            $page = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if ($locale === 'zh-TW') {
                $page['locale'] = $locale;
                $page['subject']['name'] = '演員';
                $page['display']['path'] = '/zh-TW/career/jobs/actors';
                unset($page['source_content_sha256']);
                $page['source_content_sha256'] = CareerCurrentAuthorityPackage::hashValue($page);
                $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
            }
            file_put_contents($root.'/'.$path, $bytes);
            $files[] = ['bytes' => strlen($bytes), 'canonical_slug' => 'actors', 'locale' => $locale, 'path' => $path,
                'sha256' => hash('sha256', $bytes), 'source_content_sha256' => $page['source_content_sha256']];
        }
        $slugHash = CareerCurrentAuthorityPackage::hashValue(['actors']);
        $manifest = [
            'authority_path' => 'backend/content_assets/career/current',
            'compiler_version' => CareerContentV3AuthorityPackage::COMPILER_VERSION,
            'contract_version' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'schema_version' => CareerContentV3AuthorityPackage::SCHEMA_VERSION,
            'source_registry_sha256' => str_repeat('a', 64),
            'coverage' => ['slugs' => 1, 'locales' => $localeCount, 'locale_pages' => $localeCount, 'files' => $localeCount, 'enhanced_locale_pages' => $localeCount, 'legacy_locale_pages' => 0],
            'files' => $files,
            'locales' => $locales,
            'set_hashes' => [
                'slug_set_sha256' => $slugHash,
                'locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue(array_map(static fn (string $locale): string => 'actors|'.$locale, $locales)),
                'source_semantic_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue(array_column($files, 'source_content_sha256')),
            ],
        ];
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($manifest);
        file_put_contents($root.'/manifest.json', CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest));

        $package = new CareerContentV3AuthorityPackage(1, 2, $localeCount, $slugHash);
        $reader = new CareerContentV3CanonicalReader($package, $backendRoot);
        $this->app->instance(CareerContentV3CanonicalReader::class, $reader);
        $this->app->instance(CareerPageProjector::class, new CareerPageProjector($reader, $package, new CareerContentV3FactResolver));
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
