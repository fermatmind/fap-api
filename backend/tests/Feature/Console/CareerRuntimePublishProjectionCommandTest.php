<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerRuntimePublishProjectionCommandTest extends TestCase
{
    public function test_export_command_materializes_projection_from_ledger_artifact(): void
    {
        $ledgerPath = $this->writeLedgerArtifact([
            [
                'source_slug' => 'actors',
                'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'public_eligible' => true,
                'indexability' => 'indexable',
            ],
        ]);
        $timestamp = 'runtime-projection-test-'.strtolower(str()->random(8));

        $this->artisan('career:export-runtime-publish-projection', [
            '--ledger' => $ledgerPath,
            '--timestamp' => $timestamp,
            '--json' => true,
        ])->assertExitCode(0);

        $path = storage_path('app/private/career_runtime_publish_projection/'.$timestamp.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME);
        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertSame('career_runtime_publish_projection', $payload['projection_kind'] ?? null);
        $this->assertSame(2, (int) data_get($payload, 'counts.published'));
    }

    public function test_validate_command_blocks_invalid_projection_artifact(): void
    {
        $projectionPath = storage_path('app/testing/runtime-projection-invalid-'.strtolower(str()->random(8)).'.json');
        File::ensureDirectoryExists(dirname($projectionPath));
        File::put($projectionPath, json_encode([
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => [
                [
                    'slug' => 'software-developers',
                    'locale' => 'en',
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    'runtime_publish_state' => 'blocked',
                    'detail_route_enabled' => false,
                    'dataset_visible' => true,
                    'search_visible' => false,
                    'sitemap_live' => false,
                    'llms_live' => false,
                    'llms_full_live' => false,
                    'canonical_url' => null,
                    'canonical_self' => false,
                    'robots_indexable' => false,
                    'release_gate_pass' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('career:validate-runtime-publish-projection', [
            '--projection' => $projectionPath,
            '--json' => true,
        ])->assertExitCode(1);
    }

    public function test_canonical_runtime_truth_command_materializes_truth_from_projection_artifact(): void
    {
        $projectionPath = storage_path('app/testing/runtime-projection-truth-'.strtolower(str()->random(8)).'.json');
        File::ensureDirectoryExists(dirname($projectionPath));
        File::put($projectionPath, json_encode([
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'test',
            'source_authority' => 'CareerFullReleaseLedger',
            'items' => [
                [
                    'slug' => 'actors',
                    'locale' => 'en',
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                    'runtime_publish_state' => 'published',
                    'detail_route_enabled' => true,
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'sitemap_live' => true,
                    'llms_live' => true,
                    'llms_full_live' => true,
                    'canonical_url' => 'https://fermatmind.com/en/career/jobs/actors',
                    'canonical_self' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                ],
                [
                    'slug' => 'software-developers',
                    'locale' => 'en',
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    'runtime_publish_state' => 'quarantined',
                    'detail_route_enabled' => false,
                    'dataset_visible' => false,
                    'search_visible' => false,
                    'sitemap_live' => false,
                    'llms_live' => false,
                    'llms_full_live' => false,
                    'canonical_url' => null,
                    'canonical_self' => false,
                    'robots_indexable' => false,
                    'release_gate_pass' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $timestamp = 'canonical-truth-test-'.strtolower(str()->random(8));

        $this->artisan('career:export-canonical-runtime-truth', [
            '--projection' => $projectionPath,
            '--timestamp' => $timestamp,
            '--json' => true,
        ])->assertExitCode(0);

        $path = storage_path('app/private/career_canonical_runtime_truth/'.$timestamp.'/'.CareerCanonicalRuntimeTruthExporter::TRUTH_FILENAME);
        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertSame('career_canonical_runtime_truth', data_get($payload, 'truth_kind'));
        $this->assertSame(1, (int) data_get($payload, 'counts.canonical_projection_rows'));
        $this->assertSame(1, (int) data_get($payload, 'counts.fully_live'));
        $this->assertSame(1, (int) data_get($payload, 'excluded_counts_by_public_resolution_type.keep_non_public_with_policy'));
    }

    public function test_canonical_runtime_truth_validator_blocks_surface_mismatch(): void
    {
        $truthPath = storage_path('app/testing/canonical-runtime-truth-invalid-'.strtolower(str()->random(8)).'.json');
        File::ensureDirectoryExists(dirname($truthPath));
        File::put($truthPath, json_encode([
            'truth_kind' => 'career_canonical_runtime_truth',
            'truth_version' => 'test',
            'items' => [
                [
                    'slug' => 'actors',
                    'locale' => 'en',
                    'projection_state' => 'published',
                    'route_exists' => true,
                    'final_200' => true,
                    'robots_indexable' => true,
                    'canonical_self' => true,
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'sitemap_live' => true,
                    'llms_live' => false,
                    'llms_full_live' => false,
                    'release_gate_pass' => true,
                    'fully_live' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('career:validate-canonical-runtime-truth', [
            '--truth' => $truthPath,
            '--json' => true,
        ])->assertExitCode(1);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeLedgerArtifact(array $rows): string
    {
        $path = storage_path('app/testing/runtime-ledger-'.strtolower(str()->random(8)).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'ledger_kind' => 'career_full_release_ledger',
            'ledger_version' => 'test',
            'scope' => 'test',
            'public_resolution' => [
                'rows' => $rows,
            ],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
