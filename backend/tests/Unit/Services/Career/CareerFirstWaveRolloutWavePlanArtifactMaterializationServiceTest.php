<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutWavePlanArtifactProjectionService;
use App\DTO\Career\CareerFirstWaveRolloutWavePlanArtifact;
use App\Services\Career\CareerFirstWaveRolloutWavePlanArtifactMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerFirstWaveRolloutWavePlanArtifactMaterializationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path(CareerFirstWaveRolloutWavePlanArtifactMaterializationService::OUTPUT_ROOT);
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_it_materializes_rollout_wave_plan_artifact_with_atomic_finalize(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T110000Z';
        $service = app(CareerFirstWaveRolloutWavePlanArtifactMaterializationService::class);
        $result = $service->materialize($timestamp);

        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';
        $artifactPath = $finalDir.DIRECTORY_SEPARATOR.'career-rollout-wave-plan.json';

        $this->assertSame('materialized', $result['status']);
        $this->assertSame($finalDir, $result['output_dir']);
        $this->assertSame($artifactPath, $result['artifacts']['career-rollout-wave-plan.json']);
        $this->assertDirectoryExists($finalDir);
        $this->assertDirectoryDoesNotExist($tmpDir);
        $this->assertFileExists($artifactPath);

        $payload = json_decode((string) File::get($artifactPath), true);
        $this->assertIsArray($payload);

        $expected = app(CareerFirstWaveRolloutWavePlanArtifactProjectionService::class)->build()->toArray();
        $this->assertSame($expected, $payload);
    }

    public function test_it_fails_when_output_directory_already_exists_without_finalize(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T110100Z';
        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';
        File::ensureDirectoryExists($finalDir);

        $service = app(CareerFirstWaveRolloutWavePlanArtifactMaterializationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output dir already exists');

        try {
            $service->materialize($timestamp);
        } finally {
            $this->assertDirectoryExists($finalDir);
            $this->assertDirectoryDoesNotExist($tmpDir);
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-rollout-wave-plan.json');
        }
    }

    public function test_it_rejects_invalid_projection_without_finalizing_directory(): void
    {
        $badService = new class(app(CareerFirstWaveRolloutWavePlanArtifactProjectionService::class)) extends CareerFirstWaveRolloutWavePlanArtifactMaterializationService
        {
            protected function projectedArtifact(): object
            {
                return new CareerFirstWaveRolloutWavePlanArtifact(
                    scope: 'career_first_wave_10',
                    counts: ['stable' => 1, 'candidate' => 0, 'hold' => 0, 'blocked' => 0, 'manual_review_needed' => 0],
                    cohorts: ['stable' => ['registered-nurses'], 'candidate' => [], 'hold' => [], 'blocked' => []],
                    advisory: ['manual_review_needed' => []],
                    members: [
                        [
                            'canonical_slug' => '',
                            'rollout_cohort' => 'stable',
                            'launch_tier' => 'stable',
                            'readiness_status' => 'publish_ready',
                            'lifecycle_state' => 'indexed',
                            'public_index_state' => 'indexable',
                            'supporting_routes' => [
                                'family_hub' => true,
                                'next_step_links_count' => 1,
                            ],
                            'trust_freshness' => [
                                'review_due_known' => false,
                                'review_staleness_state' => 'unknown_due_date',
                            ],
                        ],
                    ],
                );
            }
        };

        $timestamp = '20260415T110200Z';
        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains empty canonical_slug');

        try {
            $badService->materialize($timestamp);
        } finally {
            $this->assertDirectoryDoesNotExist($finalDir);
            $this->assertDirectoryDoesNotExist($tmpDir);
        }
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
