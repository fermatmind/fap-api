<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticlePublishService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use RuntimeException;

final class ArticlePromoteExistingWorkingRevisionControlled extends Command
{
    protected $signature = 'articles:promote-existing-working-revision
        {--article-id= : Exact already-published article id}
        {--working-revision-id= : Exact approved working revision id to promote}
        {--current-published-revision-id= : Exact currently-published revision id lock}
        {--translation-group-id= : Expected translation group lock}
        {--expected-slug= : Expected existing slug lock}
        {--expected-canonical= : Expected canonical path or URL lock}
        {--confirm= : Exact user confirmation phrase}
        {--ack-claim-warning= : Article id whose boundary-context claim warnings are acknowledged}
        {--preview-approved : Acknowledge authenticated preview QA passed for this exact working revision}
        {--schema-hold : Confirm schema generation/enqueue stays held}
        {--hreflang-hold : Confirm hreflang enablement stays held}
        {--search-hold : Confirm search enqueue stays held}
        {--no-revalidation : Confirm no frontend/API revalidation will be triggered}
        {--no-sitemap : Confirm sitemap eligibility will not be changed}
        {--no-llms : Confirm llms eligibility will not be changed}
        {--dry-run : Validate and plan without writing}
        {--execute : Promote after exact confirmation and preflight}
        {--json : Emit a JSON summary}';

    protected $description = 'Promote an approved working revision for an already-published existing article through a controlled fail-closed runtime.';

