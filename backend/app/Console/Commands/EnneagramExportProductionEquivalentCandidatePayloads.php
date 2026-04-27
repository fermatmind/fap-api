<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\EnneagramProductionEquivalentCandidatePayloadExporter;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramExportProductionEquivalentCandidatePayloads extends Command
{
    protected $signature = 'enneagram:export-production-equivalent-candidate-payloads
        {--candidate-dir= : Existing Phase 8-B candidate directory}
        {--output-dir= : Phase 8-B-1 report output directory}
        {--json : Emit machine-readable JSON summary}';

    protected $description = 'Generate renderable ENNEAGRAM production-equivalent candidate payload fixtures without production import.';

    public function handle(EnneagramProductionEquivalentCandidatePayloadExporter $exporter): int
    {
        try {
            $candidateDir = trim((string) ($this->option('candidate-dir') ?: (getenv('PHASE8B_CANDIDATE_DIR') ?: '')));
            $outputDir = trim((string) ($this->option('output-dir') ?: (getenv('PHASE8B1_OUTPUT_DIR') ?: '')));

            if ($candidateDir === '') {
                throw new \RuntimeException('--candidate-dir or PHASE8B_CANDIDATE_DIR is required.');
            }

            if ($outputDir === '') {
                throw new \RuntimeException('--output-dir or PHASE8B1_OUTPUT_DIR is required.');
            }

            $summary = $exporter->export($candidateDir, $outputDir);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('candidate_source_directory='.$summary['candidate_source_directory']);
                $this->line('candidate_payload_output_directory='.$summary['candidate_payload_output_directory']);
                $this->line('total_payload_count='.$summary['total_payload_count']);
                $this->line('payload_count_by_matrix='.json_encode($summary['payload_count_by_matrix'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('source_mapping_result='.json_encode($summary['source_mapping_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('metadata_sanitizer_result='.json_encode($summary['metadata_sanitizer_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('legacy_residual_result='.json_encode($summary['legacy_residual_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('fc144_boundary_result='.json_encode($summary['fc144_boundary_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('verdict='.$summary['verdict']);
            }

            return str_starts_with((string) $summary['verdict'], 'PASS_') ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
