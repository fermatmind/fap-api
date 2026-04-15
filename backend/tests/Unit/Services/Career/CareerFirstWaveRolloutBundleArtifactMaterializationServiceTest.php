<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutBundleProjectionService;
use App\DTO\Career\CareerFirstWaveRolloutBundleArtifact;
use App\DTO\Career\CareerFirstWaveRolloutCohortListArtifact;
use App\Services\Career\CareerFirstWaveRolloutBundleArtifactMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerFirstWaveRolloutBundleArtifactMaterializationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path(CareerFirstWaveRolloutBundleArtifactMaterializationService::OUTPUT_ROOT);
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_it_materializes_rollout_bundle_and_primary_lists_with_atomic_finalize(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T130000Z';
        $service = app(CareerFirstWaveRolloutBundleArtifactMaterializationService::class);
        $result = $service->materialize($timestamp);

        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';
        $expectedFiles = [
            'career-rollout-bundle.json',
            'career-stable-whitelist.json',
            'career-candidate-whitelist.json',
            'career-hold-list.json',
            'career-blocked-list.json',
        ];

        $this->assertSame('materialized', $result['status']);
        $this->assertSame($finalDir, $result['output_dir']);
        $this->assertDirectoryExists($finalDir);
        $this->assertDirectoryDoesNotExist($tmpDir);

        $projection = app(CareerFirstWaveRolloutBundleProjectionService::class)->build();
        foreach ($expectedFiles as $filename) {
            $artifactPath = $finalDir.DIRECTORY_SEPARATOR.$filename;
            $this->assertArrayHasKey($filename, $result['artifacts']);
            $this->assertSame($artifactPath, $result['artifacts'][$filename]);
            $this->assertFileExists($artifactPath);

            $payload = json_decode((string) File::get($artifactPath), true);
            $this->assertIsArray($payload);
            $this->assertSame($projection[$filename]->toArray(), $payload);
        }
    }

    public function test_it_fails_when_output_directory_already_exists_without_finalizing_new_output(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T130100Z';
        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';
        File::ensureDirectoryExists($finalDir);

        $service = app(CareerFirstWaveRolloutBundleArtifactMaterializationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output dir already exists');

        try {
            $service->materialize($timestamp);
        } finally {
            $this->assertDirectoryExists($finalDir);
            $this->assertDirectoryDoesNotExist($tmpDir);
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-rollout-bundle.json');
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-stable-whitelist.json');
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-candidate-whitelist.json');
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-hold-list.json');
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-blocked-list.json');
        }
    }

    public function test_it_rejects_inconsistent_bundle_and_list_projection_without_finalizing(): void
    {
        $badService = new class(app(CareerFirstWaveRolloutBundleProjectionService::class)) extends CareerFirstWaveRolloutBundleArtifactMaterializationService
        {
            /**
             * @return array<string, object>
             */
            protected function projectedArtifacts(): array
            {
                return [
                    'career-rollout-bundle.json' => new CareerFirstWaveRolloutBundleArtifact(
                        scope: 'career_first_wave_10',
                        counts: [
                            'stable' => 1,
                            'candidate' => 0,
                            'hold' => 0,
                            'blocked' => 0,
                            'manual_review_needed' => 0,
                        ],
                        cohorts: [
                            'stable' => ['registered-nurses'],
                            'candidate' => [],
                            'hold' => [],
                            'blocked' => [],
                        ],
                        advisory: [
                            'manual_review_needed' => [],
                        ],
                        members: [
                            [
                                'canonical_slug' => 'registered-nurses',
                                'rollout_cohort' => 'stable',
                                'launch_tier' => 'stable',
                                'readiness_status' => 'publish_ready',
                                'lifecycle_state' => 'indexed',
                                'public_index_state' => 'indexable',
                                'supporting_routes' => [
                                    'family_hub' => true,
                                    'next_step_links_count' => 2,
                                ],
                                'trust_freshness' => [
                                    'review_due_known' => true,
                                    'review_staleness_state' => 'review_scheduled',
                                ],
                            ],
                        ],
                    ),
                    'career-stable-whitelist.json' => new CareerFirstWaveRolloutCohortListArtifact(
                        scope: 'career_first_wave_10',
                        cohort: 'stable',
                        members: ['software-developers'],
                    ),
                    'career-candidate-whitelist.json' => new CareerFirstWaveRolloutCohortListArtifact(
                        scope: 'career_first_wave_10',
                        cohort: 'candidate',
                        members: [],
                    ),
                    'career-hold-list.json' => new CareerFirstWaveRolloutCohortListArtifact(
                        scope: 'career_first_wave_10',
                        cohort: 'hold',
                        members: [],
                    ),
                    'career-blocked-list.json' => new CareerFirstWaveRolloutCohortListArtifact(
                        scope: 'career_first_wave_10',
                        cohort: 'blocked',
                        members: [],
                    ),
                ];
            }
        };

        $timestamp = '20260415T130200Z';
        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bundle/list slug mismatch for stable cohort');

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
