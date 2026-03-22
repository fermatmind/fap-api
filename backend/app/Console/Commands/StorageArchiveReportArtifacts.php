<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ReportArtifactsArchiveService;
use App\Services\Storage\ArtifactLifecycleFrontDoor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StorageArchiveReportArtifacts extends Command
{
    protected $signature = 'storage:archive-report-artifacts
        {--dry-run : Build an archive plan for canonical report artifacts only}
        {--execute : Execute archive from an existing plan}
        {--disk=s3 : Remote object storage disk that receives archived canonical artifacts}
        {--plan= : Absolute or storage-relative plan path required for execute mode}
        {--json : Emit the full payload as JSON}';

    protected $description = 'Manual-only copy pipeline that archives canonical report artifacts to remote object storage without deleting or cutting over local reads.';

    public function __construct(
        private readonly ReportArtifactsArchiveService $service,
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
                $plan = $this->service->buildPlan($disk);
                $planPath = $this->persistPlan($plan);
                $payload = $plan + ['plan' => $planPath];

                return $this->emitPayload($payload);
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

            $plan['_meta'] = [
                'plan_path' => $resolvedPlanPath,
            ];
            $payload = $this->frontDoorEnabled()
                ? $this->frontDoor->execute('archive_report_artifacts', $plan, fn (array $resolvedPlan): array => $this->service->executePlan($resolvedPlan))
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
            throw new \RuntimeException('archive target disk is not configured: filesystems.disks.'.$disk.' is missing.');
        }

        if ($driver === 'local') {
            throw new \RuntimeException('archive target disk must be remote: local disks are not allowed.');
        }

        if ($driver === 's3' && trim((string) config('filesystems.disks.'.$disk.'.bucket', '')) === '') {
            throw new \RuntimeException('archive target disk is not configured: filesystems.disks.'.$disk.'.bucket is empty.');
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
                $this->error('failed to encode report artifact archive json.');

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
        $this->line('candidate_count='.(int) ($summary['candidate_count'] ?? 0));
        $this->line('copied_count='.(int) ($summary['copied_count'] ?? 0));
        $this->line('verified_count='.(int) ($summary['verified_count'] ?? 0));
        $this->line('already_archived_count='.(int) ($summary['already_archived_count'] ?? 0));
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
        $planDir = storage_path('app/private/report_artifact_archive_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_report_artifact_archive_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode report artifact archive plan json.');
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
            throw new \RuntimeException('invalid report artifact archive plan json: '.$planPath);
        }

        if ((string) ($decoded['schema'] ?? '') !== 'storage_archive_report_artifacts_plan.v1') {
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
}
