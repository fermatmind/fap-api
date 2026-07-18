<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDetailReadModel10kTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        $old = ['slug' => 'one', 'revision' => 1];
        $new = ['slug' => 'one', 'revision' => 2];
        $oldVersion = $cache->publishJobDetailReadModel('one', 'en', $old);
        $newVersion = $cache->publishJobDetailReadModel('one', 'en', $new);

        $this->assertSame($new, $cache->jobDetailPayload('one', 'en'));
        Cache::forget(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:versions:'.$newVersion);
        $this->assertSame($old, $cache->jobDetailPayload('one', 'en'));
        $this->assertSame($oldVersion, Cache::get(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:lkg'));
    }

    public function test_published_cache_miss_returns_restricted_shell_without_building_on_request_path(): void
    {
        Queue::fake();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $read = app(PublicCareerAuthorityResponseCache::class)->jobDetailRead('one', 'en');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('degraded', $read['state']);
        $this->assertSame('one', data_get($read, 'payload.identity.canonical_slug'));
        $this->assertNull(app(PublicCareerAuthorityResponseCache::class)->jobDetailPayload('one', 'en'));
        $this->assertSame([], $queries, 'Cold-miss degradation must not query full detail authority on the request path.');

        $response = $this->getJson('/api/v0.5/career/jobs/one?locale=en');

        $response->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'degraded')
            ->assertJsonPath('identity.canonical_slug', 'one')
            ->assertJsonPath('detail_availability_v1.state', 'recovering')
            ->assertJsonPath('detail_availability_v1.retryable', true)
            ->assertJsonPath('claim_permissions.integrity_state', 'restricted')
            ->assertJsonPath('claim_permissions.allow_strong_claim', false)
            ->assertJsonPath('seo_contract.index_eligible', false)
            ->assertJsonPath('seo_contract.index_state', 'degraded_cache_recovery')
            ->assertJsonPath('seo_contract.robots_policy', 'noindex,follow')
            ->assertJsonPath('display_surface_v1.implementation_contract.surface_policy', 'restricted_cache_recovery_shell')
            ->assertJsonMissingPath('truth_layer.median_pay_usd_annual')
            ->assertJsonMissingPath('score_bundle')
            ->assertJsonMissingPath('structured_data.occupation.@type');

        Queue::assertPushed(WarmCareerJobDetailProjection::class, 1);
    }

    public function test_sync_queue_never_builds_or_dispatches_the_warm_job_on_the_request_path(): void
    {
        config()->set('queue.default', 'sync');
        Queue::fake();

        $this->getJson('/api/v0.5/career/jobs/one?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'degraded')
            ->assertJsonPath('detail_availability_v1.state', 'recovering');

        Queue::assertNothingPushed();
    }

    public function test_legacy_projection_is_promoted_and_reported_as_stale_for_the_current_response(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $legacy = ['identity' => ['canonical_slug' => 'one'], 'legacy' => true];
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

    public function test_warm_dispatch_failure_does_not_turn_restricted_shell_into_a_server_error(): void
    {
        Log::spy();
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->getJson('/api/v0.5/career/jobs/one?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'degraded')
            ->assertJsonPath('detail_availability_v1.state', 'recovering');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'career_job_detail_warm_dispatch_failed'
                && ($context['slug'] ?? null) === 'one'
                && ($context['locale'] ?? null) === 'en'
                && ($context['error_class'] ?? null) === \RuntimeException::class);
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

    public function test_http_response_reports_fresh_and_stale_cache_states(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $oldVersion = $cache->publishJobDetailReadModel('one', 'en', [
            'identity' => ['canonical_slug' => 'one'],
        ]);
        $newVersion = $cache->publishJobDetailReadModel('one', 'en', [
            'identity' => ['canonical_slug' => 'one'],
            'revision' => 2,
        ]);

        $this->getJson('/api/v0.5/career/jobs/one?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'fresh')
            ->assertJsonPath('revision', 2);

        Cache::forget(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:versions:'.$newVersion);

        $this->getJson('/api/v0.5/career/jobs/one?locale=en')
            ->assertOk()
            ->assertHeader('X-Fermat-Public-Read-Cache', 'stale')
            ->assertJsonMissingPath('revision');
        $this->assertSame($oldVersion, Cache::get(PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':one:en:lkg'));
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
