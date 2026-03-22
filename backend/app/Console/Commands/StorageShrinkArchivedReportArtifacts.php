<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ReportArtifactsShrinkService;
use App\Services\Storage\ArtifactLifecycleFrontDoor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StorageShrinkArchivedReportArtifacts extends Command
{
    protected $signature = 'storage:shrink-archived-report-artifacts
        {--dry-run : Build a shrink plan for archived and rehydratable local canonical report artifacts}
        {--execute : Execute shrink from an existing plan}
        {--attempt-id=* : Narrow dry-run candidate selection to one or more attempt_ids}
        {--limit= : Limit dry-run candidate selection after proof/block evaluation}
        {--disk=s3 : Remote object storage disk that still holds archived canonical artifacts}
        {--plan= : Absolute or storage-relative plan path required for execute mode}
        {--json : Emit the full payload as JSON}';

    protected $description = 'Manual-only single-file shrink surface that deletes only local canonical report artifacts already covered by archive and rehydrate truth.';

    public function __construct(
        private readonly ReportArtifactsShrinkService $service,
        private readonly ArtifactLifecycleFrontDoor $frontDoor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        $disk = trim((string) ($this->option('disk') ?? 's3'));

        try {
            $this->ensureTargetDiskIsRemote($disk);

            if ($dryRun) {
                $plan = $this->service->buildPlan($disk, [
                    'attempt_ids' => $this->normalizedAttemptIds(),
                    'limit' => $this->normalizedLimit(),
                    'generated_by_command' => 'storage:shrink-archived-report-artifacts',
                ]);
                $planPath = $this->persistPlan($plan);
                $payload = $plan + ['plan' => $planPath];

                return $this->emitPayload($payload);
            }

            if ($this->normalizedAttemptIds() !== [] || $this->option('limit') !== null) {
                $this->error('--attempt-id and --limit are only supported with --dry-run.');

                return self::FAILURE;
            }

            $planOption = trim((string) ($this->option('plan') ?? ''));
            if ($planOption === '') {
                $this->error('--execute requires --plan.');

                return self::FAILURE;
            }

            $resolvedPlanPath = $this->resolvePlanPath($planOption);
            $plan = $this->readPlan($resolvedPlanPath);
            $planDisk = trim((string) ($plan['target_disk'] ?? $plan['disk'] ?? ''));
            if ($planDisk !== $disk) {
                $this->error('plan disk mismatch: '.$planDisk.' != '.$disk);

                return self::FAILURE;
            }

            $plan['_meta'] = array_merge(
                is_array($plan['_meta'] ?? null) ? $plan['_meta'] : [],
                ['plan_path' => $resolvedPlanPath]
            );
            $payload = $this->frontDoorEnabled()
                ? $this->frontDoor->execute('shrink_archived_report_artifacts', $plan, fn (array $resolvedPlan): array => $this->service->executePlan($resolvedPlan))
                : $this->service->executePlan($plan);

            return $this->emitPayload($payload);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function ensureTargetDiskIsRemote(string $disk): void
    {
        $driver = trim((string) config('filesystems.disks.'.$disk.'.driver', ''));
        if ($driver === '') {
            throw new \RuntimeException('shrink target disk is not configured: filesystems.disks.'.$disk.' is missing.');
        }

        if ($driver === 'local') {
            throw new \RuntimeException('shrink target disk must be remote: local disks are not allowed.');
        }

        if ($driver === 's3' && trim((string) config('filesystems.disks.'.$disk.'.bucket', '')) === '') {
            throw new \RuntimeException('shrink target disk is not configured: filesystems.disks.'.$disk.'.bucket is empty.');
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode archived report artifacts shrink json.');

                return self::FAILURE;
            }

            $this->line($encoded);

            return (($payload['status'] ?? 'executed') === 'partial_failure') ? self::FAILURE : self::SUCCESS;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('disk='.(string) ($payload['disk'] ?? ''));
        $this->line('target_disk='.(string) ($payload['target_disk'] ?? ''));
        $this->line('schema='.(string) ($payload['schema'] ?? ''));
        $this->line('plan='.(string) ($payload['plan'] ?? ''));
        $this->line('selection_scope='.(string) data_get($payload, 'selection_scope', 'legacy_unscoped_plan'));
        $requestedAttemptIds = data_get($payload, 'requested_attempt_ids', []);
        if (! is_array($requestedAttemptIds)) {
            $requestedAttemptIds = [];
        }
        $this->line('requested_attempt_ids='.implode(',', array_map(static fn (mixed $value): string => (string) $value, $requestedAttemptIds)));
        $requestedLimit = data_get($payload, 'requested_limit');
        $this->line('requested_limit='.(is_int($requestedLimit) ? (string) $requestedLimit : ''));
        $this->line('candidate_count='.(int) ($summary['candidate_count'] ?? 0));
        $this->line('deleted_count='.(int) ($summary['deleted_count'] ?? 0));
        $this->line('skipped_missing_local_count='.(int) ($summary['skipped_missing_local_count'] ?? 0));
        $this->line('blocked_legal_hold_count='.(int) ($summary['blocked_legal_hold_count'] ?? 0));
        $this->line('blocked_missing_remote_count='.(int) ($summary['blocked_missing_remote_count'] ?? 0));
        $this->line('blocked_missing_archive_proof_count='.(int) ($summary['blocked_missing_archive_proof_count'] ?? 0));
        $this->line('blocked_missing_rehydrate_proof_count='.(int) ($summary['blocked_missing_rehydrate_proof_count'] ?? 0));
        $this->line('blocked_hash_mismatch_count='.(int) ($summary['blocked_hash_mismatch_count'] ?? 0));
        $this->line('failed_count='.(int) ($summary['failed_count'] ?? 0));

        if (isset($payload['run_path'])) {
            $this->line('run_path='.(string) $payload['run_path']);
        }

        return (($payload['status'] ?? 'executed') === 'partial_failure') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/report_artifact_shrink_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_report_artifact_shrink_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode report artifact shrink plan json.');
        }

        File::put($planPath, $encoded.PHP_EOL);

        return $planPath;
    }

    /**
     * @return array<string,mixed>
     */
    private function readPlan(string $planPath): array
    {
        if (! is_file($planPath)) {
            throw new \RuntimeException('plan not found: '.$planPath);
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('invalid report artifact shrink plan json: '.$planPath);
        }

        if ((string) ($decoded['schema'] ?? '') !== 'storage_shrink_archived_report_artifacts_plan.v1') {
            throw new \RuntimeException('plan schema mismatch.');
        }

        return $decoded;
    }

    private function resolvePlanPath(string $planOption): string
    {
        if (str_starts_with($planOption, DIRECTORY_SEPARATOR)) {
            return $planOption;
        }

        $basePathCandidate = base_path($planOption);
        if (is_file($basePathCandidate)) {
            return $basePathCandidate;
        }

        return storage_path('app/private/'.ltrim($planOption, '/\\'));
    }

    private function frontDoorEnabled(): bool
    {
        return (bool) config('storage_rollout.lifecycle_front_door_enabled', false);
    }

    /**
     * @return list<string>
     */
    private function normalizedAttemptIds(): array
    {
        $values = $this->option('attempt-id');
        if (! is_array($values)) {
            return [];
        }

        $attemptIds = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        ), static fn (string $value): bool => $value !== ''));

        $attemptIds = array_values(array_unique($attemptIds));
        sort($attemptIds);

        return $attemptIds;
    }

    private function normalizedLimit(): ?int
    {
        $raw = $this->option('limit');
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $limit = trim((string) $raw);
        if (! ctype_digit($limit) || (int) $limit <= 0) {
            throw new \RuntimeException('--limit must be a positive integer.');
        }

        return (int) $limit;
    }
}
