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
        {--refresh-if-due : Existing authorized M3 natural refresh; no deployment collection}
        {--cohort= : Immutable competitive cohort id}
        {--dry-run : Evaluate without persistence}
        {--no-write : Enforce zero persistence}
        {--write-evidence : Persist only to seo_evidence_bundles}
        {--finalize-activation : Finalize an already validated production receipt after activation and smoke}
        {--preactivation-receipt= : Immutable production preactivation receipt path}
        {--json : Emit machine-readable output}';

    protected $description = 'Acquire registered public competitive evidence through the External Content Gateway';

    public function handle(
        CompetitiveSourceRegistry $registry,
        CompetitiveEvidenceIngestionService $ingestion,
        CompetitiveCloseoutBuilder $closeout,
    ): int {
        if ($this->option('refresh-if-due')) {
            try {
                return $this->refresh($registry, $ingestion, $closeout);
            } catch (Throwable) {
                return $this->emit(['status' => 'HOLD', 'hold_reason' => 'REFRESH_RUNTIME_UNAVAILABLE', 'external_reads' => 0], self::SUCCESS);
            }
        }
        $dryRun = (bool) $this->option('dry-run');
        $noWrite = (bool) $this->option('no-write');
        $write = (bool) $this->option('write-evidence');
        $finalize = (bool) $this->option('finalize-activation');
        if ($finalize) {
            return $this->finalize($closeout);
        }
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
            $releaseSha = $write ? (string) config('seo_agent_evidence.competitive.release_sha', '') : str_repeat('0', 40);
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
            );
            if (! $closeout->verify($receipt, $releaseSha)) {
                return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_RECEIPT_INVALID', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
            }

            return $this->emit($receipt, self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_REGISTRY_INVALID', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
    }

    private function refresh(CompetitiveSourceRegistry $registry, CompetitiveEvidenceIngestionService $ingestion, CompetitiveCloseoutBuilder $closeout): int
    {
        $control = app(\App\Services\SeoCouncil\Platform12\Platform12RuntimeControl::class);
        $selection = app(\App\Services\SeoCouncil\Platform12\Platform12EvidenceSelection::class);
        $state = $control->status();
        $mission = \App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet::IDS[2];
        if (! app()->runningInConsole() || ! app()->environment('production') || ! $control->allowsMission($mission)) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'REFRESH_NOT_AUTHORIZED', 'external_reads' => 0], self::SUCCESS);
        }
        if ($this->option('dry-run') || $this->option('no-write') || $this->option('write-evidence')
            || $this->option('finalize-activation') || $this->option('preactivation-receipt')
            || ! in_array(trim((string) $this->option('cohort')), ['', \App\Services\SeoCouncil\Platform12\Platform12EvidenceSelection::COHORT], true)) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'REFRESH_MODE_INVALID', 'external_reads' => 0], self::SUCCESS);
        }
        $sha = trim((string) file_get_contents(config('seo_council.release_revision_path')));
        $cycle = now('UTC')->format('Y-m-d');
        $cache = \Illuminate\Support\Facades\Cache::store(config('seo_council.runtime_cache_store', config('cache.default')));

        return $cache->lock('seo:competitive:refresh', 360)->get(function () use ($registry, $ingestion, $closeout, $selection, $control, $state, $sha, $cycle, $cache): int {
            $history = $selection->auditHistory($sha);
            if ($history['fault_count'] > 0) {
                return $this->emit(['status' => 'DENY', 'hold_reason' => 'HISTORY_INVALID', 'external_reads' => 0], self::SUCCESS);
            }
            try {
                $reference = $selection->currentReference(collect(), \Carbon\CarbonImmutable::now('UTC'), $sha);
                $expires = \Carbon\CarbonImmutable::parse($reference['bundle']['expires_at']);
                foreach ($reference['bundle']['payload']['policy_observations'] as $policy) {
                    $expires = $expires->min(\Carbon\CarbonImmutable::parse($policy['valid_until']));
                }
                if ($expires->gt(now('UTC')->addDay())) {
                    return $this->emit(['status' => 'REUSED', 'source_sha' => $reference['source_sha'], 'execution_sha' => $sha, 'external_reads' => 0], self::SUCCESS);
                }
            } catch (Throwable) {
                // Missing or incompatible input needs refresh, never a synthetic fresh time.
                $reference = null;
            }
            $archive = storage_path('app/release-receipts/seo-competitive-evidence/'.$sha.'-'.$cycle.'.json');
            if (is_file($archive) && ! is_link($archive) && filesize($archive) <= 131072) {
                try {
                    $candidate = json_decode(file_get_contents($archive), true, 64, JSON_THROW_ON_ERROR);
                    $selection->publish($candidate, $sha, $state['generation']);

                    return $this->emit(['status' => 'REUSED_CYCLE', 'execution_sha' => $sha, 'cycle' => $cycle, 'external_reads' => 0], self::SUCCESS);
                } catch (Throwable) {
                    return $this->emit(['status' => 'HOLD', 'hold_reason' => 'REFRESH_ARCHIVE_INVALID', 'external_reads' => 0], self::SUCCESS);
                }
            }
            if ($cache->has('seo:competitive:attempt:'.$cycle)) {
                return $this->emit(['status' => 'HOLD', 'hold_reason' => 'REFRESH_BACKOFF', 'external_reads' => 0], self::SUCCESS);
            }
            $cache->put('seo:competitive:attempt:'.$cycle, true, 3600);
            $originalConfig = config('seo_agent_evidence');
            $receipt = null;
            $reason = 'COMPETITIVE_WRITE_BOUNDARY_HELD';
            $reads = 0;
            try {
                // Separate fixed-M3 authorization is mandatory; selection alone grants no collection.
                if ($this->installNaturalRefreshScope($sha, $state['generation']) && $this->writeBoundaryAllowed()) {
                    $cohort = $registry->cohort(\App\Services\SeoCouncil\Platform12\Platform12EvidenceSelection::COHORT);
                    $result = $ingestion->ingest($cohort, $registry->sourcesFor($cohort), 'production', $sha, true, $cycle);
                    $reads = (int) data_get($result, 'dependency_ingestion.external_reads', 0);
                    $receipt = $closeout->finalizeRuntime($closeout->buildRuntime($result, $sha, 'production'), $sha);
                    $reason = $receipt['competitive_hold_reason'] ?? 'REFRESH_FAILED';
                    if ($closeout->verify($receipt, $sha) && ($receipt['closeout_state'] ?? null) === 'CLOSED') {
                        $directory = storage_path('app/release-receipts/seo-competitive-evidence');
                        if (! is_dir($directory)) {
                            mkdir($directory, 0750, true);
                        }
                        $path = $directory.'/'.$sha.'-'.$cycle.'.json';
                        $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                        if (! is_file($path)) {
                            $temp = tempnam($directory, '.cycle-');
                            try {
                                if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
                                    throw new \RuntimeException('REFRESH_ARCHIVE_WRITE_FAILED');
                                }
                                chmod($temp, 0640);
                                if (! link($temp, $path)) {
                                    throw new \RuntimeException('REFRESH_ARCHIVE_CONFLICT');
                                }
                            } finally {
                                if (is_file($temp)) {
                                    unlink($temp);
                                }
                            }
                        } elseif (file_get_contents($path) !== $bytes) {
                            throw new \RuntimeException('REFRESH_CYCLE_CONFLICT');
                        }
                        config()->set('seo_agent_evidence', $originalConfig);
                        $selection->publish($receipt, $sha, $state['generation']);

                        return $this->emit(['status' => 'READY', 'execution_sha' => $sha, 'cycle' => $cycle, 'external_reads' => $reads], self::SUCCESS);
                    }
                }
            } catch (Throwable $error) {
                $reason = preg_match('/^[A-Z_]{3,64}$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'REFRESH_FAILED';
            }
            config()->set('seo_agent_evidence', $originalConfig);
            $control->withControlLock(function () use ($control, $selection, $state, $sha, $cycle, $reason): void {
                if ($control->allowsMission(\App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet::IDS[2], false, $state['generation'])
                    && trim((string) file_get_contents(config('seo_council.release_revision_path'))) === $sha) {
                    $selection->atomicReference(['refresh_status' => 'failed', 'execution_sha' => $sha, 'cycle' => $cycle,
                        'reason' => $reason, 'checked_at' => now('UTC')->toAtomString()]);
                }
            });

            return $this->emit(['status' => 'HOLD', 'hold_reason' => $reason, 'external_reads' => $reads], self::SUCCESS);
        }) ?? $this->emit(['status' => 'WAIT', 'hold_reason' => 'REFRESH_LOCK_BUSY', 'external_reads' => 0], self::SUCCESS);
    }

    private function finalize(CompetitiveCloseoutBuilder $closeout): int
    {
        if (! $this->writeBoundaryAllowed() || app()->environment() !== 'production'
            || (bool) $this->option('write-evidence') || (bool) $this->option('dry-run') || (bool) $this->option('no-write')) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_ACTIVATION_BOUNDARY_HELD', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
        $sha = (string) config('seo_agent_evidence.competitive.release_sha', '');
        $path = (string) $this->option('preactivation-receipt');
        $real = realpath($path);
        $expectedDirectory = realpath(storage_path('app/release-receipts/seo-competitive-evidence'));
        $revisionPath = dirname(base_path()).'/REVISION';
        if ($real === false || $expectedDirectory === false || dirname($real) !== $expectedDirectory
            || is_link($path) || ! is_file($real) || basename($real) !== 'preactivation-'.$sha.'.json'
            || ! is_file($revisionPath)) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_PREACTIVATION_RECEIPT_HELD', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
        try {
            $preactivation = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
            $activeRevision = trim((string) file_get_contents($revisionPath));
            $receipt = $closeout->finalizeRuntime(is_array($preactivation) ? $preactivation : [], $activeRevision);
            if (! $closeout->verify($receipt, $sha) || ($receipt['closeout_state'] ?? null) !== 'CLOSED') {
                return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_ACTIVATION_OBSERVATION_HELD', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
            }

            return $this->emit($receipt, self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'HOLD', 'hold_reason' => 'COMPETITIVE_ACTIVATION_INTERNAL_HOLD', 'dependency_ingestion' => ['external_reads' => 0]], self::FAILURE);
        }
    }

    private function writeBoundaryAllowed(): bool
    {
        return in_array(app()->environment(), ['staging', 'production'], true)
            && config('seo_agent_evidence.competitive.external_read_enabled', false) === true
            && config('seo_agent_evidence.competitive.evidence_write_enabled', false) === true
            && preg_match('/^[a-f0-9]{40}$/', (string) config('seo_agent_evidence.competitive.release_sha', '')) === 1;
    }

    private function installNaturalRefreshScope(string $sha, string $generation): bool
    {
        if (! app()->runningInConsole() || ! app()->environment('production')
            || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1
            || config('seo_agent_evidence.competitive.m3_refresh.external_read_enabled', false) !== true
            || config('seo_agent_evidence.competitive.m3_refresh.evidence_write_enabled', false) !== true
            || ! app(\App\Services\SeoCouncil\Platform12\Platform12RuntimeControl::class)->allowsMission(
                \App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet::IDS[2], false, $generation,
            )) {
            return false;
        }
        // Only this short-lived natural refresh process gains the existing policy installer inputs.
        // The caller restores the complete evidence config on success and every failure path.
        config()->set('seo_agent_evidence.competitive.release_sha', $sha);
        config()->set('seo_agent_evidence.competitive.external_read_enabled', true);
        config()->set('seo_agent_evidence.competitive.evidence_write_enabled', true);

        return true;
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
