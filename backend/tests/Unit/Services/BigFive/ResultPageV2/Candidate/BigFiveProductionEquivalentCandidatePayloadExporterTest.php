<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2\Candidate;

use App\Services\BigFive\ResultPageV2\Candidate\BigFiveCandidatePackageContract;
use App\Services\BigFive\ResultPageV2\Candidate\BigFiveProductionEquivalentCandidatePayloadExporter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveProductionEquivalentCandidatePayloadExporterTest extends TestCase
{
    public function test_exporter_materializes_325_selector_ready_payloads_with_clean_governance_reports(): void
    {
        $dirs = $this->freshDirs('exporter_main');

        $summary = app(BigFiveProductionEquivalentCandidatePayloadExporter::class)->export(
            $dirs['candidate_dir'],
            $dirs['output_dir'],
        );

        $this->assertSame('PASS_FOR_BIG5_RESULT_PAGE_V2_INACTIVE_IMPORT_DRY_RUN', $summary['verdict']);
        $this->assertSame(325, $summary['payload_count']);
        $this->assertFalse($summary['activation_happened']);
        $this->assertFalse($summary['production_import_happened']);
        $this->assertFalse($summary['full_replacement_happened']);
        $this->assertCount(325, File::glob($dirs['candidate_dir'].'/candidate_payloads/*.json') ?: []);

        $manifest = $this->readJsonFile($dirs['candidate_dir'].'/candidate_manifest.json');
        $hashes = $this->readJsonFile($dirs['candidate_dir'].'/candidate_hashes.json');
        $sourceMapping = $this->readJsonFile($dirs['candidate_dir'].'/source_mapping_report.json');
        $metadataLeakage = $this->readJsonFile($dirs['candidate_dir'].'/metadata_leakage_report.json');
        $forbiddenClaim = $this->readJsonFile($dirs['candidate_dir'].'/forbidden_claim_report.json');

        $this->assertSame(BigFiveCandidatePackageContract::MANIFEST_SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertSame(325, $manifest['payload_count']);
        $this->assertSame('staging_only', $manifest['runtime_use']);
        $this->assertFalse((bool) $manifest['production_use_allowed']);
        $this->assertFalse((bool) $manifest['ready_for_runtime']);
        $this->assertFalse((bool) $manifest['ready_for_production']);
        $this->assertSame(hash_file('sha256', $dirs['candidate_dir'].'/candidate_manifest.json'), $hashes['candidate_manifest_sha256']);
        $this->assertSame(hash_file('sha256', base_path(BigFiveCandidatePackageContract::SOURCE_ASSETS_RELATIVE_PATH)), $hashes['source_assets_sha256']);
        $this->assertSame(0, $sourceMapping['source_mapping_failure_count']);
        $this->assertSame(0, $metadataLeakage['metadata_leak_count']);
        $this->assertSame(0, $forbiddenClaim['forbidden_claim_count']);
    }

    public function test_exporter_payload_hashes_are_reproducible_for_same_source_assets(): void
    {
        $first = $this->freshDirs('exporter_repro_first');
        $second = $this->freshDirs('exporter_repro_second');

        app(BigFiveProductionEquivalentCandidatePayloadExporter::class)->export($first['candidate_dir'], $first['output_dir']);
        app(BigFiveProductionEquivalentCandidatePayloadExporter::class)->export($second['candidate_dir'], $second['output_dir']);

        $this->assertSame(
            $this->readJsonFile($first['candidate_dir'].'/candidate_hashes.json'),
            $this->readJsonFile($second['candidate_dir'].'/candidate_hashes.json'),
        );
        $this->assertSame(
            hash_file('sha256', $first['candidate_dir'].'/candidate_manifest.json'),
            hash_file('sha256', $second['candidate_dir'].'/candidate_manifest.json'),
        );
    }

    /**
     * @return array{candidate_dir:string,output_dir:string}
     */
    private function freshDirs(string $suffix): array
    {
        $candidateDir = storage_path('framework/testing/bigfive_candidate_export/'.$suffix);
        $outputDir = storage_path('framework/testing/bigfive_candidate_export_output/'.$suffix);
        File::deleteDirectory($candidateDir);
        File::deleteDirectory($outputDir);

        return [
            'candidate_dir' => $candidateDir,
            'output_dir' => $outputDir,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path): array
    {
        return json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
