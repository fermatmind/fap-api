<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceIngestionService;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourceRegistry;
use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use Illuminate\Console\Command;
use Throwable;

final class SeoCompetitiveEvidenceIngest extends Command
{
    protected $signature = 'seo:competitive-evidence-ingest
        {--cohort= : Immutable competitive cohort id}
        {--dry-run : Evaluate without persistence}
        {--no-write : Enforce zero persistence}
        {--write-evidence : Persist only to seo_evidence_bundles}
        {--json : Emit machine-readable output}';

    protected $description = 'Acquire registered public competitive evidence through the External Content Gateway';

    public function handle(
        CompetitiveSourceRegistry $registry,
        CompetitiveEvidenceIngestionService $ingestion,
        CompetitiveCloseoutBuilder $closeout,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $noWrite = (bool) $this->option('no-write');
        $write = (bool) $this->option('write-evidence');
        if (($write && ($dryRun || $noWrite)) || (! $write && ! $dryRun && ! $noWrite)) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_INGEST_MODE_INVALID', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
        if ($write && ! $this->writeBoundaryAllowed()) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_WRITE_BOUNDARY_HELD', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
        $cohortId = trim((string) $this->option('cohort'));
        if ($cohortId === '') {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_COHORT_REQUIRED', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }

        try {
            $cohort = $registry->cohort($cohortId);
            $environment = app()->environment();
            $releaseSha = $write ? (string) env('SEO_RELEASE_SHA') : str_repeat('0', 40);
            $result = $ingestion->ingest(
                $cohort,
                $registry->sourcesFor($cohort),
                in_array($environment, ['staging', 'production'], true) ? $environment : 'staging',
                $releaseSha,
                $write,
            );
            $receipt = $closeout->buildRuntime(
                $result,
                $releaseSha,
                in_array($environment, ['staging', 'production'], true) ? $environment : 'staging',
                $environment === 'production' ? $releaseSha : null,
            );
            if (! $closeout->verify($receipt, $releaseSha)) {
                return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_RECEIPT_INVALID', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
            }

            return $this->emit($receipt, self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_REGISTRY_INVALID', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
    }

    private function writeBoundaryAllowed(): bool
    {
        return in_array(app()->environment(), ['staging', 'production'], true)
            && env('SEO_COMPETITIVE_EXTERNAL_READ_ENABLED') === true
            && env('SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED') === true
            && preg_match('/^[a-f0-9]{40}$/', (string) env('SEO_RELEASE_SHA')) === 1;
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $this->line((string) ($payload['status'] ?? 'HOLD').': '.(string) ($payload['hold_reason'] ?? 'NONE'));
        }

        return $code;
    }
}
