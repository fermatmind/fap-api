<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentCmsDraftReadbackQaCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-cms-draft-readback-qa.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

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

    protected $signature = 'seo-agent:cms-draft-readback-qa
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--write-evidence= : Path to a seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--target= : Exact target subject ref, e.g. article:41:en}
        {--package-sha256= : Optional expected package sha256}
        {--artifact-dir= : Directory for sanitized readback QA evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only SEO Agent CMS draft readback QA; verifies draft revision payload against a dry-run package and write evidence.';

    public function handle(): int
    {
        $packagePath = $this->readablePath((string) $this->option('package'));
        $writeEvidencePath = $this->readablePath((string) $this->option('write-evidence'));
        $target = trim((string) $this->option('target'));

        if ($packagePath === null) {
            return $this->finish($this->failureSummary('package_unreadable'));
        }
        if ($writeEvidencePath === null) {
            return $this->finish($this->failureSummary('write_evidence_unreadable'));
        }
        if ($target === '' || str_contains($target, "\0")) {
            return $this->finish($this->failureSummary('target_invalid'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $packageRaw = (string) file_get_contents($packagePath);
        $writeRaw = (string) file_get_contents($writeEvidencePath);
        $forbidden = array_values(array_unique(array_merge(
            $this->forbiddenStringsPresent($packageRaw),
            $this->forbiddenStringsPresent($writeRaw),
        )));
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $package = json_decode($packageRaw, true);
        $writeEvidence = json_decode($writeRaw, true);
        if (! is_array($package)) {
            return $this->finish($this->failureSummary('package_json_invalid'));
        }
        if (! is_array($writeEvidence)) {
            return $this->finish($this->failureSummary('write_evidence_json_invalid'));
        }

        $packageIssue = $this->validatePackage($package);
        if ($packageIssue !== null) {
            return $this->finish($this->failureSummary($packageIssue));
        }
        $writeIssue = $this->validateWriteEvidence($writeEvidence);
        if ($writeIssue !== null) {
            return $this->finish($this->failureSummary($writeIssue));
        }

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $expectedSha = trim((string) $this->option('package-sha256'));
        if ($expectedSha !== '' && $expectedSha !== $packageSha) {
            return $this->finish($this->failureSummary('package_sha256_mismatch', [
                'package_sha256' => $packageSha,
                'expected_package_sha256' => $expectedSha,
            ]));
        }
        if ((string) ($writeEvidence['package_sha256'] ?? '') !== $packageSha) {
            return $this->finish($this->failureSummary('write_evidence_package_sha256_mismatch', [
                'package_sha256' => $packageSha,
                'write_evidence_package_sha256' => (string) ($writeEvidence['package_sha256'] ?? ''),
            ]));
        }

        $proposal = $this->proposalForTarget($package, $target);
        if ($proposal === null) {
            return $this->finish($this->failureSummary('target_not_found_in_package', [
                'target' => $target,
            ]));
        }

        if ((string) ($proposal['target_model'] ?? $proposal['subject_type'] ?? '') !== 'article') {
            return $this->finish($this->failureSummary('target_model_not_supported_for_readback_qa', [
                'target' => $target,
            ]));
        }

        $writeRef = $this->writeRefForTarget($writeEvidence, $target);
        if ($writeRef === null) {
            return $this->finish($this->failureSummary('target_not_found_in_write_evidence', [
                'target' => $target,
            ]));
        }

        $articleId = $this->idFromSubjectRef($target, 'article');
        $article = Article::query()->withoutGlobalScopes()->find($articleId);
        if (! $article instanceof Article) {
            return $this->finish($this->failureSummary('article_not_found', [
                'target' => $target,
            ]));
        }

        $revision = $this->matchingArticleRevision($articleId, $packageSha, $target, $proposal, $writeRef);
        if (! $revision instanceof ArticleRevision) {
            return $this->finish($this->failureSummary('draft_revision_not_found', [
                'target' => $target,
                'package_sha256' => $packageSha,
            ]));
        }

        $evidence = $this->evidence($packagePath, $writeEvidencePath, $packageSha, $package, $writeEvidence, $proposal, $writeRef, $article, $revision, $target);
        $artifactRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-cms-draft-readback-qa-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json',
            $evidence
        );

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => (bool) ($evidence['ok'] ?? false),
            'status' => (string) ($evidence['status'] ?? 'unknown'),
            'target' => $target,
            'artifact' => $artifactRef,
            'matched_field_count' => count((array) ($evidence['matched_fields'] ?? [])),
            'mismatch_count' => count((array) ($evidence['mismatches'] ?? [])),
            'qa_finding_count' => count((array) ($evidence['qa_findings'] ?? [])),
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
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
            $dir = storage_path('app/seo-agent/cms-draft-readback-qa');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function validatePackage(array $package): ?string
    {
        if (($package['schema_version'] ?? null) !== self::PACKAGE_SCHEMA_VERSION) {
            return 'package_schema_invalid';
        }
        if ((bool) ($package['dry_run'] ?? false) !== true) {
            return 'package_not_dry_run';
        }
        if ((bool) ($package['cms_write_allowed'] ?? true) !== false) {
            return 'package_cms_write_boundary_invalid';
        }
        if ((bool) ($package['execution_permission'] ?? true) !== false) {
            return 'package_execution_boundary_invalid';
        }

        return null;
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
        if ((bool) ($writeEvidence['execute'] ?? false) !== true) {
            return 'write_evidence_not_execute';
        }
        if ((bool) ($writeEvidence['writes_attempted'] ?? false) !== true) {
            return 'write_evidence_writes_not_attempted';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>|null
     */
    private function proposalForTarget(array $package, string $target): ?array
    {
        $items = $package['proposal_items'] ?? $package['draft_briefs'] ?? [];
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['subject_ref'] ?? '') === $target) {
                return $item;
            }
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
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $writeRef
     */
    private function matchingArticleRevision(int $articleId, string $packageSha, string $target, array $proposal, array $writeRef): ?ArticleRevision
    {
        $revisionId = $writeRef['revision_id'] ?? null;
        if (is_int($revisionId) || (is_string($revisionId) && ctype_digit($revisionId))) {
            $revision = ArticleRevision::query()->withoutGlobalScopes()
                ->where('article_id', $articleId)
                ->whereKey((int) $revisionId)
                ->first();
            if ($revision instanceof ArticleRevision && $this->revisionMatches($revision, $packageSha, $target, $proposal)) {
                return $revision;
            }
        }

        return ArticleRevision::query()->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->orderByDesc('revision_no')
            ->get()
            ->first(fn (ArticleRevision $revision): bool => $this->revisionMatches($revision, $packageSha, $target, $proposal));
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function revisionMatches(ArticleRevision $revision, string $packageSha, string $target, array $proposal): bool
    {
        $payload = is_array($revision->payload_json) ? $revision->payload_json : [];

        return data_get($payload, 'seo_agent.task') === self::WRITER_TASK
            && data_get($payload, 'seo_agent.package_sha256') === $packageSha
            && data_get($payload, 'seo_agent.subject_ref') === $target
            && $this->sortedStrings((array) data_get($payload, 'seo_agent.target_fields', [])) === $this->sortedStrings((array) ($proposal['target_fields'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $writeEvidence
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $writeRef
     * @return array<string, mixed>
     */
    private function evidence(
        string $packagePath,
        string $writeEvidencePath,
        string $packageSha,
        array $package,
        array $writeEvidence,
        array $proposal,
        array $writeRef,
        Article $article,
        ArticleRevision $revision,
        string $target
    ): array {
        $payload = is_array($revision->payload_json) ? $revision->payload_json : [];
        $comparisons = $this->comparisons($proposal, $payload);
        $mismatches = array_values(array_filter($comparisons, static fn (array $row): bool => ($row['matched'] ?? false) !== true));
        $matched = array_values(array_map(
            static fn (array $row): string => (string) ($row['field'] ?? ''),
            array_filter($comparisons, static fn (array $row): bool => ($row['matched'] ?? false) === true)
        ));

        $isPublishedRevision = (int) ($article->published_revision_id ?? 0) === (int) $revision->id;
        $liveMutationIssues = [];
        if ($isPublishedRevision) {
            $liveMutationIssues[] = 'draft_revision_is_published_revision';
        }
        if (data_get($payload, 'seo_agent.publish_allowed') !== false) {
            $liveMutationIssues[] = 'publish_allowed_not_false';
        }
        if (data_get($payload, 'seo_agent.search_submit_allowed') !== false) {
            $liveMutationIssues[] = 'search_submit_allowed_not_false';
        }
        if (data_get($payload, 'seo_agent.indexing_request_allowed') !== false) {
            $liveMutationIssues[] = 'indexing_request_allowed_not_false';
        }

        $qaFindings = array_values(array_merge(
            array_map(static fn (array $row): string => 'field_mismatch:'.(string) ($row['field'] ?? 'unknown'), $mismatches),
            $liveMutationIssues
        ));
        $ok = $qaFindings === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'qa_failed',
            'target' => $target,
            'package' => $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'write_evidence' => $this->artifactRef($writeEvidencePath, self::WRITE_SCHEMA_VERSION),
            'package_sha256' => $packageSha,
            'package_summary' => [
                'draft_brief_count' => (int) ($package['draft_brief_count'] ?? $package['proposal_count'] ?? 0),
                'claim_gate_required' => (bool) ($package['claim_gate_required'] ?? false),
                'human_approval_required' => (bool) ($package['human_approval_required'] ?? false),
            ],
            'write_summary' => [
                'status' => (string) ($writeEvidence['status'] ?? ''),
                'rows_created' => (int) ($writeEvidence['rows_created'] ?? 0),
                'rows_skipped_existing' => (int) ($writeEvidence['rows_skipped_existing'] ?? 0),
                'target_write_ref' => [
                    'status' => (string) ($writeRef['status'] ?? ''),
                    'target_model' => (string) ($writeRef['target_model'] ?? ''),
                    'subject_ref' => (string) ($writeRef['subject_ref'] ?? ''),
                    'revision_id' => isset($writeRef['revision_id']) ? (int) $writeRef['revision_id'] : null,
                ],
            ],
            'draft_revision' => [
                'exists' => true,
                'model' => 'article_revision',
                'revision_id' => (int) $revision->id,
                'revision_no' => (int) $revision->revision_no,
                'article_id' => (int) $revision->article_id,
                'change_note' => (string) $revision->change_note,
                'created_at' => optional($revision->created_at)->toIso8601String(),
                'is_published_revision' => $isPublishedRevision,
                'task' => (string) data_get($payload, 'seo_agent.task', ''),
                'claim_gate_required' => (bool) data_get($payload, 'seo_agent.claim_gate_required', false),
                'human_approval_required' => (bool) data_get($payload, 'seo_agent.human_approval_required', false),
                'publish_allowed' => (bool) data_get($payload, 'seo_agent.publish_allowed', true),
                'search_submit_allowed' => (bool) data_get($payload, 'seo_agent.search_submit_allowed', true),
                'indexing_request_allowed' => (bool) data_get($payload, 'seo_agent.indexing_request_allowed', true),
            ],
            'live_article_state' => [
                'article_id' => (int) $article->id,
                'locale' => (string) $article->locale,
                'slug' => (string) $article->slug,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'working_revision_id' => $article->working_revision_id ? (int) $article->working_revision_id : null,
                'published_revision_id' => $article->published_revision_id ? (int) $article->published_revision_id : null,
                'published_at' => optional($article->published_at)->toIso8601String(),
                'published_revision_unchanged_by_this_read' => true,
            ],
            'matched_fields' => $matched,
            'mismatches' => $mismatches,
            'qa_findings' => $qaFindings,
            'read_only_counts' => [
                'article_revisions_for_article' => ArticleRevision::query()->withoutGlobalScopes()
                    ->where('article_id', (int) $article->id)
                    ->count(),
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function comparisons(array $proposal, array $payload): array
    {
        $rows = [
            $this->comparison('seo_agent.task', self::WRITER_TASK, data_get($payload, 'seo_agent.task')),
            $this->comparison('seo_agent.subject_ref', (string) ($proposal['subject_ref'] ?? ''), data_get($payload, 'seo_agent.subject_ref')),
            $this->comparison('seo_agent.target_fields', $this->sortedStrings((array) ($proposal['target_fields'] ?? [])), $this->sortedStrings((array) data_get($payload, 'seo_agent.target_fields', []))),
            $this->comparison('proposal.safe_path', (string) ($proposal['safe_path'] ?? ''), data_get($payload, 'proposal.safe_path')),
            $this->comparison('proposal.proposed_seo_title', $proposal['proposed_seo_title'] ?? null, data_get($payload, 'proposal.proposed_seo_title')),
            $this->comparison('proposal.proposed_seo_description', $proposal['proposed_seo_description'] ?? null, data_get($payload, 'proposal.proposed_seo_description')),
            $this->comparison('proposal.proposed_faq_items', $proposal['proposed_faq_items'] ?? [], data_get($payload, 'proposal.proposed_faq_items', [])),
        ];

        if (array_key_exists('proposed_internal_link_actions', $proposal)) {
            $rows[] = $this->comparison(
                'proposal.proposed_internal_link_actions',
                $proposal['proposed_internal_link_actions'] ?? [],
                data_get($payload, 'proposal.proposed_internal_link_actions', [])
            );
        }

        if (array_key_exists('proposal_quality', $proposal)) {
            $rows[] = $this->comparison(
                'proposal.proposal_quality',
                $proposal['proposal_quality'] ?? [],
                data_get($payload, 'proposal.proposal_quality', [])
            );
        }

        return $rows;
    }

    /**
     * @param  mixed  $expected
     * @param  mixed  $actual
     * @return array<string, mixed>
     */
    private function comparison(string $field, $expected, $actual): array
    {
        $normalizedExpected = $this->canonicalComparableValue($expected);
        $normalizedActual = $this->canonicalComparableValue($actual);

        return [
            'field' => $field,
            'matched' => $normalizedExpected === $normalizedActual,
            'expected_hash' => hash('sha256', json_encode($normalizedExpected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'actual_hash' => hash('sha256', json_encode($normalizedActual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'expected_summary' => $this->summaryValue($expected),
            'actual_summary' => $this->summaryValue($actual),
        ];
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function canonicalComparableValue($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->canonicalComparableValue($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function summaryValue($value)
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 160);
        }
        if (is_array($value)) {
            return [
                'type' => 'array',
                'count' => count($value),
            ];
        }

        return $value;
    }

    private function idFromSubjectRef(string $subjectRef, string $expectedType): int
    {
        $parts = explode(':', $subjectRef);
        if (($parts[0] ?? '') !== $expectedType || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            throw new RuntimeException('subject_ref_invalid');
        }

        return (int) $parts[1];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $strings = array_values(array_unique(array_map('strval', $values)));
        sort($strings);

        return $strings;
    }

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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactDir, string $filename, array $payload): array
    {
        $path = rtrim($artifactDir, '/').'/'.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => (string) ($payload['schema_version'] ?? ''),
            'sanitized_summary' => [
                'target' => (string) ($payload['target'] ?? ''),
                'status' => (string) ($payload['status'] ?? ''),
                'mismatch_count' => count((array) ($payload['mismatches'] ?? [])),
            ],
        ];
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
     * @return array<string, bool>
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
            'queue_worker_started' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
        ];
    }
}
