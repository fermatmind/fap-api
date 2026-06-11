<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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
        $cacheKey = PublicCareerAuthorityResponseCache::JOB_DETAIL_CACHE_KEY_PREFIX.':missing-career:zh-CN';
        Cache::forever($cacheKey, ['stale' => true]);

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

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_command_emits_json_report_for_targeted_job_detail_cache_refresh(): void
    {
        $cacheKey = PublicCareerAuthorityResponseCache::JOB_DETAIL_CACHE_KEY_PREFIX.':missing-career:zh-CN';
        Cache::forever($cacheKey, ['stale' => true]);

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
        $this->assertFalse(Cache::has($cacheKey));
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
}
