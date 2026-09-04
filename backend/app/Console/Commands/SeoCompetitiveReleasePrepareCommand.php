<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceIngestionService;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourceRegistry;
use App\Services\SeoAgentEvidence\Competitive\MeasurementSnapshotVerifier;
use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use App\Services\SeoCouncil\Competitive\CompetitiveReleasePrepareEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class SeoCompetitiveReleasePrepareCommand extends Command
{
    private const COHORT = 'competitive.big-five.live.v2';

    private const PROCESS_TIMEOUT_SECONDS = 1500;

    private const SUPERVISOR_TIMEOUT_SECONDS = 1800;

    protected $signature = 'seo:competitive-release-prepare
        {--candidate-sha= : Exact candidate release SHA}
        {--cohort=competitive.big-five.live.v2 : Immutable competitive cohort id}
        {--gsc-env= : Isolated GSC refresh environment file}
        {--writer-env= : Isolated Evidence writer environment file already loaded by the caller}
        {--json : Emit machine-readable output}';

    protected $description = 'Prepare production competitive evidence with reusable environment-local measurement snapshots.';

    public function handle(
        MeasurementSnapshotVerifier $snapshots,
        CompetitiveSourceRegistry $registry,
        CompetitiveEvidenceIngestionService $ingestion,
        CompetitiveCloseoutBuilder $closeout,
        CompetitiveReleasePrepareEnvelope $envelope,
    ): int {
        $startedAt = microtime(true);
        $sha = trim((string) $this->option('candidate-sha'));
        $cohortId = trim((string) $this->option('cohort'));
        $gscEnv = (string) $this->option('gsc-env');
        $writerEnv = (string) $this->option('writer-env');
        $actions = ['gsc' => 'not_run', 'cro' => 'not_run'];

        try {
            $this->preflight($sha, $cohortId, $gscEnv, $writerEnv);
        } catch (RuntimeException $exception) {
            return $this->hold('local_preflight', $this->safeReason($exception->getMessage(), 'PREFLIGHT_INTERNAL_HOLD'), $actions);
        } catch (Throwable) {
            return $this->hold('local_preflight', 'PREFLIGHT_INTERNAL_HOLD', $actions);
        }

        $measurement = $snapshots->verify($sha, 'tests', 'production');
        if (($measurement['status'] ?? null) !== 'READY') {
            $plan = $this->refreshPlan($measurement, $snapshots, 'production');
            if (($plan['hold_reason'] ?? null) !== null) {
                return $this->hold('current_measurement_validation', (string) $plan['hold_reason'], $actions, $measurement);
            }
            $actions = (array) $plan['actions'];
            $processes = $this->refreshProcesses($actions, $gscEnv);
            $failure = $this->runRefreshes($processes);
            if ($failure !== null) {
                return $this->hold('conditional_refresh', $failure, $actions, $measurement);
            }
            if (microtime(true) - $startedAt >= self::SUPERVISOR_TIMEOUT_SECONDS) {
                return $this->hold('conditional_refresh', 'SUPERVISOR_TIMEOUT', $actions, $measurement);
            }
            $measurement = $snapshots->verify($sha, 'tests', 'production');
            if (($measurement['status'] ?? null) !== 'READY') {
                return $this->hold('measurement_revalidation', 'MEASUREMENT_REVALIDATION_HOLD', $actions, $measurement);
            }
        } else {
            $actions = ['gsc' => 'reused', 'cro' => 'reused'];
        }

        try {
            $cohort = $registry->cohort($cohortId);
            $result = $ingestion->ingest(
                $cohort,
                $registry->sourcesFor($cohort),
                'production',
                $sha,
                true,
            );
            if (($result['status'] ?? null) !== 'READY') {
                return $this->hold(
                    (string) data_get($result, 'dependency_ingestion.failed_stage', 'competitive_ingestion'),
                    (string) ($result['hold_reason'] ?? 'COMPETITIVE_EVIDENCE_HOLD'),
                    $actions,
                    $measurement,
                    (array) ($result['dependency_ingestion'] ?? []),
                );
            }
            $receipt = $closeout->buildRuntime($result, $sha, 'production');
            $payload = [
                'schema_version' => 'seo.competitive_release_prepare.v1',
                'status' => 'READY',
                'failed_stage' => 'none',
                'reason_code' => 'NONE',
                'measurement_actions' => $actions,
                'measurement_snapshot_set_hash' => (string) ($measurement['measurement_snapshot_set_hash'] ?? ''),
                'measurement_bundle_set_hash' => (string) ($measurement['measurement_bundle_set_hash'] ?? ''),
                'search_snapshot_hash' => (string) data_get($measurement, 'search_measurement.snapshot_hash', ''),
                'cro_snapshot_hash' => (string) data_get($measurement, 'cro_measurement.snapshot_hash', ''),
                'dependency_ingestion' => $this->counts((array) ($result['dependency_ingestion'] ?? [])),
                'preactivation_receipt' => $receipt,
            ];
            if (! $envelope->verify($payload, $sha, 'production')) {
                return $this->hold('preactivation_receipt_build', 'COMPETITIVE_RECEIPT_INVALID', $actions, $measurement);
            }

            return $this->emit($payload, self::SUCCESS);
        } catch (Throwable) {
            return $this->hold('competitive_ingestion', 'COMPETITIVE_PREPARE_INTERNAL_HOLD', $actions, $measurement);
        }
    }

    private function preflight(string $sha, string $cohortId, string $gscEnv, string $writerEnv): void
    {
        if (app()->environment() !== 'production') {
            throw new RuntimeException('PRODUCTION_ENVIRONMENT_REQUIRED');
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1 || $cohortId !== self::COHORT) {
            throw new RuntimeException('EXACT_SHA_OR_COHORT_INVALID');
        }
        $revision = dirname(base_path()).'/REVISION';
        if (! is_file($revision) || is_link($revision) || trim((string) file_get_contents($revision)) !== $sha) {
            throw new RuntimeException('EXACT_SHA_MISMATCH');
        }
        foreach ([$gscEnv, $writerEnv] as $path) {
            if (preg_match('#^/tmp/fermatmind-11g-production-[1-9][0-9]*-[1-9][0-9]*/(?:measurement|competitive-writer)\.env$#D', $path) !== 1
                || ! is_file($path) || is_link($path) || (fileperms($path) & 0777) > 0600) {
                throw new RuntimeException('TEMP_ENV_INVALID');
            }
        }
        $configCache = (string) env('APP_CONFIG_CACHE');
        if (preg_match('#^/tmp/fermatmind-11g-production-[1-9][0-9]*-[1-9][0-9]*/competitive-config\.php$#D', $configCache) !== 1
            || file_exists($configCache) || is_link($configCache)) {
            throw new RuntimeException('CONFIG_CACHE_INVALID');
        }
        if (! (bool) config('seo_intel.write_enabled', false)) {
            throw new RuntimeException('EVIDENCE_WRITER_DISABLED');
        }
        $this->probeEvidenceWriter($sha);
    }

    private function probeEvidenceWriter(string $sha): void
    {
        $connection = DB::connection((string) config('seo_agent_evidence.connection', 'seo_intel'));
        $id = 'preflight:'.hash('sha256', $sha.'|competitive-writer');
        if (! $connection->getSchemaBuilder()->hasTable('seo_evidence_bundles')) {
            throw new RuntimeException('EVIDENCE_SCHEMA_UNAVAILABLE');
        }
        $connection->beginTransaction();
        try {
            $connection->table('seo_evidence_bundles')->insert([
                'bundle_id' => $id,
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', $id),
                'mission_id' => $id,
                'page_family' => 'tests',
                'locale' => 'en',
                'source_type' => 'preflight_probe',
                'expires_at' => now('UTC')->addMinute()->format('Y-m-d H:i:s'),
                'bundle_json' => json_encode(['preflight' => true], JSON_THROW_ON_ERROR),
                'created_at' => now('UTC'),
            ]);
            if (! $connection->table('seo_evidence_bundles')->where('bundle_id', $id)->exists()) {
                throw new RuntimeException('EVIDENCE_WRITER_READBACK_FAILED');
            }
        } finally {
            $connection->rollBack();
        }
        if ($connection->table('seo_evidence_bundles')->where('bundle_id', $id)->exists()) {
            throw new RuntimeException('EVIDENCE_WRITER_ROLLBACK_FAILED');
        }
    }

    /** @param array<string, mixed> $measurement @return array<string, mixed> */
    private function refreshPlan(array $measurement, MeasurementSnapshotVerifier $snapshots, string $environment): array
    {
        $actions = ['gsc' => 'not_run', 'cro' => 'not_run'];
        foreach ([
            'search_measurement' => 'gsc',
            'cro_measurement' => 'cro',
        ] as $modeId => $key) {
            $mode = (array) ($measurement[$key === 'gsc' ? 'search_measurement' : 'cro_measurement'] ?? []);
            if (($mode['hold_reason'] ?? null) === 'NONE') {
                $actions[$key] = 'reused';

                continue;
            }
            $reason = (string) ($mode['hold_reason'] ?? 'MEASUREMENT_HOLD');
            if (! $snapshots->refreshable($modeId, $reason)) {
                return ['actions' => $actions, 'hold_reason' => $reason];
            }
            $actions[$key] = $snapshots->hasTrustedBaseline($modeId, $environment)
                ? 'incremental_refresh'
                : 'full_refresh';
        }

        return ['actions' => $actions, 'hold_reason' => null];
    }

    /** @param array<string, string> $actions @return array<string, Process> */
    private function refreshProcesses(array $actions, string $gscEnv): array
    {
        $processes = [];
        if (in_array($actions['gsc'] ?? '', ['incremental_refresh', 'full_refresh'], true)) {
            $command = 'set -a; . "$1"; set +a; export SEO_INTEL_WRITE_ENABLED=true; exec "$2" artisan seo-intel:gsc-sync --window=90 --search-types=web ';
            if (($actions['gsc'] ?? null) === 'full_refresh') {
                $command .= '--full-window ';
            }
            $command .= '--trigger=manual --json --no-interaction --no-ansi';
            $processes['gsc'] = new Process(['bash', '-c', $command, '--', $gscEnv, PHP_BINARY], base_path());
        }
        if (in_array($actions['cro'] ?? '', ['incremental_refresh', 'full_refresh'], true)) {
            $days = ($actions['cro'] ?? null) === 'full_refresh' ? 89 : 6;
            $processes['cro'] = new Process([
                PHP_BINARY,
                'artisan',
                'analytics:refresh-seo-conversion-daily',
                '--from='.CarbonImmutable::now('UTC')->subDays($days)->toDateString(),
                '--to='.CarbonImmutable::now('UTC')->toDateString(),
                '--org=0',
                '--trigger=manual',
                '--json',
                '--no-interaction',
                '--no-ansi',
            ], base_path(), ['SEO_INTEL_ALLOW_EXTERNAL_API_CALLS' => 'false']);
        }
        foreach ($processes as $process) {
            $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        }

        return $processes;
    }

    /** @param array<string, Process> $processes */
    private function runRefreshes(array $processes): ?string
    {
        foreach ($processes as $process) {
            $process->start();
        }
        $failure = null;
        foreach ($processes as $key => $process) {
            try {
                $process->wait();
            } catch (ProcessTimedOutException) {
                $process->stop(3);
                $failure ??= $this->refreshFailureReason($key, true);

                continue;
            } catch (Throwable) {
                $process->stop(3);
                $failure ??= $this->refreshFailureReason($key, false);

                continue;
            }
            if (! $process->isSuccessful() || ! $this->refreshOutputValid($key, $process->getOutput())) {
                $failure ??= $this->refreshFailureReason($key, false);
            }
        }

        return $failure;
    }

    private function refreshFailureReason(string $key, bool $timedOut): string
    {
        return match ([$key, $timedOut]) {
            ['gsc', true] => 'GSC_REFRESH_TIMEOUT',
            ['gsc', false] => 'GSC_REFRESH_FAILED',
            ['cro', true] => 'CRO_REFRESH_TIMEOUT',
            ['cro', false] => 'CRO_REFRESH_FAILED',
            default => 'MEASUREMENT_REVALIDATION_HOLD',
        };
    }

    private function refreshOutputValid(string $key, string $output): bool
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return false;
        }
        try {
            $payload = json_decode(substr($output, $start), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($payload)
            && ($payload['status'] ?? null) === 'success'
            && ($key !== 'gsc' || (($payload['window_days'] ?? null) === 90 && ($payload['search_types'] ?? null) === ['web']))
            && ($key !== 'cro' || data_get($payload, 'readback_receipt.status') === 'pass');
    }

    /** @param array<string, string> $actions @param array<string, mixed> $measurement @param array<string, mixed> $dependency */
    private function hold(string $stage, string $reason, array $actions, array $measurement = [], array $dependency = []): int
    {
        return $this->emit([
            'schema_version' => 'seo.competitive_release_prepare.v1',
            'status' => 'HOLD',
            'failed_stage' => preg_match('/^[a-z0-9_]{3,48}$/D', $stage) === 1 ? $stage : 'internal',
            'reason_code' => $this->safeReason($reason, 'COMPETITIVE_PREPARE_INTERNAL_HOLD'),
            'measurement_actions' => $actions,
            'measurement_snapshot_set_hash' => (string) ($measurement['measurement_snapshot_set_hash'] ?? hash('sha256', 'measurement-unavailable')),
            'measurement_bundle_set_hash' => (string) ($measurement['measurement_bundle_set_hash'] ?? hash('sha256', 'measurement-unavailable')),
            'search_snapshot_hash' => (string) data_get($measurement, 'search_measurement.snapshot_hash', hash('sha256', 'search-unavailable')),
            'cro_snapshot_hash' => (string) data_get($measurement, 'cro_measurement.snapshot_hash', hash('sha256', 'cro-unavailable')),
            'dependency_ingestion' => $this->counts($dependency),
            'preactivation_receipt' => null,
        ], self::FAILURE);
    }

    /** @param array<string, mixed> $input @return array<string, int> */
    private function counts(array $input): array
    {
        return [
            'external_reads' => max(0, (int) ($input['external_reads'] ?? 0)),
            'logical_requests' => max(0, (int) ($input['logical_requests'] ?? 0)),
            'transport_attempts' => max(0, (int) ($input['transport_attempts'] ?? 0)),
            'retry_count' => max(0, (int) ($input['retry_count'] ?? 0)),
        ];
    }

    private function safeReason(string $reason, string $fallback): string
    {
        return preg_match('/^[A-Z0-9_]{3,64}$/D', $reason) === 1 ? $reason : $fallback;
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->line((bool) $this->option('json') ? $encoded : ($payload['status'].': '.$payload['reason_code']));

        return $code;
    }
}
