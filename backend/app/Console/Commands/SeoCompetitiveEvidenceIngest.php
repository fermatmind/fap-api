<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourceRegistry;
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

    public function handle(CompetitiveSourceRegistry $registry): int
    {
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
            if (($cohort['collection_state'] ?? null) !== 'approved') {
                return $this->emit([
                    'status' => 'HOLD',
                    'cohort_id' => $cohortId,
                    'hold_reason' => (string) ($cohort['hold_reason'] ?? 'SOURCE_POLICY_HOLD'),
                    'context_eligible' => false,
                    'write_performed' => false,
                    'dependency_ingestion' => ['external_reads' => 0],
                    'execution_allowed' => false,
                ], self::SUCCESS);
            }

            return $this->emit([
                'status' => 'HOLD',
                'cohort_id' => $cohortId,
                'hold_reason' => 'COMPETITIVE_LIVE_INGESTION_DORMANT',
                'context_eligible' => false,
                'write_performed' => false,
                'dependency_ingestion' => ['external_reads' => 0],
                'execution_allowed' => false,
            ], self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_REGISTRY_INVALID', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
    }

    private function writeBoundaryAllowed(): bool
    {
        return in_array(app()->environment(), ['staging', 'production'], true)
            && (bool) config('seo_agent_evidence.external_fetch_enabled', false)
            && (bool) config('seo_agent_evidence.bundle_write_enabled', false)
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
