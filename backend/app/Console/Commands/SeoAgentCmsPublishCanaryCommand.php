<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoAgentCmsPublishCanaryCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-cms-publish-canary.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const DRAFT_WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    private const TASK = 'SEO-AGENT-CMS-PUBLISH-CANARY-01';

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

    private const FORBIDDEN_CLAIM_PATTERNS = [
        '/\bdiagnos(e|is|tic)\b/i',
        '/\bcure(s|d)?\b/i',
        '/\bguarantee(d|s)?\b/i',
        '/\bofficial\s+(partner|partnership|endorsement)\b/i',
        '/\bclinically\s+proven\b/i',
        '/\bhiring\s+fit\b/i',
    ];

    protected $signature = 'seo-agent:cms-publish-canary
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--draft-write-evidence= : Path to a seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--limit=1 : Maximum CMS drafts to publish; first canary requires exactly 1}
        {--subject-ref= : Optional exact package proposal subject_ref to publish}
        {--confirm-package-sha256= : Required package sha256 for execute mode}
        {--confirm-publish= : Exact confirmation phrase for execute mode when not using low-risk auto approval}
        {--auto-approve-low-risk : Execute one low-risk ContentPage canary without an exact publish phrase}
        {--execute : Actually publish one bounded CMS draft}
        {--json : Emit JSON summary}';

    protected $description = 'Controlled SEO Agent CMS publish canary; publishes at most one low-risk ContentPage draft and never submits search/indexing.';

    public function handle(): int
    {
        $packagePath = $this->readablePath((string) $this->option('package'));
        $evidencePath = $this->readablePath((string) $this->option('draft-write-evidence'));
        if ($packagePath === null || $evidencePath === null) {
            return $this->finish($this->failureSummary('input_artifact_unreadable'));
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit !== 1) {
            return $this->finish($this->failureSummary('limit_must_equal_one'));
        }

        $packageRaw = (string) file_get_contents($packagePath);
        $evidenceRaw = (string) file_get_contents($evidencePath);
        $forbidden = array_values(array_unique(array_merge(
            $this->forbiddenStringsPresent($packageRaw),
            $this->forbiddenStringsPresent($evidenceRaw),
        )));
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $package = json_decode($packageRaw, true);
        $draftWrite = json_decode($evidenceRaw, true);
        if (! is_array($package) || ! is_array($draftWrite)) {
            return $this->finish($this->failureSummary('input_artifact_json_invalid'));
        }

        $packageIssue = $this->validatePackage($package);
        if ($packageIssue !== null) {
            return $this->finish($this->failureSummary($packageIssue));
        }

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $draftIssue = $this->validateDraftWriteEvidence($draftWrite, $packageSha);
        if ($draftIssue !== null) {
            return $this->finish($this->failureSummary($draftIssue, [
                'package_sha256' => $packageSha,
            ]));
        }

        $proposal = $this->selectedProposal($package, trim((string) $this->option('subject-ref')));
        if ($proposal === null) {
            return $this->finish($this->failureSummary('publish_candidate_missing', [
                'package_sha256' => $packageSha,
            ]));
        }

        $candidateIssue = $this->validateLowRiskContentPageProposal($proposal);
        if ($candidateIssue !== null) {
            return $this->finish($this->failureSummary($candidateIssue, [
                'package_sha256' => $packageSha,
                'target_model' => (string) ($proposal['target_model'] ?? $proposal['subject_type'] ?? ''),
            ]));
        }

        $requiredPhrase = $this->requiredConfirmationPhrase($packageSha);
        $execute = (bool) $this->option('execute');
        $autoApproveLowRisk = (bool) $this->option('auto-approve-low-risk');
        $plan = $this->publishPlan($proposal, $packageSha);

        if (! $execute) {
            return $this->finish([
                'schema_version' => self::SCHEMA_VERSION,
                'ok' => true,
                'status' => 'planned',
                'dry_run' => true,
                'execute' => false,
                'would_publish' => (bool) ($plan['publishable'] ?? false),
                'planned_count' => (bool) ($plan['publishable'] ?? false) ? 1 : 0,
                'max_rows_per_execution' => 1,
                'package_sha256' => $packageSha,
                'required_confirmation_phrase' => $requiredPhrase,
                'auto_approve_low_risk_available' => true,
                'plan' => $plan,
                'writes_attempted' => false,
                'writes_committed' => false,
                'boundaries' => $this->boundaries(false),
            ]);
        }

        if ((string) $this->option('confirm-package-sha256') !== $packageSha) {
            return $this->finish($this->failureSummary('package_sha256_confirmation_mismatch', [
                'package_sha256' => $packageSha,
                'required_confirmation_phrase' => $requiredPhrase,
            ]));
        }

        if (! $autoApproveLowRisk && (string) $this->option('confirm-publish') !== $requiredPhrase) {
            return $this->finish($this->failureSummary('confirm_publish_phrase_mismatch', [
                'package_sha256' => $packageSha,
                'required_confirmation_phrase' => $requiredPhrase,
            ]));
        }

        if (! (bool) ($plan['publishable'] ?? false)) {
            return $this->finish($this->failureSummary('publish_plan_not_publishable', [
                'package_sha256' => $packageSha,
                'plan' => $plan,
            ]));
        }

        $result = DB::transaction(fn (): array => $this->publishContentPageCanary($proposal, $packageSha, $autoApproveLowRisk));

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => (string) ($result['status'] ?? 'success'),
            'dry_run' => false,
            'execute' => true,
            'approval_mode' => $autoApproveLowRisk ? 'low_risk_auto_approved' : 'exact_human_confirmation',
            'package_sha256' => $packageSha,
            'writes_attempted' => true,
            'writes_committed' => (bool) ($result['writes_committed'] ?? false),
            'published_count' => (int) ($result['published_count'] ?? 0),
            'rows_skipped_existing' => (int) ($result['rows_skipped_existing'] ?? 0),
            'affected_refs' => (array) ($result['affected_refs'] ?? []),
            'rollback_evidence' => (array) ($result['rollback_evidence'] ?? []),
            'boundaries' => $this->boundaries((bool) ($result['writes_committed'] ?? false)),
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
     * @param  array<string, mixed>  $draftWrite
     */
    private function validateDraftWriteEvidence(array $draftWrite, string $packageSha): ?string
    {
        if (($draftWrite['schema_version'] ?? null) !== self::DRAFT_WRITE_SCHEMA_VERSION) {
            return 'draft_write_evidence_schema_invalid';
        }
        if (($draftWrite['status'] ?? null) !== 'success') {
            return 'draft_write_evidence_not_success';
        }
        if ((string) ($draftWrite['package_sha256'] ?? '') !== $packageSha) {
            return 'draft_write_package_sha_mismatch';
        }
        if ((bool) ($draftWrite['writes_attempted'] ?? false) !== true) {
            return 'draft_write_not_executed';
        }
        if ((bool) data_get($draftWrite, 'negative_guarantees.cms_publish', true) !== false
            || (bool) data_get($draftWrite, 'negative_guarantees.search_channel_submit', true) !== false
            || (bool) data_get($draftWrite, 'negative_guarantees.indexing_request', true) !== false) {
            return 'draft_write_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>|null
     */
    private function selectedProposal(array $package, string $subjectRef = ''): ?array
    {
        $items = $package['proposal_items'] ?? $package['draft_briefs'] ?? [];
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (is_array($item) && ($subjectRef === '' || (string) ($item['subject_ref'] ?? '') === $subjectRef)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function validateLowRiskContentPageProposal(array $proposal): ?string
    {
        $targetModel = (string) ($proposal['target_model'] ?? $proposal['subject_type'] ?? '');
        if ($targetModel === 'article') {
            return 'article_publish_canary_not_supported_in_v1';
        }
        if ($targetModel !== 'content_page') {
            return 'unsupported_target_model';
        }
        if (! in_array((string) ($proposal['source_family'] ?? ''), ['cms_tdk_gap', 'cms_faq_gap'], true)) {
            return 'low_risk_source_family_not_allowed';
        }
        if (! in_array((string) ($proposal['severity'] ?? ''), ['p1', 'p2'], true)) {
            return 'low_risk_severity_not_allowed';
        }
        if ((bool) ($proposal['claim_gate_required'] ?? false) !== true
            || (bool) ($proposal['human_approval_required'] ?? false) !== true
            || (bool) ($proposal['execution_permission'] ?? false) !== false) {
            return 'proposal_boundary_invalid';
        }

        $fields = $this->targetFields($proposal);
        if ($fields === []) {
            return 'target_fields_missing';
        }

        foreach ($fields as $field) {
            if (! in_array($field, [
                'seo_title',
                'seo_description',
                'canonical_url_or_path',
                'is_indexable_or_robots',
                'faq_items',
                'faq_schema_eligible',
            ], true)) {
                return 'target_field_not_allowed';
            }
        }

        if ($this->forbiddenClaimDetected($proposal)) {
            return 'forbidden_claim_detected';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return list<string>
     */
    private function targetFields(array $proposal): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', (array) ($proposal['target_fields'] ?? [])),
            static fn (string $field): bool => $field !== ''
        )));
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function publishPlan(array $proposal, string $packageSha): array
    {
        try {
            $page = $this->contentPage($proposal);
            $revision = $this->contentPageRevision($page, $proposal, $packageSha);
        } catch (RuntimeException $exception) {
            return [
                'publishable' => false,
                'issue' => $exception->getMessage(),
            ];
        }

        $alreadyPublished = $page->published_revision_id !== null
            && (int) $page->published_revision_id === (int) $revision->id
            && (string) $revision->revision_status === CmsTranslationRevision::STATUS_PUBLISHED;

        return [
            'publishable' => true,
            'target_model' => 'content_page',
            'subject_ref' => (string) ($proposal['subject_ref'] ?? ''),
            'revision_id' => (int) $revision->id,
            'already_published' => $alreadyPublished,
            'safe_path' => (string) ($proposal['safe_path'] ?? ''),
            'target_fields' => $this->targetFields($proposal),
            'rollback_snapshot_available' => true,
            'claim_gate' => 'pass_after_forbidden_claim_scan',
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function publishContentPageCanary(array $proposal, string $packageSha, bool $autoApproveLowRisk): array
    {
        $page = $this->contentPage($proposal, true);
        $revision = $this->contentPageRevision($page, $proposal, $packageSha, true);

        if ($page->published_revision_id !== null
            && (int) $page->published_revision_id === (int) $revision->id
            && (string) $revision->revision_status === CmsTranslationRevision::STATUS_PUBLISHED) {
            return [
                'status' => 'success',
                'writes_committed' => false,
                'published_count' => 0,
                'rows_skipped_existing' => 1,
                'affected_refs' => [$this->affectedRef('skipped_existing', $proposal, (int) $revision->id)],
                'rollback_evidence' => $this->rollbackEvidence($page, $revision),
            ];
        }

        $rollback = $this->rollbackEvidence($page, $revision);
        $fields = $this->targetFields($proposal);
        $updates = $this->contentPageUpdates($page, $proposal, $fields);
        $now = Carbon::now('UTC');

        $page->forceFill([
            ...$updates,
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'review_state' => 'approved',
            'legal_review_required' => false,
            'science_review_required' => false,
            'last_reviewed_at' => $now,
            'reviewer' => 'seo_agent_publish_canary',
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'operator_approved_at' => $now,
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
            'published_at' => $page->published_at ?? $now,
            'translation_status' => CmsTranslationRevision::STATUS_PUBLISHED,
        ])->save();

        $payload = is_array($revision->payload_json) ? $revision->payload_json : [];
        $revision->forceFill([
            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
            'approved_at' => $revision->approved_at ?? $now,
            'published_at' => $revision->published_at ?? $now,
            'payload_json' => [
                ...$payload,
                'seo_agent_publish_canary' => [
                    'task' => self::TASK,
                    'package_sha256' => $packageSha,
                    'approval_mode' => $autoApproveLowRisk ? 'low_risk_auto_approved' : 'exact_human_confirmation',
                    'published_at' => $now->toIso8601String(),
                    'search_submit_allowed' => false,
                    'indexing_request_allowed' => false,
                ],
            ],
        ])->save();

        return [
            'status' => 'success',
            'writes_committed' => true,
            'published_count' => 1,
            'rows_skipped_existing' => 0,
            'affected_refs' => [$this->affectedRef('published', $proposal, (int) $revision->id)],
            'rollback_evidence' => $rollback,
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function contentPage(array $proposal, bool $lock = false): ContentPage
    {
        $pageId = $this->idFromSubjectRef((string) ($proposal['subject_ref'] ?? ''), 'content_page');
        $query = ContentPage::query()->withoutGlobalScopes()->whereKey($pageId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $page = $query->first();
        if (! $page instanceof ContentPage) {
            throw new RuntimeException('content_page_not_found');
        }
        if ((string) $page->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $page->is_public) {
            throw new RuntimeException('content_page_must_already_be_public_for_canary_v1');
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function contentPageRevision(ContentPage $page, array $proposal, string $packageSha, bool $lock = false): CmsTranslationRevision
    {
        $targetFields = $this->targetFields($proposal);
        sort($targetFields);
        $query = CmsTranslationRevision::query()
            ->where('content_type', 'content_page')
            ->where('content_id', (int) $page->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        $revision = $query->get()->first(function (CmsTranslationRevision $revision) use ($packageSha, $targetFields): bool {
            $payload = is_array($revision->payload_json) ? $revision->payload_json : [];
            $fields = array_values(array_map('strval', (array) data_get($payload, 'seo_agent.target_fields', [])));
            sort($fields);

            return data_get($payload, 'seo_agent.task') === 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01'
                && data_get($payload, 'seo_agent.package_sha256') === $packageSha
                && $fields === $targetFields;
        });

        if (! $revision instanceof CmsTranslationRevision) {
            throw new RuntimeException('seo_agent_content_page_draft_revision_not_found');
        }
        if (! in_array((string) $revision->revision_status, [CmsTranslationRevision::STATUS_DRAFT, CmsTranslationRevision::STATUS_APPROVED, CmsTranslationRevision::STATUS_PUBLISHED], true)) {
            throw new RuntimeException('draft_revision_status_not_publishable');
        }

        return $revision;
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
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function contentPageUpdates(ContentPage $page, array $proposal, array $fields): array
    {
        $updates = [];
        if (in_array('seo_title', $fields, true)) {
            $title = trim((string) ($proposal['proposed_seo_title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('proposed_seo_title_missing');
            }
            $updates['seo_title'] = $title;
        }
        if (in_array('seo_description', $fields, true)) {
            $description = trim((string) ($proposal['proposed_seo_description'] ?? ''));
            if ($description === '') {
                throw new RuntimeException('proposed_seo_description_missing');
            }
            $updates['seo_description'] = $description;
            if (trim((string) ($page->meta_description ?? '')) === '') {
                $updates['meta_description'] = $description;
            }
        }
        if (in_array('canonical_url_or_path', $fields, true)) {
            $canonical = trim((string) ($proposal['proposed_canonical_path'] ?? $proposal['safe_path'] ?? ''));
            if ($canonical === '' || ! str_starts_with($canonical, '/') || str_starts_with($canonical, '//')) {
                throw new RuntimeException('proposed_canonical_path_invalid');
            }
            $updates['canonical_path'] = $canonical;
        }
        if (in_array('is_indexable_or_robots', $fields, true)) {
            $updates['is_indexable'] = true;
        }
        if (in_array('faq_items', $fields, true)) {
            $faqItems = array_values(array_filter((array) ($proposal['proposed_faq_items'] ?? []), static fn ($item): bool => is_array($item)));
            if ($faqItems === []) {
                throw new RuntimeException('proposed_faq_items_missing');
            }
            $updates['faq_items'] = $faqItems;
        }
        if (in_array('faq_schema_eligible', $fields, true)) {
            $updates['faq_schema_eligible'] = true;
            $updates['schema_eligibility_reviewed_at'] = Carbon::now('UTC');
        }

        return $updates;
    }

    private function requiredConfirmationPhrase(string $packageSha): string
    {
        return 'I explicitly approve '.self::TASK.' to publish at most 1 CMS draft from package sha256 '.$packageSha.'; no queue, no search, no indexing, no scheduler.';
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function affectedRef(string $status, array $proposal, int $revisionId): array
    {
        return [
            'status' => $status,
            'target_model' => 'content_page',
            'subject_ref' => (string) ($proposal['subject_ref'] ?? ''),
            'revision_id' => $revisionId,
            'safe_path' => (string) ($proposal['safe_path'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackEvidence(ContentPage $page, CmsTranslationRevision $revision): array
    {
        return [
            'available' => true,
            'content_page_ref' => 'content_page:'.(int) $page->id.':'.(string) $page->locale,
            'previous_revision_id' => $page->published_revision_id ? (int) $page->published_revision_id : null,
            'candidate_revision_id' => (int) $revision->id,
            'previous_state' => [
                'status' => (string) $page->status,
                'is_public' => (bool) $page->is_public,
                'is_indexable' => (bool) $page->is_indexable,
                'seo_title_hash' => hash('sha256', (string) ($page->seo_title ?? '')),
                'seo_description_hash' => hash('sha256', (string) ($page->seo_description ?? '')),
                'meta_description_hash' => hash('sha256', (string) ($page->meta_description ?? '')),
                'canonical_path' => (string) ($page->canonical_path ?? ''),
                'faq_items_count' => count((array) ($page->faq_items ?? [])),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function forbiddenClaimDetected(array $proposal): bool
    {
        $text = implode("\n", array_filter([
            (string) ($proposal['proposed_seo_title'] ?? ''),
            (string) ($proposal['proposed_seo_description'] ?? ''),
            json_encode($proposal['proposed_faq_items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        ]));

        foreach (self::FORBIDDEN_CLAIM_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
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
            'writes_attempted' => false,
            'writes_committed' => false,
            'boundaries' => $this->boundaries(false),
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
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
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
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
        ];
    }
}
