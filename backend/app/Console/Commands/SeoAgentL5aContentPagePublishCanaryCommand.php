<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentL5aContentPagePublishCanaryCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-l5a-contentpage-publish-canary.v1';

    private const DRAFT_WRITE_SCHEMA_VERSION = 'seo-agent-l5a-cms-draft-write-canary.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const DELEGATED_DRAFT_WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    private const PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-canary.v1';

    protected $signature = 'seo-agent:l5a-contentpage-publish-canary
        {--draft-write-evidence= : Path to seo-agent-l5a-cms-draft-write-canary.v1 execute evidence}
        {--limit=1 : Maximum publish count, fixed to 1 for canary1}
        {--artifact-dir= : Directory for L5-A publish canary artifacts}
        {--confirm-draft-write-sha256= : Required L5-A draft write evidence sha256 for execute mode}
        {--auto-approve-low-risk : Required with --execute; delegates only to low-risk publish canary mode}
        {--execute : Actually publish one bounded ContentPage draft}
        {--json : Emit JSON summary}';

    protected $description = 'Publish one L5-A ContentPage draft canary from verified draft write evidence; no search queue, IndexNow, Google Indexing, or scheduler.';

    public function handle(): int
    {
        $draftWritePath = $this->readablePath((string) $this->option('draft-write-evidence'));
        if ($draftWritePath === null) {
            return $this->finish($this->failureSummary('draft_write_evidence_unreadable'));
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit !== 1) {
            return $this->finish($this->failureSummary('limit_must_be_one'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $raw = (string) file_get_contents($draftWritePath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_draft_write_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $draftWrite = json_decode($raw, true);
        if (! is_array($draftWrite) || ($draftWrite['schema_version'] ?? null) !== self::DRAFT_WRITE_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('draft_write_schema_invalid'));
        }

        $draftIssue = $this->validateDraftWriteEvidence($draftWrite);
        if ($draftIssue !== null) {
            return $this->finish($this->failureSummary($draftIssue));
        }

        $packagePath = $this->filteredPackagePath($draftWrite);
        if ($packagePath === null) {
            return $this->finish($this->failureSummary('filtered_package_unreadable'));
        }

        $package = $this->readJson($packagePath);
        if (($package['schema_version'] ?? null) !== self::PACKAGE_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('filtered_package_schema_invalid'));
        }

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $candidate = $this->selectedCandidate($draftWrite);
        $proposal = $this->selectedProposal($package, $candidate);
        if ($proposal === null) {
            return $this->finish($this->failureSummary('selected_candidate_proposal_missing'));
        }

        $packageIssue = $this->validatePackageAndCandidate($draftWrite, $package, $proposal, $candidate, $packageSha);
        if ($packageIssue !== null) {
            return $this->finish($this->failureSummary($packageIssue, [
                'package_sha256' => $packageSha,
            ]));
        }

        $revisionIssue = $this->validateDraftRevision($draftWrite, $proposal, $packageSha);
        if ($revisionIssue !== null) {
            return $this->finish($this->failureSummary($revisionIssue, [
                'package_sha256' => $packageSha,
            ]));
        }

        $execute = (bool) $this->option('execute');
        if ($execute) {
            $approvalIssue = $this->executionApprovalIssue($draftWritePath);
            if ($approvalIssue !== null) {
                return $this->finish($this->failureSummary($approvalIssue, [
                    'draft_write_sha256' => hash_file('sha256', $draftWritePath) ?: '',
                ]));
            }
        }

        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $delegatedEvidence = $this->delegatedDraftWriteEvidence($draftWrite, $packageSha);
        $delegatedRef = $this->writeArtifact($artifactDir, 'seo-agent-l5a-contentpage-publish-canary-delegated-draft-write-'.$timestamp.'.json', $delegatedEvidence);
        $publishSummary = $this->runPublishCanary($packagePath, (string) $delegatedRef['path'], $packageSha, (string) ($candidate['subject_ref'] ?? ''), $execute);
        $publishOk = ($publishSummary['ok'] ?? false) === true
            && in_array((string) ($publishSummary['status'] ?? ''), ['planned', 'success'], true);

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-L5A-CONTENTPAGE-PUBLISH-CANARY1-01',
            'status' => $publishOk ? ($execute ? 'success' : 'planned') : 'blocked',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'limit' => 1,
            'draft_write_evidence' => $this->artifactRef($draftWritePath, self::DRAFT_WRITE_SCHEMA_VERSION),
            'filtered_package' => $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'delegated_draft_write_evidence' => $delegatedRef,
            'selected_candidate' => $this->sanitizedCandidate($candidate),
            'published_count' => (int) ($publishSummary['published_count'] ?? 0),
            'rows_skipped_existing' => (int) ($publishSummary['rows_skipped_existing'] ?? 0),
            'rollback_evidence' => (array) ($publishSummary['rollback_evidence'] ?? []),
            'published_safe_path' => $this->publishedSafePath($publishSummary, $candidate),
            'url_truth_required' => true,
            'claim_gate_status' => (string) data_get($publishSummary, 'plan.claim_gate', 'pass_after_forbidden_claim_scan'),
            'publish_summary' => $this->safePublishSummary($publishSummary),
            'boundaries' => $this->boundaries($execute && (int) ($publishSummary['published_count'] ?? 0) === 1),
            'blocked_actions' => [
                'article_publish',
                'cms_bulk_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexnow_live_submit',
                'google_indexing_api_call',
                'scheduler_activation',
                'queue_worker_activation',
            ],
        ];
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-l5a-contentpage-publish-canary-'.$timestamp.'.json', $evidence);

        if (! $publishOk) {
            return $this->finish($this->failureSummary('contentpage_publish_canary_failed', [
                'artifact' => $artifact,
                'publish_summary' => $this->safePublishSummary($publishSummary),
            ]));
        }

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $execute ? 'success' : 'planned',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'published_count' => (int) ($evidence['published_count'] ?? 0),
            'rows_skipped_existing' => (int) ($evidence['rows_skipped_existing'] ?? 0),
            'rollback_evidence' => $evidence['rollback_evidence'],
            'published_safe_path' => $evidence['published_safe_path'],
            'url_truth_required' => true,
            'search_channel_enqueue' => false,
            'indexnow_live_submit' => false,
            'artifact' => $artifact,
            'boundaries' => $evidence['boundaries'],
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
            $dir = storage_path('app/seo-agent/l5a-contentpage-publish-canary');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $draftWrite
     */
    private function validateDraftWriteEvidence(array $draftWrite): ?string
    {
        if (($draftWrite['status'] ?? null) !== 'success'
            || (bool) ($draftWrite['execute'] ?? false) !== true
            || (int) ($draftWrite['planned_count'] ?? 0) !== 1) {
            return 'draft_write_evidence_not_success_execute';
        }
        if ((int) ($draftWrite['rows_created'] ?? 0) + (int) ($draftWrite['rows_skipped_existing'] ?? 0) !== 1) {
            return 'draft_write_row_count_invalid';
        }
        if ((bool) data_get($draftWrite, 'rollback_pointer.available') !== true
            || ! is_numeric(data_get($draftWrite, 'rollback_pointer.candidate_revision_id'))) {
            return 'rollback_evidence_missing';
        }
        if ((bool) data_get($draftWrite, 'negative_guarantees.cms_publish', true) !== false
            || (bool) data_get($draftWrite, 'negative_guarantees.search_channel_enqueue', true) !== false
            || (bool) data_get($draftWrite, 'negative_guarantees.indexnow_live_submit', true) !== false
            || (bool) data_get($draftWrite, 'negative_guarantees.google_indexing_api_call', true) !== false) {
            return 'draft_write_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draftWrite
     */
    private function filteredPackagePath(array $draftWrite): ?string
    {
        $path = $this->readablePath((string) data_get($draftWrite, 'filtered_package.path'));
        if ($path === null) {
            return null;
        }

        $expectedSha = (string) data_get($draftWrite, 'filtered_package.sha256');
        if ($expectedSha !== '' && (hash_file('sha256', $path) ?: '') !== $expectedSha) {
            return null;
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            throw new RuntimeException('artifact_contains_forbidden_fields');
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('artifact_json_invalid');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $draftWrite
     * @return array<string, mixed>
     */
    private function selectedCandidate(array $draftWrite): array
    {
        return is_array($draftWrite['selected_candidate'] ?? null) ? $draftWrite['selected_candidate'] : [];
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function selectedProposal(array $package, array $candidate): ?array
    {
        $items = array_values(array_filter(
            (array) ($package['proposal_items'] ?? $package['draft_briefs'] ?? []),
            static fn ($item): bool => is_array($item)
        ));

        foreach ($items as $item) {
            if ((string) ($item['subject_ref'] ?? '') === (string) ($candidate['subject_ref'] ?? '')
                && (string) ($item['safe_path'] ?? '') === (string) ($candidate['safe_path'] ?? '')) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draftWrite
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $candidate
     */
    private function validatePackageAndCandidate(array $draftWrite, array $package, array $proposal, array $candidate, string $packageSha): ?string
    {
        if ((string) data_get($draftWrite, 'filtered_package.sha256') !== $packageSha
            || (string) ($draftWrite['idempotency_key'] ?? '') !== $packageSha) {
            return 'package_sha_mismatch';
        }
        if ((string) data_get($package, 'l5a_canary.task') !== 'SEO-AGENT-L5A-CMS-DRAFT-WRITE-CANARY1-01') {
            return 'l5a_package_marker_missing';
        }
        if ((string) data_get($package, 'l5a_canary.selected_subject_ref') !== (string) ($candidate['subject_ref'] ?? '')
            || (string) data_get($package, 'l5a_canary.selected_safe_path') !== (string) ($candidate['safe_path'] ?? '')) {
            return 'l5a_package_candidate_mismatch';
        }
        if ((string) ($candidate['target_model'] ?? '') !== 'content_page'
            || (string) ($candidate['subject_type'] ?? '') !== 'content_page'
            || (string) ($proposal['target_model'] ?? '') !== 'content_page') {
            return 'selected_candidate_not_content_page';
        }
        if (! in_array((string) ($candidate['source_family'] ?? ''), ['cms_tdk_gap', 'cms_faq_gap'], true)
            || ! in_array((string) ($candidate['severity'] ?? ''), ['p1', 'p2'], true)) {
            return 'selected_candidate_not_low_risk';
        }
        $safePath = (string) ($candidate['safe_path'] ?? '');
        $canonical = (string) ($proposal['proposed_canonical_path'] ?? $proposal['safe_path'] ?? '');
        if ($safePath === '' || $canonical === '' || $safePath !== $canonical || ! str_starts_with($safePath, '/') || str_starts_with($safePath, '//')) {
            return 'canonical_safe_path_mismatch';
        }
        if ((bool) ($proposal['claim_gate_required'] ?? false) !== true
            || (bool) ($proposal['human_approval_required'] ?? false) !== true
            || (bool) ($proposal['execution_permission'] ?? false) !== false) {
            return 'proposal_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draftWrite
     * @param  array<string, mixed>  $proposal
     */
    private function validateDraftRevision(array $draftWrite, array $proposal, string $packageSha): ?string
    {
        $pageId = $this->idFromSubjectRef((string) ($proposal['subject_ref'] ?? ''), 'content_page');
        $revisionId = (int) data_get($draftWrite, 'rollback_pointer.candidate_revision_id');
        $page = ContentPage::query()->withoutGlobalScopes()->find($pageId);
        $revision = CmsTranslationRevision::query()->find($revisionId);
        if (! $page instanceof ContentPage || ! $revision instanceof CmsTranslationRevision) {
            return 'draft_revision_missing';
        }
        if ((string) $page->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $page->is_public || ! (bool) $page->is_indexable) {
            return 'content_page_public_indexable_invalid';
        }
        if ((string) $revision->content_type !== 'content_page' || (int) $revision->content_id !== (int) $page->id) {
            return 'draft_revision_content_mismatch';
        }
        if (! in_array((string) $revision->revision_status, [CmsTranslationRevision::STATUS_DRAFT, CmsTranslationRevision::STATUS_APPROVED, CmsTranslationRevision::STATUS_PUBLISHED], true)) {
            return 'draft_revision_status_not_publishable';
        }
        if ((string) data_get($revision->payload_json, 'seo_agent.package_sha256') !== $packageSha) {
            return 'draft_revision_package_sha_mismatch';
        }

        return null;
    }

    private function idFromSubjectRef(string $subjectRef, string $expectedType): int
    {
        $parts = explode(':', $subjectRef);
        if (($parts[0] ?? '') !== $expectedType || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return 0;
        }

        return (int) $parts[1];
    }

    private function executionApprovalIssue(string $draftWritePath): ?string
    {
        if ((bool) $this->option('auto-approve-low-risk') !== true) {
            return 'execute_requires_auto_approve_low_risk';
        }
        $expectedSha = hash_file('sha256', $draftWritePath) ?: '';
        if ((string) $this->option('confirm-draft-write-sha256') !== $expectedSha) {
            return 'draft_write_sha256_confirmation_mismatch';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draftWrite
     * @return array<string, mixed>
     */
    private function delegatedDraftWriteEvidence(array $draftWrite, string $packageSha): array
    {
        return [
            'schema_version' => self::DELEGATED_DRAFT_WRITE_SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'execute' => true,
            'approval_mode' => 'low_risk_auto_approved',
            'package_sha256' => $packageSha,
            'writes_attempted' => true,
            'writes_committed' => (int) ($draftWrite['rows_created'] ?? 0) === 1,
            'rows_created' => (int) ($draftWrite['rows_created'] ?? 0),
            'rows_skipped_existing' => (int) ($draftWrite['rows_skipped_existing'] ?? 0),
            'negative_guarantees' => [
                'cms_publish' => false,
                'search_channel_submit' => false,
                'indexing_request' => false,
            ],
        ];
    }

    private function runPublishCanary(string $packagePath, string $delegatedEvidencePath, string $packageSha, string $subjectRef, bool $execute): array
    {
        $command = $this->getApplication()?->find('seo-agent:cms-publish-canary');
        if ($command === null) {
            return $this->failureSummary('cms_publish_canary_command_missing');
        }

        $input = [
            'command' => 'seo-agent:cms-publish-canary',
            '--package' => $packagePath,
            '--draft-write-evidence' => $delegatedEvidencePath,
            '--limit' => 1,
            '--subject-ref' => $subjectRef,
            '--json' => true,
        ];
        if ($execute) {
            $input['--confirm-package-sha256'] = $packageSha;
            $input['--auto-approve-low-risk'] = true;
            $input['--execute'] = true;
        }

        $buffer = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput($input), $buffer);
        $summary = json_decode(trim($buffer->fetch()), true);
        if (! is_array($summary)) {
            return $this->failureSummary('cms_publish_canary_summary_json_invalid', [
                'publish_exit_code' => $exitCode,
            ]);
        }
        $summary['publish_exit_code'] = $exitCode;

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $publishSummary
     * @param  array<string, mixed>  $candidate
     */
    private function publishedSafePath(array $publishSummary, array $candidate): string
    {
        foreach ((array) ($publishSummary['affected_refs'] ?? []) as $ref) {
            if (is_array($ref) && (string) ($ref['safe_path'] ?? '') !== '') {
                return (string) $ref['safe_path'];
            }
        }

        return (string) ($candidate['safe_path'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $publishSummary
     * @return array<string, mixed>
     */
    private function safePublishSummary(array $publishSummary): array
    {
        return [
            'schema_version' => (string) ($publishSummary['schema_version'] ?? self::PUBLISH_SCHEMA_VERSION),
            'ok' => (bool) ($publishSummary['ok'] ?? false),
            'status' => (string) ($publishSummary['status'] ?? ''),
            'dry_run' => (bool) ($publishSummary['dry_run'] ?? false),
            'execute' => (bool) ($publishSummary['execute'] ?? false),
            'would_publish' => (bool) ($publishSummary['would_publish'] ?? false),
            'planned_count' => (int) ($publishSummary['planned_count'] ?? 0),
            'published_count' => (int) ($publishSummary['published_count'] ?? 0),
            'rows_skipped_existing' => (int) ($publishSummary['rows_skipped_existing'] ?? 0),
            'affected_refs' => array_values((array) ($publishSummary['affected_refs'] ?? [])),
            'rollback_evidence' => (array) ($publishSummary['rollback_evidence'] ?? []),
            'boundaries' => (array) ($publishSummary['boundaries'] ?? []),
            'issues' => array_values(array_map('strval', (array) ($publishSummary['issues'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function sanitizedCandidate(array $candidate): array
    {
        return [
            'source_id' => (string) ($candidate['source_id'] ?? ''),
            'source_family' => (string) ($candidate['source_family'] ?? ''),
            'subject_type' => (string) ($candidate['subject_type'] ?? ''),
            'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'target_model' => (string) ($candidate['target_model'] ?? ''),
            'safe_path' => (string) ($candidate['safe_path'] ?? ''),
            'severity' => (string) ($candidate['severity'] ?? ''),
            'gap_codes' => array_values(array_map('strval', (array) ($candidate['gap_codes'] ?? []))),
            'target_fields' => array_values(array_map('strval', (array) ($candidate['target_fields'] ?? []))),
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
            'boundaries' => $this->boundaries(false),
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

        return $this->artifactRef($path, (string) ($payload['schema_version'] ?? 'unknown'));
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
     * @return array<string, bool>
     */
    private function boundaries(bool $published): array
    {
        return [
            'cms_publish' => $published,
            'published_revision_mutation' => $published,
            'live_published_content_mutation' => $published,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexnow_live_submit' => false,
            'indexing_request' => false,
            'google_indexing_api_call' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
            'frontend_code_mutation' => false,
        ];
    }
}
