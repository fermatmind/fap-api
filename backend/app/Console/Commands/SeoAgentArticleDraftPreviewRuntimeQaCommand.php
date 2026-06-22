<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentArticleDraftPreviewRuntimeQaCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-article-draft-preview-runtime-qa.v1';

    private const WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    private const WRITER_TASK = 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01';

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

    protected $signature = 'seo-agent:article-draft-preview-runtime-qa
        {--write-evidence= : Path to a seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--target= : Exact target subject ref, e.g. article:41:en}
        {--revision-id= : Exact ArticleRevision id to verify as draft preview}
        {--artifact-dir= : Directory for sanitized preview/runtime QA evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only SEO Agent article draft preview/runtime QA; verifies draft preview identity without changing public runtime state.';

    public function handle(): int
    {
        $writeEvidencePath = $this->readablePath((string) $this->option('write-evidence'));
        $target = trim((string) $this->option('target'));
        $revisionId = filter_var($this->option('revision-id'), FILTER_VALIDATE_INT);

        if ($writeEvidencePath === null) {
            return $this->finish($this->failureSummary('write_evidence_unreadable'));
        }
        if ($target === '' || str_contains($target, "\0")) {
            return $this->finish($this->failureSummary('target_invalid'));
        }
        if (! is_int($revisionId) || $revisionId <= 0) {
            return $this->finish($this->failureSummary('revision_id_invalid'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $writeRaw = (string) file_get_contents($writeEvidencePath);
        $forbidden = $this->forbiddenStringsPresent($writeRaw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $writeEvidence = json_decode($writeRaw, true);
        if (! is_array($writeEvidence)) {
            return $this->finish($this->failureSummary('write_evidence_json_invalid'));
        }

        $writeIssue = $this->validateWriteEvidence($writeEvidence);
        if ($writeIssue !== null) {
            return $this->finish($this->failureSummary($writeIssue));
        }

        $writeRef = $this->writeRefForTarget($writeEvidence, $target);
        if ($writeRef === null) {
            return $this->finish($this->failureSummary('target_not_found_in_write_evidence', [
                'target' => $target,
            ]));
        }
        if ((string) ($writeRef['target_model'] ?? '') !== 'article') {
            return $this->finish($this->failureSummary('target_model_not_supported_for_preview_runtime_qa', [
                'target' => $target,
            ]));
        }
        if ((int) ($writeRef['revision_id'] ?? 0) !== $revisionId) {
            return $this->finish($this->failureSummary('revision_id_write_evidence_mismatch', [
                'target' => $target,
                'revision_id' => $revisionId,
                'write_evidence_revision_id' => isset($writeRef['revision_id']) ? (int) $writeRef['revision_id'] : null,
            ]));
        }

        $articleId = $this->idFromSubjectRef($target, 'article');
        $article = Article::query()->withoutGlobalScopes()->find($articleId);
        if (! $article instanceof Article) {
            return $this->finish($this->failureSummary('article_not_found', [
                'target' => $target,
            ]));
        }

        $beforeState = $this->articleState($article);
        $revision = ArticleRevision::query()->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->whereKey($revisionId)
            ->first();
        if (! $revision instanceof ArticleRevision) {
            return $this->finish($this->failureSummary('draft_revision_not_found', [
                'target' => $target,
                'revision_id' => $revisionId,
            ]));
        }

        $publishedRevision = null;
        if ($article->published_revision_id !== null) {
            $publishedRevision = ArticleRevision::query()->withoutGlobalScopes()
                ->where('article_id', $articleId)
                ->whereKey((int) $article->published_revision_id)
                ->first();
        }

        $payload = is_array($revision->payload_json) ? $revision->payload_json : [];
        $evidence = $this->evidence($writeEvidencePath, $writeEvidence, $writeRef, $article, $revision, $publishedRevision, $payload, $target, $beforeState);
        $artifactRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-article-draft-preview-runtime-qa-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json',
            $evidence
        );

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => (bool) ($evidence['ok'] ?? false),
            'status' => (string) ($evidence['status'] ?? 'unknown'),
            'target' => $target,
            'revision_id' => $revisionId,
            'artifact' => $artifactRef,
            'preview_readable' => (bool) data_get($evidence, 'preview_read.preview_readable', false),
            'public_runtime_uses_published_revision' => (bool) data_get($evidence, 'public_runtime.public_runtime_uses_published_revision', false),
            'mutation_detected' => (bool) data_get($evidence, 'read_only_invariants.mutation_detected', true),
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $writeEvidence
     */
    private function validateWriteEvidence(array $writeEvidence): ?string
    {
        if (($writeEvidence['schema_version'] ?? null) !== self::WRITE_SCHEMA_VERSION) {
            return 'write_evidence_schema_invalid';
        }
        if (($writeEvidence['status'] ?? null) !== 'success') {
            return 'write_evidence_not_success';
        }
        if ((bool) ($writeEvidence['execute'] ?? false) !== true || (bool) ($writeEvidence['writes_attempted'] ?? false) !== true) {
            return 'write_evidence_not_execute';
        }
        if ((string) ($writeEvidence['package_sha256'] ?? '') === '') {
            return 'write_evidence_package_sha256_missing';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $writeEvidence
     * @return array<string, mixed>|null
     */
    private function writeRefForTarget(array $writeEvidence, string $target): ?array
    {
        foreach ((array) ($writeEvidence['affected_refs'] ?? []) as $ref) {
            if (is_array($ref) && (string) ($ref['subject_ref'] ?? '') === $target) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $writeEvidence
     * @param  array<string, mixed>  $writeRef
     * @param  array<string, mixed>|null  $publishedRevision
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $beforeState
     * @return array<string, mixed>
     */
    private function evidence(
        string $writeEvidencePath,
        array $writeEvidence,
        array $writeRef,
        Article $article,
        ArticleRevision $revision,
        ?ArticleRevision $publishedRevision,
        array $payload,
        string $target,
        array $beforeState
    ): array {
        $article->refresh();
        $afterState = $this->articleState($article);
        $findings = $this->qaFindings($writeEvidence, $article, $revision, $publishedRevision, $payload, $target, $beforeState, $afterState);
        $criticalCount = count(array_filter($findings, static fn (array $finding): bool => ($finding['severity'] ?? '') === 'critical'));
        $status = $criticalCount > 0 ? 'blocked' : 'success';

        $isPublishedRevision = (int) ($article->published_revision_id ?? 0) === (int) $revision->id;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $criticalCount === 0,
            'status' => $status,
            'target' => $target,
            'write_evidence' => $this->artifactRef($writeEvidencePath, self::WRITE_SCHEMA_VERSION),
            'package_sha256' => (string) ($writeEvidence['package_sha256'] ?? ''),
            'write_summary' => [
                'target_write_ref' => [
                    'status' => (string) ($writeRef['status'] ?? ''),
                    'target_model' => (string) ($writeRef['target_model'] ?? ''),
                    'subject_ref' => (string) ($writeRef['subject_ref'] ?? ''),
                    'revision_id' => isset($writeRef['revision_id']) ? (int) $writeRef['revision_id'] : null,
                ],
            ],
            'preview_read' => [
                'preview_readable' => ! $isPublishedRevision && (int) $revision->article_id === (int) $article->id,
                'mode' => 'draft_revision_locked_read',
                'target_model' => 'article_revision',
                'revision_id' => (int) $revision->id,
                'revision_no' => (int) $revision->revision_no,
                'article_id' => (int) $revision->article_id,
                'is_published_revision' => $isPublishedRevision,
                'is_working_revision' => (int) ($article->working_revision_id ?? 0) === (int) $revision->id,
                'payload_identity' => [
                    'writer_task' => (string) data_get($payload, 'seo_agent.task', ''),
                    'subject_ref' => (string) data_get($payload, 'seo_agent.subject_ref', ''),
                    'package_sha256' => (string) data_get($payload, 'seo_agent.package_sha256', ''),
                    'publish_allowed' => data_get($payload, 'seo_agent.publish_allowed'),
                    'search_submit_allowed' => data_get($payload, 'seo_agent.search_submit_allowed'),
                    'indexing_request_allowed' => data_get($payload, 'seo_agent.indexing_request_allowed'),
                ],
            ],
            'public_runtime' => [
                'article_id' => (int) $article->id,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'working_revision_id' => $article->working_revision_id ? (int) $article->working_revision_id : null,
                'published_revision_id' => $article->published_revision_id ? (int) $article->published_revision_id : null,
                'published_revision_exists' => $publishedRevision instanceof ArticleRevision,
                'public_runtime_uses_published_revision' => $publishedRevision instanceof ArticleRevision
                    && ! $isPublishedRevision
                    && (string) $article->status === 'published',
                'draft_revision_leaked_to_public_runtime' => $isPublishedRevision,
            ],
            'read_only_invariants' => [
                'before_article_state' => $beforeState,
                'after_article_state' => $afterState,
                'article_state_unchanged_by_this_read' => $beforeState === $afterState,
                'mutation_detected' => $beforeState !== $afterState,
                'article_revision_count_for_article' => ArticleRevision::query()->withoutGlobalScopes()
                    ->where('article_id', (int) $article->id)
                    ->count(),
            ],
            'qa_findings' => $findings,
            'critical_finding_count' => $criticalCount,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $writeEvidence
     * @param  array<string, mixed>|null  $publishedRevision
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $beforeState
     * @param  array<string, mixed>  $afterState
     * @return list<array<string, string>>
     */
    private function qaFindings(
        array $writeEvidence,
        Article $article,
        ArticleRevision $revision,
        ?ArticleRevision $publishedRevision,
        array $payload,
        string $target,
        array $beforeState,
        array $afterState
    ): array {
        $findings = [];
        if ((string) $article->status !== 'published') {
            $findings[] = $this->finding('public_runtime', 'critical', 'article_not_published');
        }
        if (! $publishedRevision instanceof ArticleRevision) {
            $findings[] = $this->finding('public_runtime', 'critical', 'published_revision_missing');
        }
        if ((int) ($article->published_revision_id ?? 0) === (int) $revision->id) {
            $findings[] = $this->finding('public_runtime', 'critical', 'draft_revision_is_public_published_revision');
        }
        if ($beforeState !== $afterState) {
            $findings[] = $this->finding('read_only', 'critical', 'article_state_mutated_by_preview_runtime_qa');
        }
        if (data_get($payload, 'seo_agent.task') !== self::WRITER_TASK) {
            $findings[] = $this->finding('preview_read', 'critical', 'revision_writer_task_invalid');
        }
        if (data_get($payload, 'seo_agent.subject_ref') !== $target) {
            $findings[] = $this->finding('preview_read', 'critical', 'revision_subject_ref_mismatch');
        }
        if (data_get($payload, 'seo_agent.package_sha256') !== (string) ($writeEvidence['package_sha256'] ?? '')) {
            $findings[] = $this->finding('preview_read', 'critical', 'revision_package_sha256_mismatch');
        }
        foreach ([
            'publish_allowed' => 'publish_allowed_not_false',
            'search_submit_allowed' => 'search_submit_allowed_not_false',
            'indexing_request_allowed' => 'indexing_request_allowed_not_false',
        ] as $payloadKey => $issue) {
            if (data_get($payload, 'seo_agent.'.$payloadKey) !== false) {
                $findings[] = $this->finding('preview_read', 'critical', $issue);
            }
        }

        return $findings;
    }

    /**
     * @return array<string, mixed>
     */
    private function articleState(Article $article): array
    {
        return [
            'id' => (int) $article->id,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'working_revision_id' => $article->working_revision_id ? (int) $article->working_revision_id : null,
            'published_revision_id' => $article->published_revision_id ? (int) $article->published_revision_id : null,
            'updated_at' => optional($article->updated_at)->toISOString(),
        ];
    }

    private function idFromSubjectRef(string $subjectRef, string $expectedType): int
    {
        $parts = explode(':', $subjectRef);
        if (count($parts) < 3 || $parts[0] !== $expectedType || ! ctype_digit($parts[1])) {
            return 0;
        }

        return (int) $parts[1];
    }

    private function readablePath(string $rawPath): ?string
    {
        $path = trim($rawPath);
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
            $dir = storage_path('app/seo-agent/article-draft-preview-runtime-qa');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $payload): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($payload, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRef(string $path, string $schemaVersion): array
    {
        return [
            'schema_version' => $schemaVersion,
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $dir, string $filename, array $payload): array
    {
        $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Failed to encode preview/runtime QA artifact.');
        }
        file_put_contents($path, $encoded."\n");

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @return array<string, false>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'published_revision_mutation' => false,
            'live_published_content_mutation' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
            'live_gsc_api_call' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $surface, string $severity, string $issue): array
    {
        return [
            'surface' => $surface,
            'severity' => $severity,
            'issue' => $issue,
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
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf('%s %s', $summary['schema_version'] ?? self::SCHEMA_VERSION, $summary['status'] ?? 'unknown'));
        }

        return (bool) ($summary['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
