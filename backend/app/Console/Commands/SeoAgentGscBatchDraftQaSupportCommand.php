<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentGscBatchDraftQaSupportCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-gsc-batch-draft-qa-support.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

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

    protected $signature = 'seo-agent:gsc-batch-draft-qa-support
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--write-evidence= : Path to a seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--target=* : Optional subject_ref filter; may be repeated}
        {--artifact-dir= : Directory for sanitized batch QA evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only batch QA summarizer for GSC cohort CMS draft write evidence; keeps single-target readback QA compatible.';

    public function handle(): int
    {
        $packagePath = $this->readablePath((string) $this->option('package'));
        $writeEvidencePath = $this->readablePath((string) $this->option('write-evidence'));
        if ($packagePath === null) {
            return $this->finish($this->failureSummary('package_unreadable'));
        }
        if ($writeEvidencePath === null) {
            return $this->finish($this->failureSummary('write_evidence_unreadable'));
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

        $evidence = $this->evidence($packagePath, $writeEvidencePath, $package, $writeEvidence, $packageSha);
        $artifactRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-gsc-batch-draft-qa-support-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json',
            $evidence
        );

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => (bool) ($evidence['ok'] ?? false),
            'status' => (string) ($evidence['status'] ?? 'unknown'),
            'target_count' => (int) ($evidence['target_count'] ?? 0),
            'mismatch_count' => (int) ($evidence['mismatch_count'] ?? 0),
            'artifact' => $artifactRef,
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
        if ((bool) ($package['dry_run'] ?? false) !== true || (bool) ($package['cms_write_allowed'] ?? true) !== false || (bool) ($package['execution_permission'] ?? true) !== false) {
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
     * @param  array<string, mixed>  $writeEvidence
     * @return array<string, mixed>
     */
    private function evidence(string $packagePath, string $writeEvidencePath, array $package, array $writeEvidence, string $packageSha): array
    {
        $targets = array_values(array_filter(array_map('strval', (array) $this->option('target'))));
        $proposals = $this->proposalsByTarget($package);
        $refs = array_values(array_filter((array) ($writeEvidence['affected_refs'] ?? []), static function (mixed $ref) use ($targets): bool {
            if (! is_array($ref)) {
                return false;
            }
            if ((string) ($ref['target_model'] ?? '') !== 'article') {
                return false;
            }

            return $targets === [] || in_array((string) ($ref['subject_ref'] ?? ''), $targets, true);
        }));
        $targetVerdicts = array_map(fn (array $ref): array => $this->targetVerdict($ref, $proposals, $packageSha), $refs);
        $mismatchCount = array_sum(array_map(static fn (array $verdict): int => count((array) ($verdict['mismatches'] ?? [])), $targetVerdicts));
        $findingCount = array_sum(array_map(static fn (array $verdict): int => count((array) ($verdict['qa_findings'] ?? [])), $targetVerdicts));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $mismatchCount === 0 && $findingCount === 0 ? 'success' : 'review_required',
            'package' => $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'write_evidence' => $this->artifactRef($writeEvidencePath, self::WRITE_SCHEMA_VERSION),
            'package_sha256' => $packageSha,
            'target_count' => count($targetVerdicts),
            'mismatch_count' => $mismatchCount,
            'qa_finding_count' => $findingCount,
            'target_verdicts' => $targetVerdicts,
            'post_approval_command_sequence' => [
                'run seo-agent:cms-draft-readback-qa for each affected target when single-target detail is needed',
                'run seo-agent:article-draft-claim-risk-qa for each draft revision before publish readiness',
                'run seo-agent:article-draft-preview-runtime-qa for each draft revision before publish readiness',
                'do not publish until a separate publish gate readiness artifact passes',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  array<string, array<string, mixed>>  $proposals
     * @return array<string, mixed>
     */
    private function targetVerdict(array $ref, array $proposals, string $packageSha): array
    {
        $target = (string) ($ref['subject_ref'] ?? '');
        $proposal = $proposals[$target] ?? null;
        $revisionId = (int) ($ref['revision_id'] ?? 0);
        $articleId = $this->idFromSubjectRef($target);
        $article = $articleId > 0 ? Article::query()->withoutGlobalScopes()->find($articleId) : null;
        $revision = $articleId > 0 && $revisionId > 0
            ? ArticleRevision::query()->withoutGlobalScopes()->where('article_id', $articleId)->whereKey($revisionId)->first()
            : null;
        $payload = $revision instanceof ArticleRevision && is_array($revision->payload_json) ? $revision->payload_json : [];
        $mismatches = [];
        $matched = [];
        $findings = [];

        if (! is_array($proposal)) {
            $findings[] = 'proposal_missing_for_target';
        }
        if (! $article instanceof Article) {
            $findings[] = 'article_missing_for_target';
        }
        if (! $revision instanceof ArticleRevision) {
            $findings[] = 'draft_revision_missing_for_target';
        }
        if (data_get($payload, 'seo_agent.package_sha256') !== $packageSha) {
            $findings[] = 'revision_package_sha256_mismatch';
        }
        if (data_get($payload, 'seo_agent.subject_ref') !== $target) {
            $findings[] = 'revision_subject_ref_mismatch';
        }

        foreach ($this->proposalFieldMap() as $field => $payloadPath) {
            $expected = is_array($proposal) ? ($proposal[$field] ?? null) : null;
            $actual = data_get($payload, $payloadPath);
            if ($this->canonical($expected) === $this->canonical($actual)) {
                $matched[] = $payloadPath;
            } else {
                $mismatches[] = 'field_mismatch:'.$payloadPath;
            }
        }

        return [
            'subject_ref' => $target,
            'revision_id' => $revisionId,
            'locale' => $this->locale($target, (string) ($proposal['safe_path'] ?? '')),
            'safe_path' => (string) ($proposal['safe_path'] ?? ''),
            'matched_fields' => $matched,
            'mismatches' => $mismatches,
            'qa_findings' => $findings,
            'proposal_quality' => is_array($proposal) ? ($proposal['proposal_quality'] ?? []) : [],
            'internal_link_actions' => is_array($proposal) ? ($proposal['proposed_internal_link_actions'] ?? []) : [],
            'claim_risk_handoff_status' => (bool) ($proposal['claim_gate_required'] ?? true) ? 'required_pending' : 'not_required',
            'preview_runtime_handoff_status' => 'required_pending',
            'public_runtime_published_revision_id' => $article instanceof Article && $article->published_revision_id ? (int) $article->published_revision_id : null,
            'execution_permission' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, array<string, mixed>>
     */
    private function proposalsByTarget(array $package): array
    {
        $items = $package['proposal_items'] ?? $package['draft_briefs'] ?? [];
        $out = [];
        foreach ((array) $items as $item) {
            if (is_array($item) && (string) ($item['subject_ref'] ?? '') !== '') {
                $out[(string) $item['subject_ref']] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function proposalFieldMap(): array
    {
        return [
            'proposed_seo_title' => 'proposal.proposed_seo_title',
            'proposed_seo_description' => 'proposal.proposed_seo_description',
            'proposed_faq_items' => 'proposal.proposed_faq_items',
            'proposed_internal_link_actions' => 'proposal.proposed_internal_link_actions',
            'proposal_quality' => 'proposal.proposal_quality',
        ];
    }

    private function idFromSubjectRef(string $subjectRef): int
    {
        $parts = explode(':', $subjectRef);

        return ($parts[0] ?? '') === 'article' && ctype_digit($parts[1] ?? '') ? (int) $parts[1] : 0;
    }

    private function locale(string $subjectRef, string $safePath): string
    {
        $parts = explode(':', $subjectRef);
        if (($parts[2] ?? '') !== '') {
            return (string) $parts[2];
        }

        return str_starts_with($safePath, '/zh/') ? 'zh-CN' : (str_starts_with($safePath, '/en/') ? 'en' : '');
    }

    private function canonical(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '';
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
            $dir = storage_path('app/seo-agent/gsc-batch-draft-qa-support');
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
            throw new RuntimeException('Failed to encode GSC batch draft QA artifact.');
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
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'external_model_api_call' => false,
            'live_gsc_api_call' => false,
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
