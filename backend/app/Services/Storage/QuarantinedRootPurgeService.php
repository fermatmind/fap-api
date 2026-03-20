<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ContentReleaseExactManifest;
use Illuminate\Support\Facades\File;

final class QuarantinedRootPurgeService
{
    private const ALLOWED_SOURCE_KIND = 'legacy.source_pack';

    private const PLAN_SCHEMA = 'storage_purge_quarantined_root_plan.v1';

    private const RUN_SCHEMA = 'storage_purge_quarantined_root_run.v1';

    public function __construct(
        private readonly QuarantinedRootRestoreService $restoreService,
        private readonly ExactReleaseRehydrateService $rehydrateService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(string $itemRoot, string $disk): array
    {
        $normalizedItemRoot = $this->normalizeRoot($itemRoot);
        $disk = trim($disk);

        try {
            $context = $this->inspectItemRoot($normalizedItemRoot, $disk);

            return [
                'schema' => (string) config('storage_rollout.purge_plan_schema_version', self::PLAN_SCHEMA),
                'generated_at' => now()->toAtomString(),
                'disk' => $disk,
                'item_root' => $normalizedItemRoot,
                'exact_manifest_id' => (int) $context['exact_manifest_id'],
                'exact_identity_hash' => (string) $context['exact_identity_hash'],
                'source_kind' => (string) $context['source_kind'],
                'source_storage_path' => (string) $context['source_storage_path'],
                'manifest_hash' => (string) $context['manifest_hash'],
                'file_count' => (int) $context['file_count'],
                'total_size_bytes' => (int) $context['total_size_bytes'],
                'status' => 'planned',
                'blocked_reason' => null,
                'validation' => $context['validation'],
            ];
        } catch (\Throwable $e) {
            return [
                'schema' => (string) config('storage_rollout.purge_plan_schema_version', self::PLAN_SCHEMA),
                'generated_at' => now()->toAtomString(),
                'disk' => $disk,
                'item_root' => $normalizedItemRoot,
                'exact_manifest_id' => null,
                'exact_identity_hash' => null,
                'source_kind' => null,
                'source_storage_path' => null,
                'manifest_hash' => null,
                'file_count' => 0,
                'total_size_bytes' => 0,
                'status' => 'blocked',
                'blocked_reason' => $e->getMessage(),
                'validation' => [
                    'quarantine_item_under_base' => $this->isUnderPrefix($normalizedItemRoot, $this->quarantineRootBase()),
                ],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan, ?string $expectedItemRoot = null): array
    {
        $resolvedPlan = $this->resolveExecutablePlan($plan, $expectedItemRoot);
        $itemRoot = $this->normalizeRoot((string) ($resolvedPlan['item_root'] ?? ''));
        $runId = now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8);
        $runBase = $this->purgeRootBase().DIRECTORY_SEPARATOR.$runId;
        $itemReceiptDir = $runBase.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.hash('sha256', $itemRoot);
        File::ensureDirectoryExists($itemReceiptDir);

        $startedAt = now()->toAtomString();
        $purgedAt = null;
        $status = 'failure';
        $error = null;
        $itemDeleted = false;

        $this->writeRun(
            $runBase,
            $startedAt,
            null,
            (string) ($resolvedPlan['disk'] ?? ''),
            true,
            0,
            0,
            0,
            0,
            [[
                'item_root' => $itemRoot,
                'exact_manifest_id' => (int) ($resolvedPlan['exact_manifest_id'] ?? 0),
                'exact_identity_hash' => (string) ($resolvedPlan['exact_identity_hash'] ?? ''),
                'source_kind' => (string) ($resolvedPlan['source_kind'] ?? ''),
                'source_storage_path' => (string) ($resolvedPlan['source_storage_path'] ?? ''),
                'file_count' => (int) ($resolvedPlan['file_count'] ?? 0),
                'total_size_bytes' => (int) ($resolvedPlan['total_size_bytes'] ?? 0),
                'status' => 'in_progress',
                'error' => null,
            ]]
        );

        try {
            if (! is_dir($itemRoot)) {
                throw new \RuntimeException('quarantine item root does not exist.');
            }

            File::deleteDirectory($itemRoot);
            if (is_dir($itemRoot)) {
                throw new \RuntimeException('failed to delete quarantine item root.');
            }

            $itemDeleted = true;
            $status = 'success';
            $purgedAt = now()->toAtomString();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->writeReceipt($itemReceiptDir.DIRECTORY_SEPARATOR.'purge.json', [
            'item_root' => $itemRoot,
            'exact_manifest_id' => (int) ($resolvedPlan['exact_manifest_id'] ?? 0),
            'exact_identity_hash' => (string) ($resolvedPlan['exact_identity_hash'] ?? ''),
            'source_kind' => (string) ($resolvedPlan['source_kind'] ?? ''),
            'source_storage_path' => (string) ($resolvedPlan['source_storage_path'] ?? ''),
            'file_count' => (int) ($resolvedPlan['file_count'] ?? 0),
            'total_size_bytes' => (int) ($resolvedPlan['total_size_bytes'] ?? 0),
            'purged_at' => $purgedAt,
            'status' => $status,
            'error' => $error,
        ]);

        $this->writeRun(
            $runBase,
            $startedAt,
            $purgedAt,
            (string) ($resolvedPlan['disk'] ?? ''),
            true,
            $status === 'success' ? 1 : 0,
            $status === 'success' ? 0 : 1,
            $status === 'success' ? (int) ($resolvedPlan['file_count'] ?? 0) : 0,
            $status === 'success' ? (int) ($resolvedPlan['total_size_bytes'] ?? 0) : 0,
            [[
                'item_root' => $itemRoot,
                'exact_manifest_id' => (int) ($resolvedPlan['exact_manifest_id'] ?? 0),
                'exact_identity_hash' => (string) ($resolvedPlan['exact_identity_hash'] ?? ''),
                'source_kind' => (string) ($resolvedPlan['source_kind'] ?? ''),
                'source_storage_path' => (string) ($resolvedPlan['source_storage_path'] ?? ''),
                'file_count' => (int) ($resolvedPlan['file_count'] ?? 0),
                'total_size_bytes' => (int) ($resolvedPlan['total_size_bytes'] ?? 0),
                'status' => $status,
                'error' => $error,
                'item_deleted' => $itemDeleted,
            ]]
        );

        return [
            'run_id' => $runId,
            'run_dir' => $runBase,
            'status' => $status,
            'item_root' => $itemRoot,
            'exact_manifest_id' => (int) ($resolvedPlan['exact_manifest_id'] ?? 0),
            'source_kind' => (string) ($resolvedPlan['source_kind'] ?? ''),
            'purged_root_count' => $status === 'success' ? 1 : 0,
            'failed_root_count' => $status === 'success' ? 0 : 1,
            'purged_file_count' => $status === 'success' ? (int) ($resolvedPlan['file_count'] ?? 0) : 0,
            'purged_total_size_bytes' => $status === 'success' ? (int) ($resolvedPlan['total_size_bytes'] ?? 0) : 0,
            'error' => $error,
            'receipt_path' => $itemReceiptDir.DIRECTORY_SEPARATOR.'purge.json',
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function resolveExecutablePlan(array $plan, ?string $expectedItemRoot): array
    {
        $schema = trim((string) ($plan['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.purge_plan_schema_version', self::PLAN_SCHEMA)) {
            throw new \RuntimeException('invalid purge plan schema: '.$schema);
        }

        $disk = trim((string) ($plan['disk'] ?? ''));
        if ($disk === '') {
            throw new \RuntimeException('purge plan disk is required.');
        }

        $planItemRoot = $this->normalizeRoot((string) ($plan['item_root'] ?? ''));
        $requestedItemRoot = $expectedItemRoot !== null ? $this->normalizeRoot($expectedItemRoot) : $planItemRoot;
        if ($requestedItemRoot === '') {
            throw new \RuntimeException('purge item root is required.');
        }

        if ($planItemRoot !== '' && $requestedItemRoot !== '' && $planItemRoot !== $requestedItemRoot) {
            throw new \RuntimeException('purge plan item_root does not match requested item root.');
        }

        $freshPlan = $this->buildPlan($requestedItemRoot, $disk);
        if (($freshPlan['status'] ?? '') !== 'planned') {
            throw new \RuntimeException((string) ($freshPlan['blocked_reason'] ?? 'purge plan is blocked.'));
        }

        $comparisons = [
            'item_root',
            'exact_manifest_id',
            'exact_identity_hash',
            'source_kind',
            'source_storage_path',
            'manifest_hash',
            'file_count',
            'total_size_bytes',
        ];
        foreach ($comparisons as $field) {
            if (($plan[$field] ?? null) !== ($freshPlan[$field] ?? null)) {
                throw new \RuntimeException('plan_candidate_mismatch');
            }
        }

        return $freshPlan;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectItemRoot(string $itemRoot, string $disk): array
    {
        if ($itemRoot === '') {
            throw new \RuntimeException('purge item root is required.');
        }

        if (! $this->isUnderPrefix($itemRoot, $this->quarantineRootBase())) {
            throw new \RuntimeException('purge item root must be under the quarantine root base.');
        }

        if (! is_dir($itemRoot)) {
            throw new \RuntimeException('quarantine item root does not exist.');
        }

        $sentinelPath = $itemRoot.DIRECTORY_SEPARATOR.'.quarantine.json';
        if (! is_file($sentinelPath)) {
            throw new \RuntimeException('quarantine sentinel is missing.');
        }

        $sentinel = json_decode((string) File::get($sentinelPath), true);
        if (! is_array($sentinel)) {
            throw new \RuntimeException('failed to decode quarantine sentinel json.');
        }

        $sentinelSchema = trim((string) ($sentinel['schema'] ?? ''));
        if ($sentinelSchema !== (string) config('storage_rollout.quarantine_run_schema_version', 'storage_quarantine_exact_root_run.v1')) {
            throw new \RuntimeException('invalid quarantine sentinel schema: '.$sentinelSchema);
        }

        $sourceKind = trim((string) ($sentinel['source_kind'] ?? ''));
        if ($sourceKind !== self::ALLOWED_SOURCE_KIND) {
            throw new \RuntimeException('purge source_kind is not allowed.');
        }

        $exactManifestId = (int) ($sentinel['exact_manifest_id'] ?? 0);
        if ($exactManifestId <= 0) {
            throw new \RuntimeException('quarantine sentinel exact_manifest_id is missing.');
        }

        $exactIdentityHash = trim((string) ($sentinel['exact_identity_hash'] ?? ''));
        if ($exactIdentityHash === '') {
            throw new \RuntimeException('quarantine sentinel exact_identity_hash is missing.');
        }

        $sourceStoragePath = $this->normalizeRoot((string) ($sentinel['source_storage_path'] ?? ''));
        if ($sourceStoragePath === '') {
            throw new \RuntimeException('quarantine sentinel source_storage_path is missing.');
        }

        $manifestHash = strtolower(trim((string) ($sentinel['manifest_hash'] ?? '')));
        if ($manifestHash === '') {
            throw new \RuntimeException('quarantine sentinel manifest_hash is missing.');
        }

        $manifest = $this->loadExactManifest($exactManifestId);
        $validation = [
            'quarantine_item_under_base' => true,
            'quarantine_sentinel_exists' => true,
            'quarantine_schema_valid' => true,
            'source_kind_allowed' => true,
            'exact_manifest_exists' => true,
        ];

        $this->assertSentinelMatchesManifest($sentinel, $manifest);
        $validation['sentinel_matches_exact_authority'] = true;

        $fileRows = $manifest->files()->orderBy('logical_path')->get();
        if ($fileRows->isEmpty()) {
            throw new \RuntimeException('exact manifest has no file rows.');
        }
        $validation['exact_child_rows_exist'] = true;

        $restorePlan = $this->restoreService->buildPlan($itemRoot);
        if (($restorePlan['status'] ?? '') !== 'planned') {
            throw new \RuntimeException('restore dry-run is blocked: '.(string) ($restorePlan['blocked_reason'] ?? 'restore blocked.'));
        }
        $validation['restore_dry_run_plannable'] = true;

        $rehydratePlan = $this->rehydrateService->buildPlan($exactManifestId, null, $disk);
        $missingLocations = (int) data_get($rehydratePlan, 'summary.missing_locations', 0);
        if ($missingLocations > 0) {
            throw new \RuntimeException('missing verified remote_copy coverage for purge: '.$missingLocations);
        }
        $validation['verified_remote_copy_complete'] = true;

        return [
            'item_root' => $itemRoot,
            'exact_manifest_id' => $exactManifestId,
            'exact_identity_hash' => $exactIdentityHash,
            'source_kind' => $sourceKind,
            'source_storage_path' => $sourceStoragePath,
            'manifest_hash' => $manifestHash,
            'file_count' => (int) $fileRows->count(),
            'total_size_bytes' => (int) $fileRows->sum('size_bytes'),
            'validation' => $validation,
        ];
    }

    private function loadExactManifest(int $exactManifestId): ContentReleaseExactManifest
    {
        $manifest = ContentReleaseExactManifest::query()
            ->with('files')
            ->find($exactManifestId);

        if (! $manifest instanceof ContentReleaseExactManifest) {
            throw new \RuntimeException('exact manifest is missing.');
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $sentinel
     */
    private function assertSentinelMatchesManifest(array $sentinel, ContentReleaseExactManifest $manifest): void
    {
        $pairs = [
            'exact_manifest_id' => (int) $manifest->getKey(),
            'exact_identity_hash' => trim((string) $manifest->exact_identity_hash),
            'source_kind' => trim((string) $manifest->source_kind),
            'source_storage_path' => $this->normalizeRoot((string) $manifest->source_storage_path),
            'manifest_hash' => strtolower(trim((string) $manifest->manifest_hash)),
        ];

        foreach ($pairs as $field => $expected) {
            $actual = $sentinel[$field] ?? null;
            if (is_string($expected)) {
                $actual = trim((string) $actual);
            } elseif (is_int($expected)) {
                $actual = (int) $actual;
            }

            if ($actual !== $expected) {
                throw new \RuntimeException('quarantine sentinel does not match exact authority.');
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function writeRun(
        string $runBase,
        string $startedAt,
        ?string $completedAt,
        string $disk,
        bool $executedFromPlan,
        int $purgedRootCount,
        int $failedRootCount,
        int $purgedFileCount,
        int $purgedTotalSizeBytes,
        array $items,
    ): void {
        $payload = [
            'schema' => (string) config('storage_rollout.purge_run_schema_version', self::RUN_SCHEMA),
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'disk' => $disk,
            'executed_from_plan' => $executedFromPlan,
            'purged_root_count' => $purgedRootCount,
            'failed_root_count' => $failedRootCount,
            'purged_file_count' => $purgedFileCount,
            'purged_total_size_bytes' => $purgedTotalSizeBytes,
            'items' => $items,
        ];

        $this->writeReceipt($runBase.DIRECTORY_SEPARATOR.'run.json', $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeReceipt(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode purge metadata json.');
        }

        File::put($path, $encoded.PHP_EOL);
    }

    private function quarantineRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.quarantine_root_dir', 'app/private/quarantine/release_roots'));
        $relative = ltrim($relative, '/\\');

        return $this->normalizeRoot(storage_path($relative));
    }

    private function purgeRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.purge_root_dir', 'app/private/quarantine/purge_runs'));
        $relative = ltrim($relative, '/\\');

        return $this->normalizeRoot(storage_path($relative));
    }

    private function isUnderPrefix(string $path, string $prefix): bool
    {
        $path = $this->normalizeRoot($path);
        $prefix = $this->normalizeRoot($prefix);
        if ($path === '' || $prefix === '') {
            return false;
        }

        return $path === $prefix || str_starts_with($path.'/', $prefix.'/');
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }
}
