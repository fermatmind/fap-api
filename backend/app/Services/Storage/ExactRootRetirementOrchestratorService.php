<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\File;

final class ExactRootRetirementOrchestratorService
{
    private const PLAN_SCHEMA = 'storage_retire_exact_roots_plan.v1';

    private const RUN_SCHEMA = 'storage_retire_exact_roots_run.v1';

    /**
     * @var list<string>
     */
    private const ALLOWED_ACTIONS = [
        'quarantine',
        'purge',
    ];

    public function __construct(
        private readonly ExactRootQuarantineService $quarantineService,
        private readonly QuarantinedRootPurgeService $purgeService,
    ) {}

    /**
     * @param  list<string>  $sourceKinds
     * @return array<string,mixed>
     */
    public function buildPlan(string $action, string $disk, array $sourceKinds = [], ?int $limit = null): array
    {
        $action = $this->normalizeAction($action);
        $disk = $this->normalizeDisk($disk);
        $sourceKinds = $this->normalizeSourceKinds($sourceKinds);
        $limit = $this->normalizeLimit($limit);

        return $action === 'quarantine'
            ? $this->buildQuarantinePlan($action, $disk, $sourceKinds, $limit)
            : $this->buildPurgePlan($action, $disk, $sourceKinds, $limit);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan): array
    {
        $resolvedPlan = $this->resolveExecutablePlan($plan);
        $action = (string) $resolvedPlan['action'];
        $disk = (string) $resolvedPlan['disk'];

        $runId = now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8);
        $runBase = $this->retirementRootBase().DIRECTORY_SEPARATOR.$runId;
        File::ensureDirectoryExists($runBase);

        $startedAt = now()->toAtomString();
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $blockedCount = 0;
        $skippedCount = 0;

