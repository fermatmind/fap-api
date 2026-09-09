<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Support\Career\CareerVerifyOnlyRequestAuthorizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesCareerDetailCacheFixture;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDetailReadModel10kTest extends TestCase
{
    use UsesCareerDetailCacheFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installCareerDetailCacheFixture();
        Cache::flush();
        config()->set('queue.default', 'database');
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(items: [
                'one|en' => $this->publishedItem('one', 'en'),
                'one|zh' => $this->publishedItem('one', 'zh'),
                'two|en' => $this->publishedItem('two', 'en'),
                'blocked|en' => [
                    'slug' => 'blocked',
                    'locale' => 'en',
                    'runtime_publish_state' => 'held',
                    'detail_route_enabled' => false,
                    'robots_indexable' => false,
                    'release_gate_pass' => false,
                ],
            ]),
        );
    }

    public function test_versioned_detail_projection_switches_atomically_and_uses_lkg(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $old = $this->detailCacheFixture(['slug' => 'one', 'revision' => 1]);
        $new = $this->detailCacheFixture(['slug' => 'one', 'revision' => 2]);
        $oldVersion = $cache->publishJobDetailReadModel('one', 'en', $old);
        $newVersion = $cache->publishJobDetailReadModel('one', 'en', $new);

        $this->assertSame($new, $cache->jobDetailPayload('one', 'en'));
        Cache::forget(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:versions:'.$newVersion);
        $this->assertSame($old, $cache->jobDetailPayload('one', 'en'));
        $this->assertSame($oldVersion, Cache::get(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:lkg'));
    }

    public function test_verify_only_job_index_read_suppresses_cache_state_log(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishJobIndexReadModelsAtomically([
            'en' => ['items' => []],
        ]);
        Log::spy();

        $this->assertSame([], $cache->jobIndexPayload('en', recordCacheState: false)['items']);
        Log::shouldNotHaveReceived('info');
    }

    public function test_legacy_projection_is_promoted_and_reported_as_stale_for_the_current_response(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $legacy = $this->detailCacheFixture(['identity' => ['canonical_slug' => 'one'], 'legacy' => true]);
        Cache::forever($cache->jobDetailCacheKey('one', 'en'), $legacy);

        $read = $cache->jobDetailRead('one', 'en');

        $this->assertSame('stale', $read['state']);
        $this->assertSame($legacy, $read['payload']);
        $this->assertIsString(Cache::get($cache->jobDetailActiveVersionKey('one', 'en')));
        $this->assertFalse(Cache::has($cache->jobDetailCacheKey('one', 'en')));
    }

    public function test_repeated_published_cache_misses_dispatch_only_one_unique_warm_job(): void
    {
        Queue::fake();
        $cache = app(PublicCareerAuthorityResponseCache::class);

        $this->assertSame('degraded', $cache->jobDetailRead('one', 'en')['state']);
        $this->assertSame('degraded', $cache->jobDetailRead('one', 'en')['state']);
        $this->assertSame('degraded', $cache->jobDetailRead('one', 'en')['state']);

        Queue::assertPushed(WarmCareerJobDetailProjection::class, 1);
    }

    public function test_negative_cache_is_written_only_for_a_real_non_public_projection(): void
    {
        Queue::fake();
        $cache = app(PublicCareerAuthorityResponseCache::class);

        $cache->publishJobDetailReadModel('blocked', 'en', ['slug' => 'blocked', 'leaked' => true]);
        $this->assertNull($cache->jobDetailPayload('blocked', 'en'));
        $this->assertTrue(Cache::has($cache->jobDetailNegativeKey('blocked', 'en')));
        $this->assertNull($cache->jobDetailPayload('unknown', 'en'));
        $this->assertFalse(Cache::has($cache->jobDetailNegativeKey('unknown', 'en')));
        $this->assertFalse(Cache::has($cache->jobDetailNegativeKey('one', 'en')));
        Queue::assertNothingPushed();

        $cache->jobDetailPayload('one', 'en');
        $this->assertFalse(Cache::has($cache->jobDetailNegativeKey('one', 'en')));
        Queue::assertPushed(WarmCareerJobDetailProjection::class, 1);
    }

    public function test_publishing_one_slug_never_invalidates_another_slug_or_locale(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishJobDetailReadModel('one', 'en', ['slug' => 'one']);
        $twoKey = $cache->jobDetailActiveVersionKey('two', 'en');
        $cache->publishJobDetailReadModel('two', 'en', $this->detailCacheFixture(['slug' => 'two'], 'two'));
        $twoVersion = Cache::get($twoKey);

        $cache->publishJobDetailReadModel('one', 'en', $this->detailCacheFixture(['slug' => 'one', 'revision' => 2]));

        $this->assertSame($twoVersion, Cache::get($twoKey));
        $this->assertSame($this->detailCacheFixture(['slug' => 'two'], 'two'), $cache->jobDetailPayload('two', 'en'));
    }

    public function test_resumable_command_queues_bounded_parallel_jobs_instead_of_serially_warming_20k_entries(): void
    {
        Queue::fake();
        $slugs = array_map(static fn (int $index): string => 'career-'.$index, range(1, 600));

        $firstExit = Artisan::call('career:queue-warm-job-details', [
            '--slugs' => implode(',', $slugs),
            '--locales' => 'en,zh-CN',
            '--batch-size' => 250,
            '--resume-key' => 'test-10k',
            '--reset' => true,
            '--json' => true,
        ]);
        $first = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $firstExit);
        $this->assertSame('queued_partial', $first['status']);
        $this->assertSame(500, $first['queued_jobs']);
        $this->assertSame(250, $first['cursor_after']);
        Queue::assertPushed(WarmCareerJobDetailProjection::class, 500);

        Artisan::call('career:queue-warm-job-details', [
            '--slugs' => implode(',', $slugs),
            '--locales' => 'en,zh-CN',
            '--batch-size' => 250,
            '--resume-key' => 'test-10k',
            '--json' => true,
        ]);
        $second = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(500, $second['cursor_after']);
        $this->assertSame(100, $second['remaining_slugs']);
        Queue::assertPushed(WarmCareerJobDetailProjection::class, 1000);
    }

    /** @return array<string, mixed> */
    private function publishedItem(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'runtime_publish_state' => 'published',
            'detail_route_enabled' => true,
            'robots_indexable' => true,
            'release_gate_pass' => true,
        ];
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
}
