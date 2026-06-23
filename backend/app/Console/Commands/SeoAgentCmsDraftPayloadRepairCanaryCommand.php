<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class SeoAgentCmsDraftPayloadRepairCanaryCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-cms-draft-payload-repair-canary.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    private const WRITER_TASK = 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01';

    private const TASK = 'SEO-AGENT-CMS-DRAFT-PAYLOAD-REPAIR-CANARY-01';

    private const ALLOWED_MISMATCHES = [
        'proposal.proposed_internal_link_actions',
        'proposal.proposal_quality',
    ];

    private const SEO_TITLE_MAX_LENGTH = 60;

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'client_email',
        'private_key',
        'Bearer ',
        'Cookie:',
        'Set-Cookie:',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:cms-draft-payload-repair-canary
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--write-evidence= : Path to the old seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--target= : Exact article subject_ref, e.g. article:41:en}
        {--artifact-dir= : Directory for repair and compatible write evidence artifacts}
        {--override-proposed-seo-title= : Optional controlled replacement for proposal.proposed_seo_title, max 60 chars}
        {--confirm-package-sha256= : Required package sha256 for execute mode}
        {--confirm-repair= : Exact confirmation phrase for execute mode}
        {--execute : Actually append one repaired ArticleRevision draft}
        {--json : Emit JSON summary}';

    protected $description = 'Append one repaired SEO Agent article draft revision when an old draft payload only missed optional proposal quality fields.';

    public function handle(): int
    {
        $packagePath = $this->readablePath((string) $this->option('package'));
        if ($packagePath === null) {
            return $this->finish($this->failureSummary('package_unreadable'));
        }

        $writeEvidencePath = $this->readablePath((string) $this->option('write-evidence'));
        if ($writeEvidencePath === null) {
            return $this->finish($this->failureSummary('write_evidence_unreadable'));
        }

        $target = trim((string) $this->option('target'));
        if ($target === '') {
            return $this->finish($this->failureSummary('target_required'));
        }

        $packageRaw = (string) file_get_contents($packagePath);
        $writeRaw = (string) file_get_contents($writeEvidencePath);
        $forbidden = array_values(array_unique(array_merge(
            $this->forbiddenStringsPresent($packageRaw),
            $this->forbiddenStringsPresent($writeRaw)
        )));
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $package = json_decode($packageRaw, true);
        if (! is_array($package)) {
            return $this->finish($this->failureSummary('package_json_invalid'));
        }

        $writeEvidence = json_decode($writeRaw, true);
        if (! is_array($writeEvidence)) {
            return $this->finish($this->failureSummary('write_evidence_json_invalid'));
        }

        $validationIssue = $this->validateInputs($package, $writeEvidence);
        if ($validationIssue !== null) {
            return $this->finish($this->failureSummary($validationIssue));
        }

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $confirmedSha = trim((string) $this->option('confirm-package-sha256'));

        $artifactDir = $this->artifactDir();
        $proposal = $this->proposalForTarget($package, $target);
        if ($proposal === null) {
            return $this->finish($this->failureSummary('target_not_found_in_package', [
                'package_sha256' => $packageSha,
            ]));
        }
        $overrideTitle = $this->proposedSeoTitleOverride();
        if (is_array($overrideTitle)) {
            return $this->finish($this->failureSummary((string) ($overrideTitle['issue'] ?? 'override_proposed_seo_title_invalid'), [
                'package_sha256' => $packageSha,
            ]));
        }
        $effectivePackage = $package;
        $effectiveProposal = $proposal;
        $effectivePackageSha = $packageSha;
        $effectivePackageArtifact = null;
        if (is_string($overrideTitle)) {
            $effectiveProposal['proposed_seo_title'] = $overrideTitle;
            $effectivePackage = $this->packageWithProposal($package, $target, $effectiveProposal);
            $effectivePackageArtifact = $this->writeArtifact($artifactDir, 'seo-agent-cms-draft-package-repaired-', $effectivePackage);
            $effectivePackageSha = (string) ($effectivePackageArtifact['sha256'] ?? '');
        }
        if ($confirmedSha !== '' && $confirmedSha !== $effectivePackageSha) {
            return $this->finish($this->failureSummary('package_sha256_confirmation_mismatch', [
                'package_sha256' => $effectivePackageSha,
            ]));
        }

        $articleId = $this->articleIdFromTarget($target);
        if ($articleId === null) {
            return $this->finish($this->failureSummary('unsupported_or_invalid_target', [
                'target' => $target,
            ]));
        }

        $writeRef = $this->writeRefForTarget($writeEvidence, $target);
        if ($writeRef === null) {
            return $this->finish($this->failureSummary('target_not_found_in_write_evidence', [
                'target' => $target,
            ]));
        }

        $article = Article::query()->withoutGlobalScopes()->find($articleId);
        if (! $article instanceof Article) {
            return $this->finish($this->failureSummary('article_not_found', [
                'target' => $target,
            ]));
        }

        $oldRevision = $this->oldRevision($articleId, $writeRef);
        if (! $oldRevision instanceof ArticleRevision) {
            return $this->finish($this->failureSummary('old_revision_not_found', [
                'target' => $target,
            ]));
        }

        $targetFields = $this->targetFields($effectiveProposal);
        if (! $this->revisionCoreMatches($oldRevision, $packageSha, $target, $targetFields)) {
            return $this->finish($this->failureSummary('old_revision_identity_mismatch', [
                'target' => $target,
            ]));
        }

        $comparisons = $this->comparisons($effectiveProposal, is_array($oldRevision->payload_json) ? $oldRevision->payload_json : []);
        $mismatches = array_values(array_map(
            static fn (array $row): string => (string) ($row['field'] ?? ''),
            array_filter($comparisons, static fn (array $row): bool => ($row['matched'] ?? false) !== true)
        ));
        sort($mismatches);
        $allowed = self::ALLOWED_MISMATCHES;
        if (is_string($overrideTitle)) {
            $allowed[] = 'proposal.proposed_seo_title';
        }
        sort($allowed);

        if ($mismatches === []) {
            return $this->finish($this->failureSummary('old_revision_already_clean', [
                'target' => $target,
                'package_sha256' => $effectivePackageSha,
            ]));
        }
        if (array_values(array_diff($mismatches, $allowed)) !== []) {
            return $this->finish($this->failureSummary('mismatch_set_not_repairable', [
                'target' => $target,
                'package_sha256' => $effectivePackageSha,
                'mismatches' => $mismatches,
                'allowed_mismatches' => $allowed,
            ]));
        }
        if ($this->matchingCleanRepairExists($articleId, $effectivePackageSha, $target, $effectiveProposal, (int) $oldRevision->id)) {
            return $this->finish($this->failureSummary('repaired_revision_already_exists', [
                'target' => $target,
                'package_sha256' => $effectivePackageSha,
            ]));
        }

        $execute = (bool) $this->option('execute');
        $confirmationPhrase = $this->requiredConfirmationPhrase($target, $effectivePackageSha);
        if (! $execute) {
            $evidence = $this->repairEvidence($packagePath, $writeEvidencePath, $packageSha, $effectivePackageSha, $effectivePackageArtifact, $target, $article, $oldRevision, null, $mismatches, [
                'ok' => true,
                'status' => 'planned',
                'dry_run' => true,
                'execute' => false,
                'would_append_revision' => true,
                'required_confirmation_phrase' => $confirmationPhrase,
                'field_overrides' => $this->fieldOverridesEvidence($overrideTitle),
            ]);
            $artifact = $this->writeArtifact($artifactDir, 'seo-agent-cms-draft-payload-repair-canary-', $evidence);

            return $this->finish([
                ...$evidence,
                'artifact' => $artifact,
            ]);
        }

        if ($confirmedSha !== $effectivePackageSha) {
            return $this->finish($this->failureSummary('package_sha256_confirmation_mismatch', [
                'package_sha256' => $effectivePackageSha,
                'required_confirmation_phrase' => $confirmationPhrase,
            ]));
        }
        if ((string) $this->option('confirm-repair') !== $confirmationPhrase) {
            return $this->finish($this->failureSummary('confirm_repair_phrase_mismatch', [
                'package_sha256' => $packageSha,
                'required_confirmation_phrase' => $confirmationPhrase,
            ]));
        }

        try {
            $newRevision = DB::transaction(fn (): ArticleRevision => $this->appendRepairRevision($article, $oldRevision, $effectiveProposal, $effectivePackageSha, $targetFields));
        } catch (RuntimeException $exception) {
            return $this->finish($this->failureSummary($exception->getMessage(), [
                'target' => $target,
                'package_sha256' => $effectivePackageSha,
            ]));
        }

        $compatibleWriteEvidence = $this->compatibleWriteEvidence($effectivePackageSha, $target, (int) $newRevision->id);
        $compatibleArtifact = $this->writeArtifact($artifactDir, 'seo-agent-controlled-cms-draft-write-repair-', $compatibleWriteEvidence);
        $evidence = $this->repairEvidence($packagePath, $writeEvidencePath, $packageSha, $effectivePackageSha, $effectivePackageArtifact, $target, $article, $oldRevision, $newRevision, $mismatches, [
            'ok' => true,
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'would_append_revision' => false,
            'rows_created' => 1,
            'compatible_write_evidence' => $compatibleArtifact,
            'field_overrides' => $this->fieldOverridesEvidence($overrideTitle),
        ]);
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-cms-draft-payload-repair-canary-', $evidence);

        return $this->finish([
            ...$evidence,
            'artifact' => $artifact,
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
     * @return string|array{issue:string}|null
     */
    private function proposedSeoTitleOverride(): string|array|null
    {
        $raw = $this->option('override-proposed-seo-title');
        if ($raw === null) {
            return null;
        }

        $title = trim((string) $raw);
        if ($title === '' || str_contains($title, "\0")) {
            return ['issue' => 'override_proposed_seo_title_invalid'];
        }
        if ($this->forbiddenStringsPresent($title) !== []) {
            return ['issue' => 'forbidden_input_field_present'];
        }
        if (mb_strlen($title) > self::SEO_TITLE_MAX_LENGTH) {
            return ['issue' => 'override_proposed_seo_title_too_long'];
        }

        return $title;
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $writeEvidence
     */
    private function validateInputs(array $package, array $writeEvidence): ?string
    {
        if (($package['schema_version'] ?? null) !== self::PACKAGE_SCHEMA_VERSION) {
            return 'package_schema_invalid';
        }
        if ((bool) ($package['dry_run'] ?? false) !== true
            || (bool) ($package['cms_write_allowed'] ?? true) !== false
            || (bool) ($package['execution_permission'] ?? true) !== false) {
            return 'package_boundary_invalid';
        }
        if (($writeEvidence['schema_version'] ?? null) !== self::WRITE_SCHEMA_VERSION) {
            return 'write_evidence_schema_invalid';
        }
        if ((bool) ($writeEvidence['execute'] ?? false) !== true
            || (bool) ($writeEvidence['writes_attempted'] ?? false) !== true) {
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
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function packageWithProposal(array $package, string $target, array $proposal): array
    {
        foreach (['proposal_items', 'draft_briefs'] as $key) {
            if (! is_array($package[$key] ?? null)) {
                continue;
            }
            foreach ($package[$key] as $index => $item) {
                if (is_array($item) && (string) ($item['subject_ref'] ?? '') === $target) {
                    $package[$key][$index] = [
                        ...$item,
                        ...$proposal,
                    ];
                }
            }
        }

        return $package;
    }

    private function articleIdFromTarget(string $target): ?int
    {
        $parts = explode(':', $target);
        if (($parts[0] ?? '') !== 'article' || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return (int) $parts[1];
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
     * @param  array<string, mixed>  $writeRef
     */
    private function oldRevision(int $articleId, array $writeRef): ?ArticleRevision
    {
        $revisionId = $writeRef['revision_id'] ?? null;
        if (! is_int($revisionId) && (! is_string($revisionId) || ! ctype_digit($revisionId))) {
            return null;
        }

        return ArticleRevision::query()
            ->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->whereKey((int) $revisionId)
            ->first();
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
     * @param  list<string>  $targetFields
     */
    private function revisionCoreMatches(ArticleRevision $revision, string $packageSha, string $target, array $targetFields): bool
    {
        $payload = is_array($revision->payload_json) ? $revision->payload_json : [];
        $actual = array_values(array_map('strval', (array) data_get($payload, 'seo_agent.target_fields', [])));
        sort($actual);
        $expected = $targetFields;
        sort($expected);

        return data_get($payload, 'seo_agent.task') === self::WRITER_TASK
            && data_get($payload, 'seo_agent.package_sha256') === $packageSha
            && data_get($payload, 'seo_agent.subject_ref') === $target
            && $actual === $expected;
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
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function matchingCleanRepairExists(int $articleId, string $packageSha, string $target, array $proposal, int $oldRevisionId): bool
    {
        $targetFields = $this->targetFields($proposal);

        return ArticleRevision::query()
            ->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->where('id', '!=', $oldRevisionId)
            ->get()
            ->contains(function (ArticleRevision $revision) use ($packageSha, $target, $proposal, $targetFields): bool {
                $payload = is_array($revision->payload_json) ? $revision->payload_json : [];

                return $this->revisionCoreMatches($revision, $packageSha, $target, $targetFields)
                    && array_filter($this->comparisons($proposal, $payload), static fn (array $row): bool => ($row['matched'] ?? false) !== true) === [];
            });
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  $targetFields
     */
    private function appendRepairRevision(Article $article, ArticleRevision $oldRevision, array $proposal, string $packageSha, array $targetFields): ArticleRevision
    {
        $freshOldRevision = ArticleRevision::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $oldRevision->id)
            ->lockForUpdate()
            ->first();
        if (! $freshOldRevision instanceof ArticleRevision) {
            throw new RuntimeException('old_revision_not_found');
        }

        $revisionNo = ((int) ArticleRevision::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $article->org_id)
            ->where('article_id', (int) $article->id)
            ->lockForUpdate()
            ->max('revision_no')) + 1;

        return ArticleRevision::query()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'revision_no' => $revisionNo,
            'editor_admin_user_id' => null,
            'title' => (string) $freshOldRevision->title,
            'excerpt' => $freshOldRevision->excerpt,
            'content_md' => (string) $freshOldRevision->content_md,
            'content_html' => $freshOldRevision->content_html,
            'change_note' => 'SEO Agent controlled draft payload repair canary',
            'payload_json' => $this->revisionPayload($proposal, $packageSha, $targetFields),
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  $targetFields
     * @return array<string, mixed>
     */
    private function revisionPayload(array $proposal, string $packageSha, array $targetFields): array
    {
        return [
            'seo_agent' => [
                'task' => self::WRITER_TASK,
                'package_sha256' => $packageSha,
                'subject_ref' => (string) ($proposal['subject_ref'] ?? ''),
                'target_fields' => $targetFields,
                'claim_gate_required' => true,
                'human_approval_required' => true,
                'publish_allowed' => false,
                'search_submit_allowed' => false,
                'indexing_request_allowed' => false,
                'repair_task' => self::TASK,
            ],
            'proposal' => [
                'safe_path' => (string) ($proposal['safe_path'] ?? ''),
                'proposed_seo_title' => $proposal['proposed_seo_title'] ?? null,
                'proposed_seo_description' => $proposal['proposed_seo_description'] ?? null,
                'proposed_faq_items' => $proposal['proposed_faq_items'] ?? [],
                'proposed_canonical_path' => $proposal['proposed_canonical_path'] ?? null,
                'proposed_indexability' => $proposal['proposed_indexability'] ?? null,
                'proposed_internal_link_actions' => $proposal['proposed_internal_link_actions'] ?? [],
                'proposal_quality' => $proposal['proposal_quality'] ?? [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compatibleWriteEvidence(string $packageSha, string $target, int $revisionId): array
    {
        return [
            'schema_version' => self::WRITE_SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'approval_mode' => 'exact_human_confirmation',
            'repair_source_task' => self::TASK,
            'package_sha256' => $packageSha,
            'writes_attempted' => true,
            'writes_committed' => true,
            'planned_count' => 1,
            'rows_created' => 1,
            'rows_skipped_existing' => 0,
            'rows_failed' => [],
            'affected_refs' => [
                [
                    'status' => 'created',
                    'target_model' => 'article',
                    'subject_ref' => $target,
                    'revision_id' => $revisionId,
                ],
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  list<string>  $mismatches
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function repairEvidence(
        string $packagePath,
        string $writeEvidencePath,
        string $packageSha,
        string $effectivePackageSha,
        ?array $effectivePackageArtifact,
        string $target,
        Article $article,
        ArticleRevision $oldRevision,
        ?ArticleRevision $newRevision,
        array $mismatches,
        array $state
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            ...$state,
            'target' => $target,
            'source_package' => $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'effective_package' => $effectivePackageArtifact ?? $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'old_write_evidence' => $this->artifactRef($writeEvidencePath, self::WRITE_SCHEMA_VERSION),
            'source_package_sha256' => $packageSha,
            'package_sha256' => $effectivePackageSha,
            'repair_policy' => [
                'mode' => 'append_article_revision',
                'allowed_mismatches' => [
                    ...self::ALLOWED_MISMATCHES,
                    'proposal.proposed_seo_title',
                ],
                'seo_title_max_length' => self::SEO_TITLE_MAX_LENGTH,
                'copy_body_from_previous_draft_revision' => true,
                'mutate_old_revision' => false,
            ],
            'old_revision' => [
                'revision_id' => (int) $oldRevision->id,
                'revision_no' => (int) $oldRevision->revision_no,
                'is_published_revision' => (int) ($article->published_revision_id ?? 0) === (int) $oldRevision->id,
            ],
            'new_revision' => [
                'revision_id' => $newRevision instanceof ArticleRevision ? (int) $newRevision->id : null,
                'revision_no' => $newRevision instanceof ArticleRevision ? (int) $newRevision->revision_no : null,
                'is_published_revision' => $newRevision instanceof ArticleRevision
                    ? (int) ($article->published_revision_id ?? 0) === (int) $newRevision->id
                    : false,
            ],
            'article_state' => [
                'article_id' => (int) $article->id,
                'locale' => (string) $article->locale,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'working_revision_id' => $article->working_revision_id ? (int) $article->working_revision_id : null,
                'published_revision_id' => $article->published_revision_id ? (int) $article->published_revision_id : null,
                'published_revision_unchanged' => true,
            ],
            'mismatches_repaired' => $mismatches,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fieldOverridesEvidence(?string $overrideTitle): ?array
    {
        if ($overrideTitle === null) {
            return null;
        }

        return [
            'proposal.proposed_seo_title' => [
                'length' => mb_strlen($overrideTitle),
                'max_length' => self::SEO_TITLE_MAX_LENGTH,
                'sha256' => hash('sha256', $overrideTitle),
            ],
        ];
    }

    private function requiredConfirmationPhrase(string $target, string $packageSha): string
    {
        return 'I explicitly approve '.self::TASK.' to append 1 repaired CMS draft revision for '.$target.' from package sha256 '.$packageSha.'; no publish, no queue, no search, no indexing, no scheduler.';
    }

    private function artifactDir(): string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/cms-draft-payload-repair-canary');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $dir, string $prefix, array $payload): array
    {
        $path = rtrim($dir, '/').'/'.$prefix.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $this->artifactRef($path, (string) ($payload['schema_version'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRef(string $path, string $schemaVersion): array
    {
        return [
            'path' => $path,
            'size' => is_file($path) ? filesize($path) : null,
            'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            'schema_version' => $schemaVersion,
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
     * @param  mixed  $value
     * @return mixed
     */
    private function canonicalComparableValue($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalComparableValue($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalComparableValue($item), $value);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $strings = array_values(array_map('strval', $values));
        sort($strings);

        return $strings;
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
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
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
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
        ];
    }
}
