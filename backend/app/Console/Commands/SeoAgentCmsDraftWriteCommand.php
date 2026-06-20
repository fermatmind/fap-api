<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoAgentCmsDraftWriteCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const TASK = 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01';

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

    protected $signature = 'seo-agent:cms-draft-write
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--limit=1 : Maximum draft rows to create, 1..10}
        {--confirm-package-sha256= : Required package sha256 for execute mode}
        {--confirm-write= : Exact confirmation phrase for execute mode}
        {--execute : Actually create bounded CMS draft revisions}
        {--json : Emit JSON summary}';

    protected $description = 'Controlled SEO Agent CMS draft writer; defaults to dry-run and never publishes or submits search/indexing.';

    public function handle(): int
    {
        $packagePath = $this->packagePath();
        if ($packagePath === null) {
            return $this->finish($this->failureSummary('package_unreadable'));
        }

        $limit = $this->limit();
        if ($limit === null) {
            return $this->finish($this->failureSummary('limit_out_of_bounds'));
        }

        $raw = (string) file_get_contents($packagePath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $package = json_decode($raw, true);
        if (! is_array($package)) {
            return $this->finish($this->failureSummary('package_json_invalid'));
        }

        $validationIssue = $this->validatePackage($package);
        if ($validationIssue !== null) {
            return $this->finish($this->failureSummary($validationIssue));
        }

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $requiredPhrase = $this->requiredConfirmationPhrase($limit, $packageSha);
        $proposals = array_slice($this->proposalItems($package), 0, $limit);
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            return $this->finish([
                'schema_version' => self::SCHEMA_VERSION,
                'ok' => true,
                'status' => 'planned',
                'dry_run' => true,
                'execute' => false,
                'would_write' => $proposals !== [],
                'planned_count' => count($proposals),
                'max_rows_per_execution' => 10,
                'package_sha256' => $packageSha,
                'required_confirmation_phrase' => $requiredPhrase,
                'writes_attempted' => false,
                'writes_committed' => false,
                'negative_guarantees' => $this->negativeGuarantees(),
            ]);
        }

        $confirmationIssue = $this->validateConfirmation($packageSha, $requiredPhrase);
        if ($confirmationIssue !== null) {
            return $this->finish($this->failureSummary($confirmationIssue, [
                'package_sha256' => $packageSha,
                'required_confirmation_phrase' => $requiredPhrase,
            ]));
        }

        $created = [];
        $skipped = [];
        $failed = [];

        foreach ($proposals as $proposal) {
            try {
                $result = DB::transaction(fn (): array => $this->writeProposal($proposal, $packageSha));
                if (($result['status'] ?? '') === 'skipped_existing') {
                    $skipped[] = $result;
                } else {
                    $created[] = $result;
                }
            } catch (RuntimeException $exception) {
                $failed[] = [
                    'subject_ref' => (string) ($proposal['subject_ref'] ?? ''),
                    'issue' => $exception->getMessage(),
                ];
            }
        }

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $failed === [],
            'status' => $failed === [] ? 'success' : 'partial_failure',
            'dry_run' => false,
            'execute' => true,
            'package_sha256' => $packageSha,
            'writes_attempted' => true,
            'writes_committed' => $created !== [],
            'planned_count' => count($proposals),
            'rows_created' => count($created),
            'rows_skipped_existing' => count($skipped),
            'rows_failed' => $failed,
            'affected_refs' => array_values(array_merge($created, $skipped)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function packagePath(): ?string
    {
        $path = trim((string) $this->option('package'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function limit(): ?int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        return is_int($limit) && $limit >= 1 && $limit <= 10 ? $limit : null;
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
        if ((bool) ($package['claim_gate_required'] ?? false) !== true
            || (bool) ($package['human_approval_required'] ?? false) !== true) {
            return 'package_approval_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private function proposalItems(array $package): array
    {
        $items = $package['proposal_items'] ?? $package['draft_briefs'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    private function validateConfirmation(string $packageSha, string $requiredPhrase): ?string
    {
        if ((string) $this->option('confirm-package-sha256') !== $packageSha) {
            return 'package_sha256_confirmation_mismatch';
        }
        if ((string) $this->option('confirm-write') !== $requiredPhrase) {
            return 'confirm_write_phrase_mismatch';
        }

        return null;
    }

    private function requiredConfirmationPhrase(int $limit, string $packageSha): string
    {
        return 'I explicitly approve '.self::TASK.' to write at most '.$limit.' CMS draft rows from package sha256 '.$packageSha.'; no publish, no queue, no search, no indexing, no scheduler.';
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function writeProposal(array $proposal, string $packageSha): array
    {
        $targetModel = (string) ($proposal['target_model'] ?? $proposal['subject_type'] ?? '');

        return match ($targetModel) {
            'article' => $this->writeArticleRevision($proposal, $packageSha),
            'content_page' => $this->writeContentPageRevision($proposal, $packageSha),
            default => throw new RuntimeException('unsupported_target_model'),
        };
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function writeArticleRevision(array $proposal, string $packageSha): array
    {
        $articleId = $this->idFromSubjectRef((string) ($proposal['subject_ref'] ?? ''), 'article');
        $article = Article::query()->withoutGlobalScopes()->find($articleId);
        if (! $article instanceof Article) {
            throw new RuntimeException('article_not_found');
        }

        $targetFields = $this->targetFields($proposal);
        if ($this->articleRevisionExists((int) $article->id, $packageSha, $targetFields)) {
            return $this->affectedRef('skipped_existing', 'article', (string) ($proposal['subject_ref'] ?? ''), null);
        }

        $revisionNo = ((int) ArticleRevision::query()->withoutGlobalScopes()
            ->where('org_id', (int) $article->org_id)
            ->where('article_id', (int) $article->id)
            ->max('revision_no')) + 1;

        $revision = ArticleRevision::query()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'revision_no' => $revisionNo,
            'editor_admin_user_id' => null,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'content_html' => $article->content_html,
            'change_note' => 'SEO Agent controlled draft proposal',
            'payload_json' => $this->revisionPayload($proposal, $packageSha, $targetFields),
            'created_at' => Carbon::now('UTC'),
        ]);

        return $this->affectedRef('created', 'article', (string) ($proposal['subject_ref'] ?? ''), (int) $revision->id);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function writeContentPageRevision(array $proposal, string $packageSha): array
    {
        $pageId = $this->idFromSubjectRef((string) ($proposal['subject_ref'] ?? ''), 'content_page');
        $page = ContentPage::query()->withoutGlobalScopes()->find($pageId);
        if (! $page instanceof ContentPage) {
            throw new RuntimeException('content_page_not_found');
        }

        $targetFields = $this->targetFields($proposal);
        if ($this->contentPageRevisionExists((int) $page->id, $packageSha, $targetFields)) {
            return $this->affectedRef('skipped_existing', 'content_page', (string) ($proposal['subject_ref'] ?? ''), null);
        }

        $revisionNo = ((int) CmsTranslationRevision::query()
            ->where('content_type', 'content_page')
            ->where('content_id', (int) $page->id)
            ->max('revision_number')) + 1;

        $revision = CmsTranslationRevision::query()->create([
            'org_id' => (int) $page->org_id,
            'content_type' => 'content_page',
            'content_id' => (int) $page->id,
            'source_content_id' => $page->source_content_id ? (int) $page->source_content_id : null,
            'translation_group_id' => (string) $page->translation_group_id,
            'locale' => (string) $page->locale,
            'source_locale' => (string) ($page->source_locale ?: $page->locale),
            'revision_number' => $revisionNo,
            'revision_status' => CmsTranslationRevision::STATUS_DRAFT,
            'source_version_hash' => $page->source_version_hash,
            'translated_from_version_hash' => $page->translated_from_version_hash,
            'payload_json' => $this->revisionPayload($proposal, $packageSha, $targetFields),
            'supersedes_revision_id' => $page->working_revision_id ? (int) $page->working_revision_id : null,
            'created_by_admin_id' => null,
            'reviewed_at' => null,
            'approved_at' => null,
            'archived_at' => null,
            'published_at' => null,
        ]);

        return $this->affectedRef('created', 'content_page', (string) ($proposal['subject_ref'] ?? ''), (int) $revision->id);
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
     * @return array<string, mixed>
     */
    private function revisionPayload(array $proposal, string $packageSha, array $targetFields): array
    {
        return [
            'seo_agent' => [
                'task' => self::TASK,
                'package_sha256' => $packageSha,
                'subject_ref' => (string) ($proposal['subject_ref'] ?? ''),
                'target_fields' => $targetFields,
                'claim_gate_required' => true,
                'human_approval_required' => true,
                'publish_allowed' => false,
                'search_submit_allowed' => false,
                'indexing_request_allowed' => false,
            ],
            'proposal' => [
                'safe_path' => (string) ($proposal['safe_path'] ?? ''),
                'proposed_seo_title' => $proposal['proposed_seo_title'] ?? null,
                'proposed_seo_description' => $proposal['proposed_seo_description'] ?? null,
                'proposed_faq_items' => $proposal['proposed_faq_items'] ?? [],
                'proposed_canonical_path' => $proposal['proposed_canonical_path'] ?? null,
                'proposed_indexability' => $proposal['proposed_indexability'] ?? null,
            ],
        ];
    }

    /**
     * @param  list<string>  $targetFields
     */
    private function articleRevisionExists(int $articleId, string $packageSha, array $targetFields): bool
    {
        return ArticleRevision::query()->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->get(['payload_json'])
            ->contains(fn (ArticleRevision $revision): bool => $this->payloadMatches($revision->payload_json, $packageSha, $targetFields));
    }

    /**
     * @param  list<string>  $targetFields
     */
    private function contentPageRevisionExists(int $contentId, string $packageSha, array $targetFields): bool
    {
        return CmsTranslationRevision::query()
            ->where('content_type', 'content_page')
            ->where('content_id', $contentId)
            ->get(['payload_json'])
            ->contains(fn (CmsTranslationRevision $revision): bool => $this->payloadMatches($revision->payload_json, $packageSha, $targetFields));
    }

    /**
     * @param  mixed  $payload
     * @param  list<string>  $targetFields
     */
    private function payloadMatches($payload, string $packageSha, array $targetFields): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $fields = array_values(array_map('strval', (array) data_get($payload, 'seo_agent.target_fields', [])));
        sort($fields);
        $expected = $targetFields;
        sort($expected);

        return data_get($payload, 'seo_agent.task') === self::TASK
            && data_get($payload, 'seo_agent.package_sha256') === $packageSha
            && $fields === $expected;
    }

    private function affectedRef(string $status, string $targetModel, string $subjectRef, ?int $revisionId): array
    {
        return [
            'status' => $status,
            'target_model' => $targetModel,
            'subject_ref' => $subjectRef,
            'revision_id' => $revisionId,
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
