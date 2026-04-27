<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\EnneagramInactiveCandidateReleaseImporter;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramImportInactiveCandidateRelease extends Command
{
    protected $signature = 'enneagram:import-inactive-candidate-release
        {--candidate-dir= : Existing Phase 8-B candidate directory}
        {--output-dir= : Phase 8-D-2b report output directory}
        {--json : Emit machine-readable JSON summary}';

    protected $description = 'Materialize an inactive ENNEAGRAM candidate release artifact without activation.';

    public function handle(EnneagramInactiveCandidateReleaseImporter $importer): int
    {
        try {
            $candidateDir = trim((string) ($this->option('candidate-dir') ?: (getenv('PHASE8B_CANDIDATE_DIR') ?: '')));
            $outputDir = trim((string) ($this->option('output-dir') ?: (getenv('PHASE8D2B_OUTPUT_DIR') ?: '')));

            if ($candidateDir === '') {
                throw new \RuntimeException('--candidate-dir or PHASE8B_CANDIDATE_DIR is required.');
            }

            if ($outputDir === '') {
                throw new \RuntimeException('--output-dir or PHASE8D2B_OUTPUT_DIR is required.');
            }

            $contracts = array_filter([
                'candidate_manifest_sha256' => trim((string) (getenv('PHASE8B_EXPECTED_CANDIDATE_MANIFEST_SHA256') ?: '')),
                'runtime_registry_manifest_sha256' => trim((string) (getenv('PHASE8B_EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256') ?: '')),
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
