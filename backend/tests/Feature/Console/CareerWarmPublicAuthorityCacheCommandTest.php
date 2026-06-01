<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
