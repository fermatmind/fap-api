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

final class SeoAgentL5aCmsDraftWriteCanaryCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-l5a-cms-draft-write-canary.v1';

    private const CANDIDATE_REVIEW_SCHEMA_VERSION = 'seo-agent-l5a-candidate-review.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const DRAFT_WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    protected $signature = 'seo-agent:l5a-cms-draft-write-canary
        {--candidate-review= : Path to seo-agent-l5a-candidate-review.v1 JSON artifact}
        {--limit=1 : Maximum candidate count, fixed to 1 for canary1}
        {--artifact-dir= : Directory for L5-A draft write canary artifacts}
        {--confirm-candidate-review-sha256= : Required candidate review sha256 for execute mode}
        {--auto-approve-low-risk : Required with --execute; delegates only to low-risk draft writer mode}
        {--execute : Actually create at most one CMS draft revision}
        {--json : Emit JSON summary}';

    protected $description = 'Turn one reviewed L5-A low-risk content_page candidate into a bounded CMS draft write canary.';

    public function handle(): int
    {
        $candidateReviewPath = $this->candidateReviewPath();
        if ($candidateReviewPath === null) {
            return $this->finish($this->failureSummary('candidate_review_unreadable'));
        }

        $limit = $this->limit();
        if ($limit !== 1) {
            return $this->finish($this->failureSummary('limit_must_be_one'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $candidateReviewRaw = (string) file_get_contents($candidateReviewPath);
        $forbidden = $this->forbiddenStringsPresent($candidateReviewRaw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_candidate_review_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $candidateReview = json_decode($candidateReviewRaw, true);
        if (! is_array($candidateReview) || ($candidateReview['schema_version'] ?? null) !== self::CANDIDATE_REVIEW_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('candidate_review_schema_invalid'));
        }
        if (($candidateReview['status'] ?? null) !== 'success' || (int) ($candidateReview['selected_count'] ?? 0) < 1) {
            return $this->finish($this->failureSummary('candidate_review_not_success'));
        }

        $candidate = $this->selectedCandidate($candidateReview);
        $candidateIssue = $this->validateCandidate($candidate);
        if ($candidateIssue !== null) {
            return $this->finish($this->failureSummary($candidateIssue));
        }

        $sourcePackagePath = $this->sourcePackagePath($candidateReview);
        if ($sourcePackagePath === null) {
            return $this->finish($this->failureSummary('source_draft_package_missing'));
        }

        $sourcePackage = $this->readJson($sourcePackagePath);
        if (($sourcePackage['schema_version'] ?? null) !== self::PACKAGE_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('source_draft_package_schema_invalid'));
        }

        $proposal = $this->matchingProposal($sourcePackage, $candidate);
        if ($proposal === null) {
            return $this->finish($this->failureSummary('selected_candidate_proposal_missing'));
        }

        $filteredPackage = $this->filteredPackage($sourcePackage, $proposal, $candidateReviewPath, $candidate);
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $filteredPackageRef = $this->writeArtifact($artifactDir, 'seo-agent-l5a-cms-draft-write-canary-package-'.$timestamp.'.json', $filteredPackage);
        $filteredPackagePath = (string) $filteredPackageRef['path'];
        $filteredPackageSha = (string) $filteredPackageRef['sha256'];

        $execute = (bool) $this->option('execute');
        if ($execute) {
            $approvalIssue = $this->executionApprovalIssue($candidateReviewPath);
            if ($approvalIssue !== null) {
                return $this->finish($this->failureSummary($approvalIssue, [
                    'candidate_review_sha256' => hash_file('sha256', $candidateReviewPath) ?: '',
                    'filtered_package' => $filteredPackageRef,
                ]));
            }
        }

        $draftWriteSummary = $this->runDraftWriter($filteredPackagePath, $execute);
        $writeOk = ($draftWriteSummary['ok'] ?? false) === true
            && in_array((string) ($draftWriteSummary['status'] ?? ''), ['planned', 'success'], true);

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-L5A-CMS-DRAFT-WRITE-CANARY1-01',
            'status' => $writeOk ? ($execute ? 'success' : 'planned') : 'blocked',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'limit' => 1,
            'candidate_review' => $this->artifactRef($candidateReviewPath, self::CANDIDATE_REVIEW_SCHEMA_VERSION),
            'source_package' => $this->artifactRef($sourcePackagePath, self::PACKAGE_SCHEMA_VERSION),
            'filtered_package' => $filteredPackageRef,
            'selected_candidate' => $this->sanitizedCandidate($candidate),
            'planned_count' => (int) ($draftWriteSummary['planned_count'] ?? 1),
            'rows_created' => (int) ($draftWriteSummary['rows_created'] ?? 0),
            'rows_skipped_existing' => (int) ($draftWriteSummary['rows_skipped_existing'] ?? 0),
            'rows_failed' => array_values((array) ($draftWriteSummary['rows_failed'] ?? [])),
            'draft_refs' => $this->draftRefs($draftWriteSummary),
            'idempotency_key' => $filteredPackageSha,
            'rollback_pointer' => $this->rollbackPointer($candidate, $draftWriteSummary),
            'draft_write' => $this->draftWriteEvidenceSummary($draftWriteSummary),
            'allowed_actions' => $execute ? ['cms_draft_revision_write'] : ['cms_draft_write_plan'],
            'blocked_actions' => [
                'cms_publish',
                'search_channel_queue_write',
                'search_channel_submit',
                'indexnow_live_submit',
                'google_indexing_api_call',
                'scheduler_activation',
                'queue_worker_activation',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-l5a-cms-draft-write-canary-'.$timestamp.'.json', $evidence);

        if (! $writeOk) {
            return $this->finish($this->failureSummary('cms_draft_write_canary_failed', [
                'artifact' => $artifact,
                'draft_write' => $this->draftWriteEvidenceSummary($draftWriteSummary),
            ]));
        }

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $execute ? 'success' : 'planned',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'planned_count' => (int) ($evidence['planned_count'] ?? 0),
            'rows_created' => (int) ($evidence['rows_created'] ?? 0),
            'rows_skipped_existing' => (int) ($evidence['rows_skipped_existing'] ?? 0),
            'draft_refs' => $evidence['draft_refs'],
            'idempotency_key' => $filteredPackageSha,
            'rollback_pointer' => $evidence['rollback_pointer'],
            'artifact' => $artifact,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function candidateReviewPath(): ?string
    {
        $path = trim((string) $this->option('candidate-review'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function limit(): ?int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        return is_int($limit) ? $limit : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/l5a-cms-draft-write-canary');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $candidateReview
     * @return array<string, mixed>
     */
    private function selectedCandidate(array $candidateReview): array
    {
        $candidate = $candidateReview['selected_candidate'] ?? null;
        if (is_array($candidate)) {
            return $candidate;
        }

        $candidates = array_values(array_filter(
            (array) ($candidateReview['selected_candidates'] ?? []),
            static fn ($item): bool => is_array($item)
        ));

        return (array) ($candidates[0] ?? []);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function validateCandidate(array $candidate): ?string
    {
        if ((string) ($candidate['target_model'] ?? '') !== 'content_page'
            || (string) ($candidate['subject_type'] ?? '') !== 'content_page') {
            return 'selected_candidate_not_content_page';
        }
        if (! in_array((string) ($candidate['source_family'] ?? ''), ['cms_tdk_gap', 'cms_faq_gap'], true)) {
            return 'selected_candidate_source_family_not_low_risk';
        }
        if (! in_array((string) ($candidate['severity'] ?? ''), ['p1', 'p2'], true)) {
            return 'selected_candidate_severity_not_low_risk';
        }
        $safePath = (string) ($candidate['safe_path'] ?? '');
        if ($safePath === '' || preg_match('#https?://#i', $safePath) === 1) {
            return 'selected_candidate_safe_path_invalid';
        }
        if ($this->forbiddenStringsPresent(json_encode($candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') !== []) {
            return 'selected_candidate_forbidden_field_present';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidateReview
     */
    private function sourcePackagePath(array $candidateReview): ?string
    {
        $path = trim((string) data_get($candidateReview, 'input_artifacts.cms_draft_package_dry_run.path'));
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
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
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function matchingProposal(array $package, array $candidate): ?array
    {
        $items = array_values(array_filter(
            (array) ($package['proposal_items'] ?? $package['draft_briefs'] ?? []),
            static fn ($item): bool => is_array($item)
        ));

        foreach ($items as $item) {
            if ((string) ($item['source_id'] ?? '') !== ''
                && (string) ($item['source_id'] ?? '') === (string) ($candidate['source_id'] ?? '')) {
                return $item;
            }
        }

        foreach ($items as $item) {
            if ((string) ($item['subject_ref'] ?? '') === (string) ($candidate['subject_ref'] ?? '')
                && (string) ($item['safe_path'] ?? '') === (string) ($candidate['safe_path'] ?? '')) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $sourcePackage
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function filteredPackage(array $sourcePackage, array $proposal, string $candidateReviewPath, array $candidate): array
    {
        $package = $sourcePackage;
        $package['draft_brief_count'] = 1;
        $package['draft_briefs'] = [$proposal];
        $package['proposal_count'] = 1;
        $package['proposal_items'] = [$proposal];
        $package['l5a_canary'] = [
            'task' => 'SEO-AGENT-L5A-CMS-DRAFT-WRITE-CANARY1-01',
            'source_candidate_review_sha256' => hash_file('sha256', $candidateReviewPath) ?: '',
            'selected_subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'selected_safe_path' => (string) ($candidate['safe_path'] ?? ''),
            'max_rows_per_execution' => 1,
            'publish_allowed' => false,
            'search_channel_enqueue_allowed' => false,
            'indexnow_live_submit_allowed' => false,
        ];

        return $package;
    }

    /**
     * @return array<string, mixed>
     */
    private function runDraftWriter(string $packagePath, bool $execute): array
    {
        $command = $this->getApplication()?->find('seo-agent:cms-draft-write');
        if ($command === null) {
            return $this->failureSummary('cms_draft_write_command_missing');
        }

        $input = [
            'command' => 'seo-agent:cms-draft-write',
            '--package' => $packagePath,
            '--limit' => 1,
            '--json' => true,
        ];
        if ($execute) {
            $input['--auto-approve-low-risk'] = true;
            $input['--execute'] = true;
        }

        $buffer = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput($input), $buffer);
        $summary = json_decode(trim($buffer->fetch()), true);
        if (! is_array($summary)) {
            return $this->failureSummary('cms_draft_write_summary_json_invalid', [
                'draft_write_exit_code' => $exitCode,
            ]);
        }
        $summary['draft_write_exit_code'] = $exitCode;

        return $summary;
    }

    private function executionApprovalIssue(string $candidateReviewPath): ?string
    {
        if ((bool) $this->option('auto-approve-low-risk') !== true) {
            return 'execute_requires_auto_approve_low_risk';
        }
        $expectedSha = hash_file('sha256', $candidateReviewPath) ?: '';
        if ((string) $this->option('confirm-candidate-review-sha256') !== $expectedSha) {
            return 'candidate_review_sha256_confirmation_mismatch';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draftWriteSummary
     * @return list<array<string, mixed>>
     */
    private function draftRefs(array $draftWriteSummary): array
    {
        $refs = [];
        foreach ((array) ($draftWriteSummary['affected_refs'] ?? []) as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $refs[] = [
                'status' => (string) ($ref['status'] ?? ''),
                'target_model' => (string) ($ref['target_model'] ?? ''),
                'subject_ref' => (string) ($ref['subject_ref'] ?? ''),
                'revision_id' => $ref['revision_id'] ?? null,
            ];
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $draftWriteSummary
     * @return array<string, mixed>
     */
    private function rollbackPointer(array $candidate, array $draftWriteSummary): array
    {
        $pageId = $this->contentPageId((string) ($candidate['subject_ref'] ?? ''));
        $page = $pageId > 0 ? ContentPage::query()->withoutGlobalScopes()->find($pageId) : null;
        $revisionId = null;
        foreach ($this->draftRefs($draftWriteSummary) as $ref) {
            if (($ref['target_model'] ?? '') === 'content_page') {
                $revisionId = $ref['revision_id'] ?? null;
                break;
            }
        }

        return [
            'available' => $page instanceof ContentPage,
            'target_model' => 'content_page',
            'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'candidate_revision_id' => is_numeric($revisionId) ? (int) $revisionId : null,
            'previous_working_revision_id' => $page instanceof ContentPage && $page->working_revision_id ? (int) $page->working_revision_id : null,
            'previous_published_revision_id' => $page instanceof ContentPage && $page->published_revision_id ? (int) $page->published_revision_id : null,
            'latest_draft_revision_id' => $this->latestDraftRevisionId($pageId),
        ];
    }

    private function contentPageId(string $subjectRef): int
    {
        $parts = explode(':', $subjectRef);

        return ($parts[0] ?? '') === 'content_page' && isset($parts[1]) && ctype_digit($parts[1])
            ? (int) $parts[1]
            : 0;
    }

    private function latestDraftRevisionId(int $pageId): ?int
    {
        if ($pageId <= 0) {
            return null;
        }

        $id = CmsTranslationRevision::query()
            ->where('content_type', 'content_page')
            ->where('content_id', $pageId)
            ->where('revision_status', CmsTranslationRevision::STATUS_DRAFT)
            ->max('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function draftWriteEvidenceSummary(array $summary): array
    {
        return [
            'schema_version' => (string) ($summary['schema_version'] ?? self::DRAFT_WRITE_SCHEMA_VERSION),
            'ok' => (bool) ($summary['ok'] ?? false),
            'status' => (string) ($summary['status'] ?? ''),
            'dry_run' => (bool) ($summary['dry_run'] ?? false),
            'execute' => (bool) ($summary['execute'] ?? false),
            'planned_count' => (int) ($summary['planned_count'] ?? 0),
            'rows_created' => (int) ($summary['rows_created'] ?? 0),
            'rows_skipped_existing' => (int) ($summary['rows_skipped_existing'] ?? 0),
            'rows_failed' => array_values((array) ($summary['rows_failed'] ?? [])),
            'writes_attempted' => (bool) ($summary['writes_attempted'] ?? false),
            'writes_committed' => (bool) ($summary['writes_committed'] ?? false),
            'affected_refs' => $this->draftRefs($summary),
            'issues' => array_values(array_map('strval', (array) ($summary['issues'] ?? []))),
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
            'negative_guarantees' => $this->negativeGuarantees(),
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
    private function negativeGuarantees(): array
    {
        return [
            'cms_publish' => false,
            'published_revision_mutation' => false,
            'live_published_content_mutation' => false,
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
