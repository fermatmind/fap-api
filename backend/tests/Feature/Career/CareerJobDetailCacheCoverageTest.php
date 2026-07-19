<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\CareerJobDetailCacheCoverageService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDetailCacheCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('queue.default', 'database');
    }

    public function test_it_classifies_the_complete_cache_chain_with_bounded_examples(): void
    {
        $slugs = ['active', 'broken-pointer', 'excluded', 'invalid-payload', 'legacy', 'lkg', 'missing-payload', 'missing-pointer'];
        $this->bindProjection($slugs, ['excluded|zh' => $this->heldItem('excluded', 'zh')]);
        $cache = app(PublicCareerAuthorityResponseCache::class);

        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('active', $locale, ['slug' => 'active']);
            $this->seedLkgOnly($cache, 'lkg', $locale);
            Cache::forever($cache->jobDetailCacheKey('legacy', $locale), ['slug' => 'legacy']);
            $this->assertTrue($cache->jobDetailCacheIsReady('lkg', $locale));
            $this->assertTrue($cache->jobDetailCacheIsReady('legacy', $locale));
            Cache::forever($cache->jobDetailActiveVersionKey('missing-payload', $locale), 'ghost');
            Cache::forever($cache->jobDetailActiveVersionKey('broken-pointer', $locale), ['not-a-version']);
            Cache::forever($cache->jobDetailActiveVersionKey('invalid-payload', $locale), 'invalid');
            Cache::forever($this->versionPayloadKey('invalid-payload', $locale, 'invalid'), 'not-an-array');
        }

        $inspection = app(CareerJobDetailCacheCoverageService::class)->inspect(['en', 'zh-CN'], 1);
        $report = $inspection['report'];

        $this->assertSame(8, $report['published_slug_count']);
        $this->assertSame(2, $report['locale_count']);
        $this->assertSame(16, $report['expected_target_count']);
        $this->assertSame(2, $report['ready_active_count']);
        $this->assertSame(2, $report['ready_lkg_count']);
        $this->assertSame(2, $report['legacy_migratable_count']);
        $this->assertSame(3, $report['missing_pointer_count']);
        $this->assertSame(2, $report['missing_payload_count']);
        $this->assertSame(2, $report['broken_pointer_count']);
        $this->assertSame(2, $report['invalid_payload_count']);
        $this->assertSame(5, $report['missing_count']);
        $this->assertSame(4, $report['broken_count']);
        $this->assertSame(1, $report['excluded_count']);
        $this->assertSame(0.4, $report['coverage_ratio']);
        $this->assertSame('incomplete', $report['status']);
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertSame('stale', $cache->jobDetailRead('lkg', $locale)['state']);
            $this->assertSame('stale', $cache->jobDetailRead('legacy', $locale)['state']);
        }
        foreach ($report['examples'] as $examples) {
            $this->assertCount(1, $examples);
        }
    }

    public function test_generated_1046_slug_fixture_reports_2092_dynamic_targets(): void
    {
        $slugs = array_map(static fn (int $index): string => sprintf('career-%04d', $index), range(1, 1046));
        $this->bindProjection($slugs);

        $report = app(CareerJobDetailCacheCoverageService::class)->inspect(['en', 'zh-CN'])['report'];

        $this->assertSame(1046, $report['published_slug_count']);
        $this->assertSame(2, $report['locale_count']);
        $this->assertSame(2092, $report['expected_target_count']);
        $this->assertSame(2092, $report['missing_pointer_count']);
        $this->assertSame(2092, $report['missing_count']);
        $this->assertSame(0.0, $report['coverage_ratio']);
        $this->assertSame('incomplete', $report['status']);
    }

    public function test_verify_only_is_default_and_performs_no_cache_write_or_queue_dispatch(): void
    {
        $this->bindProjection(['one']);
        Queue::fake();
        Cache::forever('coverage-sentinel', ['unchanged' => true]);

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('incomplete', $report['status']);
        $this->assertSame(2, $report['missing_count']);
        $this->assertSame(['unchanged' => true], Cache::get('coverage-sentinel'));
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:default'));
        Queue::assertNothingPushed();
    }

    public function test_rollout_floor_fails_closed_before_deploy_or_repair_when_target_count_is_too_small(): void
    {
        $this->bindProjection(['one']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('one', $locale, ['slug' => 'one']);
        }
        Queue::fake();

        $verifyExit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--verify-only' => true,
            '--minimum-targets' => 2092,
            '--json' => true,
        ]);
        $verifyReport = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $verifyExit);
        $this->assertSame('incomplete', $verifyReport['status']);
        $this->assertSame(2, $verifyReport['eligible_target_count']);
        $this->assertSame(2092, $verifyReport['minimum_target_count']);
        $this->assertFalse($verifyReport['minimum_target_count_met']);

        $repairExit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing' => true,
            '--minimum-targets' => 2092,
            '--json' => true,
        ]);

        $this->assertSame(1, $repairExit);
        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:default'));
    }

    public function test_rollout_floor_rejects_malformed_cli_values_before_repair(): void
    {
        $this->bindProjection(['one']);
        Queue::fake();

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing' => true,
            '--minimum-targets' => '2,092',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--minimum-targets must be a non-negative base-10 integer.', Artisan::output());
        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:default'));
    }

    public function test_repair_queues_only_missing_or_broken_targets_and_preserves_healthy_caches(): void
    {
        $this->bindProjection(['active', 'legacy', 'lkg', 'missing']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('active', $locale, ['slug' => 'active']);
            $this->seedLkgOnly($cache, 'lkg', $locale);
            Cache::forever($cache->jobDetailCacheKey('legacy', $locale), ['slug' => 'legacy']);
        }
        $activeVersions = array_map(
            static fn (string $locale): mixed => Cache::get($cache->jobDetailActiveVersionKey('active', $locale)),
            ['en', 'zh-CN'],
        );
        Queue::fake();

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing' => true,
            '--batch-size' => 100,
            '--resume-key' => 'healthy-preserved',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('repair_queued_complete', $report['status']);
        $this->assertSame(2, $report['repair']['queued_jobs']);
        Queue::assertPushed(WarmCareerJobDetailProjection::class, 2);
        Queue::assertPushed(WarmCareerJobDetailProjection::class, static fn (WarmCareerJobDetailProjection $job): bool => $job->slug === 'missing');
        foreach (['en', 'zh-CN'] as $index => $locale) {
            $this->assertSame($activeVersions[$index], Cache::get($cache->jobDetailActiveVersionKey('active', $locale)));
            $this->assertTrue(Cache::has($cache->jobDetailCacheKey('legacy', $locale)));
        }
    }

    public function test_repair_resume_is_stable_within_a_pass_and_wraps_to_repair_later_regressions(): void
    {
        $this->bindProjection(['one', 'three', 'two']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        Queue::fake();
        $options = [
            '--repair-missing' => true,
            '--batch-size' => 2,
            '--resume-key' => 'resume-test',
            '--json' => true,
        ];

        foreach ([2, 4, 6] as $expectedCursor) {
            $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options));
            $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($expectedCursor, $report['repair']['cursor_after']);
        }
        Queue::assertPushed(WarmCareerJobDetailProjection::class, 6);

        foreach (['one', 'three', 'two'] as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $cache->publishJobDetailReadModel($slug, $locale, ['slug' => $slug]);
            }
        }
        $this->travel(301)->seconds();

        $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['repair']['cursor_wrapped']);
        $this->assertSame(0, $report['repair']['cursor_before']);
        $this->assertSame(2, $report['repair']['cursor_after']);
        $this->assertSame(0, $report['repair']['queued_jobs']);
        Queue::assertPushed(WarmCareerJobDetailProjection::class, 6);

        Cache::forget($cache->jobDetailActiveVersionKey('one', 'en'));
        foreach ([4, 6] as $expectedCursor) {
            $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options));
            $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($report['repair']['cursor_wrapped']);
            $this->assertSame($expectedCursor, $report['repair']['cursor_after']);
            $this->assertSame(0, $report['repair']['queued_jobs']);
        }

        $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['repair']['cursor_wrapped']);
        $this->assertSame(0, $report['repair']['cursor_before']);
        $this->assertSame(1, $report['repair']['queued_jobs']);
        Queue::assertPushed(WarmCareerJobDetailProjection::class, 7);
        Queue::assertPushed(WarmCareerJobDetailProjection::class, static fn (WarmCareerJobDetailProjection $job): bool => $job->slug === 'one' && $job->locale === 'en');
    }

    public function test_production_repair_requires_explicit_confirmation(): void
    {
        $this->bindProjection(['one']);
        Queue::fake();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('career:verify-job-detail-cache-coverage', ['--repair-missing' => true])
            ->expectsOutput('Production repair requires --confirm-production-write; verification remains read-only by default.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:default'));
    }

    public function test_sync_repair_warms_only_missing_targets_and_rechecks_complete_coverage(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'coverage-family',
            'title_en' => 'Coverage Family',
            'title_zh' => '缓存覆盖职业族',
        ]);
        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'missing',
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Missing Cache Career',
            'canonical_title_zh' => '待补缓存职业',
            'search_h1_zh' => '待补缓存职业',
            'task_prototype_signature' => [],
            'trust_inheritance_scope' => [],
        ]);
        $this->bindProjection(['active', 'legacy', 'missing']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('active', $locale, ['slug' => 'active']);
            Cache::forever($cache->jobDetailCacheKey('legacy', $locale), ['slug' => 'legacy']);
        }
        $activeVersions = array_map(
            static fn (string $locale): mixed => Cache::get($cache->jobDetailActiveVersionKey('active', $locale)),
            ['en', 'zh-CN'],
        );

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing-sync' => true,
            '--maximum-sync-repairs' => 2,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('sync_repair_completed', $report['status']);
        $this->assertSame('ready', $report['coverage_status']);
        $this->assertTrue($report['repair']['write_executed']);
        $this->assertSame(2, $report['repair']['cached_target_count']);
        $this->assertSame(0, $report['repair']['failed_target_count']);
        foreach (['en', 'zh-CN'] as $index => $locale) {
            $this->assertSame($activeVersions[$index], Cache::get($cache->jobDetailActiveVersionKey('active', $locale)));
            $this->assertTrue(Cache::has($cache->jobDetailCacheKey('legacy', $locale)));
            $this->assertIsString(Cache::get($cache->jobDetailActiveVersionKey('missing', $locale)));
        }
    }

    public function test_sync_repair_refuses_before_writes_when_missing_count_exceeds_limit(): void
    {
        $this->bindProjection(['one', 'two']);
        $cache = app(PublicCareerAuthorityResponseCache::class);

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing-sync' => true,
            '--maximum-sync-repairs' => 3,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('sync_repair_refused_over_limit', $report['status']);
        $this->assertFalse($report['repair']['write_executed']);
        $this->assertSame(4, $report['repair']['repairable_target_count']);
        foreach (['one', 'two'] as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $this->assertFalse(Cache::has($cache->jobDetailActiveVersionKey($slug, $locale)));
            }
        }
    }

    public function test_sync_repair_is_forbidden_in_production(): void
    {
        $this->bindProjection(['one']);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('career:verify-job-detail-cache-coverage', ['--repair-missing-sync' => true])
            ->expectsOutput('Synchronous cache coverage repair is forbidden in production.')
            ->assertExitCode(1);
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, array<string, mixed>>  $overrides
     */
    private function bindProjection(array $slugs, array $overrides = []): void
    {
        $items = [];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[$slug.'|'.$locale] = $overrides[$slug.'|'.$locale] ?? $this->publishedItem($slug, $locale);
            }
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(items: $items),
        );
        $this->app->forgetInstance(CareerJobDetailCacheCoverageService::class);
        $this->app->forgetInstance(PublicCareerAuthorityResponseCache::class);
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

    /** @return array<string, mixed> */
    private function heldItem(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'runtime_publish_state' => 'held',
            'detail_route_enabled' => false,
            'robots_indexable' => false,
            'release_gate_pass' => false,
        ];
    }

    private function seedLkgOnly(PublicCareerAuthorityResponseCache $cache, string $slug, string $locale): void
    {
        $cache->publishJobDetailReadModel($slug, $locale, ['slug' => $slug, 'revision' => 1]);
        $activeVersion = $cache->publishJobDetailReadModel($slug, $locale, ['slug' => $slug, 'revision' => 2]);
        Cache::forget($this->versionPayloadKey($slug, $locale, $activeVersion));
    }

    private function versionPayloadKey(string $slug, string $locale, string $version): string
    {
        $locale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';

        return PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':'.$slug.':'.$locale.':versions:'.$version;
    }
}
