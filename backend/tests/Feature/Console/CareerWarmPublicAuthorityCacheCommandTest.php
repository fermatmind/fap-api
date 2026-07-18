<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CareerWarmPublicAuthorityCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_warms_public_authority_payloads_for_http_reuse(): void
    {
        Cache::forget(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
        Cache::forget(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY);
        Cache::forget(PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':en:public');
        Cache::forget(PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY);
        Cache::forget(PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':zh-CN:public');

        $this->artisan('career:warm-public-authority-cache')
            ->expectsOutputToContain('career_warm_phase=dataset_payloads state=starting')
            ->expectsOutputToContain('career_warm_phase=dataset_payloads state=finished')
            ->expectsOutputToContain('career_warm_phase=job_index_en state=starting')
            ->expectsOutputToContain('career_warm_phase=job_index_en state=finished')
            ->expectsOutputToContain('career_warm_phase=job_index_zh_cn state=starting')
            ->expectsOutputToContain('career_warm_phase=job_index_zh_cn state=finished')
            ->expectsOutputToContain('career_warm_phase=launch_governance_closure state=starting')
            ->expectsOutputToContain('career_warm_phase=launch_governance_closure state=finished')
            ->expectsOutputToContain('status=warmed')
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY)
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY)
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':en:public')
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':zh-CN:public')
            ->expectsOutputToContain(PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY)
            ->assertExitCode(0);

        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY));
        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY));
        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':en:public'));
        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':zh-CN:public'));
        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY));
    }

    public function test_directory_only_warms_directory_versions_without_touching_broader_career_caches(): void
    {
        $sentinels = [
            PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY => ['sentinel' => 'dataset'],
            PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY => ['sentinel' => 'method'],
            PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':en:public' => ['sentinel' => 'jobs-en'],
            PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':zh-CN:public' => ['sentinel' => 'jobs-zh'],
            PublicCareerAuthorityResponseCache::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY => ['sentinel' => 'governance'],
        ];
        foreach ($sentinels as $key => $value) {
            Cache::forever($key, $value);
        }

        $exitCode = Artisan::call('career:warm-public-authority-cache', [
            '--directory-only' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('warmed', $report['status']);
        $this->assertSame(['career_directory_en', 'career_directory_zh_cn'], array_keys($report['entries']));
        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertSame('ready', app(PublicCareerAuthorityResponseCache::class)->directoryCacheStatus($locale)['status']);
        }
        foreach ($sentinels as $key => $value) {
            $this->assertSame($value, Cache::get($key), $key);
        }
    }

    public function test_directory_only_rejects_job_detail_warm_options(): void
    {
        $this->artisan('career:warm-public-authority-cache', [
            '--directory-only' => true,
            '--job-detail-slugs' => 'example',
        ])
            ->expectsOutput('--directory-only cannot be combined with job-detail warm options.')
            ->assertExitCode(1);
    }

    public function test_warm_path_reuses_expensive_first_wave_authority_builders_within_one_process(): void
    {
        $repoRoot = dirname(__DIR__, 4);

        $datasetAuthorityBuilder = file_get_contents($repoRoot.'/backend/app/Services/Career/Dataset/CareerFullDatasetAuthorityBuilder.php');
        $this->assertIsString($datasetAuthorityBuilder);
        $this->assertStringContainsString('buildFromReleaseLedger($ledger)', $datasetAuthorityBuilder);
        $this->assertStringNotContainsString('$this->strongIndexEligibilityService->build()->toArray()', $datasetAuthorityBuilder);

        foreach ([
            '/backend/app/Domain/Career/Publish/FirstWavePublishReadyValidator.php' => 'private static array $validationMemo',
            '/backend/app/Domain/Career/Publish/CareerFirstWaveLaunchTierSummaryService.php' => 'private static ?CareerFirstWaveLaunchTierSummary $summaryMemo',
            '/backend/app/Domain/Career/Publish/FirstWaveReadinessSummaryService.php' => 'private static array $summaryMemo',
            '/backend/app/Domain/Career/Publish/CareerFirstWaveLifecycleSummaryService.php' => 'private static ?CareerFirstWaveLifecycleSummary $summaryMemo',
            '/backend/app/Domain/Career/Publish/CareerFirstWaveDiscoverabilityManifestService.php' => 'private static ?CareerFirstWaveDiscoverabilityManifest $manifestMemo',
            '/backend/app/Domain/Career/Publish/CareerFirstWaveNextStepLinksService.php' => 'private array $summaryBySlug',
        ] as $relativePath => $expectedNeedle) {
            $source = file_get_contents($repoRoot.$relativePath);
            $this->assertIsString($source);
            $this->assertStringContainsString($expectedNeedle, $source, $relativePath);
        }
    }

    public function test_command_can_forget_and_warm_targeted_job_detail_cache_by_slug_and_locale(): void
    {
        $legacyCacheKey = PublicCareerAuthorityResponseCache::JOB_DETAIL_CACHE_KEY_PREFIX.':missing-career:zh-CN';
        $cacheKey = PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':missing-career:zh-CN:active';
        Cache::forever($legacyCacheKey, ['stale' => true]);

        $this->artisan('career:warm-public-authority-cache', [
            '--job-detail-slugs' => 'missing-career',
            '--job-detail-locales' => 'zh-CN',
            '--forget-job-detail' => true,
            '--job-detail-only' => true,
        ])
            ->expectsOutputToContain('career_warm_phase=job_detail_zh_cn_missing-career state=starting')
            ->expectsOutputToContain('career_warm_phase=job_detail_zh_cn_missing-career state=finished')
            ->expectsOutputToContain($cacheKey)
            ->expectsOutputToContain('status=warmed')
            ->assertExitCode(0);

        $this->assertFalse(Cache::has($legacyCacheKey));
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_command_emits_json_report_for_targeted_job_detail_cache_refresh(): void
    {
        $legacyCacheKey = PublicCareerAuthorityResponseCache::JOB_DETAIL_CACHE_KEY_PREFIX.':missing-career:zh-CN';
        $cacheKey = PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':missing-career:zh-CN:active';
        Cache::forever($legacyCacheKey, ['stale' => true]);

        $exitCode = Artisan::call('career:warm-public-authority-cache', [
            '--job-detail-slugs' => 'missing-career',
            '--job-detail-locales' => 'zh-CN',
            '--forget-job-detail' => true,
            '--job-detail-only' => true,
            '--json' => true,
        ]);

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exitCode);
        $this->assertSame('warmed', $report['status']);
        $this->assertSame($cacheKey, $report['entries']['job_detail_zh_cn_missing-career']['cache_key']);
        $this->assertSame('missing', $report['entries']['job_detail_zh_cn_missing-career']['status']);
        $this->assertSame(0, $report['entries']['job_detail_zh_cn_missing-career']['member_count']);
        $this->assertFalse(Cache::has($legacyCacheKey));
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_combined_warm_finishes_targeted_details_before_activating_directory(): void
    {
        $exitCode = Artisan::call('career:warm-public-authority-cache', [
            '--job-detail-slugs' => 'missing-career',
            '--job-detail-locales' => 'en',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $detailFinished = strpos($output, 'career_warm_phase=job_detail_en_missing-career state=finished');
        $directoryStarted = strpos($output, 'career_warm_phase=job_index_en state=starting');
        $this->assertIsInt($detailFinished);
        $this->assertIsInt($directoryStarted);
        $this->assertLessThan($directoryStarted, $detailFinished);
    }

    public function test_command_can_warm_job_detail_caches_from_manifest_for_multiple_locales(): void
    {
        $manifestPath = tempnam(sys_get_temp_dir(), 'career-job-detail-manifest-');
        $this->assertIsString($manifestPath);
        file_put_contents($manifestPath, json_encode([
            'items' => [
                ['slug' => 'missing-career'],
                ['slug' => 'another-missing-career'],
                ['slug' => 'missing-career'],
                ['slug' => ''],
            ],
        ], JSON_THROW_ON_ERROR));

        foreach (['missing-career', 'another-missing-career'] as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                Cache::forever(PublicCareerAuthorityResponseCache::JOB_DETAIL_CACHE_KEY_PREFIX.':'.$slug.':'.$locale, ['stale' => true]);
            }
        }

        try {
            $exitCode = Artisan::call('career:warm-public-authority-cache', [
                '--job-detail-manifest' => $manifestPath,
                '--job-detail-manifest-source' => 'items',
                '--job-detail-locales' => 'en,zh-CN',
                '--forget-job-detail' => true,
                '--job-detail-only' => true,
                '--json' => true,
            ]);

            $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exitCode);
            $this->assertSame('warmed', $report['status']);
            $this->assertSame(2, $report['job_detail_refresh']['slug_count']);
            $this->assertSame(['en', 'zh-CN'], $report['job_detail_refresh']['locales']);
            $this->assertSame(4, $report['job_detail_refresh']['expected_cache_entries']);
            $this->assertSame(4, $report['job_detail_refresh']['observed_cache_entries']);
            $this->assertSame(['missing' => 4], $report['job_detail_refresh']['status_counts']);
            $this->assertSame($manifestPath, $report['job_detail_refresh']['manifest_path']);
            $this->assertSame('items', $report['job_detail_refresh']['manifest_source']);
            $this->assertArrayHasKey('job_detail_en_missing-career', $report['entries']);
            $this->assertArrayHasKey('job_detail_zh_cn_another-missing-career', $report['entries']);

            foreach (['missing-career', 'another-missing-career'] as $slug) {
                foreach (['en', 'zh-CN'] as $locale) {
                    $this->assertFalse(Cache::has(PublicCareerAuthorityResponseCache::JOB_DETAIL_CACHE_KEY_PREFIX.':'.$slug.':'.$locale));
                }
            }
        } finally {
            @unlink($manifestPath);
        }
    }

    public function test_job_detail_only_requires_target_slugs(): void
    {
        $this->artisan('career:warm-public-authority-cache', [
            '--job-detail-only' => true,
        ])
            ->expectsOutput('--job-detail-only requires --job-detail-slugs or --job-detail-manifest.')
            ->assertExitCode(1);
    }

    public function test_directory_versions_switch_atomically_and_fall_back_to_lkg(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $oldPayload = ['public_count' => 1046, 'items' => [['slug' => 'old']]];
        $newPayload = ['public_count' => 1047, 'items' => [['slug' => 'new']]];

        $oldVersion = $cache->publishDirectoryReadModel('zh-CN', $oldPayload);
        $newVersion = $cache->publishDirectoryReadModel('zh-CN', $newPayload);

        $this->assertSame($newPayload, $cache->directoryReadModelPayload('zh-CN'));
        Cache::forget(PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':zh-CN:versions:'.$newVersion);
        $this->assertSame($oldPayload, $cache->directoryReadModelPayload('zh-CN'));
        $this->assertSame($oldVersion, $cache->directoryCacheStatus('zh-CN')['lkg_version']);
    }

    public function test_multi_locale_directory_activation_restores_all_pointers_when_later_locale_fails(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $oldEn = ['public_count' => 1, 'items' => [['slug' => 'old-en']]];
        $oldZh = ['public_count' => 1, 'items' => [['slug' => 'old-zh']]];
        $oldEnVersion = $cache->publishDirectoryReadModel('en', $oldEn);
        $oldZhVersion = $cache->publishDirectoryReadModel('zh-CN', $oldZh);
        $cacheManager = Cache::getFacadeRoot();
        $zhActiveKey = PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':zh-CN:active';

        try {
            $cacheMock = Cache::partialMock();
            $cacheMock->shouldReceive('lock')
                ->andReturnUsing(static fn (string $key, int $seconds) => $cacheManager->lock($key, $seconds));
            $cacheMock->shouldReceive('get')
                ->andReturnUsing(static fn (string $key, mixed $default = null): mixed => $cacheManager->get($key, $default));
            $cacheMock->shouldReceive('has')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->has($key));
            $cacheMock->shouldReceive('forget')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->forget($key));
            $cacheMock->shouldReceive('forever')
                ->andReturnUsing(static function (string $key, mixed $value) use ($cacheManager, $zhActiveKey, $oldZhVersion): bool {
                    if ($key === $zhActiveKey && $value !== $oldZhVersion) {
                        throw new \RuntimeException('synthetic zh activation failure');
                    }

                    return $cacheManager->forever($key, $value);
                });

            try {
                $cache->publishDirectoryReadModelsAtomically([
                    'en' => ['public_count' => 2, 'items' => [['slug' => 'new-en']]],
                    'zh-CN' => ['public_count' => 2, 'items' => [['slug' => 'new-zh']]],
                ]);
                $this->fail('The synthetic second-locale activation should fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('synthetic zh activation failure', $exception->getMessage());
            }
        } finally {
            Cache::swap($cacheManager);
        }

        $this->assertSame($oldEnVersion, $cache->directoryCacheStatus('en')['active_version']);
        $this->assertSame($oldZhVersion, $cache->directoryCacheStatus('zh-CN')['active_version']);
        $this->assertSame($oldEn, $cache->directoryReadModelPayload('en'));
        $this->assertSame($oldZh, $cache->directoryReadModelPayload('zh-CN'));
    }

    public function test_multi_locale_directory_payloads_are_built_only_after_all_rebuild_locks_are_held(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'lock-guard-family',
            'title_en' => 'Lock Guard Family',
            'title_zh' => '锁保护职业族',
        ]);
        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'lock-guard-career',
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Lock Guard Career',
            'canonical_title_zh' => '锁保护职业',
            'search_h1_zh' => '锁保护职业',
            'task_prototype_signature' => [],
            'trust_inheritance_scope' => [],
        ]);
        $projectionItems = array_map(
            static fn (string $locale): array => [
                'slug' => 'lock-guard-career',
                'locale' => $locale,
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
            ],
            ['en', 'zh'],
        );
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cacheManager = Cache::getFacadeRoot();
        $lockState = (object) ['depth' => 0, 'max_depth' => 0];
        $detailReadinessReads = 0;

        try {
            $cacheMock = Cache::partialMock();
            $cacheMock->shouldReceive('lock')
                ->twice()
                ->andReturnUsing(static fn () => new class($lockState)
                {
                    public function __construct(private readonly object $state) {}

                    public function block(int $seconds, callable $callback): mixed
                    {
                        $this->state->depth++;
                        $this->state->max_depth = max($this->state->max_depth, $this->state->depth);

                        try {
                            return $callback();
                        } finally {
                            $this->state->depth--;
                        }
                    }
                });
            $cacheMock->shouldReceive('get')
                ->andReturnUsing(function (string $key, mixed $default = null) use ($cacheManager, $lockState, &$detailReadinessReads): mixed {
                    if (
                        str_starts_with($key, PublicCareerAuthorityResponseCache::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX.':')
                        && str_ends_with($key, ':active')
                    ) {
                        $detailReadinessReads++;
                        $this->assertSame(2, $lockState->depth, 'Detail readiness was inspected before both locale rebuild locks were held.');
                    }

                    return $cacheManager->get($key, $default);
                });
            $cacheMock->shouldReceive('has')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->has($key));
            $cacheMock->shouldReceive('forever')
                ->andReturnUsing(static fn (string $key, mixed $value): bool => $cacheManager->forever($key, $value));
            $cacheMock->shouldReceive('forget')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->forget($key));

            $cache->warmDirectoryReadModels(['en', 'zh-CN'], null, $projectionItems);
        } finally {
            Cache::swap($cacheManager);
        }

        $this->assertGreaterThan(0, $detailReadinessReads);
        $this->assertSame(2, $lockState->max_depth);
    }

    public function test_fifty_cold_rebuild_contenders_only_execute_one_builder(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $activeKey = PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':en:active';
        $lkgKey = PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':en:lkg';
        $jobIndexKey = PublicCareerAuthorityResponseCache::JOB_INDEX_CACHE_KEY_PREFIX.':en:public';
        Cache::forget($activeKey);
        Cache::forget($lkgKey);
        Cache::forget($jobIndexKey);
        $rebuilds = 0;

        for ($request = 0; $request < 50; $request++) {
            $result = $cache->singleFlightDirectoryRebuild('en', null, function () use ($cache, &$rebuilds, $jobIndexKey): array {
                $rebuilds++;
                $jobIndex = ['bundle_kind' => 'career_job_index', 'items' => [['slug' => 'one']]];
                Cache::forever($jobIndexKey, $jobIndex);
                $cache->publishDirectoryReadModel('en', ['public_count' => 1, 'items' => [['slug' => 'one']]]);

                return $jobIndex;
            });

            $this->assertSame('career_job_index', $result['bundle_kind']);
        }

        $this->assertSame(1, $rebuilds);
    }

    public function test_failed_rebuild_keeps_serving_previous_version_instead_of_empty_items(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $payload = ['public_count' => 1046, 'items' => [['slug' => 'known-good']]];
        $version = $cache->publishDirectoryReadModel('zh-CN', $payload);

        try {
            $cache->singleFlightDirectoryRebuild('zh-CN', $version, static function (): array {
                throw new \RuntimeException('synthetic rebuild failure');
            });
            $this->fail('The synthetic rebuild should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic rebuild failure', $exception->getMessage());
        }

        $this->assertSame($payload, $cache->directoryReadModelPayload('zh-CN'));
        $this->assertSame(1046, $cache->directoryReadModelPayload('zh-CN')['public_count']);
    }

    public function test_missing_directory_authority_throws_instead_of_returning_fake_empty_array(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach ([
            PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':zh-CN:active',
            PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':zh-CN:lkg',
            PublicCareerAuthorityResponseCache::DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX.':zh-CN',
        ] as $key) {
            Cache::forget($key);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Career directory authority cache is unavailable');

        $cache->directoryReadModelPayload('zh-CN');
    }

    public function test_verify_only_fails_without_versions_and_passes_after_en_zh_warm(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            Cache::forget(PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':'.$locale.':active');
            Cache::forget(PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':'.$locale.':lkg');
        }

        $this->artisan('career:warm-public-authority-cache', ['--verify-only' => true, '--json' => true])
            ->assertExitCode(1);

        $cache->publishDirectoryReadModel('en', ['public_count' => 1, 'items' => [['slug' => 'one']]]);
        $cache->publishDirectoryReadModel('zh-CN', ['public_count' => 1, 'items' => [['slug' => 'one']]]);

        $this->artisan('career:warm-public-authority-cache', ['--verify-only' => true, '--json' => true])
            ->expectsOutputToContain('"status": "ready"')
            ->assertExitCode(0);
    }

    public function test_runtime_schedule_verifies_directory_cache_without_rebuilding(): void
    {
        $schedule = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($schedule);
        $this->assertStringContainsString(
            "career:warm-public-authority-cache --verify-only --json')->everyTenMinutes()->withoutOverlapping()",
            $schedule,
        );
    }
}
