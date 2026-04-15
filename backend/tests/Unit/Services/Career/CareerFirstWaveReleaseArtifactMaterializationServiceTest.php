<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveReleaseArtifactProjectionService;
use App\DTO\Career\CareerFirstWaveLaunchManifestArtifact;
use App\DTO\Career\CareerFirstWaveSmokeMatrixArtifact;
use App\Services\Career\CareerFirstWaveReleaseArtifactMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerFirstWaveReleaseArtifactMaterializationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path(CareerFirstWaveReleaseArtifactMaterializationService::OUTPUT_ROOT);
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_it_materializes_two_release_artifacts_together_with_atomic_finalize(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T090000Z';
        $service = app(CareerFirstWaveReleaseArtifactMaterializationService::class);
        $result = $service->materialize($timestamp);

        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';
        $launchPath = $finalDir.DIRECTORY_SEPARATOR.'career-launch-manifest.json';
        $smokePath = $finalDir.DIRECTORY_SEPARATOR.'career-smoke-matrix.json';

        $this->assertSame('materialized', $result['status']);
        $this->assertSame($finalDir, $result['output_dir']);
        $this->assertSame($launchPath, $result['artifacts']['career-launch-manifest.json']);
        $this->assertSame($smokePath, $result['artifacts']['career-smoke-matrix.json']);
        $this->assertDirectoryExists($finalDir);
        $this->assertFileExists($launchPath);
        $this->assertFileExists($smokePath);
        $this->assertDirectoryDoesNotExist($tmpDir);

        $launchPayload = json_decode((string) File::get($launchPath), true);
        $smokePayload = json_decode((string) File::get($smokePath), true);
        $this->assertIsArray($launchPayload);
        $this->assertIsArray($smokePayload);

        $projection = app(CareerFirstWaveReleaseArtifactProjectionService::class)->build();
        $expectedLaunch = $projection['career-launch-manifest.json']->toArray();
        $expectedSmoke = $projection['career-smoke-matrix.json']->toArray();

        $this->assertSame($expectedLaunch, $launchPayload);
        $this->assertSame($expectedSmoke, $smokePayload);
        $this->assertSame(
            collect($launchPayload['members'])->pluck('canonical_slug')->values()->all(),
            collect($smokePayload['members'])->pluck('canonical_slug')->values()->all(),
        );
    }

    public function test_it_fails_when_output_directory_already_exists_without_finalizing_new_output(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T090100Z';
        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';
        File::ensureDirectoryExists($finalDir);

        $service = app(CareerFirstWaveReleaseArtifactMaterializationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output dir already exists');

        try {
            $service->materialize($timestamp);
        } finally {
            $this->assertDirectoryExists($finalDir);
            $this->assertDirectoryDoesNotExist($tmpDir);
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-launch-manifest.json');
            $this->assertFileDoesNotExist($finalDir.DIRECTORY_SEPARATOR.'career-smoke-matrix.json');
        }
    }

    public function test_it_rejects_projection_validation_failure_and_does_not_finalize(): void
    {
        $badService = new class(app(CareerFirstWaveReleaseArtifactProjectionService::class)) extends CareerFirstWaveReleaseArtifactMaterializationService
        {
            /**
             * @return array<string, object>
             */
            protected function projectedArtifacts(): array
            {
                return [
                    'career-launch-manifest.json' => new CareerFirstWaveLaunchManifestArtifact(
                        scope: 'career_first_wave_10',
                        counts: ['total' => 1, 'stable' => 1, 'candidate' => 0, 'hold' => 0, 'blocked' => 0],
                        groups: ['stable' => ['registered-nurses'], 'candidate' => [], 'hold' => [], 'blocked' => []],
                        members: [[
                            'canonical_slug' => 'registered-nurses',
                            'launch_tier' => 'stable',
                            'readiness_status' => 'publish_ready',
                            'lifecycle_state' => 'indexed',
                            'public_index_state' => 'indexable',
                            'supporting_routes' => ['family_hub' => true, 'next_step_links_count' => 1],
                            'trust_freshness' => ['review_due_known' => false, 'review_staleness_state' => 'unknown_due_date'],
                        ]],
                    ),
                    'career-smoke-matrix.json' => new CareerFirstWaveSmokeMatrixArtifact(
                        scope: 'career_first_wave_10',
                        members: [[
                            'canonical_slug' => 'software-developers',
                            'smoke_matrix' => [
                                'job_detail_route_known' => true,
                                'discoverable_route_known' => true,
                                'seo_contract_present' => true,
                                'structured_data_authority_present' => true,
                                'trust_freshness_present' => true,
                                'family_support_route_present' => true,
                                'next_step_support_present' => true,
                            ],
                        ]],
                    ),
                ];
            }
        };

        $timestamp = '20260415T090200Z';
        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $tmpDir = $finalDir.'.tmp';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('slug sets are inconsistent');

        try {
            $badService->materialize($timestamp);
        } finally {
            $this->assertDirectoryDoesNotExist($finalDir);
            $this->assertDirectoryDoesNotExist($tmpDir);
        }
    }

    public function test_it_accepts_same_slug_set_even_when_member_order_differs_between_artifacts(): void
    {
        $service = new class(app(CareerFirstWaveReleaseArtifactProjectionService::class)) extends CareerFirstWaveReleaseArtifactMaterializationService
        {
            /**
             * @return array<string, object>
             */
            protected function projectedArtifacts(): array
            {
                return [
                    'career-launch-manifest.json' => new CareerFirstWaveLaunchManifestArtifact(
                        scope: 'career_first_wave_10',
                        counts: ['total' => 2, 'stable' => 2, 'candidate' => 0, 'hold' => 0, 'blocked' => 0],
                        groups: ['stable' => ['registered-nurses', 'software-developers'], 'candidate' => [], 'hold' => [], 'blocked' => []],
                        members: [
                            [
                                'canonical_slug' => 'registered-nurses',
                                'launch_tier' => 'stable',
                                'readiness_status' => 'publish_ready',
                                'lifecycle_state' => 'indexed',
                                'public_index_state' => 'indexable',
                                'supporting_routes' => ['family_hub' => true, 'next_step_links_count' => 1],
                                'trust_freshness' => ['review_due_known' => false, 'review_staleness_state' => 'unknown_due_date'],
                            ],
                            [
                                'canonical_slug' => 'software-developers',
                                'launch_tier' => 'stable',
                                'readiness_status' => 'publish_ready',
                                'lifecycle_state' => 'indexed',
                                'public_index_state' => 'indexable',
                                'supporting_routes' => ['family_hub' => true, 'next_step_links_count' => 1],
                                'trust_freshness' => ['review_due_known' => false, 'review_staleness_state' => 'unknown_due_date'],
                            ],
                        ],
                    ),
                    'career-smoke-matrix.json' => new CareerFirstWaveSmokeMatrixArtifact(
                        scope: 'career_first_wave_10',
                        members: [
                            [
                                'canonical_slug' => 'software-developers',
                                'smoke_matrix' => [
                                    'job_detail_route_known' => true,
                                    'discoverable_route_known' => true,
                                    'seo_contract_present' => true,
                                    'structured_data_authority_present' => true,
                                    'trust_freshness_present' => true,
                                    'family_support_route_present' => true,
                                    'next_step_support_present' => true,
                                ],
                            ],
                            [
                                'canonical_slug' => 'registered-nurses',
                                'smoke_matrix' => [
                                    'job_detail_route_known' => true,
                                    'discoverable_route_known' => true,
                                    'seo_contract_present' => true,
                                    'structured_data_authority_present' => true,
                                    'trust_freshness_present' => true,
                                    'family_support_route_present' => true,
                                    'next_step_support_present' => true,
                                ],
                            ],
                        ],
                    ),
                ];
            }
        };

        $timestamp = '20260415T090300Z';
        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;

        $result = $service->materialize($timestamp);

        $this->assertSame('materialized', $result['status']);
        $this->assertDirectoryExists($finalDir);
        $this->assertFileExists($finalDir.DIRECTORY_SEPARATOR.'career-launch-manifest.json');
        $this->assertFileExists($finalDir.DIRECTORY_SEPARATOR.'career-smoke-matrix.json');
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
