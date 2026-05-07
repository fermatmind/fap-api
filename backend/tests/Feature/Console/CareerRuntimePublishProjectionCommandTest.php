<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
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
