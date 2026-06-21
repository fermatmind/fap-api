<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\ResultPageV2\Candidate\BigFiveProductionEquivalentCandidatePayloadExporter;
use Illuminate\Console\Command;
use Throwable;

final class BigFiveExportProductionEquivalentCandidatePayloads extends Command
{
    protected $signature = 'bigfive:export-production-equivalent-candidate-payloads
        {--candidate-dir= : Existing or new Big Five result page V2 candidate directory}
        {--output-dir= : Big Five candidate export report output directory}
        {--json : Emit machine-readable JSON summary}';

    protected $description = 'Generate BIG5 result page V2 production-equivalent candidate payload fixtures without production import.';

    public function handle(BigFiveProductionEquivalentCandidatePayloadExporter $exporter): int
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

            $summary = $exporter->export($candidateDir, $outputDir);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('candidate_source_directory='.$summary['candidate_source_directory']);
                $this->line('candidate_payload_output_directory='.$summary['candidate_payload_output_directory']);
                $this->line('payload_count='.$summary['payload_count']);
                $this->line('source_mapping_result='.json_encode($summary['source_mapping_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('metadata_leakage_result='.json_encode($summary['metadata_leakage_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('forbidden_claim_result='.json_encode($summary['forbidden_claim_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->line('verdict='.$summary['verdict']);
            }

            return str_starts_with((string) $summary['verdict'], 'PASS_') ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
