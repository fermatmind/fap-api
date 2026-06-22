<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentArticleDraftClaimRiskQaCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-article-draft-claim-risk-qa.v1';

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

    protected $signature = 'seo-agent:article-draft-claim-risk-qa
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--write-evidence= : Path to a seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--target= : Exact target subject ref, e.g. article:41:en}
        {--revision-id= : Exact ArticleRevision id to review}
        {--artifact-dir= : Directory for sanitized claim-risk QA evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only SEO Agent article draft claim-risk QA for title, meta, FAQ, and internal-link proposals.';

    public function handle(): int
    {
        $packagePath = $this->readablePath((string) $this->option('package'));
        $writeEvidencePath = $this->readablePath((string) $this->option('write-evidence'));
        $target = trim((string) $this->option('target'));
        $revisionId = filter_var($this->option('revision-id'), FILTER_VALIDATE_INT);

        if ($packagePath === null) {
            return $this->finish($this->failureSummary('package_unreadable'));
        }
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
            return $this->finish($this->failureSummary('target_model_not_supported_for_claim_risk_qa', [
                'target' => $target,
            ]));
        }

        $writeRef = $this->writeRefForTarget($writeEvidence, $target);
        if ($writeRef === null) {
            return $this->finish($this->failureSummary('target_not_found_in_write_evidence', [
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

        $payload = is_array($revision->payload_json) ? $revision->payload_json : [];
        $identityIssue = $this->revisionIdentityIssue($payload, $proposal, $target, $packageSha);
        if ($identityIssue !== null) {
            return $this->finish($this->failureSummary($identityIssue, [
                'target' => $target,
                'revision_id' => $revisionId,
            ]));
        }

        $evidence = $this->evidence($packagePath, $writeEvidencePath, $packageSha, $proposal, $writeRef, $article, $revision, $payload, $target);
        $artifactRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-article-draft-claim-risk-qa-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json',
            $evidence
        );

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => (bool) ($evidence['ok'] ?? false),
            'status' => (string) ($evidence['status'] ?? 'unknown'),
            'target' => $target,
            'revision_id' => $revisionId,
            'artifact' => $artifactRef,
            'critical_finding_count' => (int) ($evidence['critical_finding_count'] ?? 0),
            'warning_finding_count' => (int) ($evidence['warning_finding_count'] ?? 0),
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
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
        if ((bool) ($package['cms_write_allowed'] ?? true) !== false || (bool) ($package['execution_permission'] ?? true) !== false) {
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
        if ((bool) ($writeEvidence['execute'] ?? false) !== true || (bool) ($writeEvidence['writes_attempted'] ?? false) !== true) {
            return 'write_evidence_not_execute';
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $proposal
     */
    private function revisionIdentityIssue(array $payload, array $proposal, string $target, string $packageSha): ?string
    {
        if (data_get($payload, 'seo_agent.task') !== self::WRITER_TASK) {
            return 'revision_writer_task_invalid';
        }
        if (data_get($payload, 'seo_agent.package_sha256') !== $packageSha) {
            return 'revision_package_sha256_mismatch';
        }
        if (data_get($payload, 'seo_agent.subject_ref') !== $target) {
            return 'revision_subject_ref_mismatch';
        }
        if ($this->sortedStrings((array) data_get($payload, 'seo_agent.target_fields', [])) !== $this->sortedStrings((array) ($proposal['target_fields'] ?? []))) {
            return 'revision_target_fields_mismatch';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $writeRef
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function evidence(
        string $packagePath,
        string $writeEvidencePath,
        string $packageSha,
        array $proposal,
        array $writeRef,
        Article $article,
        ArticleRevision $revision,
        array $payload,
        string $target
    ): array {
        $fieldVerdicts = $this->fieldVerdicts($proposal, $payload, $target);
        $findings = [];
        foreach ($fieldVerdicts as $verdict) {
            foreach ((array) ($verdict['findings'] ?? []) as $finding) {
                if (is_array($finding)) {
                    $findings[] = $finding;
                }
            }
        }

        $isPublishedRevision = (int) ($article->published_revision_id ?? 0) === (int) $revision->id;
        if ($isPublishedRevision) {
            $findings[] = $this->finding('draft_revision', 'critical', 'draft_revision_is_published_revision');
        }
        foreach ([
            'publish_allowed' => 'publish_allowed_not_false',
            'search_submit_allowed' => 'search_submit_allowed_not_false',
            'indexing_request_allowed' => 'indexing_request_allowed_not_false',
        ] as $payloadKey => $issue) {
            if (data_get($payload, 'seo_agent.'.$payloadKey) !== false) {
                $findings[] = $this->finding('draft_revision', 'critical', $issue);
            }
        }

        $criticalCount = count(array_filter($findings, static fn (array $finding): bool => ($finding['severity'] ?? '') === 'critical'));
        $warningCount = count(array_filter($findings, static fn (array $finding): bool => ($finding['severity'] ?? '') === 'warning'));
        $status = $criticalCount > 0 ? 'blocked' : ($warningCount > 0 ? 'review_required' : 'success');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $criticalCount === 0,
            'status' => $status,
            'target' => $target,
            'package' => $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'write_evidence' => $this->artifactRef($writeEvidencePath, self::WRITE_SCHEMA_VERSION),
            'package_sha256' => $packageSha,
            'write_summary' => [
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
                'is_published_revision' => $isPublishedRevision,
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
                'published_revision_unchanged_by_this_read' => true,
            ],
            'field_verdicts' => $fieldVerdicts,
            'findings' => $findings,
            'critical_finding_count' => $criticalCount,
            'warning_finding_count' => $warningCount,
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
    private function fieldVerdicts(array $proposal, array $payload, string $target): array
    {
        $fields = [
            'title' => [
                'expected' => $proposal['proposed_seo_title'] ?? null,
                'actual' => data_get($payload, 'proposal.proposed_seo_title'),
            ],
            'meta' => [
                'expected' => $proposal['proposed_seo_description'] ?? null,
                'actual' => data_get($payload, 'proposal.proposed_seo_description'),
            ],
            'faq' => [
                'expected' => $proposal['proposed_faq_items'] ?? [],
                'actual' => data_get($payload, 'proposal.proposed_faq_items', []),
            ],
            'internal_link' => [
                'expected' => $proposal['proposed_internal_link_actions'] ?? [],
                'actual' => data_get($payload, 'proposal.proposed_internal_link_actions', []),
            ],
        ];

        $locale = (string) (explode(':', $target)[2] ?? '');
        $verdicts = [];
        foreach ($fields as $field => $values) {
            $findings = [];
            $matched = $this->canonicalComparableValue($values['expected']) === $this->canonicalComparableValue($values['actual']);
            if (! $matched) {
                $findings[] = $this->finding($field, 'critical', 'proposal_payload_mismatch');
            }

            $text = $this->fieldText($values['actual']);
            foreach ($this->claimFindings($field, $text) as $finding) {
                $findings[] = $finding;
            }
            foreach ($this->localeFindings($field, $text, $locale) as $finding) {
                $findings[] = $finding;
            }
            if ($field === 'internal_link') {
                foreach ($this->internalLinkFindings((array) ($values['actual'] ?? [])) as $finding) {
                    $findings[] = $finding;
                }
            }

            $criticalCount = count(array_filter($findings, static fn (array $finding): bool => ($finding['severity'] ?? '') === 'critical'));
            $warningCount = count(array_filter($findings, static fn (array $finding): bool => ($finding['severity'] ?? '') === 'warning'));
            $verdicts[] = [
                'field' => $field,
                'matched_package_intent' => $matched,
                'status' => $criticalCount > 0 ? 'blocked' : ($warningCount > 0 ? 'review_required' : 'pass'),
                'expected_hash' => hash('sha256', json_encode($this->canonicalComparableValue($values['expected']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'actual_hash' => hash('sha256', json_encode($this->canonicalComparableValue($values['actual']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'actual_summary' => $this->summaryValue($values['actual']),
                'findings' => $findings,
            ];
        }

        return $verdicts;
    }

    /**
     * @return list<array<string, string>>
     */
    private function claimFindings(string $field, string $text): array
    {
        $findings = [];
        $checks = [
            ['critical', 'clinical_or_diagnostic_claim', '/\b(diagnos(?:e|is|tic)|clinical(?:ly)?|treat(?:ment)?|cure[sd]?)\b/i'],
            ['critical', 'guaranteed_outcome_claim', '/\b(guarantee[sd]?|certain(?:ly)?|will\s+(?:always|definitely))\b/i'],
            ['critical', 'hiring_fit_claim', '/\b(hiring\s+fit|job\s+performance\s+predict(?:or|ion)|hire\s+decision)\b/i'],
            ['critical', 'official_endorsement_claim', '/\bofficial\s+(?:partner|partnership|endorsement)\b/i'],
            ['warning', 'ranking_or_certainty_claim', '/\b(?:best|top|number\s*1|#1|rank(?:s|ed)?\s+first|predicts?)\b/i'],
        ];

        foreach ($checks as [$severity, $issue, $pattern]) {
            if (preg_match($pattern, $text) === 1 && ! $this->isNegatedSafeClaim($text, (string) $issue)) {
                $findings[] = $this->finding($field, (string) $severity, (string) $issue);
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string, string>>
     */
    private function localeFindings(string $field, string $text, string $locale): array
    {
        if ($text === '') {
            return [$this->finding($field, 'critical', 'proposal_text_empty')];
        }
        if (in_array($field, ['title', 'meta', 'faq'], true) && in_array($locale, ['zh', 'zh-CN'], true) && ! $this->containsCjk($text)) {
            return [$this->finding($field, 'warning', 'locale_mismatch_missing_chinese_text')];
        }
        if (in_array($field, ['title', 'meta', 'faq'], true) && $locale === 'en' && $this->containsCjk($text)) {
            return [$this->finding($field, 'warning', 'locale_mismatch_contains_chinese_text')];
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return list<array<string, string>>
     */
    private function internalLinkFindings(array $actions): array
    {
        $findings = [];
        foreach ($actions as $action) {
            $text = is_string($action) ? $action : json_encode($action, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $text = is_string($text) ? $text : '';
            if (preg_match('#https?://#i', $text) === 1) {
                $findings[] = $this->finding('internal_link', 'critical', 'unsafe_full_url_internal_link_action');
            }
            if (str_contains($text, '..')) {
                $findings[] = $this->finding('internal_link', 'critical', 'unsafe_path_traversal_internal_link_action');
            }
        }

        return $findings;
    }

    private function isNegatedSafeClaim(string $text, string $issue): bool
    {
        $lower = mb_strtolower($text);
        if ($issue === 'clinical_or_diagnostic_claim') {
            return str_contains($lower, 'non-diagnostic')
                || str_contains($lower, 'not diagnostic')
                || str_contains($lower, 'not a diagnosis');
        }
        if ($issue === 'guaranteed_outcome_claim') {
            return str_contains($lower, 'do not guarantee')
                || str_contains($lower, 'does not guarantee')
                || str_contains($lower, 'no guarantee')
                || str_contains($lower, '不能保证');
        }
        if ($issue === 'ranking_or_certainty_claim') {
            return str_contains($lower, 'not predict')
                || str_contains($lower, 'do not predict')
                || str_contains($lower, 'does not predict');
        }

        return false;
    }

    private function fieldText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function ($item) use (&$parts): void {
                if (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            });

            return implode(' ', $parts);
        }

        return '';
    }

    private function finding(string $field, string $severity, string $issue): array
    {
        return [
            'field' => $field,
            'severity' => $severity,
            'issue' => $issue,
        ];
    }

    private function containsCjk(string $text): bool
    {
        return preg_match('/\p{Han}/u', $text) === 1;
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
            $dir = storage_path('app/seo-agent/article-draft-claim-risk-qa');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
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
                'critical_finding_count' => (int) ($payload['critical_finding_count'] ?? 0),
                'warning_finding_count' => (int) ($payload['warning_finding_count'] ?? 0),
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
            'live_gsc_api_call' => false,
        ];
    }
}
