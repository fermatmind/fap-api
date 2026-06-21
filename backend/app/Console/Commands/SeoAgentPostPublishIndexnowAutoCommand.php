<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPage;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueApprovalExecutor;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueBoundedLiveExecutor;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueWriteService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoAgentPostPublishIndexnowAutoCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-post-publish-indexnow-auto.v1';

    private const SINGLE_PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-canary.v1';

    private const AUTO_PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-auto-canary.v1';

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'token',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:post-publish-indexnow-auto
        {--publish-evidence= : Path to seo-agent CMS publish evidence JSON}
        {--limit=3 : Maximum published ContentPage URLs to submit to IndexNow, 1..3}
        {--base-url= : Public site base URL used to resolve safe paths; defaults to app.url}
        {--artifact-dir= : Directory for IndexNow auto evidence artifacts}
        {--execute : Enqueue, approve, and live-submit IndexNow queue items}
        {--json : Emit JSON summary}';

    protected $description = 'Auto-submit low-risk published ContentPage canaries to IndexNow only; no Google Indexing or scheduler.';

    public function handle(
        SearchChannelQueuePlanner $planner,
        SearchChannelQueueWriteService $writer,
        SearchChannelQueueApprovalExecutor $approval,
        SearchChannelQueueBoundedLiveExecutor $live,
    ): int {
        $evidencePath = $this->readablePath((string) $this->option('publish-evidence'));
        if ($evidencePath === null) {
            return $this->finish($this->failureSummary('publish_evidence_unreadable'));
        }

        $limit = $this->limit();
        if ($limit === null) {
            return $this->finish($this->failureSummary('limit_out_of_bounds'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $raw = (string) file_get_contents($evidencePath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $evidence = json_decode($raw, true);
        if (! is_array($evidence)) {
            return $this->finish($this->failureSummary('publish_evidence_json_invalid'));
        }

        $evidenceIssue = $this->validatePublishEvidence($evidence);
        if ($evidenceIssue !== null) {
            return $this->finish($this->failureSummary($evidenceIssue));
        }

        $publishedRefs = array_slice($this->publishedRefs($evidence), 0, $limit);
        if ($publishedRefs === []) {
            return $this->finish($this->failureSummary('published_content_page_refs_missing'));
        }

        $targets = [];
        $issues = [];
        foreach ($publishedRefs as $ref) {
            $target = $this->publishedTarget($ref);
            if (($target['ok'] ?? false) !== true) {
                $issues[] = (string) ($target['issue'] ?? 'published_target_invalid');
                continue;
            }
            $targets[] = $target;
        }

        if ($issues !== []) {
            return $this->finish($this->failureSummary('published_target_validation_failed', [
                'target_issues' => array_values(array_unique($issues)),
            ]));
        }

        $execute = (bool) $this->option('execute');
        $evidenceSha = hash_file('sha256', $evidencePath) ?: '';
        $plans = [];
        $plannedItems = [];
        $duplicateSubmitted = [];
        $duplicateReadyIds = [];
        $planIssues = [];

        foreach ($targets as $target) {
            $canonicalUrl = (string) $target['canonical_url'];
            $plan = $planner->plan('indexnow', 'content_page', 1, $canonicalUrl);
            $plans[] = $this->safePlanSummary($plan, $target);
            foreach ((array) ($plan['planned_items'] ?? []) as $item) {
                if (is_array($item)) {
                    $plannedItems[] = $item;
                }
            }

            if ((int) ($plan['planned_queue_count'] ?? 0) < 1) {
                $existing = $this->existingIndexnowQueueItem($canonicalUrl);
                if ($existing !== null && ($existing['execution_state'] ?? '') === 'submitted') {
                    $duplicateSubmitted[] = $existing;
                    continue;
                }
                if ($existing !== null && in_array(($existing['execution_state'] ?? ''), ['dry_run_ready'], true)) {
                    $duplicateReadyIds[] = (int) $existing['id'];
                    continue;
                }

                $planIssues[] = (string) (($plan['source_unavailable_reason'] ?? null) ?: 'indexnow_queue_plan_unavailable');
            }
        }

        $plannedItems = array_slice($plannedItems, 0, $limit);
        if (! $execute) {
            $artifact = $this->writeEvidence($artifactDir, $evidencePath, $evidenceSha, 'planned', [
                'execute' => false,
                'target_count' => count($targets),
                'planned_queue_count' => count($plannedItems),
                'duplicate_ready_count' => count($duplicateReadyIds),
                'duplicate_submitted_count' => count($duplicateSubmitted),
                'plans' => $plans,
                'issues' => array_values(array_unique($planIssues)),
            ]);

            return $this->finish($this->successSummary('planned', false, $artifact, [
                'target_count' => count($targets),
                'planned_queue_count' => count($plannedItems),
                'duplicate_submitted_count' => count($duplicateSubmitted),
                'external_calls_attempted' => false,
                'search_submission_attempted' => false,
            ]));
        }

        if ($plannedItems === [] && $duplicateReadyIds === [] && $duplicateSubmitted === []) {
            return $this->finish($this->failureSummary('no_indexnow_queue_items_available', [
                'plans' => $plans,
                'plan_issues' => array_values(array_unique($planIssues)),
            ]));
        }

        $writeResult = $plannedItems === [] ? ['batch_ids' => [], 'written_items' => 0] : $writer->write($plannedItems);
        $queueItemIds = array_values(array_unique(array_merge(
            $this->queueItemIdsForPlannedItems($plannedItems),
            $duplicateReadyIds,
        )));

        [$pendingIds, $approvedIds, $submittedIds, $blockedStates] = $this->partitionQueueItems($queueItemIds);
        $approveResult = ['status' => 'skipped', 'queue_item_ids' => [], 'issues' => []];
        if ($pendingIds !== []) {
            $approvePhrase = $approval->approvalPhrase($pendingIds, ['indexnow']);
            $approveResult = $approval->approve(
                queueItemIds: $pendingIds,
                channels: ['indexnow'],
                approvalPhrase: null,
                approvalToken: hash('sha256', $approvePhrase),
                actorId: 'seo-agent-indexnow-auto',
                dryRun: false,
            );
        }

        if (! in_array(($approveResult['status'] ?? 'success'), ['success', 'skipped'], true)) {
            $artifact = $this->writeEvidence($artifactDir, $evidencePath, $evidenceSha, 'blocked', [
                'execute' => true,
                'write_result' => $writeResult,
                'approve_result' => $this->safeExecutorSummary($approveResult),
                'blocked_states' => $blockedStates,
            ]);

            return $this->finish($this->failureSummary('indexnow_queue_approval_failed', ['artifact' => $artifact]));
        }

        $submitIds = array_values(array_unique(array_merge($approvedIds, $pendingIds)));
        $submitResult = ['status' => 'skipped', 'queue_item_ids' => [], 'issues' => []];
        if ($submitIds !== []) {
            $submitPhrase = $live->approvalPhrase($submitIds, ['indexnow']);
            $submitResult = $live->submit(
                queueItemIds: $submitIds,
                channels: ['indexnow'],
                approvalPhrase: null,
                approvalToken: hash('sha256', $submitPhrase),
                actorId: 'seo-agent-indexnow-auto',
                dryRun: false,
            );
        }

        $ok = in_array(($submitResult['status'] ?? 'success'), ['success', 'skipped'], true) && $blockedStates === [];
        $artifact = $this->writeEvidence($artifactDir, $evidencePath, $evidenceSha, $ok ? 'success' : 'blocked', [
            'execute' => true,
            'target_count' => count($targets),
            'write_result' => $writeResult,
            'queue_item_ids' => $queueItemIds,
            'submitted_queue_item_ids' => $submitIds,
            'already_submitted_queue_item_ids' => array_values(array_unique(array_merge(
                $submittedIds,
                array_map(static fn (array $item): int => (int) $item['id'], $duplicateSubmitted),
            ))),
            'approve_result' => $this->safeExecutorSummary($approveResult),
            'submit_result' => $this->safeExecutorSummary($submitResult),
            'blocked_states' => $blockedStates,
            'plans' => $plans,
        ]);

        if (! $ok) {
            return $this->finish($this->failureSummary('indexnow_live_submission_failed', ['artifact' => $artifact]));
        }

        return $this->finish($this->successSummary('success', true, $artifact, [
            'target_count' => count($targets),
            'written_items' => (int) ($writeResult['written_items'] ?? 0),
            'approved_count' => count($pendingIds),
            'submitted_count' => count($submitIds),
            'duplicate_submitted_count' => count($duplicateSubmitted) + count($submittedIds),
            'external_calls_attempted' => count($submitIds) > 0,
            'search_submission_attempted' => count($submitIds) > 0,
        ]));
    }

    private function readablePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function limit(): ?int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        return is_int($limit) && $limit >= 1 && $limit <= 3 ? $limit : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/post-publish-indexnow-auto');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function validatePublishEvidence(array $evidence): ?string
    {
        $schema = (string) ($evidence['schema_version'] ?? '');
        if (! in_array($schema, [self::SINGLE_PUBLISH_SCHEMA_VERSION, self::AUTO_PUBLISH_SCHEMA_VERSION], true)) {
            return 'publish_evidence_schema_invalid';
        }
        if (($evidence['status'] ?? null) !== 'success') {
            return 'publish_evidence_not_success';
        }

        if ($schema === self::SINGLE_PUBLISH_SCHEMA_VERSION) {
            if ((bool) ($evidence['execute'] ?? false) !== true
                || (bool) ($evidence['writes_committed'] ?? false) !== true
                || (int) ($evidence['published_count'] ?? 0) !== 1) {
                return 'single_publish_evidence_missing_committed_publish';
            }
            if ((bool) data_get($evidence, 'rollback_evidence.available') !== true) {
                return 'rollback_evidence_missing';
            }
            if ((bool) data_get($evidence, 'boundaries.search_channel_enqueue') !== false
                || (bool) data_get($evidence, 'boundaries.indexing_request') !== false) {
                return 'publish_evidence_boundary_invalid';
            }

            return null;
        }

        if ((bool) data_get($evidence, 'publish_summary.execute') !== true
            || (int) data_get($evidence, 'publish_summary.published_count', 0) < 1) {
            return 'auto_publish_evidence_missing_committed_publish';
        }
        if ((bool) data_get($evidence, 'negative_guarantees.search_channel_enqueue') !== false
            || (bool) data_get($evidence, 'negative_guarantees.indexing_request') !== false) {
            return 'auto_publish_evidence_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function publishedRefs(array $evidence): array
    {
        if (($evidence['schema_version'] ?? null) === self::SINGLE_PUBLISH_SCHEMA_VERSION) {
            return array_values(array_filter(
                (array) ($evidence['affected_refs'] ?? []),
                static fn ($ref): bool => is_array($ref) && ($ref['status'] ?? null) === 'published'
            ));
        }

        $refs = [];
        foreach ((array) ($evidence['publish_results'] ?? []) as $result) {
            if (! is_array($result) || (int) ($result['published_count'] ?? 0) < 1) {
                continue;
            }
            foreach ((array) ($result['affected_refs'] ?? []) as $ref) {
                if (is_array($ref) && ($ref['status'] ?? null) === 'published') {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function publishedTarget(array $ref): array
    {
        if (($ref['target_model'] ?? null) !== 'content_page') {
            return ['ok' => false, 'issue' => 'target_model_not_supported'];
        }

        $pageId = $this->idFromSubjectRef((string) ($ref['subject_ref'] ?? ''), 'content_page');
        if ($pageId < 1) {
            return ['ok' => false, 'issue' => 'subject_ref_invalid'];
        }

        $page = ContentPage::query()->withoutGlobalScopes()->find($pageId);
        if (! $page instanceof ContentPage) {
            return ['ok' => false, 'issue' => 'content_page_not_found'];
        }
        if ((string) $page->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $page->is_public || ! (bool) $page->is_indexable) {
            return ['ok' => false, 'issue' => 'content_page_not_public_indexable'];
        }

        $safePath = (string) ($ref['safe_path'] ?? $page->canonical_path ?? $page->path ?? '');
        $canonicalUrl = $this->canonicalUrl($safePath);
        if ($canonicalUrl === null) {
            return ['ok' => false, 'issue' => 'canonical_url_resolution_failed'];
        }

        return [
            'ok' => true,
            'subject_ref' => (string) ($ref['subject_ref'] ?? ''),
            'safe_path' => $safePath,
            'canonical_url' => $canonicalUrl,
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
        ];
    }

    private function canonicalUrl(string $safePath): ?string
    {
        if ($safePath === '' || ! str_starts_with($safePath, '/') || str_starts_with($safePath, '//')) {
            return null;
        }

        $base = trim((string) ($this->option('base-url') ?: config('app.url')));
        if ($base === '') {
            return null;
        }

        $url = rtrim($base, '/').$safePath;

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }

    private function idFromSubjectRef(string $subjectRef, string $expectedType): int
    {
        $parts = explode(':', $subjectRef);
        if (($parts[0] ?? '') !== $expectedType || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return 0;
        }

        return (int) $parts[1];
    }

    /**
     * @param  list<array<string, mixed>>  $plannedItems
     * @return list<int>
     */
    private function queueItemIdsForPlannedItems(array $plannedItems): array
    {
        if ($plannedItems === []) {
            return [];
        }

        return DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_search_channel_queue_items')
            ->whereIn('idempotency_key', array_values(array_map(static fn (array $item): string => (string) ($item['idempotency_key'] ?? ''), $plannedItems)))
            ->where('channel', 'indexnow')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{id:int, approval_state:string, execution_state:string}|null
     */
    private function existingIndexnowQueueItem(string $canonicalUrl): ?array
    {
        $row = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_search_channel_queue_items')
            ->where('url_hash', hash('sha256', $canonicalUrl))
            ->where('channel', 'indexnow')
            ->orderByDesc('id')
            ->first(['id', 'approval_state', 'execution_state']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'approval_state' => (string) $row->approval_state,
            'execution_state' => (string) $row->execution_state,
        ];
    }

    /**
     * @param  list<int>  $queueItemIds
     * @return array{0:list<int>,1:list<int>,2:list<int>,3:list<array<string, mixed>>}
     */
    private function partitionQueueItems(array $queueItemIds): array
    {
        $pending = [];
        $approved = [];
        $submitted = [];
        $blocked = [];
        if ($queueItemIds === []) {
            return [$pending, $approved, $submitted, $blocked];
        }

        $items = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
            ->table('seo_search_channel_queue_items')
            ->whereIn('id', $queueItemIds)
            ->orderBy('id')
            ->get(['id', 'approval_state', 'execution_state', 'channel']);

        foreach ($items as $item) {
            $id = (int) $item->id;
            if ((string) $item->channel !== 'indexnow') {
                $blocked[] = ['queue_item_id' => $id, 'issue' => 'non_indexnow_channel_blocked'];
                continue;
            }

            $approval = (string) $item->approval_state;
            $execution = (string) $item->execution_state;
            if ($approval === 'pending' && $execution === 'dry_run_ready') {
                $pending[] = $id;
            } elseif ($approval === 'approved' && $execution === 'dry_run_ready') {
                $approved[] = $id;
            } elseif ($execution === 'submitted') {
                $submitted[] = $id;
            } else {
                $blocked[] = [
                    'queue_item_id' => $id,
                    'approval_state' => $approval,
                    'execution_state' => $execution,
                    'issue' => 'queue_item_state_not_auto_submittable',
                ];
            }
        }

        return [$pending, $approved, $submitted, $blocked];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function safePlanSummary(array $plan, array $target): array
    {
        return [
            'safe_path' => (string) ($target['safe_path'] ?? ''),
            'canonical_url_hash' => (string) ($target['canonical_url_hash'] ?? ''),
            'candidate_count' => (int) ($plan['candidate_count'] ?? 0),
            'eligible_count' => (int) ($plan['eligible_count'] ?? 0),
            'planned_queue_count' => (int) ($plan['planned_queue_count'] ?? 0),
            'duplicate_detected' => (bool) ($plan['duplicate_detected'] ?? false),
            'reason_code_breakdown' => (array) ($plan['reason_code_breakdown'] ?? []),
            'selected_channels' => array_values(array_map('strval', (array) ($plan['selected_channels'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function safeExecutorSummary(array $result): array
    {
        return [
            'status' => (string) ($result['status'] ?? ''),
            'dry_run' => (bool) ($result['dry_run'] ?? false),
            'queue_item_ids' => array_values(array_map('intval', (array) ($result['queue_item_ids'] ?? []))),
            'queue_item_count' => (int) ($result['queue_item_count'] ?? 0),
            'channels' => array_values(array_map('strval', (array) ($result['channels'] ?? []))),
            'issues' => array_values(array_map('strval', (array) ($result['issues'] ?? []))),
            'external_calls_attempted' => (bool) ($result['external_calls_attempted'] ?? false),
            'search_submission_attempted' => (bool) ($result['search_submission_attempted'] ?? false),
            'writes_attempted' => (bool) ($result['writes_attempted'] ?? false),
            'writes_committed' => (bool) ($result['writes_committed'] ?? false),
            'items' => array_values(array_map(static fn ($item): array => [
                'queue_item_id' => (int) data_get(is_array($item) ? $item : [], 'queue_item_id', 0),
                'channel' => (string) data_get(is_array($item) ? $item : [], 'channel', ''),
                'url_hash' => (string) data_get(is_array($item) ? $item : [], 'url_hash', ''),
                'status' => (string) data_get(is_array($item) ? $item : [], 'status', ''),
                'submission_status' => (string) data_get(is_array($item) ? $item : [], 'submission_status', ''),
                'execution_state' => (string) data_get(is_array($item) ? $item : [], 'execution_state', ''),
                'http_status' => data_get(is_array($item) ? $item : [], 'http_status'),
                'issues' => array_values(array_map('strval', (array) data_get(is_array($item) ? $item : [], 'issues', []))),
            ], (array) ($result['items'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function writeEvidence(string $artifactDir, string $publishEvidencePath, string $publishEvidenceSha, string $status, array $extra): array
    {
        $path = rtrim($artifactDir, '/').'/seo-agent-post-publish-indexnow-auto-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-POST-PUBLISH-INDEXNOW-AUTO-01',
            'status' => $status,
            'run_mode' => 'post_publish_indexnow_auto',
            'command' => 'php artisan seo-agent:post-publish-indexnow-auto',
            'publish_evidence' => [
                'path' => $publishEvidencePath,
                'size' => filesize($publishEvidencePath) ?: 0,
                'sha256' => $publishEvidenceSha,
            ],
            ...$extra,
            'allowed_actions' => [
                'url_truth_read',
                'search_channel_queue_write',
                'search_channel_queue_approve',
                'indexnow_live_submit',
                'evidence_artifact_write',
            ],
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function successSummary(string $status, bool $execute, array $artifact, array $extra): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $status,
            'execute' => $execute,
            ...$extra,
            'artifact' => $artifact,
            'google_indexing_live_api_called' => false,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'issues' => [$issue],
            ...$extra,
            'google_indexing_live_api_called' => false,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
            if (is_array($summary['artifact'] ?? null)) {
                $this->line('artifact_path='.(string) ($summary['artifact']['path'] ?? ''));
                $this->line('artifact_size='.(string) ($summary['artifact']['size'] ?? 0));
                $this->line('artifact_sha256='.(string) ($summary['artifact']['sha256'] ?? ''));
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function forbiddenActions(): array
    {
        return [
            'google_sitemap_live_submit',
            'google_indexing_live_api_call',
            'baidu_push_live_submit',
            'cms_write',
            'cms_publish',
            'scheduler_activation',
            'queue_worker_activation',
            'frontend_code_mutation',
            'external_model_api_call',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'cms_write' => false,
            'cms_publish' => false,
            'google_sitemap_live_submit' => false,
            'google_indexing_api_call' => false,
            'baidu_push_live_submit' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
            'frontend_code_mutation' => false,
        ];
    }
}
