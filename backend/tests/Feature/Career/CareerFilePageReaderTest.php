<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\CareerFilePageReader;
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
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('career_job_display_assets', $query['query']);
            self::assertStringNotContainsString('career_jobs', $query['query']);
        }
    }

    public function test_missing_editorial_content_preserves_a_normal_english_page(): void
    {
        $this->publication(true);
        $bundle = app(CareerFilePageReader::class)->read('health-educators', 'en');
        self::assertSame([], $bundle['career_page']['content']['blocks']);
        self::assertSame('missing', $bundle['career_page']['hero']['metrics'][4]['availability']);
    }

    public function test_unpublished_identity_does_not_gain_a_public_page(): void
    {
        $this->publication(false);
        self::assertNull(app(CareerFilePageReader::class)->read('actors', 'en'));
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
            $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN')->assertOk()
                ->assertJsonPath('career_page.subject.canonical_slug', 'actors')
                ->assertJsonMissing(['poison' => 'old database prose']);
            $times[] = (microtime(true) - $start) * 1000;
        }
        sort($times);
        self::assertLessThan(400, $times[8]);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
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

    public function test_absent_authoritative_file_returns_explicit_503_instead_of_old_body(): void
    {
        $this->publication(true);
        $this->getJson('/api/v0.5/career/jobs/missing-file-role?locale=en')->assertStatus(503)
            ->assertJsonPath('error', 'CAREER_PAGE_UNAVAILABLE');
    }
}
