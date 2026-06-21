<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\ResultPageV2\Candidate\BigFiveInactiveCandidateReleaseImporter;
use Illuminate\Console\Command;
use Throwable;

final class BigFiveImportInactiveCandidateRelease extends Command
{
    protected $signature = 'bigfive:import-inactive-candidate-release
        {--candidate-dir= : Existing Big Five result page V2 candidate directory}
        {--output-dir= : Big Five inactive import report output directory}
        {--json : Emit machine-readable JSON summary}';

    protected $description = 'Materialize an inactive BIG5 result page V2 candidate release artifact without activation.';

    public function handle(BigFiveInactiveCandidateReleaseImporter $importer): int
    {
        try {
            $candidateDir = trim((string) ($this->option('candidate-dir') ?: (getenv('BIG5_PHASE_CANDIDATE_DIR') ?: '')));
            $outputDir = trim((string) ($this->option('output-dir') ?: (getenv('BIG5_PHASE_OUTPUT_DIR') ?: '')));

            if ($candidateDir === '') {
                throw new \RuntimeException('--candidate-dir or BIG5_PHASE_CANDIDATE_DIR is required.');
            }
            if ($outputDir === '') {
                throw new \RuntimeException('--output-dir or BIG5_PHASE_OUTPUT_DIR is required.');
            }

            $contracts = array_filter([
                'candidate_manifest_sha256' => trim((string) (getenv('BIG5_EXPECTED_CANDIDATE_MANIFEST_SHA256') ?: '')),
                'source_assets_sha256' => trim((string) (getenv('BIG5_EXPECTED_SOURCE_ASSETS_SHA256') ?: '')),
            ], static fn (string $value): bool => $value !== '');

            $summary = $importer->import($candidateDir, $outputDir, $contracts);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('candidate_source_directory='.$summary['candidate_source_directory']);
                $this->line('inactive_release_id='.$summary['inactive_release_id']);
                $this->line('inactive_release_storage_path='.$summary['inactive_release_storage_path']);
                $this->line('candidate_payload_count='.$summary['candidate_payload_count']);
                $this->line('verdict='.$summary['verdict']);
            }

            return str_starts_with((string) $summary['verdict'], 'PASS_') ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
