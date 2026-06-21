<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2\Candidate;

use App\Services\BigFive\ResultPageV2\Candidate\BigFiveCandidatePackageContract;
use App\Services\BigFive\ResultPageV2\Candidate\BigFiveInactiveCandidateReleaseImporter;
use App\Services\BigFive\ResultPageV2\Candidate\BigFiveProductionEquivalentCandidatePayloadExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveInactiveCandidateReleaseImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_materializes_inactive_release_without_activation_or_runtime_source_change(): void
    {
        $fixture = $this->candidateFixture('importer_main');
        $sourceHashBefore = hash_file('sha256', base_path(BigFiveCandidatePackageContract::SOURCE_ASSETS_RELATIVE_PATH));

        $summary = app(BigFiveInactiveCandidateReleaseImporter::class)->import(
            $fixture['candidate_dir'],
            $fixture['import_output_dir'],
            [
                'candidate_manifest_sha256' => $fixture['candidate_manifest_sha256'],
                'source_assets_sha256' => $fixture['source_assets_sha256'],
            ],
        );

        $this->assertSame('PASS_FOR_BIG5_RESULT_PAGE_V2_INACTIVE_IMPORT_GATE', $summary['verdict']);
        $this->assertSame(325, $summary['candidate_payload_count']);
        $this->assertSame($sourceHashBefore, hash_file('sha256', base_path(BigFiveCandidatePackageContract::SOURCE_ASSETS_RELATIVE_PATH)));
        $this->assertFalse(DB::table('content_pack_activations')
            ->where('pack_id', BigFiveCandidatePackageContract::PACK_ID)
            ->where('pack_version', BigFiveCandidatePackageContract::PACK_VERSION)
            ->exists());
        $this->assertDatabaseHas('content_pack_releases', [
            'id' => $summary['inactive_release_id'],
            'to_pack_id' => BigFiveCandidatePackageContract::PACK_ID,
            'pack_version' => BigFiveCandidatePackageContract::PACK_VERSION,
            'action' => BigFiveCandidatePackageContract::RELEASE_ACTION,
        ]);
        $this->assertDatabaseHas('content_release_manifests', [
            'content_pack_release_id' => $summary['inactive_release_id'],
            'pack_id' => BigFiveCandidatePackageContract::PACK_ID,
            'pack_version' => BigFiveCandidatePackageContract::PACK_VERSION,
            'manifest_hash' => 'sha256:'.$fixture['candidate_manifest_sha256'],
        ]);
        $this->assertDirectoryExists(storage_path('app/'.$summary['inactive_release_storage_path'].'/candidate/candidate_payloads'));
        $this->assertCount(
            325,
            File::glob(storage_path('app/'.$summary['inactive_release_storage_path'].'/candidate/candidate_payloads/*.json')) ?: []
        );
    }

    public function test_importer_fails_closed_on_candidate_manifest_hash_mismatch(): void
    {
        $fixture = $this->candidateFixture('importer_hash_mismatch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Candidate manifest hash mismatch');

        app(BigFiveInactiveCandidateReleaseImporter::class)->import(
            $fixture['candidate_dir'],
            $fixture['import_output_dir'],
            [
                'candidate_manifest_sha256' => str_repeat('0', 64),
                'source_assets_sha256' => $fixture['source_assets_sha256'],
            ],
        );
    }

    public function test_importer_fails_closed_on_payload_count_mismatch(): void
    {
        $fixture = $this->candidateFixture('importer_count_mismatch');
        $payloadFiles = File::glob($fixture['candidate_dir'].'/candidate_payloads/*.json') ?: [];
        $this->assertNotEmpty($payloadFiles);
        File::delete($payloadFiles[0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('candidate_payloads count mismatch');

        app(BigFiveInactiveCandidateReleaseImporter::class)->import(
            $fixture['candidate_dir'],
            $fixture['import_output_dir'],
            [
                'candidate_manifest_sha256' => $fixture['candidate_manifest_sha256'],
                'source_assets_sha256' => $fixture['source_assets_sha256'],
            ],
        );
    }

    /**
     * @return array{candidate_dir:string,import_output_dir:string,candidate_manifest_sha256:string,source_assets_sha256:string}
     */
    private function candidateFixture(string $suffix): array
    {
        $candidateDir = storage_path('framework/testing/bigfive_candidate_import/'.$suffix);
        $exportOutputDir = storage_path('framework/testing/bigfive_candidate_import_export_output/'.$suffix);
        $importOutputDir = storage_path('framework/testing/bigfive_candidate_import_output/'.$suffix);
        File::deleteDirectory($candidateDir);
        File::deleteDirectory($exportOutputDir);
        File::deleteDirectory($importOutputDir);

        $summary = app(BigFiveProductionEquivalentCandidatePayloadExporter::class)->export($candidateDir, $exportOutputDir);

        return [
            'candidate_dir' => $candidateDir,
            'import_output_dir' => $importOutputDir,
            'candidate_manifest_sha256' => (string) $summary['candidate_manifest_sha256'],
            'source_assets_sha256' => (string) $summary['source_assets_sha256'],
        ];
    }
}