    public function handle(ArticlePublishService $publisher, AuditLogger $auditLogger): int
    {
        $articleId = $this->positiveIntOption('article-id');
        $workingRevisionId = $this->positiveIntOption('working-revision-id');
        $currentPublishedRevisionId = $this->positiveIntOption('current-published-revision-id');
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $expectedConfirmation = $this->expectedConfirmation($articleId, $workingRevisionId);
        $confirmation = trim((string) $this->option('confirm'));

        $errors = [];

        if ($dryRun && $execute) {
            $errors[] = $this->issue('mode', 'dry_run_execute_conflict', 'Choose either --dry-run or --execute, not both.');
        }

        if (! $dryRun && ! $execute) {
            $errors[] = $this->issue('mode', 'mode_required', 'Choose --dry-run or --execute.');
        }

        foreach ([
            'article-id' => $articleId,
            'working-revision-id' => $workingRevisionId,
            'current-published-revision-id' => $currentPublishedRevisionId,
        ] as $option => $value) {
            if ($value <= 0) {
                $errors[] = $this->issue($option, 'positive_integer_required', "Option --{$option} must be a positive integer.");
            }
        }

        if ($execute && ! hash_equals($expectedConfirmation, $confirmation)) {
            $errors[] = $this->issue(
                'confirm',
                'confirmation_mismatch',
                'Exact confirmation phrase is required before existing-article promotion.',
                ['expected_confirmation' => $expectedConfirmation]
            );
        }

        if ($execute && ! (bool) $this->option('preview-approved')) {
            $errors[] = $this->issue('preview-approved', 'preview_approval_required', 'Authenticated preview QA acknowledgement is required before execute.');
        }

        if ($execute) {
            foreach ($this->requiredHoldOptions() as $option) {
                if (! (bool) $this->option($option)) {
                    $errors[] = $this->issue($option, 'required_hold_flag_missing', "Execute requires --{$option}.");
                }
            }
        }

        $plan = $articleId > 0 && $workingRevisionId > 0 && $currentPublishedRevisionId > 0
            ? $this->preflight($articleId, $workingRevisionId, $currentPublishedRevisionId)
            : [
                'article_id' => $articleId,
                'working_revision_id' => $workingRevisionId,
                'current_published_revision_id' => $currentPublishedRevisionId,
                'ok' => false,
                'errors' => [],
            ];

        foreach ((array) ($plan['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $errors[] = $error;
            }
        }

        $summary = [
            'ok' => $errors === [],
            'dry_run' => $dryRun,
            'execute' => $execute,
            'action' => $execute ? 'promote_existing_working_revision' : 'would_promote_existing_working_revision',
            'expected_confirmation' => $expectedConfirmation,
            'article_id' => $articleId,
            'working_revision_id' => $workingRevisionId,
            'current_published_revision_id' => $currentPublishedRevisionId,
            'preview_approved' => (bool) $this->option('preview-approved'),
            'hold_flags' => $this->holdFlags(),
            'plan' => $plan,
            'errors' => $errors,
            'promoted_article_id' => null,
        ];

        if ($errors === [] && $execute) {
            try {
                $article = $publisher->promoteExistingWorkingRevision(
                    $articleId,
                    $workingRevisionId,
                    $currentPublishedRevisionId,
                    'controlled_existing_article_working_revision_promotion'
                );

                $this->logPromotion($auditLogger, $article, $plan, $confirmation);

                $summary['promoted_article_id'] = (int) $article->id;
                $summary['plan'] = $this->preflight(
                    $articleId,
                    $workingRevisionId,
                    $currentPublishedRevisionId,
                    afterPromotion: true
                );
            } catch (RuntimeException|\InvalidArgumentException $exception) {
                $errors[] = $this->issue(
                    'promotion',
                    'promotion_failed',
                    $exception->getMessage(),
                    ['article_id' => $articleId, 'working_revision_id' => $workingRevisionId]
                );
                $summary['ok'] = false;
                $summary['errors'] = $errors;
            }
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function positiveIntOption(string $option): int
    {
        $value = $this->option($option);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function expectedConfirmation(int $articleId, int $workingRevisionId): string
    {
        return "I explicitly approve Codex to promote article id {$articleId} working revision {$workingRevisionId} after preflight passes.";
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight(
        int $articleId,
        int $workingRevisionId,
        int $currentPublishedRevisionId,
        bool $afterPromotion = false
    ): array {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'seoMeta', 'category', 'tags'])
            ->find($articleId);

        if (! $article instanceof Article) {
            return [
                'article_id' => $articleId,
                'ok' => false,
                'errors' => [$this->issue('article', 'article_not_found', 'Article not found.', ['article_id' => $articleId])],
            ];
        }

        $workingRevision = ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($workingRevisionId)
            ->first();
        $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
        $import = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->latest('id')
            ->first();

        $expectedTranslationGroupId = trim((string) $this->option('translation-group-id'));
        $expectedSlug = trim((string) $this->option('expected-slug'));
        $expectedCanonical = trim((string) $this->option('expected-canonical'));
        $bodyHash = $workingRevision instanceof ArticleTranslationRevision
            ? $this->bodyHash((string) $workingRevision->content_md)
            : '';
        $claimStatus = (string) data_get($import?->claim_result_json, 'status', '');
        $claimMatches = is_array(data_get($import?->claim_result_json, 'matches'))
            ? (array) data_get($import?->claim_result_json, 'matches')
            : [];
        $mediaStatus = (string) data_get($import?->media_json, 'status', '');
        $referencesStatus = (string) data_get($import?->references_json, 'status', '');
        $graphStatus = (string) data_get($import?->graph_json, 'status', '');
        $answerSurfaceStatus = (string) data_get($import?->answer_surface_json, 'status', '');

        $errors = [];
        $warnings = [];

        if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
            $errors[] = $this->issue('article.status', 'article_not_published_public', 'Existing-article promotion requires an already-published public article.');
        }

        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $errors[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_publishable', 'Archived or soft-deleted articles cannot be promoted.');
        }

        if (method_exists($article, 'trashed') && $article->trashed()) {
            $errors[] = $this->issue('article.deleted_at', 'article_soft_deleted', 'Soft-deleted articles cannot be promoted.');
        }

        if ((int) ($article->published_revision_id ?? 0) !== $currentPublishedRevisionId && ! $afterPromotion) {
            $errors[] = $this->issue('published_revision_id', 'published_revision_lock_mismatch', 'Current published revision lock does not match article state.');
        }

        if ((int) ($article->working_revision_id ?? 0) !== $workingRevisionId) {
            $errors[] = $this->issue('working_revision_id', 'working_revision_lock_mismatch', 'Working revision lock does not match article state.');
        }

        if ($workingRevisionId === $currentPublishedRevisionId && ! $afterPromotion) {
            $errors[] = $this->issue('working_revision_id', 'working_revision_not_isolated', 'Working revision must be isolated from the current published revision.');
        }

        if ($expectedTranslationGroupId === '') {
            $errors[] = $this->issue('translation-group-id', 'translation_group_lock_required', 'Expected translation group lock is required.');
        } elseif ((string) $article->translation_group_id !== $expectedTranslationGroupId) {
            $errors[] = $this->issue('translation_group_id', 'translation_group_mismatch', 'Article translation group does not match expected lock.');
        }

        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected-slug', 'slug_lock_required', 'Expected slug lock is required.');
        } elseif ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('slug', 'slug_lock_mismatch', 'Article slug does not match expected lock.');
        }

        if ($expectedCanonical === '') {
            $errors[] = $this->issue('expected-canonical', 'canonical_lock_required', 'Expected canonical lock is required.');
        } elseif (! $seoMeta instanceof ArticleSeoMeta || $this->canonicalPath((string) $seoMeta->canonical_url) !== $this->canonicalPath($expectedCanonical)) {
            $errors[] = $this->issue('canonical_url', 'canonical_lock_mismatch', 'SEO canonical does not match expected lock.');
        }

        if (! $workingRevision instanceof ArticleTranslationRevision) {
            $errors[] = $this->issue('working_revision', 'working_revision_not_found', 'Working revision not found.');
        } else {
            if ((int) $workingRevision->article_id !== (int) $article->id
                || (int) $workingRevision->org_id !== (int) $article->org_id
                || (string) $workingRevision->locale !== (string) $article->locale) {
                $errors[] = $this->issue('working_revision', 'working_revision_identity_mismatch', 'Working revision does not match article identity.');
            }

            if ((string) $workingRevision->translation_group_id !== (string) $article->translation_group_id) {
                $errors[] = $this->issue('working_revision.translation_group_id', 'working_revision_translation_group_mismatch', 'Working revision translation group does not match article.');
            }

            if (! $afterPromotion && (string) $workingRevision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
                $errors[] = $this->issue('working_revision.revision_status', 'revision_not_editorially_approved', 'Working revision must be editorially approved before promotion.');
            }

            if ($afterPromotion && (string) $workingRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
                $errors[] = $this->issue('working_revision.revision_status', 'revision_not_published_after_promotion', 'Working revision should be published after promotion.');
            }

            if (! $afterPromotion && ((int) ($workingRevision->reviewed_by ?? 0) <= 0 || $workingRevision->reviewed_at === null)) {
                $errors[] = $this->issue('working_revision.review', 'revision_review_missing', 'Approved working revision must include review actor and timestamp.');
            }

            if (! $afterPromotion && $workingRevision->approved_at === null) {
                $errors[] = $this->issue('working_revision.approved_at', 'revision_approval_missing', 'Approved working revision must include approval timestamp.');
            }

            if (trim((string) $workingRevision->title) === '') {
                $errors[] = $this->issue('working_revision.title', 'revision_title_missing', 'Working revision title must be present.');
            }

            if (trim((string) $workingRevision->content_md) === '') {
                $errors[] = $this->issue('working_revision.content_md', 'revision_body_missing', 'Working revision body must be present.');
            }
        }

        if (! $import instanceof ArticleEditorialPackageImport) {
            $errors[] = $this->issue('import', 'missing_existing_update_import_gate', 'Existing-article promotion requires a latest import gate record.');
        } else {
            if ((string) $import->content_track !== 'seo_content_package_existing_article_update') {
                $errors[] = $this->issue('import.content_track', 'invalid_existing_update_content_track', 'Latest import must come from the existing-article SEO update writer.');
            }

            if (! in_array((string) $import->status, [
                ArticleEditorialPackageImport::STATUS_IMPORTED,
                ArticleEditorialPackageImport::STATUS_WARNING,
            ], true)) {
                $errors[] = $this->issue('import.status', 'invalid_import_status', 'Import gate status must be imported or warning.');
            }

            if ((string) $import->intended_status !== 'working_revision_human_review') {
                $errors[] = $this->issue('import.intended_status', 'invalid_import_intended_status', 'Existing update import must target a human-review working revision.');
            }

            if ((string) data_get($import->validation_summary_json, 'operation') !== 'update_existing_article_working_revision') {
                $errors[] = $this->issue('import.validation_summary_json.operation', 'invalid_import_operation', 'Existing update import operation does not match promotion lane.');
            }

            if (! (bool) data_get($import->validation_summary_json, 'schema_hreflang_search_hold')) {
                $errors[] = $this->issue('import.validation_summary_json.schema_hreflang_search_hold', 'missing_downstream_hold_record', 'Import gate must record schema/hreflang/search holds.');
            }

            if ($bodyHash !== '' && ! hash_equals((string) $import->body_hash, $bodyHash)) {
                $errors[] = $this->issue('body_hash', 'body_hash_mismatch', 'Working revision body hash does not match latest import gate hash.');
            }

            if ((string) data_get($import->exactness_json, 'status') !== 'passed') {
                $errors[] = $this->issue('import.exactness_json.status', 'exactness_not_passed', 'Import exactness gate must be passed.');
            }

            if ($expectedSlug !== '' && (string) $import->slug !== $expectedSlug) {
                $errors[] = $this->issue('import.slug', 'import_slug_lock_mismatch', 'Import slug does not match expected slug lock.');
            }

            if ($expectedCanonical !== ''
                && (string) data_get($import->exactness_json, 'canonical_url', $expectedCanonical) !== ''
                && $this->canonicalPath((string) data_get($import->exactness_json, 'canonical_url')) !== $this->canonicalPath($expectedCanonical)) {
                $errors[] = $this->issue('import.exactness_json.canonical_url', 'import_canonical_lock_mismatch', 'Import canonical lock does not match expected canonical.');
            }

            if (! in_array($mediaStatus, ['complete', 'unchanged_hold'], true)) {
                $errors[] = $this->issue('import.media_json.status', 'media_gate_not_acceptable', 'Existing update media status must be complete or unchanged_hold.');
            }

            if ($referencesStatus !== 'complete' && (int) $import->references_count <= 0) {
                $warnings[] = $this->issue('import.references_json.status', 'references_operator_review_hold', 'References remain under existing-article operator-review hold.');
            }

            if (! in_array($graphStatus, ['complete', 'unchanged_hold'], true)) {
                $warnings[] = $this->issue('import.graph_json.status', 'graph_operator_review_hold', 'Graph metadata remains unchanged for existing article promotion.');
            }

            if (! in_array($answerSurfaceStatus, ['complete', 'visible_only'], true)) {
                $warnings[] = $this->issue('import.answer_surface_json.status', 'answer_surface_visible_only_hold', 'Answer surface remains visible-only for existing article promotion.');
            }
        }

        if ($claimStatus === 'blocked') {
            $errors[] = $this->issue('claim', 'claim_blocked', 'Claim linter blocked this article.');
        }

        if ($claimStatus === 'warning') {
            $allBoundaryContext = $claimMatches !== [] && collect($claimMatches)->every(
                static fn (mixed $match): bool => is_array($match) && (bool) ($match['boundary_context'] ?? false)
            );

            if (! $allBoundaryContext) {
                $errors[] = $this->issue('claim', 'claim_warning_not_boundary_context', 'Claim warnings include non-boundary context matches.');
            }

            if ((int) $this->positiveIntOption('ack-claim-warning') !== $articleId) {
                $errors[] = $this->issue('claim', 'claim_warning_ack_required', 'Boundary-context claim warnings must be explicitly acknowledged for this article.');
            }
        }

        if (trim((string) $article->cover_image_url) === '' || trim((string) $article->cover_image_alt) === '') {
            $errors[] = $this->issue('cover_image', 'cover_image_or_alt_missing', 'Article cover image URL and alt text must be present.');
        }

        if (! $article->category) {
            $errors[] = $this->issue('category', 'category_missing', 'Article category must be present.');
        }

        if ($article->tags->count() <= 0) {
            $errors[] = $this->issue('tags', 'tags_missing', 'Article tags must be present.');
        }

        if (! $seoMeta instanceof ArticleSeoMeta) {
            $errors[] = $this->issue('seo', 'seo_meta_missing', 'SEO meta must be present.');
        } else {
            foreach (['seo_title', 'seo_description', 'canonical_url', 'og_image_url'] as $field) {
                if (trim((string) $seoMeta->{$field}) === '') {
                    $errors[] = $this->issue("seo.{$field}", 'seo_field_missing', "SEO field {$field} must be present.");
                }
            }

            if (! (bool) $article->is_indexable || (string) $seoMeta->robots !== 'index,follow') {
                $errors[] = $this->issue('indexability', 'existing_article_not_indexable', 'Existing SEO article must already be indexable before promotion.');
            }
        }

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'canonical_url' => (string) ($seoMeta?->canonical_url ?? ''),
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
            'current_published_revision_id' => $currentPublishedRevisionId,
            'working_revision_id' => $workingRevisionId,
            'working_revision_status' => $workingRevision?->revision_status,
            'working_revision_body_hash' => $bodyHash,
            'import_id' => $import?->id,
            'import_status' => $import?->status,
            'import_content_track' => $import?->content_track,
            'claim_status' => $claimStatus,
            'claim_warning_acknowledged' => (int) $this->positiveIntOption('ack-claim-warning') === $articleId,
            'media_status' => $mediaStatus,
            'references_status' => $referencesStatus,
            'references_count' => (int) ($import?->references_count ?? 0),
            'graph_status' => $graphStatus,
            'answer_surface_status' => $answerSurfaceStatus,
            'after_promotion' => $afterPromotion,
            'ok' => $errors === [],
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredHoldOptions(): array
    {
        return ['schema-hold', 'hreflang-hold', 'search-hold', 'no-revalidation', 'no-sitemap', 'no-llms'];
    }

    /**
     * @return array<string,bool>
     */
    private function holdFlags(): array
    {
        $flags = [];
        foreach ($this->requiredHoldOptions() as $option) {
            $flags[$option] = (bool) $this->option($option);
        }

        return $flags;
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
    }

    private function canonicalPath(string $canonical): string
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return '';
        }

