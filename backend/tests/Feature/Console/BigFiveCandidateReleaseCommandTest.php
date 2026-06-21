<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\BigFive\ResultPageV2\Candidate\BigFiveCandidatePackageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveCandidateReleaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_command_materializes_candidate_payloads_as_json(): void
    {
        $candidateDir = $this->freshDir('command_export_candidate');
        $outputDir = $this->freshDir('command_export_output');

        $this->artisan('bigfive:export-production-equivalent-candidate-payloads', [
            '--candidate-dir' => $candidateDir,
            '--output-dir' => $outputDir,
            '--json' => true,
        ])->assertExitCode(0);

        $summary = $this->readJsonFile($outputDir.'/big5_candidate_export_summary.json');
        $this->assertSame('PASS_FOR_BIG5_RESULT_PAGE_V2_INACTIVE_IMPORT_DRY_RUN', $summary['verdict'] ?? null);
        $this->assertSame(325, $summary['payload_count'] ?? null);
        $this->assertCount(325, File::glob($candidateDir.'/candidate_payloads/*.json') ?: []);
    }

    public function test_import_command_materializes_inactive_release_as_json(): void
    {
        $candidateDir = $this->freshDir('command_import_candidate');
        $exportOutputDir = $this->freshDir('command_import_export_output');
        $importOutputDir = $this->freshDir('command_import_output');

        $this->artisan('bigfive:export-production-equivalent-candidate-payloads', [
            '--candidate-dir' => $candidateDir,
            '--output-dir' => $exportOutputDir,
            '--json' => true,
        ])->assertExitCode(0);

        $hashes = $this->readJsonFile($candidateDir.'/candidate_hashes.json');
        putenv('BIG5_EXPECTED_CANDIDATE_MANIFEST_SHA256='.$hashes['candidate_manifest_sha256']);
        putenv('BIG5_EXPECTED_SOURCE_ASSETS_SHA256='.$hashes['source_assets_sha256']);

        try {
            $this->artisan('bigfive:import-inactive-candidate-release', [
                '--candidate-dir' => $candidateDir,
                '--output-dir' => $importOutputDir,
                '--json' => true,
            ])->assertExitCode(0);
        } finally {
            putenv('BIG5_EXPECTED_CANDIDATE_MANIFEST_SHA256');
            putenv('BIG5_EXPECTED_SOURCE_ASSETS_SHA256');
        }

        $summary = $this->readJsonFile($importOutputDir.'/big5_inactive_import_summary.json');
        $this->assertSame('PASS_FOR_BIG5_RESULT_PAGE_V2_INACTIVE_IMPORT_GATE', $summary['verdict'] ?? null);
        $this->assertSame(325, $summary['candidate_payload_count'] ?? null);
        $this->assertFalse(DB::table('content_pack_activations')
            ->where('pack_id', BigFiveCandidatePackageContract::PACK_ID)
            ->where('pack_version', BigFiveCandidatePackageContract::PACK_VERSION)
            ->exists());
    }

    public function test_import_command_fails_closed_on_hash_mismatch(): void
    {
        $candidateDir = $this->freshDir('command_hash_candidate');
        $exportOutputDir = $this->freshDir('command_hash_export_output');
        $importOutputDir = $this->freshDir('command_hash_import_output');

        $this->artisan('bigfive:export-production-equivalent-candidate-payloads', [
            '--candidate-dir' => $candidateDir,
            '--output-dir' => $exportOutputDir,
            '--json' => true,
        ])->assertExitCode(0);

        putenv('BIG5_EXPECTED_CANDIDATE_MANIFEST_SHA256='.str_repeat('0', 64));
        try {
            $this->artisan('bigfive:import-inactive-candidate-release', [
                '--candidate-dir' => $candidateDir,
                '--output-dir' => $importOutputDir,
                '--json' => true,
            ])->assertExitCode(1);
        } finally {
            putenv('BIG5_EXPECTED_CANDIDATE_MANIFEST_SHA256');
        }
    }

    private function freshDir(string $suffix): string
    {
        $dir = storage_path('framework/testing/bigfive_candidate_command/'.$suffix);
        File::deleteDirectory($dir);

        return $dir;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path): array
    {
        return json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