        foreach ((array) ($resolvedPlan['blocked'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $results[] = $this->resultFromExistingEntry($entry, 'blocked');
            $blockedCount++;
        }

        foreach ((array) ($resolvedPlan['skipped'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $results[] = $this->resultFromExistingEntry($entry, 'skipped');
            $skippedCount++;
        }

        foreach ((array) ($resolvedPlan['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                $results[] = [
                    'action' => $action,
                    'status' => 'failure',
                    'blocked_reason' => null,
                    'error' => 'invalid_plan_candidate',
                ];
                $failureCount++;

                continue;
            }

            if ($action === 'quarantine') {
                $result = $this->executeQuarantineCandidate($candidate, $disk);
            } else {
                $result = $this->executePurgeCandidate($candidate, $disk);
            }

            $results[] = $result;
            $status = (string) ($result['status'] ?? 'failure');
            if ($status === 'success') {
                $successCount++;
            } elseif ($status === 'blocked') {
                $blockedCount++;
            } elseif ($status === 'skipped') {
                $skippedCount++;
            } else {
                $failureCount++;
            }
        }

        $finishedAt = now()->toAtomString();
        $status = $failureCount > 0
            ? 'failure'
            : (($blockedCount > 0 || $skippedCount > 0) ? 'partial' : 'success');

        $run = [
            'schema' => (string) config('storage_rollout.retirement_run_schema_version', self::RUN_SCHEMA),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'action' => $action,
            'disk' => $disk,
            'plan' => $resolvedPlan,
            'status' => $status,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'blocked_count' => $blockedCount,
            'skipped_count' => $skippedCount,
            'results' => $results,
        ];

        $encoded = json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode retirement run json.');
        }

        File::put($runBase.DIRECTORY_SEPARATOR.'run.json', $encoded.PHP_EOL);

        return [
            'run_id' => $runId,
            'run_dir' => $runBase,
            'status' => $status,
            'action' => $action,
            'disk' => $disk,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'blocked_count' => $blockedCount,
            'skipped_count' => $skippedCount,
            'results' => $results,
        ];
    }

    /**
     * @param  list<string>  $sourceKinds
     * @return array<string,mixed>
     */
    private function buildQuarantinePlan(string $action, string $disk, array $sourceKinds, ?int $limit): array
    {
        $basePlan = $this->quarantineService->buildPlan($disk);
        $candidates = array_map(fn (array $entry): array => $this->transformQuarantineEntry($entry, $action, 'planned'), (array) ($basePlan['candidates'] ?? []));
        $blocked = array_map(fn (array $entry): array => $this->transformQuarantineEntry($entry, $action, 'blocked'), (array) ($basePlan['blocked'] ?? []));
        $skipped = array_map(fn (array $entry): array => $this->transformQuarantineEntry($entry, $action, 'skipped'), (array) ($basePlan['skipped'] ?? []));

        [$candidates, $filteredCandidates] = $this->applySourceKindFilter($candidates, $sourceKinds, 'source_kind_filtered_out');
        [$blocked, $filteredBlocked] = $this->applySourceKindFilter($blocked, $sourceKinds, 'source_kind_filtered_out');
        [$skipped, $filteredSkipped] = $this->applySourceKindFilter($skipped, $sourceKinds, 'source_kind_filtered_out');

        $skipped = array_merge($skipped, $filteredCandidates, $filteredBlocked, $filteredSkipped);
        [$candidates, $overflow] = $this->applyLimit($candidates, $limit);
        $skipped = array_merge($skipped, $overflow);

        return $this->finalizePlan($action, $disk, $sourceKinds, $limit, $candidates, $blocked, $skipped);
    }

    /**
     * @param  list<string>  $sourceKinds
     * @return array<string,mixed>
     */
    private function buildPurgePlan(string $action, string $disk, array $sourceKinds, ?int $limit): array
    {
        $candidates = [];
        $blocked = [];
        $skipped = [];

        foreach ($this->quarantineItemRoots() as $itemRoot) {
            $plan = $this->purgeService->buildPlan($itemRoot, $disk);
            $entry = $this->transformPurgeEntry($plan, $action);

            if ((string) ($entry['status'] ?? '') === 'planned') {
                $candidates[] = $entry;
            } else {
                $blocked[] = $entry;
            }
        }

        [$candidates, $filteredCandidates] = $this->applySourceKindFilter($candidates, $sourceKinds, 'source_kind_filtered_out');
        [$blocked, $filteredBlocked] = $this->applySourceKindFilter($blocked, $sourceKinds, 'source_kind_filtered_out');

        $skipped = array_merge($skipped, $filteredCandidates, $filteredBlocked);
        [$candidates, $overflow] = $this->applyLimit($candidates, $limit);
        $skipped = array_merge($skipped, $overflow);

        return $this->finalizePlan($action, $disk, $sourceKinds, $limit, $candidates, $blocked, $skipped);
    }

    /**
     * @param  list<string>  $sourceKinds
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<array<string,mixed>>  $blocked
     * @param  list<array<string,mixed>>  $skipped
     * @return array<string,mixed>
     */
    private function finalizePlan(
        string $action,
        string $disk,
        array $sourceKinds,
        ?int $limit,
        array $candidates,
        array $blocked,
        array $skipped,
    ): array {
        $candidateFileCount = 0;
        $candidateTotalSizeBytes = 0;
        foreach ($candidates as $candidate) {
            $candidateFileCount += (int) ($candidate['file_count'] ?? 0);
            $candidateTotalSizeBytes += (int) ($candidate['total_size_bytes'] ?? 0);
        }

        return [
            'schema' => (string) config('storage_rollout.retirement_plan_schema_version', self::PLAN_SCHEMA),
            'generated_at' => now()->toAtomString(),
            'action' => $action,
            'disk' => $disk,
            'source_kinds' => $sourceKinds,
            'limit' => $limit,
            'summary' => [
                'candidate_count' => count($candidates),
                'blocked_count' => count($blocked),
                'skipped_count' => count($skipped),
                'candidate_file_count' => $candidateFileCount,
                'candidate_total_size_bytes' => $candidateTotalSizeBytes,
            ],
            'candidates' => array_values($candidates),
            'blocked' => array_values($blocked),
            'skipped' => array_values($skipped),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function executeQuarantineCandidate(array $candidate, string $disk): array
    {
        $manifestId = (int) ($candidate['exact_manifest_id'] ?? 0);
        $freshPlan = $this->quarantineService->buildPlan($disk);
        $freshCandidate = collect((array) ($freshPlan['candidates'] ?? []))
            ->firstWhere('exact_manifest_id', $manifestId);

        if (! is_array($freshCandidate)) {
            $blockedEntry = collect((array) ($freshPlan['blocked'] ?? []))
                ->firstWhere('exact_manifest_id', $manifestId);

            return $this->resultFromExistingEntry(
                $this->transformQuarantineEntry(
                    is_array($blockedEntry)
                        ? $blockedEntry
                        : [
                            'exact_manifest_id' => $manifestId,
                            'source_kind' => (string) ($candidate['source_kind'] ?? ''),
                            'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
                            'reason' => 'fresh_candidate_not_found',
                        ],
                    'quarantine',
                    'blocked'
                ),
                'blocked'
            );
        }

        foreach ([
            'exact_manifest_id',
            'exact_identity_hash',
            'source_kind',
            'source_storage_path',
            'manifest_hash',
            'source_disk',
            'pack_id',
            'pack_version',
            'file_count',
            'total_size_bytes',
        ] as $field) {
            if (($candidate[$field] ?? null) !== ($freshCandidate[$field] ?? null)) {
                return $this->resultFromExistingEntry([
                    'action' => 'quarantine',
                    'status' => 'blocked',
                    'exact_manifest_id' => $manifestId,
                    'exact_identity_hash' => (string) ($candidate['exact_identity_hash'] ?? ''),
                    'source_kind' => (string) ($candidate['source_kind'] ?? ''),
                    'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
                    'manifest_hash' => (string) ($candidate['manifest_hash'] ?? ''),
                    'file_count' => (int) ($candidate['file_count'] ?? 0),
                    'total_size_bytes' => (int) ($candidate['total_size_bytes'] ?? 0),
                    'blocked_reason' => 'plan_candidate_mismatch',
                ], 'blocked');
            }
        }

        $miniPlan = [
            'schema' => (string) config('storage_rollout.quarantine_plan_schema_version', 'storage_quarantine_exact_roots_plan.v1'),
            'generated_at' => now()->toAtomString(),
            'target_disk' => $disk,
            'summary' => [
                'candidate_count' => 1,
                'blocked_count' => 0,
                'skipped_count' => 0,
            ],
            'candidates' => [$freshCandidate],
            'blocked' => [],
            'skipped' => [],
            'totals' => [
                'candidate_file_count' => (int) ($freshCandidate['file_count'] ?? 0),
                'candidate_total_size_bytes' => (int) ($freshCandidate['total_size_bytes'] ?? 0),
            ],
        ];

        $result = $this->quarantineService->executePlan($miniPlan);
        if ((int) ($result['quarantined_root_count'] ?? 0) === 1) {
            $quarantinedEntry = is_array(($result['quarantined'][0] ?? null)) ? $result['quarantined'][0] : [];

            return [
                'action' => 'quarantine',
                'status' => 'success',
                'exact_manifest_id' => $manifestId,
                'exact_identity_hash' => (string) ($candidate['exact_identity_hash'] ?? ''),
                'source_kind' => (string) ($candidate['source_kind'] ?? ''),
                'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
                'manifest_hash' => (string) ($candidate['manifest_hash'] ?? ''),
                'file_count' => (int) ($candidate['file_count'] ?? 0),
                'total_size_bytes' => (int) ($candidate['total_size_bytes'] ?? 0),
                'blocked_reason' => null,
                'error' => null,
                'run_dir' => (string) ($result['run_dir'] ?? ''),
                'target_root' => (string) ($quarantinedEntry['target_root'] ?? ''),
            ];
        }

        $failure = is_array(($result['failures'][0] ?? null)) ? $result['failures'][0] : [];

        return [
            'action' => 'quarantine',
            'status' => 'failure',
            'exact_manifest_id' => $manifestId,
            'exact_identity_hash' => (string) ($candidate['exact_identity_hash'] ?? ''),
            'source_kind' => (string) ($candidate['source_kind'] ?? ''),
            'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
            'manifest_hash' => (string) ($candidate['manifest_hash'] ?? ''),
            'file_count' => (int) ($candidate['file_count'] ?? 0),
            'total_size_bytes' => (int) ($candidate['total_size_bytes'] ?? 0),
            'blocked_reason' => null,
            'error' => (string) ($failure['detail'] ?? $failure['reason'] ?? 'quarantine_failed'),
            'run_dir' => (string) ($result['run_dir'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function executePurgeCandidate(array $candidate, string $disk): array
    {
        $itemRoot = $this->normalizeRoot((string) ($candidate['item_root'] ?? ''));
        $freshPlan = $this->purgeService->buildPlan($itemRoot, $disk);
        if ((string) ($freshPlan['status'] ?? '') !== 'planned') {
            return $this->resultFromExistingEntry(
                $this->transformPurgeEntry($freshPlan, 'purge'),
                'blocked'
            );
        }

        foreach ([
            'item_root',
            'exact_manifest_id',
            'exact_identity_hash',
            'source_kind',
            'source_storage_path',
            'manifest_hash',
            'file_count',
            'total_size_bytes',
        ] as $field) {
            if (($candidate[$field] ?? null) !== ($freshPlan[$field] ?? null)) {
                return $this->resultFromExistingEntry([
                    'action' => 'purge',
                    'status' => 'blocked',
                    'item_root' => $itemRoot,
                    'exact_manifest_id' => (int) ($candidate['exact_manifest_id'] ?? 0),
                    'exact_identity_hash' => (string) ($candidate['exact_identity_hash'] ?? ''),
                    'source_kind' => (string) ($candidate['source_kind'] ?? ''),
                    'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
                    'manifest_hash' => (string) ($candidate['manifest_hash'] ?? ''),
                    'file_count' => (int) ($candidate['file_count'] ?? 0),
                    'total_size_bytes' => (int) ($candidate['total_size_bytes'] ?? 0),
                    'blocked_reason' => 'plan_candidate_mismatch',
                ], 'blocked');
            }
        }

        $result = $this->purgeService->executePlan($freshPlan, $itemRoot);
        $status = (string) ($result['status'] ?? 'failure');

        return [
            'action' => 'purge',
            'status' => $status === 'success' ? 'success' : 'failure',
            'item_root' => $itemRoot,
            'exact_manifest_id' => (int) ($candidate['exact_manifest_id'] ?? 0),
            'exact_identity_hash' => (string) ($candidate['exact_identity_hash'] ?? ''),
            'source_kind' => (string) ($candidate['source_kind'] ?? ''),
            'source_storage_path' => (string) ($candidate['source_storage_path'] ?? ''),
            'manifest_hash' => (string) ($candidate['manifest_hash'] ?? ''),
            'file_count' => (int) ($candidate['file_count'] ?? 0),
            'total_size_bytes' => (int) ($candidate['total_size_bytes'] ?? 0),
            'blocked_reason' => null,
            'error' => $status === 'success' ? null : (string) ($result['error'] ?? 'purge_failed'),
            'run_dir' => (string) ($result['run_dir'] ?? ''),
            'receipt_path' => (string) ($result['receipt_path'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function resolveExecutablePlan(array $plan): array
    {
        $schema = trim((string) ($plan['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.retirement_plan_schema_version', self::PLAN_SCHEMA)) {
            throw new \RuntimeException('invalid retirement plan schema: '.$schema);
        }

        $action = $this->normalizeAction((string) ($plan['action'] ?? ''));
        $disk = $this->normalizeDisk((string) ($plan['disk'] ?? ''));

        $actionValues = [$action];
        foreach (['candidates', 'blocked', 'skipped'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $entry) {
                if (is_array($entry)) {
                    $entryAction = trim((string) ($entry['action'] ?? ''));
                    if ($entryAction !== '') {
                        $actionValues[] = $entryAction;
                    }
                }
            }
        }

        $actionValues = array_values(array_unique($actionValues));
        if (count($actionValues) !== 1 || $actionValues[0] !== $action) {
            throw new \RuntimeException('mixed_action_plan_not_executable');
        }

        $plan['action'] = $action;
        $plan['disk'] = $disk;

        return $plan;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function transformQuarantineEntry(array $entry, string $action, string $status): array
    {
        return [
            'action' => $action,
            'status' => $status,
            'blocked_reason' => $status === 'planned'
                ? null
                : (string) ($entry['reason'] ?? $entry['blocked_reason'] ?? ''),
            'item_root' => null,
        ] + $entry;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function transformPurgeEntry(array $entry, string $action): array
    {
        return [
            'action' => $action,
            'status' => (string) ($entry['status'] ?? 'blocked'),
            'blocked_reason' => (string) ($entry['status'] ?? '') === 'planned'
                ? null
                : (string) ($entry['blocked_reason'] ?? 'purge blocked'),
        ] + $entry;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  list<string>  $sourceKinds
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function applySourceKindFilter(array $entries, array $sourceKinds, string $reason): array
    {
        if ($sourceKinds === []) {
            return [$entries, []];
        }

        $kept = [];
        $filtered = [];
        foreach ($entries as $entry) {
            $sourceKind = trim((string) ($entry['source_kind'] ?? ''));
            if ($sourceKind !== '' && ! in_array($sourceKind, $sourceKinds, true)) {
                $entry['status'] = 'skipped';
                $entry['blocked_reason'] = $reason;
                $filtered[] = $entry;

                continue;
            }

            $kept[] = $entry;
        }

        return [$kept, $filtered];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function applyLimit(array $candidates, ?int $limit): array
    {
        if ($limit === null) {
            return [$candidates, []];
        }

        $kept = [];
        $overflow = [];
        foreach ($candidates as $candidate) {
            if (count($kept) < $limit) {
                $kept[] = $candidate;

                continue;
            }

            $candidate['status'] = 'skipped';
            $candidate['blocked_reason'] = 'limit_exceeded';
            $overflow[] = $candidate;
        }

        return [$kept, $overflow];
    }

    /**
     * @return list<string>
     */
    private function quarantineItemRoots(): array
    {
        $pattern = $this->quarantineRootBase().DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'root';
        $roots = glob($pattern, GLOB_NOSORT) ?: [];

        $normalized = [];
        foreach ($roots as $root) {
            if (is_dir($root)) {
                $normalized[] = $this->normalizeRoot($root);
            }
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $sourceKinds
     * @return list<string>
     */
    private function normalizeSourceKinds(array $sourceKinds): array
    {
        $normalized = [];
        foreach ($sourceKinds as $sourceKind) {
            $value = trim((string) $sourceKind);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private function normalizeAction(string $action): string
    {
        $action = trim($action);
        if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new \RuntimeException('invalid retirement action: '.$action);
        }

        return $action;
    }

    private function normalizeDisk(string $disk): string
    {
        $disk = trim($disk);
        if ($disk === '') {
            throw new \RuntimeException('retirement disk is required.');
        }

        return $disk;
    }

    private function normalizeLimit(?int $limit): ?int
    {
        if ($limit === null) {
            return null;
        }

        if ($limit <= 0) {
            throw new \RuntimeException('retirement limit must be greater than zero.');
        }

        return $limit;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function resultFromExistingEntry(array $entry, string $fallbackStatus): array
    {
        return [
            'action' => (string) ($entry['action'] ?? ''),
            'status' => (string) ($entry['status'] ?? $fallbackStatus),
            'item_root' => $entry['item_root'] ?? null,
            'exact_manifest_id' => $entry['exact_manifest_id'] ?? null,
            'exact_identity_hash' => $entry['exact_identity_hash'] ?? null,
            'source_kind' => $entry['source_kind'] ?? null,
            'source_storage_path' => $entry['source_storage_path'] ?? null,
            'manifest_hash' => $entry['manifest_hash'] ?? null,
            'file_count' => (int) ($entry['file_count'] ?? 0),
            'total_size_bytes' => (int) ($entry['total_size_bytes'] ?? 0),
            'blocked_reason' => $entry['blocked_reason'] ?? null,
            'error' => $entry['error'] ?? null,
        ];
    }

    private function retirementRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.retirement_root_dir', 'app/private/retirement_runs'));
        $relative = ltrim($relative, '/\\');

        return storage_path($relative);
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
