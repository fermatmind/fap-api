<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentL5aIndexnowSubmitCanaryCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-l5a-indexnow-submit-canary.v1';

    private const PUBLISH_SCHEMA_VERSION = 'seo-agent-l5a-contentpage-publish-canary.v1';

    private const DELEGATED_PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-canary.v1';

    protected $signature = 'seo-agent:l5a-indexnow-submit-canary
        {--publish-evidence= : Path to seo-agent-l5a-contentpage-publish-canary.v1 execute evidence}
        {--limit=1 : Maximum IndexNow submit count, fixed to 1 for canary1}
        {--base-url= : Public site base URL used to resolve safe paths; defaults to app.url}
        {--artifact-dir= : Directory for L5-A IndexNow canary artifacts}
        {--confirm-publish-evidence-sha256= : Required L5-A publish evidence sha256 for execute mode}
        {--execute : Enqueue or reuse, approve, and live-submit one IndexNow queue item}
        {--json : Emit JSON summary}';

    protected $description = 'Submit one L5-A published ContentPage canary to IndexNow through URL Truth only; no Google Indexing, Baidu, sitemap, scheduler, or queue worker.';

    public function handle(): int
    {
        $publishPath = $this->readablePath((string) $this->option('publish-evidence'));
        if ($publishPath === null) {
            return $this->finish($this->failureSummary('publish_evidence_unreadable'));
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit !== 1) {
            return $this->finish($this->failureSummary('limit_must_be_one'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $raw = (string) file_get_contents($publishPath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_publish_evidence_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $publishEvidence = json_decode($raw, true);
        if (! is_array($publishEvidence) || ($publishEvidence['schema_version'] ?? null) !== self::PUBLISH_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('publish_evidence_schema_invalid'));
        }

        $publishIssue = $this->validatePublishEvidence($publishEvidence);
        if ($publishIssue !== null) {
            return $this->finish($this->failureSummary($publishIssue));
        }

        $target = $this->publishedTarget($publishEvidence);
        if (($target['ok'] ?? false) !== true) {
            return $this->finish($this->failureSummary((string) ($target['issue'] ?? 'published_target_invalid')));
        }

        $urlTruth = $this->urlTruth($target);
        if (($urlTruth['ok'] ?? false) !== true) {
            $artifact = $this->writeL5aArtifact($artifactDir, $publishPath, 'blocked', false, $target, [
                'issues' => [(string) ($urlTruth['issue'] ?? 'url_truth_invalid')],
                'url_truth' => $this->safeUrlTruthSummary($urlTruth),
            ]);

            return $this->finish($this->failureSummary((string) ($urlTruth['issue'] ?? 'url_truth_invalid'), [
                'artifact' => $artifact,
                'url_truth' => $this->safeUrlTruthSummary($urlTruth),
            ]));
        }

        $execute = (bool) $this->option('execute');
        $publishSha = hash_file('sha256', $publishPath) ?: '';
        if ($execute && (string) $this->option('confirm-publish-evidence-sha256') !== $publishSha) {
            return $this->finish($this->failureSummary('publish_evidence_sha256_confirmation_mismatch', [
                'publish_evidence_sha256' => $publishSha,
            ]));
        }

        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $delegatedPath = rtrim($artifactDir, '/').'/seo-agent-l5a-indexnow-submit-canary-delegated-publish-'.$timestamp.'.json';
        $this->writeJson($delegatedPath, $this->delegatedPublishEvidence($publishEvidence, $target));

        $delegatedSummary = $this->runPostPublishIndexnowAuto($delegatedPath, $artifactDir, $execute);
        $delegatedOk = ($delegatedSummary['ok'] ?? false) === true
            && in_array((string) ($delegatedSummary['status'] ?? ''), ['planned', 'success'], true);

        $queueState = $this->queueState((string) $target['canonical_url_hash']);
        $artifact = $this->writeL5aArtifact($artifactDir, $publishPath, $delegatedOk ? ($execute ? 'success' : 'planned') : 'blocked', $execute, $target, [
            'delegated_publish_evidence' => $this->artifactRef($delegatedPath, self::DELEGATED_PUBLISH_SCHEMA_VERSION),
            'delegated_indexnow_artifact' => is_array($delegatedSummary['artifact'] ?? null) ? $delegatedSummary['artifact'] : null,
            'url_truth' => $this->safeUrlTruthSummary($urlTruth),
            'queue_item_id' => $queueState['queue_item_id'],
            'queue_item_ids' => $queueState['queue_item_id'] === null ? [] : [$queueState['queue_item_id']],
            'approval_state' => $queueState['approval_state'],
            'execution_state' => $queueState['execution_state'],
            'provider_response_status' => $this->providerResponseStatus($delegatedSummary),
            'submitted_count' => (int) ($delegatedSummary['submitted_count'] ?? 0),
            'duplicate_submitted_count' => (int) ($delegatedSummary['duplicate_submitted_count'] ?? 0),
            'written_items' => (int) ($delegatedSummary['written_items'] ?? 0),
            'planned_queue_count' => (int) ($delegatedSummary['planned_queue_count'] ?? 0),
            'issues' => array_values(array_map('strval', (array) ($delegatedSummary['issues'] ?? []))),
        ]);

        if (! $delegatedOk) {
            return $this->finish($this->failureSummary('indexnow_submit_canary_failed', [
                'artifact' => $artifact,
                'delegated_status' => (string) ($delegatedSummary['status'] ?? 'unknown'),
                'delegated_issues' => array_values(array_map('strval', (array) ($delegatedSummary['issues'] ?? []))),
            ]));
        }

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $execute ? 'success' : 'planned',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'target_count' => 1,
            'queue_item_id' => $queueState['queue_item_id'],
            'approval_state' => $queueState['approval_state'],
            'execution_state' => $queueState['execution_state'],
            'provider_response_status' => $this->providerResponseStatus($delegatedSummary),
            'submitted_count' => (int) ($delegatedSummary['submitted_count'] ?? 0),
            'duplicate_submitted_count' => (int) ($delegatedSummary['duplicate_submitted_count'] ?? 0),
            'planned_queue_count' => (int) ($delegatedSummary['planned_queue_count'] ?? 0),
            'written_items' => (int) ($delegatedSummary['written_items'] ?? 0),
            'artifact' => $artifact,
            'google_indexing_live_api_called' => false,
            'boundaries' => $this->boundaries($execute, ((int) ($delegatedSummary['submitted_count'] ?? 0)) > 0),
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
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

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/l5a-indexnow-submit-canary');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $publishEvidence
     */
    private function validatePublishEvidence(array $publishEvidence): ?string
    {
        if (($publishEvidence['task'] ?? null) !== 'SEO-AGENT-L5A-CONTENTPAGE-PUBLISH-CANARY1-01'
            || ($publishEvidence['status'] ?? null) !== 'success'
            || (bool) ($publishEvidence['execute'] ?? false) !== true) {
            return 'publish_evidence_not_success_execute';
        }
        if ((int) ($publishEvidence['published_count'] ?? 0) + (int) ($publishEvidence['rows_skipped_existing'] ?? 0) !== 1) {
            return 'publish_evidence_single_url_required';
        }
        if ((bool) data_get($publishEvidence, 'rollback_evidence.available') !== true) {
            return 'rollback_evidence_missing';
        }
        if ((bool) ($publishEvidence['url_truth_required'] ?? false) !== true) {
            return 'url_truth_required_missing';
        }
        if ((bool) data_get($publishEvidence, 'boundaries.search_channel_enqueue', true) !== false
            || (bool) data_get($publishEvidence, 'boundaries.search_channel_submit', true) !== false
            || (bool) data_get($publishEvidence, 'boundaries.indexnow_live_submit', true) !== false
            || (bool) data_get($publishEvidence, 'boundaries.google_indexing_api_call', true) !== false
            || (bool) data_get($publishEvidence, 'boundaries.scheduler_activation', true) !== false) {
            return 'publish_evidence_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $publishEvidence
     * @return array<string, mixed>
     */
    private function publishedTarget(array $publishEvidence): array
    {
        $candidate = is_array($publishEvidence['selected_candidate'] ?? null) ? $publishEvidence['selected_candidate'] : [];
        if (($candidate['target_model'] ?? null) !== 'content_page' || ($candidate['subject_type'] ?? null) !== 'content_page') {
            return ['ok' => false, 'issue' => 'selected_candidate_not_content_page'];
        }

        $safePath = (string) ($publishEvidence['published_safe_path'] ?? $candidate['safe_path'] ?? '');
        if ($safePath === '' || ! str_starts_with($safePath, '/') || str_starts_with($safePath, '//')) {
            return ['ok' => false, 'issue' => 'published_safe_path_invalid'];
        }
        if ($safePath !== (string) ($candidate['safe_path'] ?? '')) {
            return ['ok' => false, 'issue' => 'published_safe_path_candidate_mismatch'];
        }

        $pageId = $this->idFromSubjectRef((string) ($candidate['subject_ref'] ?? ''), 'content_page');
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
        if ((string) ($page->canonical_path ?: $page->path) !== $safePath) {
            return ['ok' => false, 'issue' => 'content_page_canonical_safe_path_mismatch'];
        }

        $canonicalUrl = $this->canonicalUrl($safePath);
        if ($canonicalUrl === null) {
            return ['ok' => false, 'issue' => 'canonical_url_resolution_failed'];
        }

        return [
            'ok' => true,
            'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'page_id' => $pageId,
            'locale' => (string) ($page->locale ?? ''),
            'safe_path' => $safePath,
            'canonical_url' => $canonicalUrl,
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function urlTruth(array $target): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        if (! Schema::connection($connection)->hasTable('seo_urls')) {
            return ['ok' => false, 'issue' => 'seo_urls_table_missing'];
        }

        $canonicalUrl = (string) $target['canonical_url'];
        $pageId = (string) $target['page_id'];
        $exact = DB::connection($connection)
            ->table('seo_urls')
            ->where('canonical_url', $canonicalUrl)
            ->where('page_entity_type', 'content_page')
            ->orderByDesc('id')
            ->first();

        if ($exact === null) {
            $entityRows = DB::connection($connection)
                ->table('seo_urls')
                ->where('page_entity_type', 'content_page')
                ->where('entity_id_or_slug', $pageId)
                ->orderByDesc('id')
                ->get(['canonical_url_hash', 'entity_id_or_slug'])
                ->all();

            return [
                'ok' => false,
                'issue' => $entityRows === [] ? 'url_truth_row_missing' : 'url_truth_canonical_path_mismatch',
                'entity_row_count' => count($entityRows),
            ];
        }

        $metadata = $this->metadata($exact->metadata_json ?? null);
        $claimSafe = (bool) ($metadata['claim_safe'] ?? false);
        $claimState = (string) ($metadata['claim_boundary_state'] ?? ($claimSafe ? 'approved' : ''));
        if ((string) $exact->indexability_state !== 'indexable'
            || (bool) $exact->is_private_flow
            || ! in_array((string) $exact->source_authority, config('seo_intel.search_channel_queue.approved_source_authorities', []), true)) {
            return [
                'ok' => false,
                'issue' => 'url_truth_row_not_submit_eligible',
                'canonical_url_hash' => (string) $exact->canonical_url_hash,
                'indexability_state' => (string) $exact->indexability_state,
                'source_authority' => (string) $exact->source_authority,
                'private_flow' => (bool) $exact->is_private_flow,
                'claim_boundary_state' => $claimState,
            ];
        }

        return [
            'ok' => true,
            'canonical_url_hash' => (string) $exact->canonical_url_hash,
            'entity_id_or_slug' => (string) ($exact->entity_id_or_slug ?? ''),
            'source_authority' => (string) $exact->source_authority,
            'indexability_state' => (string) $exact->indexability_state,
            'claim_boundary_state' => $claimState,
        ];
    }

    /**
     * @param  array<string, mixed>  $publishEvidence
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function delegatedPublishEvidence(array $publishEvidence, array $target): array
    {
        return [
            'schema_version' => self::DELEGATED_PUBLISH_SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'execute' => true,
            'writes_committed' => true,
            'published_count' => 1,
            'affected_refs' => [[
                'status' => 'published',
                'target_model' => 'content_page',
                'subject_ref' => (string) $target['subject_ref'],
                'safe_path' => (string) $target['safe_path'],
            ]],
            'rollback_evidence' => [
                'available' => true,
                'content_page_ref' => (string) $target['subject_ref'],
            ],
            'boundaries' => [
                'cms_publish' => true,
                'search_channel_enqueue' => false,
                'indexing_request' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPostPublishIndexnowAuto(string $delegatedPath, string $artifactDir, bool $execute): array
    {
        $command = $this->getApplication()?->find('seo-agent:post-publish-indexnow-auto');
        if ($command === null) {
            return $this->failureSummary('post_publish_indexnow_auto_command_missing');
        }

        $input = [
            'command' => 'seo-agent:post-publish-indexnow-auto',
            '--publish-evidence' => $delegatedPath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ];
        if ($execute) {
            $input['--execute'] = true;
        }

        $buffer = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput($input), $buffer);
        $summary = json_decode(trim($buffer->fetch()), true);
        if (! is_array($summary)) {
            return $this->failureSummary('post_publish_indexnow_auto_summary_json_invalid', [
                'delegated_exit_code' => $exitCode,
            ]);
        }
        $summary['delegated_exit_code'] = $exitCode;

        return $summary;
    }

    /**
     * @return array{queue_item_id:?int,approval_state:?string,execution_state:?string}
     */
    private function queueState(string $canonicalUrlHash): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        if (! Schema::connection($connection)->hasTable('seo_search_channel_queue_items')) {
            return ['queue_item_id' => null, 'approval_state' => null, 'execution_state' => null];
        }

        $row = DB::connection($connection)
            ->table('seo_search_channel_queue_items')
            ->where('url_hash', $canonicalUrlHash)
            ->where('channel', 'indexnow')
            ->orderByDesc('id')
            ->first(['id', 'approval_state', 'execution_state']);

        if ($row === null) {
            return ['queue_item_id' => null, 'approval_state' => null, 'execution_state' => null];
        }

        return [
            'queue_item_id' => (int) $row->id,
            'approval_state' => (string) $row->approval_state,
            'execution_state' => (string) $row->execution_state,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function providerResponseStatus(array $summary): ?int
    {
        $value = data_get($summary, 'submit_result.items.0.http_status');
        if (! is_numeric($value) && is_string(data_get($summary, 'artifact.path'))) {
            $artifact = json_decode((string) file_get_contents((string) data_get($summary, 'artifact.path')), true);
            if (is_array($artifact)) {
                $value = data_get($artifact, 'submit_result.items.0.http_status');
            }
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function canonicalUrl(string $safePath): ?string
    {
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
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<string, mixed>  $urlTruth
     * @return array<string, mixed>
     */
    private function safeUrlTruthSummary(array $urlTruth): array
    {
        return [
            'ok' => (bool) ($urlTruth['ok'] ?? false),
            'issue' => isset($urlTruth['issue']) ? (string) $urlTruth['issue'] : null,
            'canonical_url_hash' => isset($urlTruth['canonical_url_hash']) ? (string) $urlTruth['canonical_url_hash'] : null,
            'source_authority' => isset($urlTruth['source_authority']) ? (string) $urlTruth['source_authority'] : null,
            'indexability_state' => isset($urlTruth['indexability_state']) ? (string) $urlTruth['indexability_state'] : null,
            'claim_boundary_state' => isset($urlTruth['claim_boundary_state']) ? (string) $urlTruth['claim_boundary_state'] : null,
            'private_flow' => isset($urlTruth['private_flow']) ? (bool) $urlTruth['private_flow'] : null,
            'entity_row_count' => isset($urlTruth['entity_row_count']) ? (int) $urlTruth['entity_row_count'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function writeL5aArtifact(string $artifactDir, string $publishPath, string $status, bool $execute, array $target, array $extra): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-L5A-INDEXNOW-SUBMIT-CANARY1-01',
            'status' => $status,
            'dry_run' => ! $execute,
            'execute' => $execute,
            'limit' => 1,
            'publish_evidence' => $this->artifactRef($publishPath, self::PUBLISH_SCHEMA_VERSION),
            'selected_target' => [
                'subject_ref' => (string) ($target['subject_ref'] ?? ''),
                'safe_path' => (string) ($target['safe_path'] ?? ''),
                'canonical_url_hash' => (string) ($target['canonical_url_hash'] ?? ''),
            ],
            ...$extra,
            'google_indexing_live_api_called' => false,
            'boundaries' => $this->boundaries($execute, (int) ($extra['submitted_count'] ?? 0) > 0),
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];

        $filename = 'seo-agent-l5a-indexnow-submit-canary-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        $path = rtrim($artifactDir, '/').'/'.$filename;
        $this->writeJson($path, $payload);

        return $this->artifactRef($path, self::SCHEMA_VERSION);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRef(string $path, string $schemaVersion): array
    {
        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => $schemaVersion,
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
            'boundaries' => $this->boundaries(false, false),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach ([
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
        ] as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @return array<string, bool>
     */
    private function boundaries(bool $execute, bool $submitted): array
    {
        return [
            'cms_write' => false,
            'cms_publish' => false,
            'url_truth_read' => true,
            'search_channel_enqueue' => $execute,
            'search_channel_approve' => $execute,
            'search_channel_submit' => $submitted,
            'indexnow_live_submit' => $submitted,
            'google_sitemap_live_submit' => false,
            'google_indexing_api_call' => false,
            'baidu_push_live_submit' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'bulk_publish' => false,
            'article_publish' => false,
            'frontend_code_mutation' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function forbiddenActions(): array
    {
        return [
            'google_indexing_live_api_call',
            'google_sitemap_live_submit',
            'baidu_push_live_submit',
            'cms_write',
            'cms_publish',
            'article_publish',
            'bulk_publish',
            'scheduler_activation',
            'queue_worker_activation',
            'frontend_code_mutation',
            'external_model_api_call',
            'direct_url_submit',
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
            'article_publish' => false,
            'bulk_publish' => false,
            'direct_url_submit' => false,
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
