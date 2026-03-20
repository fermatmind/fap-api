<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ContentReleaseExactManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class ExactRootQuarantineService
{
    /**
     * @var list<string>
     */
    private const ALLOWED_SOURCE_KINDS = [
        'legacy.source_pack',
    ];

    private const PLAN_SCHEMA = 'storage_quarantine_exact_roots_plan.v1';

    public function __construct(
        private readonly ExactReleaseRehydrateService $rehydrateService,
        private readonly ReleaseStorageLocator $releaseStorageLocator,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(string $disk): array
    {
        $disk = trim($disk);
        $activeReleaseIds = $this->activeReleaseIds();
        $snapshotReleaseIds = $this->snapshotReleaseIds();
        $protectedRoots = $this->protectedResolvedRoots($activeReleaseIds, $snapshotReleaseIds);
        $duplicateRoots = $this->duplicateSourceRoots();

        $candidates = [];
        $blocked = [];
        $skipped = [];
        $candidateFiles = 0;
        $candidateBytes = 0;

        ContentReleaseExactManifest::query()
            ->orderBy('id')
            ->get()
            ->each(function (ContentReleaseExactManifest $manifest) use (
                $disk,
                $activeReleaseIds,
                $snapshotReleaseIds,
                $protectedRoots,
                $duplicateRoots,
                &$candidates,
                &$blocked,
                &$skipped,
                &$candidateFiles,
                &$candidateBytes
            ): void {
                $evaluation = $this->evaluateManifest(
                    $manifest,
                    $disk,
                    $activeReleaseIds,
                    $snapshotReleaseIds,
                    $protectedRoots,
                    $duplicateRoots,
                );

                if (($evaluation['status'] ?? '') === 'candidate') {
                    $candidate = $evaluation['entry'];
                    $candidates[] = $candidate;
                    $candidateFiles += (int) ($candidate['file_count'] ?? 0);
                    $candidateBytes += (int) ($candidate['total_size_bytes'] ?? 0);

                    return;
                }

                if (($evaluation['status'] ?? '') === 'skipped') {
                    $skipped[] = $evaluation['entry'];

                    return;
                }

                $blocked[] = $evaluation['entry'];
            });

        return [
            'schema' => (string) config('storage_rollout.quarantine_plan_schema_version', self::PLAN_SCHEMA),
            'generated_at' => now()->toAtomString(),
            'target_disk' => $disk,
            'summary' => [
                'candidate_count' => count($candidates),
                'blocked_count' => count($blocked),
                'skipped_count' => count($skipped),
            ],
            'candidates' => $candidates,
            'blocked' => $blocked,
            'skipped' => $skipped,
            'totals' => [
                'candidate_file_count' => $candidateFiles,
                'candidate_total_size_bytes' => $candidateBytes,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan): array
    {
        $disk = trim((string) ($plan['target_disk'] ?? ''));
        if ($disk === '') {
            throw new \RuntimeException('invalid quarantine plan: target_disk is required.');
        }

        $runId = now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8);
        $runBase = $this->quarantineRootBase().DIRECTORY_SEPARATOR.$runId;
        $itemsBase = $runBase.DIRECTORY_SEPARATOR.'items';
        File::ensureDirectoryExists($itemsBase);

        $quarantined = [];
        $failures = [];
        $quarantinedFiles = 0;
        $quarantinedBytes = 0;

        foreach ((array) ($plan['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            try {
                $resolvedCandidate = $this->resolveExecutableCandidate($candidate, $disk);
                if (($resolvedCandidate['status'] ?? '') !== 'candidate') {
                    $failures[] = (array) ($resolvedCandidate['entry'] ?? []);

                    continue;
                }

                /** @var array<string,mixed> $runtimeCandidate */
                $runtimeCandidate = (array) ($resolvedCandidate['entry'] ?? []);
                $sourceRoot = $this->normalizeRoot((string) ($runtimeCandidate['source_storage_path'] ?? ''));
                $targetRoot = $runBase.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.hash('sha256', $sourceRoot).DIRECTORY_SEPARATOR.'root';
                File::ensureDirectoryExists(dirname($targetRoot));

                if (file_exists($targetRoot)) {
                    throw new \RuntimeException('quarantine target already exists: '.$targetRoot);
                }

                if (! File::moveDirectory($sourceRoot, $targetRoot)) {
                    throw new \RuntimeException('failed to move root into quarantine.');
                }

                $quarantineMeta = [
                    'schema' => (string) config('storage_rollout.quarantine_run_schema_version', 'storage_quarantine_exact_root_run.v1'),
                    'exact_manifest_id' => (int) ($runtimeCandidate['exact_manifest_id'] ?? 0),
                    'exact_identity_hash' => (string) ($runtimeCandidate['exact_identity_hash'] ?? ''),
                    'source_kind' => (string) ($runtimeCandidate['source_kind'] ?? ''),
                    'source_disk' => (string) ($runtimeCandidate['source_disk'] ?? 'local'),
                    'source_storage_path' => $sourceRoot,
                    'pack_id' => $runtimeCandidate['pack_id'] ?? null,
                    'pack_version' => $runtimeCandidate['pack_version'] ?? null,
                    'manifest_hash' => (string) ($runtimeCandidate['manifest_hash'] ?? ''),
                    'quarantined_at' => now()->toAtomString(),
                    'target_disk' => $disk,
                    'remote_blob_coverage' => $runtimeCandidate['remote_blob_coverage'] ?? null,
                    'file_count' => (int) ($runtimeCandidate['file_count'] ?? 0),
                    'total_size_bytes' => (int) ($runtimeCandidate['total_size_bytes'] ?? 0),
                ];

                $encoded = json_encode($quarantineMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if (! is_string($encoded)) {
                    throw new \RuntimeException('failed to encode quarantine sentinel.');
                }

                File::put($targetRoot.DIRECTORY_SEPARATOR.'.quarantine.json', $encoded.PHP_EOL);

                $quarantined[] = [
                    'exact_manifest_id' => (int) ($runtimeCandidate['exact_manifest_id'] ?? 0),
                    'source_kind' => (string) ($runtimeCandidate['source_kind'] ?? ''),
                    'source_storage_path' => $sourceRoot,
                    'target_root' => $targetRoot,
                    'file_count' => (int) ($runtimeCandidate['file_count'] ?? 0),
                    'total_size_bytes' => (int) ($runtimeCandidate['total_size_bytes'] ?? 0),
                ];
                $quarantinedFiles += (int) ($runtimeCandidate['file_count'] ?? 0);
                $quarantinedBytes += (int) ($runtimeCandidate['total_size_bytes'] ?? 0);
            } catch (\Throwable $e) {
                $failures[] = [
                    'exact_manifest_id' => (int) ($candidate['exact_manifest_id'] ?? 0),
                    'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
                    'reason' => 'move_failed',
                    'detail' => $e->getMessage(),
                ];
            }
        }

        $run = [
            'schema' => (string) config('storage_rollout.quarantine_run_schema_version', 'storage_quarantine_exact_root_run.v1'),
            'generated_at' => now()->toAtomString(),
            'target_disk' => $disk,
            'quarantined' => $quarantined,
            'failures' => $failures,
            'summary' => [
                'quarantined_root_count' => count($quarantined),
                'failed_root_count' => count($failures),
                'blocked_root_count' => count((array) ($plan['blocked'] ?? [])),
                'quarantined_file_count' => $quarantinedFiles,
                'quarantined_total_size_bytes' => $quarantinedBytes,
            ],
        ];
        $encodedRun = json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encodedRun)) {
            throw new \RuntimeException('failed to encode quarantine run json.');
        }

        File::put($runBase.DIRECTORY_SEPARATOR.'run.json', $encodedRun.PHP_EOL);

        return [
            'run_id' => $runId,
            'run_dir' => $runBase,
            'quarantined_root_count' => count($quarantined),
            'failed_root_count' => count($failures),
            'blocked_root_count' => count((array) ($plan['blocked'] ?? [])),
            'quarantined_file_count' => $quarantinedFiles,
            'quarantined_total_size_bytes' => $quarantinedBytes,
            'quarantined' => $quarantined,
            'failures' => $failures,
        ];
    }

    /**
     * @param  list<string>  $activeReleaseIds
     * @param  list<string>  $snapshotReleaseIds
     * @param  array<string,bool>  $protectedRoots
     * @param  array<string,int>  $duplicateRoots
     * @return array{status:string,entry:array<string,mixed>}
     */
    private function evaluateManifest(
        ContentReleaseExactManifest $manifest,
        string $disk,
        array $activeReleaseIds,
        array $snapshotReleaseIds,
        array $protectedRoots,
        array $duplicateRoots,
    ): array {
        $sourceKind = trim((string) $manifest->source_kind);
        $sourceRoot = $this->normalizeRoot((string) $manifest->source_storage_path);
        $releaseId = trim((string) ($manifest->content_pack_release_id ?? ''));
        $fileRows = $manifest->files()->orderBy('logical_path')->get();
        $fileCount = $fileRows->count();
        $totalBytes = (int) $fileRows->sum('size_bytes');

        $baseEntry = [
            'exact_manifest_id' => (int) $manifest->getKey(),
            'exact_identity_hash' => (string) $manifest->exact_identity_hash,
            'source_kind' => $sourceKind,
            'source_disk' => (string) ($manifest->source_disk ?? 'local'),
            'source_storage_path' => $sourceRoot,
            'content_pack_release_id' => $releaseId !== '' ? $releaseId : null,
            'pack_id' => $manifest->pack_id,
            'pack_version' => $manifest->pack_version,
            'manifest_hash' => (string) $manifest->manifest_hash,
            'file_count' => $fileCount,
            'total_size_bytes' => $totalBytes,
        ];

        if (! in_array($sourceKind, self::ALLOWED_SOURCE_KINDS, true)) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'source_kind_not_allowed',
                ],
            ];
        }

        if ($sourceRoot === '') {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'source_root_missing',
                ],
            ];
        }

        if (($duplicateRoots[$sourceRoot] ?? 0) > 1) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'multiple_exact_manifests_for_root',
                ],
            ];
        }

        if ($this->releaseStorageLocator->compiledDirFromRoot($sourceRoot) === null) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'physical_root_missing',
                ],
            ];
        }

        if ($fileCount === 0) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'exact_file_rows_missing',
                ],
            ];
        }

        if ($releaseId !== '' && in_array($releaseId, $activeReleaseIds, true)) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'active_release_root',
                ],
            ];
        }

        if ($releaseId !== '' && in_array($releaseId, $snapshotReleaseIds, true)) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'snapshot_referenced_root',
                ],
            ];
        }

        if (isset($protectedRoots[$sourceRoot])) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'runtime_root_in_use',
                ],
            ];
        }

        if ($this->isUnderNoTouchPrefix($sourceRoot)) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'runtime_no_touch_root',
                ],
            ];
        }

        try {
            $rehydratePlan = $this->rehydrateService->buildPlan((int) $manifest->getKey(), null, $disk);
        } catch (\Throwable $e) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'rehydrate_plan_failed',
                    'detail' => $e->getMessage(),
                ],
            ];
        }

        $missingLocations = (int) data_get($rehydratePlan, 'summary.missing_locations', 0);
        if ($missingLocations > 0) {
            return [
                'status' => 'blocked',
                'entry' => $baseEntry + [
                    'reason' => 'missing_verified_remote_copy',
                    'missing_locations' => $missingLocations,
                ],
            ];
        }

        return [
            'status' => 'candidate',
            'entry' => $baseEntry + [
                'remote_blob_coverage' => [
                    'disk' => $disk,
                    'file_count' => $fileCount,
                    'missing_locations' => 0,
                    'verified_remote_copy' => true,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{status:string,entry:array<string,mixed>}
     */
    private function resolveExecutableCandidate(array $candidate, string $disk): array
    {
        $manifestId = (int) ($candidate['exact_manifest_id'] ?? 0);
        $manifest = ContentReleaseExactManifest::query()->find($manifestId);
        if (! $manifest instanceof ContentReleaseExactManifest) {
            return [
                'status' => 'blocked',
                'entry' => [
                    'exact_manifest_id' => $manifestId,
                    'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
                    'reason' => 'exact_manifest_missing',
                ],
            ];
        }

        $recheck = $this->evaluateManifest(
            $manifest,
            $disk,
            $this->activeReleaseIds(),
            $this->snapshotReleaseIds(),
            $this->protectedResolvedRoots($this->activeReleaseIds(), $this->snapshotReleaseIds()),
            $this->duplicateSourceRoots(),
        );

        if (($recheck['status'] ?? '') !== 'candidate') {
            return [
                'status' => 'blocked',
                'entry' => (array) ($recheck['entry'] ?? []),
            ];
        }

        $plannedSourceRoot = $this->normalizeRoot((string) ($candidate['source_storage_path'] ?? ''));
        $runtimeCandidate = (array) ($recheck['entry'] ?? []);
        $runtimeSourceRoot = $this->normalizeRoot((string) ($runtimeCandidate['source_storage_path'] ?? ''));
        if (
            $plannedSourceRoot !== $runtimeSourceRoot
            || trim((string) ($candidate['exact_identity_hash'] ?? '')) !== trim((string) ($runtimeCandidate['exact_identity_hash'] ?? ''))
            || trim((string) ($candidate['source_kind'] ?? '')) !== trim((string) ($runtimeCandidate['source_kind'] ?? ''))
            || trim((string) ($candidate['manifest_hash'] ?? '')) !== trim((string) ($runtimeCandidate['manifest_hash'] ?? ''))
        ) {
            return [
                'status' => 'blocked',
                'entry' => [
                    'exact_manifest_id' => $manifestId,
                    'source_storage_path' => $plannedSourceRoot,
                    'reason' => 'plan_candidate_mismatch',
                ],
            ];
        }

        return [
            'status' => 'candidate',
            'entry' => $runtimeCandidate,
        ];
    }

    /**
     * @return list<string>
     */
    private function activeReleaseIds(): array
    {
        return DB::table('content_pack_activations')
            ->pluck('release_id')
            ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function snapshotReleaseIds(): array
    {
        $columns = [
            'from_content_pack_release_id',
            'to_content_pack_release_id',
            'activation_before_release_id',
            'activation_after_release_id',
        ];

        $ids = [];
        foreach (DB::table('content_release_snapshots')->get($columns) as $row) {
            foreach ($columns as $column) {
                $value = trim((string) ($row->{$column} ?? ''));
                if ($value !== '') {
                    $ids[$value] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  list<string>  $activeReleaseIds
     * @param  list<string>  $snapshotReleaseIds
     * @return array<string,bool>
     */
    private function protectedResolvedRoots(array $activeReleaseIds, array $snapshotReleaseIds): array
    {
        $roots = [];
        $releaseIds = array_values(array_unique(array_merge($activeReleaseIds, $snapshotReleaseIds)));
        if ($releaseIds !== []) {
            $releases = DB::table('content_pack_releases')
                ->whereIn('id', $releaseIds)
                ->get();

            foreach ($releases as $release) {
                $source = $this->releaseStorageLocator->resolveReleaseSource($release);
                if ($source === null) {
                    continue;
                }

                $root = $this->normalizeRoot((string) ($source['root'] ?? ''));
                if ($root !== '') {
                    $roots[$root] = true;
                }
            }
        }

        foreach ($this->releaseStorageLocator->liveAliasRoots() as $root) {
            $normalized = $this->normalizeRoot($root);
            if ($normalized !== '') {
                $roots[$normalized] = true;
            }
        }

        return $roots;
    }

    /**
     * @return array<string,int>
     */
    private function duplicateSourceRoots(): array
    {
        $counts = [];

        ContentReleaseExactManifest::query()
            ->select(['source_storage_path'])
            ->get()
            ->each(function (ContentReleaseExactManifest $manifest) use (&$counts): void {
                $root = $this->normalizeRoot((string) $manifest->source_storage_path);
                if ($root === '') {
                    return;
                }

                $counts[$root] = ($counts[$root] ?? 0) + 1;
            });

        return $counts;
    }

    private function isUnderNoTouchPrefix(string $root): bool
    {
        $prefixes = [
            storage_path('app/private/content_releases/backups'),
            storage_path('app/private/packs_v2_materialized'),
            storage_path('app/private/artifacts'),
            storage_path('app/private/reports'),
            storage_path('app/reports'),
            storage_path('app/content_packs_v2_materialized'),
            $this->defaultLiveAliasPrefix(),
        ];

        foreach ($prefixes as $prefix) {
            $prefix = $this->normalizeRoot($prefix);
            if ($prefix === '') {
                continue;
            }

            if ($root === $prefix || str_starts_with($root.'/', $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function defaultLiveAliasPrefix(): string
    {
        $packsRoot = rtrim((string) config('content_packs.root', ''), '/\\');
        if ($packsRoot === '') {
            return '';
        }

        return basename($packsRoot) === 'default'
            ? $packsRoot
            : $packsRoot.DIRECTORY_SEPARATOR.'default';
    }

    private function quarantineRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.quarantine_root_dir', 'app/private/quarantine/release_roots'));
        $relative = ltrim($relative, '/\\');

        return storage_path($relative);
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }
}