        $path = (string) (parse_url($canonical, PHP_URL_PATH) ?: $canonical);
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function logPromotion(AuditLogger $auditLogger, Article $article, array $plan, string $confirmation): void
    {
        $auditLogger->log(
            Request::create('/ops/articles/promote-existing-working-revision', 'POST'),
            'codex_controlled_existing_article_working_revision_promotion',
            'article',
            (string) $article->id,
            [
                'confirmation_sha256' => hash('sha256', $confirmation),
                'article_id' => (int) $article->id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'working_revision_id' => (int) ($plan['working_revision_id'] ?? 0),
                'previous_published_revision_id' => (int) ($plan['current_published_revision_id'] ?? 0),
                'new_published_revision_id' => (int) ($article->published_revision_id ?? 0),
                'body_hash' => (string) ($plan['working_revision_body_hash'] ?? ''),
                'import_id' => (int) ($plan['import_id'] ?? 0),
                'claim_status' => (string) ($plan['claim_status'] ?? ''),
                'hold_flags' => $this->holdFlags(),
                'preview_approved' => (bool) $this->option('preview-approved'),
                'source' => 'controlled_existing_article_working_revision_promotion',
            ],
            reason: 'controlled_existing_article_working_revision_promotion',
            result: 'success',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('execute='.(($summary['execute'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? ''));
        $this->line('article_id='.(string) ($summary['article_id'] ?? ''));
        $this->line('working_revision_id='.(string) ($summary['working_revision_id'] ?? ''));
        $this->line('current_published_revision_id='.(string) ($summary['current_published_revision_id'] ?? ''));
        $this->line('expected_confirmation='.(string) ($summary['expected_confirmation'] ?? ''));
        $this->line('promoted_article_id='.(string) ($summary['promoted_article_id'] ?? ''));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('error='.$this->issueLine($error));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return implode('|', array_filter([
            'field='.(string) ($issue['field'] ?? ''),
            'code='.(string) ($issue['code'] ?? ''),
            'message='.(string) ($issue['message'] ?? ''),
        ]));
    }
}
