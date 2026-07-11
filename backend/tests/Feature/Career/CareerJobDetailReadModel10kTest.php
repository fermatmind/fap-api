<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDetailReadModel10kTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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
        $old = ['slug' => 'one', 'revision' => 1];
        $new = ['slug' => 'one', 'revision' => 2];
        $oldVersion = $cache->publishJobDetailReadModel('one', 'en', $old);
        $newVersion = $cache->publishJobDetailReadModel('one', 'en', $new);

        $this->assertSame($new, $cache->jobDetailPayload('one', 'en'));
        Cache::forget(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:versions:'.$newVersion);
        $this->assertSame($old, $cache->jobDetailPayload('one', 'en'));
        $this->assertSame($oldVersion, Cache::get(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:lkg'));
    }

    public function test_published_cache_miss_never_builds_on_the_request_path_or_returns_fake_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Career detail authority cache is unavailable for published slug one (en).');

        app(PublicCareerAuthorityResponseCache::class)->jobDetailPayload('one', 'en');
    }

    public function test_negative_cache_is_written_only_for_a_real_non_public_projection(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);

        $cache->publishJobDetailReadModel('blocked', 'en', ['slug' => 'blocked', 'leaked' => true]);
        $this->assertNull($cache->jobDetailPayload('blocked', 'en'));
        $this->assertTrue(Cache::has($cache->jobDetailNegativeKey('blocked', 'en')));
        $this->assertNull($cache->jobDetailPayload('unknown', 'en'));
        $this->assertFalse(Cache::has($cache->jobDetailNegativeKey('unknown', 'en')));
        $this->assertFalse(Cache::has($cache->jobDetailNegativeKey('one', 'en')));

        try {
            $cache->jobDetailPayload('one', 'en');
        } catch (\RuntimeException) {
            // A published miss is unavailable, never negative-cached.
        }
        $this->assertFalse(Cache::has($cache->jobDetailNegativeKey('one', 'en')));
    }

    public function test_publishing_one_slug_never_invalidates_another_slug_or_locale(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishJobDetailReadModel('one', 'en', ['slug' => 'one']);
        $twoKey = $cache->jobDetailActiveVersionKey('two', 'en');
        $cache->publishJobDetailReadModel('two', 'en', ['slug' => 'two']);
        $twoVersion = Cache::get($twoKey);

        $cache->publishJobDetailReadModel('one', 'en', ['slug' => 'one', 'revision' => 2]);

        $this->assertSame($twoVersion, Cache::get($twoKey));
        $this->assertSame(['slug' => 'two'], $cache->jobDetailPayload('two', 'en'));
    }

    public function test_warm_detail_api_reads_only_the_projection_with_p95_below_400ms(): void
    {
        app(PublicCareerAuthorityResponseCache::class)->publishJobDetailReadModel('one', 'en', [
            'bundle_kind' => 'career_job_detail',
            'identity' => ['canonical_slug' => 'one'],
            'seo_contract' => ['canonical_path' => '/en/career/jobs/one'],
        ]);
        $durations = [];

        for ($request = 0; $request < 25; $request++) {
            $started = hrtime(true);
            $this->getJson('/api/v0.5/career/jobs/one?locale=en')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', 'one');
            $durations[] = (hrtime(true) - $started) / 1_000_000;
        }

        sort($durations);
        $p95 = $durations[(int) floor((count($durations) - 1) * 0.95)];
        $this->assertLessThanOrEqual(400, $p95, sprintf('warm detail p95 %.3fms exceeded 400ms', $p95));
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
}
