<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Compilation\CareerContentV3Projector;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
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
use Tests\Support\DynamicCareerContentV3CanonicalReader;
use Tests\TestCase;

final class CareerJobDetailCacheCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(
            CareerContentV3CanonicalReader::class,
            new DynamicCareerContentV3CanonicalReader(app(CareerContentV3Projector::class)),
        );
        Cache::flush();
        config()->set('queue.default', 'database');
    }

    public function test_it_classifies_the_complete_cache_chain_with_bounded_examples(): void
    {
        $slugs = ['active', 'broken-pointer', 'excluded', 'invalid-payload', 'legacy', 'lkg', 'missing-payload', 'missing-pointer'];
        $this->bindProjection($slugs, ['excluded|zh' => $this->heldItem('excluded', 'zh')]);
        $cache = app(PublicCareerAuthorityResponseCache::class);

        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('active', $locale, $this->detailPayload('active', $locale));
            $this->seedLkgOnly($cache, 'lkg', $locale);
            Cache::forever($cache->jobDetailCacheKey('legacy', $locale), $this->detailPayload('legacy', $locale));
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

    public function test_verify_only_accepts_a_non_empty_fully_covered_dynamic_cohort(): void
    {
        $this->bindProjection(['one']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('one', $locale, $this->detailPayload('one', $locale));
        }

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--verify-only' => true,
            '--minimum-targets' => 1,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $report['status']);
        $this->assertSame(2, $report['eligible_target_count']);
        $this->assertSame($report['eligible_target_count'], $report['covered_target_count']);
        $this->assertSame(0, $report['missing_count']);
        $this->assertSame(0, $report['broken_count']);
        $this->assertSame(1, $report['coverage_ratio']);
        $this->assertTrue($report['minimum_target_count_met']);
    }

    public function test_content_v3_is_derived_on_read_without_doubling_the_cached_authority_body(): void
    {
        $this->bindProjection(['one']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $page = [
            'hero' => ['h1' => 'One'],
            'definition_block' => ['body' => 'Authoritative body.'],
        ];
        $payload = [
            'display_surface_v1' => [
                'page' => [
                    'locale' => 'en',
                    'content' => $page,
                ],
                'sources' => [],
                'content_v3' => app(CareerContentV3Projector::class)->project('one', 'en', $page, null),
            ],
        ];
        $version = $cache->publishJobDetailReadModel('one', 'en', $payload);
        $encoded = Cache::get($this->versionPayloadKey('one', 'en', $version));
        $this->assertSame('career.job-detail.gzip-json.v1', $encoded['codec'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($encoded['sha256'] ?? ''));
        $this->assertIsString($encoded['payload'] ?? null);

        $historicalPayload = $payload;
        unset($historicalPayload['display_surface_v1']['content_v3']);
        Cache::forever($this->versionPayloadKey('one', 'en', $version), $historicalPayload);

        $compactionWrites = $cache->compactDerivedJobDetailContentV3(['one'], ['en']);

        $stored = Cache::get($this->versionPayloadKey('one', 'en', $version));
        $read = $cache->jobDetailRead('one', 'en');
        $publication = $cache->jobDetailPublicationSnapshot(['one'], ['en']);

        $this->assertSame(1, $compactionWrites);
        $this->assertIsArray($stored);
        $this->assertSame('career.job-detail.gzip-json.v1', $stored['codec'] ?? null);
        $this->assertIsString($stored['payload'] ?? null);
        $this->assertSame('fresh', $read['state']);
        $this->assertSame(
            'career.detail.content.v3',
            data_get($read, 'payload.display_surface_v1.content_v3.contract_version'),
        );
        $this->assertSame('one', data_get($read, 'payload.display_surface_v1.content_v3.subject.canonical_slug'));
        $this->assertSame('One', data_get($read, 'payload.display_surface_v1.content_v3.subject.name'));
        $this->assertSame('Authoritative body.', data_get(
            $read,
            'payload.display_surface_v1.content_v3.blocks.0.items.0.data.paragraphs.0',
        ));
        $this->assertSame('career.detail.content.v3', data_get(
            $publication,
            'one.en.payload.display_surface_v1.content_v3.contract_version',
        ));
    }

    public function test_verify_only_rejects_an_empty_dynamic_cohort(): void
    {
        $this->bindProjection([]);

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--verify-only' => true,
            '--minimum-targets' => 1,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('incomplete', $report['status']);
        $this->assertSame(0, $report['eligible_target_count']);
        $this->assertFalse($report['minimum_target_count_met']);
    }

    public function test_rollout_floor_does_not_override_incomplete_cache_coverage(): void
    {
        $slugs = array_map(static fn (int $index): string => sprintf('career-%04d', $index), range(1, 1046));
        $this->bindProjection($slugs);

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--verify-only' => true,
            '--minimum-targets' => 2092,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('incomplete', $report['status']);
        $this->assertSame(2092, $report['eligible_target_count']);
        $this->assertSame(2092, $report['missing_count']);
        $this->assertTrue($report['minimum_target_count_met']);
    }

    public function test_rollout_floor_fails_closed_before_deploy_or_repair_when_target_count_is_too_small(): void
    {
        $this->bindProjection(['one']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('one', $locale, $this->detailPayload('one', $locale));
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
            $cache->publishJobDetailReadModel('active', $locale, $this->detailPayload('active', $locale));
            $this->seedLkgOnly($cache, 'lkg', $locale);
            Cache::forever($cache->jobDetailCacheKey('legacy', $locale), $this->detailPayload('legacy', $locale));
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
        Queue::assertPushed(
            WarmCareerJobDetailProjection::class,
            static fn (WarmCareerJobDetailProjection $job): bool => $job->slug === 'missing'
                && $job->queue === 'default',
        );
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
                $cache->publishJobDetailReadModel($slug, $locale, $this->detailPayload($slug, $locale));
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

    public function test_production_direct_repair_requires_explicit_confirmation_before_writes(): void
    {
        $this->bindProjection(['one']);
        Queue::fake();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('career:verify-job-detail-cache-coverage', ['--repair-missing-direct' => true])
            ->expectsOutput('Production repair requires --confirm-production-write; verification remains read-only by default.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:default'));
    }

    public function test_direct_repair_modes_are_mutually_exclusive_and_verify_only_remains_read_only(): void
    {
        $this->bindProjection(['one']);
        Queue::fake();

        foreach ([
            ['--repair-missing-direct' => true, '--repair-missing' => true],
            ['--repair-missing-direct' => true, '--repair-missing-sync' => true],
        ] as $options) {
            $this->assertSame(1, Artisan::call('career:verify-job-detail-cache-coverage', $options));
            $this->assertStringContainsString('Repair modes are mutually exclusive.', Artisan::output());
        }

        $this->assertSame(1, Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing-direct' => true,
            '--verify-only' => true,
        ]));
        $this->assertStringContainsString('--verify-only cannot be combined with a repair mode.', Artisan::output());
        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:default'));
    }

    public function test_direct_repair_runs_in_process_with_stable_resume_and_is_idempotent(): void
    {
        $this->createOccupation('one');
        $this->createOccupation('two');
        $this->bindProjection(['one', 'two']);
        config()->set('queue.default', 'sync');
        Queue::fake();
        $options = [
            '--repair-missing-direct' => true,
            '--batch-size' => 2,
            '--resume-key' => 'candidate-direct',
            '--json' => true,
        ];

        $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options + ['--reset' => true]));
        $first = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('direct_repair_partial', $first['status']);
        $this->assertSame('incomplete', $first['coverage_status']);
        $this->assertSame('direct', $first['repair']['mode']);
        $this->assertSame(0, $first['repair']['cursor_before']);
        $this->assertSame(2, $first['repair']['cursor_after']);
        $this->assertSame(2, $first['repair']['remaining_targets']);
        $this->assertSame(2, $first['repair']['cached_target_count']);
        $this->assertSame(0, $first['repair']['failed_target_count']);
        Queue::assertNothingPushed();

        $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options));
        $second = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('direct_repair_completed', $second['status']);
        $this->assertSame('ready', $second['coverage_status']);
        $this->assertSame(4, $second['repair']['cursor_after']);
        $this->assertSame(0, $second['repair']['remaining_targets']);
        $this->assertSame(0, $second['repair']['remaining_repairable_count']);
        foreach (['one', 'two'] as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $this->assertIsString(Cache::get(
                    app(PublicCareerAuthorityResponseCache::class)->jobDetailActiveVersionKey($slug, $locale),
                ));
            }
        }

        $cache = app(PublicCareerAuthorityResponseCache::class);
        $versionBefore = Cache::get($cache->jobDetailActiveVersionKey('one', 'en'));
        $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options + ['--reset' => true]));
        $idempotent = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('direct_repair_completed', $idempotent['status']);
        $this->assertSame(0, $idempotent['repair']['cached_target_count']);
        $this->assertSame($versionBefore, Cache::get($cache->jobDetailActiveVersionKey('one', 'en')));
        Queue::assertNothingPushed();
    }

    public function test_direct_repair_rejects_oversized_batches_before_writes(): void
    {
        $this->bindProjection(['one']);
        Queue::fake();

        $this->artisan('career:verify-job-detail-cache-coverage', [
            '--repair-missing-direct' => true,
            '--batch-size' => 251,
        ])
            ->expectsOutput('--batch-size must be an integer between 1 and 250 for direct repair.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:default'));
    }

    public function test_direct_repair_failure_keeps_cursor_and_reports_partial_writes(): void
    {
        $this->createOccupation('buildable');
        $this->bindProjection(['buildable', 'unbuildable']);
        Queue::fake();

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing-direct' => true,
            '--batch-size' => 4,
            '--resume-key' => 'candidate-failure',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('direct_repair_failed', $report['status']);
        $this->assertSame(0, $report['repair']['cursor_before']);
        $this->assertSame(0, $report['repair']['cursor_after']);
        $this->assertSame(2, $report['repair']['cached_target_count']);
        $this->assertSame(2, $report['repair']['failed_target_count']);
        $this->assertTrue($report['repair']['write_executed']);
        $this->assertFalse(Cache::has('career:detail-cache-coverage-repair:v1:candidate-failure'));
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $this->assertIsString(Cache::get($cache->jobDetailActiveVersionKey('buildable', 'en')));
        $this->assertStringStartsWith(
            PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':',
            $cache->jobDetailActiveVersionKey('buildable', 'en'),
        );
        Queue::assertNothingPushed();
    }

    public function test_sync_repair_warms_only_missing_targets_and_rechecks_complete_coverage(): void
    {
        $this->createOccupation('missing');
        $this->bindProjection(['active', 'legacy', 'missing']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $cache->publishJobDetailReadModel('active', $locale, $this->detailPayload('active', $locale));
            Cache::forever($cache->jobDetailCacheKey('legacy', $locale), $this->detailPayload('legacy', $locale));
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

    public function test_sync_repair_fails_when_a_target_remains_missing_after_repair(): void
    {
        $this->createOccupation('buildable');
        $this->bindProjection(['buildable', 'unbuildable']);
        $cache = app(PublicCareerAuthorityResponseCache::class);

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing-sync' => true,
            '--maximum-sync-repairs' => 4,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('sync_repair_incomplete', $report['status']);
        $this->assertSame('incomplete', $report['coverage_status']);
        $this->assertSame(2, $report['covered_target_count']);
        $this->assertSame(2, $report['missing_count']);
        $this->assertSame(2, $report['repair']['cached_target_count']);
        $this->assertSame(2, $report['repair']['failed_target_count']);
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertIsString(Cache::get($cache->jobDetailActiveVersionKey('buildable', $locale)));
            $this->assertFalse(Cache::has($cache->jobDetailActiveVersionKey('unbuildable', $locale)));
        }
    }

    public function test_sync_repair_refuses_before_writes_when_missing_count_exceeds_limit(): void
    {
        $slugs = array_map(static fn (int $index): string => sprintf('career-%03d', $index), range(1, 251));
        $this->bindProjection($slugs);
        $cache = app(PublicCareerAuthorityResponseCache::class);

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing-sync' => true,
            '--locales' => 'en',
            '--maximum-sync-repairs' => 250,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('sync_repair_refused_over_limit', $report['status']);
        $this->assertFalse($report['repair']['write_executed']);
        $this->assertSame(251, $report['repair']['repairable_target_count']);
        $this->assertSame(250, $report['repair']['maximum_sync_repairs']);
        $this->assertFalse(Cache::has($cache->jobDetailActiveVersionKey('career-001', 'en')));
        $this->assertFalse(Cache::has($cache->jobDetailActiveVersionKey('career-251', 'en')));
    }

    public function test_sync_repair_rejects_a_limit_above_the_exact_1046_locale_cohort(): void
    {
        $exit = Artisan::call('career:verify-job-detail-cache-coverage', [
            '--repair-missing-sync' => true,
            '--maximum-sync-repairs' => 2093,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            '--maximum-sync-repairs must be an integer between 1 and 2092.',
            Artisan::output(),
        );
    }

    public function test_production_sync_repair_requires_explicit_confirmation_before_writes(): void
    {
        $this->bindProjection(['one']);
        $this->app->detectEnvironment(static fn (): string => 'production');
        $cache = app(PublicCareerAuthorityResponseCache::class);

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', ['--repair-missing-sync' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Production repair requires --confirm-production-write; verification remains read-only by default.',
            Artisan::output(),
        );

        $this->assertFalse(Cache::has($cache->jobDetailActiveVersionKey('one', 'en')));
        $this->assertFalse(Cache::has($cache->jobDetailActiveVersionKey('one', 'zh-CN')));
    }

    public function test_production_sync_repair_warms_only_the_current_public_cohort_and_is_idempotent(): void
    {
        $publicSlugs = array_map(static fn (int $index): string => sprintf('public-career-%02d', $index), range(1, 30));
        foreach ([...$publicSlugs, 'held-career'] as $slug) {
            $this->createOccupation($slug);
        }
        $this->bindProjection($publicSlugs);
        $this->app->detectEnvironment(static fn (): string => 'production');
        Queue::fake();

        $options = [
            '--repair-missing-sync' => true,
            '--confirm-production-write' => true,
            '--minimum-targets' => 1,
            '--maximum-sync-repairs' => 250,
            '--json' => true,
        ];

        $exit = Artisan::call('career:verify-job-detail-cache-coverage', $options);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertJson($output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('sync_repair_completed', $report['status']);
        $this->assertSame('ready', $report['coverage_status']);
        $this->assertSame(30, $report['published_slug_count']);
        $this->assertSame(60, $report['eligible_target_count']);
        $this->assertSame(60, $report['covered_target_count']);
        $this->assertSame(0, $report['missing_count']);
        $this->assertSame(0, $report['broken_count']);
        $this->assertSame(1, $report['coverage_ratio']);
        $this->assertTrue($report['minimum_target_count_met']);
        $this->assertTrue($report['repair']['write_executed']);
        $this->assertSame(60, $report['repair']['cached_target_count']);
        $this->assertSame(0, $report['repair']['failed_target_count']);

        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertFalse(Cache::has($cache->jobDetailActiveVersionKey('held-career', $locale)));
        }
        Queue::assertNothingPushed();

        $this->assertSame(0, Artisan::call('career:verify-job-detail-cache-coverage', $options));
        $idempotent = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('sync_repair_completed', $idempotent['status']);
        $this->assertFalse($idempotent['repair']['write_executed']);
        $this->assertSame(0, $idempotent['repair']['cached_target_count']);
        $this->assertSame(60, $idempotent['covered_target_count']);
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
        $cache->publishJobDetailReadModel($slug, $locale, $this->detailPayload($slug, $locale, 1));
        $activeVersion = $cache->publishJobDetailReadModel($slug, $locale, $this->detailPayload($slug, $locale, 2));
        Cache::forget($this->versionPayloadKey($slug, $locale, $activeVersion));
    }

    /** @return array<string, mixed> */
    private function detailPayload(string $slug, string $locale, int $revision = 1): array
    {
        return [
            'slug' => $slug,
            'revision' => $revision,
            'display_surface_v1' => [
                'page' => [
                    'locale' => $locale,
                    'content' => [
                        'hero' => ['h1' => ucfirst(str_replace('-', ' ', $slug))],
                    ],
                ],
                'sources' => [],
            ],
        ];
    }

    private function createOccupation(string $slug): void
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'coverage-family'],
            [
                'title_en' => 'Coverage Family',
                'title_zh' => '缓存覆盖职业族',
            ],
        );
        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => ucfirst(str_replace('-', ' ', $slug)),
            'canonical_title_zh' => '待补缓存职业',
            'search_h1_zh' => '待补缓存职业',
            'task_prototype_signature' => [],
            'trust_inheritance_scope' => [],
        ]);
    }

    private function versionPayloadKey(string $slug, string $locale, string $version): string
    {
        $locale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';

        return PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':'.$slug.':'.$locale.':versions:'.$version;
    }
}
