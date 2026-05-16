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
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ArticlePublishControlled extends Command
{
    protected $signature = 'articles:publish-controlled
        {--article=* : Article id to publish}
        {--confirm= : Exact user confirmation phrase}
        {--ack-claim-warning=* : Article id whose boundary-context claim warnings are acknowledged}
        {--make-indexable : Mark the article and SEO meta indexable during publish}
        {--dry-run : Run preflight without publishing}
        {--json : Emit a JSON summary}';

    protected $description = 'Publish reviewed article drafts only behind an explicit controlled Codex-assisted publish confirmation.';

    public function handle(ArticlePublishService $publisher, AuditLogger $auditLogger): int
    {
        $articleIds = $this->articleIds();
        $acknowledgedWarnings = $this->acknowledgedWarningIds();
        $dryRun = (bool) $this->option('dry-run');
        $makeIndexable = (bool) $this->option('make-indexable');
        $expectedConfirmation = $this->expectedConfirmation($articleIds);
        $confirmation = trim((string) $this->option('confirm'));

        $plans = [];
        $errors = [];

        if ($articleIds === []) {
            $errors[] = $this->issue('article', 'missing_article', 'At least one --article id is required.');
        }

        if (! $dryRun && ! hash_equals($expectedConfirmation, $confirmation)) {
            $errors[] = $this->issue(
                'confirm',
                'confirmation_mismatch',
                'Exact confirmation phrase is required before controlled publish.',
                ['expected_confirmation' => $expectedConfirmation]
            );
        }

        foreach ($articleIds as $articleId) {
            $plan = $this->preflightArticle($articleId, $acknowledgedWarnings, $makeIndexable);
            $plans[] = $plan;

            foreach ($plan['errors'] as $error) {
                $errors[] = $error;
            }
        }

        $summary = [
            'ok' => $errors === [],
            'dry_run' => $dryRun,
            'expected_confirmation' => $expectedConfirmation,
            'make_indexable' => $makeIndexable,
            'article_ids' => $articleIds,
            'articles' => $plans,
            'errors' => $errors,
            'published_article_ids' => [],
        ];

        if ($errors === [] && ! $dryRun) {
            $publishedIds = [];
            foreach ($plans as $plan) {
                try {
                    $publishedIds[] = $this->publishPlannedArticle($plan, $publisher, $auditLogger, $expectedConfirmation, $makeIndexable);
                } catch (RuntimeException $exception) {
                    $errors[] = $this->issue(
                        'publish',
                        'publish_preflight_failed',
                        $exception->getMessage(),
                        ['article_id' => (int) ($plan['article_id'] ?? 0)]
                    );
                    break;
                }
            }

            $summary['published_article_ids'] = $publishedIds;
            $summary['errors'] = $errors;
            $summary['ok'] = $errors === [];
        }

        $this->emitSummary($summary);

        return $summary['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<int>
     */
    private function articleIds(): array
    {
        $ids = [];
        foreach ((array) $this->option('article') as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function acknowledgedWarningIds(): array
    {
        $ids = [];
        foreach ((array) $this->option('ack-claim-warning') as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function expectedConfirmation(array $articleIds): string
    {
        $idList = implode(',', $articleIds);
        $label = count($articleIds) === 1 ? 'article id' : 'article ids';

        return "I explicitly approve Codex to publish {$label} {$idList} after preflight passes.";
    }

    /**
     * @param  list<int>  $acknowledgedWarnings
     * @return array<string,mixed>
     */
    private function preflightArticle(int $articleId, array $acknowledgedWarnings, bool $makeIndexable, bool $lockForUpdate = false): array
    {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'seoMeta', 'category', 'tags'])
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->find($articleId);

        if (! $article instanceof Article) {
            return [
                'article_id' => $articleId,
                'ok' => false,
                'errors' => [$this->issue('article', 'article_not_found', 'Article not found.', ['article_id' => $articleId])],
            ];
        }

        if ($lockForUpdate && $article->working_revision_id !== null) {
            $revision = ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->whereKey((int) $article->working_revision_id)
                ->lockForUpdate()
                ->first();

            $article->setRelation('workingRevision', $revision);
        }

        $import = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->latest('id')
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->first();

        $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
        $revision = $article->workingRevision instanceof ArticleTranslationRevision ? $article->workingRevision : null;
        $editorialPackage = is_array($seoMeta?->schema_json)
            ? (array) data_get($seoMeta->schema_json, 'editorial_package_v1', [])
            : [];
        $bodyHash = $revision instanceof ArticleTranslationRevision
            ? hash('sha256', preg_replace("/\r\n?/", "\n", trim((string) $revision->content_md)))
            : '';
        $claimStatus = (string) data_get($import?->claim_result_json, 'status', '');
        $claimMatches = is_array(data_get($import?->claim_result_json, 'matches'))
            ? (array) data_get($import?->claim_result_json, 'matches')
            : [];
        $sensitivityLevel = (string) data_get($editorialPackage, 'sensitivity_level', '');

        $errors = [];

        if ((string) $article->status === 'published' || $article->published_revision_id !== null) {
            $errors[] = $this->issue('article', 'already_published', 'Article is already published.');
        }

        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $errors[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_publishable', 'Archived or soft-deleted articles cannot be controlled-published.');
        }

        if (method_exists($article, 'trashed') && $article->trashed()) {
            $errors[] = $this->issue('article.deleted_at', 'article_soft_deleted', 'Soft-deleted articles cannot be controlled-published.');
        }

        if (! in_array((string) $article->status, ['draft', 'review_pending'], true)) {
            $errors[] = $this->issue('article.status', 'invalid_status', 'Controlled publish only accepts draft or review_pending articles.');
        }

        if ((bool) $article->is_public) {
            $errors[] = $this->issue('article.is_public', 'already_public', 'Article is already public before controlled publish.');
        }

        if (! $revision instanceof ArticleTranslationRevision) {
            $errors[] = $this->issue('working_revision', 'missing_working_revision', 'Article must have a working revision.');
        } elseif (in_array((string) $revision->revision_status, [
            ArticleTranslationRevision::STATUS_STALE,
            ArticleTranslationRevision::STATUS_ARCHIVED,
            ArticleTranslationRevision::STATUS_PUBLISHED,
        ], true)) {
            $errors[] = $this->issue('working_revision.revision_status', 'invalid_revision_status', 'Working revision status is not publishable.');
        } elseif ((string) $revision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
            $errors[] = $this->issue('working_revision.revision_status', 'revision_not_editorially_approved', 'Working revision must be editorially approved before controlled publish.');
        }

        if ($revision instanceof ArticleTranslationRevision && (string) $revision->revision_status === ArticleTranslationRevision::STATUS_APPROVED) {
            if ((int) ($revision->reviewed_by ?? 0) <= 0 || $revision->reviewed_at === null) {
                $errors[] = $this->issue('working_revision.review', 'revision_review_missing', 'Approved working revision must include review actor and timestamp.');
            }

            if ($revision->approved_at === null) {
                $errors[] = $this->issue('working_revision.approved_at', 'revision_approval_missing', 'Approved working revision must include approval timestamp.');
            }
        }

        if (! $import instanceof ArticleEditorialPackageImport) {
            $errors[] = $this->issue('import', 'missing_import_gate', 'Controlled publish requires an editorial package import gate record.');
        } elseif (! in_array((string) $import->status, [
            ArticleEditorialPackageImport::STATUS_IMPORTED,
            ArticleEditorialPackageImport::STATUS_WARNING,
        ], true)) {
            $errors[] = $this->issue('import.status', 'invalid_import_status', 'Import gate status must be imported or warning.');
        }

        if ($import instanceof ArticleEditorialPackageImport && $bodyHash !== '' && ! hash_equals((string) $import->body_hash, $bodyHash)) {
            $errors[] = $this->issue('body_hash', 'body_hash_mismatch', 'Working revision body hash does not match latest import gate hash.');
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

            if (! in_array($articleId, $acknowledgedWarnings, true)) {
                $errors[] = $this->issue('claim', 'claim_warning_ack_required', 'Boundary-context claim warnings must be explicitly acknowledged for this article.');
            }
        }

        if (in_array($sensitivityLevel, ['health_sensitive', 'ability_sensitive'], true)) {
            $errors[] = $this->issue('sensitivity_level', 'sensitive_publish_not_allowed', 'Controlled Codex publish does not allow health_sensitive or ability_sensitive content.');
        }

        if ((string) data_get($import?->media_json, 'status') !== 'complete') {
            $errors[] = $this->issue('media', 'media_incomplete', 'Cover image, alt, prompt, and style tag must be complete.');
        }

        if ((int) ($import?->references_count ?? 0) <= 0) {
            $errors[] = $this->issue('references', 'references_missing', 'References must be present before controlled publish.');
        }

        if ((string) data_get($import?->graph_json, 'status') !== 'complete') {
            $errors[] = $this->issue('graph', 'graph_incomplete', 'Graph metadata must be complete before controlled publish.');
        }

        if (trim((string) $article->cover_image_alt) === '') {
            $errors[] = $this->issue('cover_image_alt', 'cover_alt_missing', 'Cover image alt text must be present.');
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
        }

        if (! $makeIndexable && (! (bool) $article->is_indexable || (string) ($seoMeta?->robots ?? '') !== 'index,follow')) {
            $errors[] = $this->issue('indexability', 'make_indexable_required', 'Use --make-indexable to publish SEO articles that are currently noindex drafts.');
        }

        $ctaSlots = data_get($editorialPackage, 'cta_slots', []);
        if (! is_array($ctaSlots) || count($ctaSlots) <= 0) {
            $errors[] = $this->issue('cta_slots', 'cta_slots_missing', 'Editorial package CTA slots must be present.');
        }

        $faqItems = data_get($editorialPackage, 'answer_surface_v1.faq_items', []);
        if (! is_array($faqItems) || count($faqItems) <= 0) {
            $errors[] = $this->issue('faq_items', 'faq_items_missing', 'FAQ items must be present for controlled SEO article publish.');
        }

        return [
            'article_id' => $articleId,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'title' => (string) $article->title,
            'status' => (string) $article->status,
            'working_revision_id' => $revision?->id,
            'working_revision_status' => $revision?->revision_status,
            'import_id' => $import?->id,
            'import_status' => $import?->status,
            'claim_status' => $claimStatus,
            'claim_warning_acknowledged' => in_array($articleId, $acknowledgedWarnings, true),
            'claim_matches_count' => count($claimMatches),
            'body_hash' => $bodyHash,
            'references_count' => (int) ($import?->references_count ?? 0),
            'media_status' => (string) data_get($import?->media_json, 'status', ''),
            'graph_status' => (string) data_get($import?->graph_json, 'status', ''),
            'cta_count' => is_array($ctaSlots) ? count($ctaSlots) : 0,
            'faq_count' => is_array($faqItems) ? count($faqItems) : 0,
            'make_indexable' => $makeIndexable,
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function publishPlannedArticle(
        array $plan,
        ArticlePublishService $publisher,
        AuditLogger $auditLogger,
        string $confirmation,
        bool $makeIndexable
    ): int {
        $articleId = (int) $plan['article_id'];

        $article = DB::transaction(function () use ($articleId, $plan, $publisher, $makeIndexable): Article {
            $acknowledgedWarnings = ((bool) ($plan['claim_warning_acknowledged'] ?? false)) ? [$articleId] : [];
            $revalidatedPlan = $this->preflightArticle($articleId, $acknowledgedWarnings, $makeIndexable, lockForUpdate: true);

            if (($revalidatedPlan['errors'] ?? []) !== []) {
                $codes = collect((array) $revalidatedPlan['errors'])
                    ->map(static fn (mixed $error): string => is_array($error) ? (string) ($error['code'] ?? '') : '')
                    ->filter()
                    ->implode(',');

                throw new RuntimeException('controlled publish preflight failed before publish: '.$codes);
            }

            $article = Article::query()
                ->withoutGlobalScopes()
                ->with('workingRevision')
                ->whereKey($articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article || ! $article->workingRevision instanceof ArticleTranslationRevision) {
                throw new RuntimeException('planned article disappeared before publish.');
            }

            if ($makeIndexable) {
                $article->forceFill(['is_indexable' => true])->save();

                ArticleSeoMeta::query()
                    ->withoutGlobalScopes()
                    ->where('article_id', $articleId)
                    ->update([
                        'robots' => 'index,follow',
                        'is_indexable' => true,
                    ]);
            }

            return $publisher->publishArticle($articleId, 'controlled_codex_publish');
        });

        $auditLogger->log(
            Request::create('/ops/articles/publish-controlled', 'POST'),
            'codex_controlled_article_publish',
            'article',
            (string) $articleId,
            [
                'confirmation_sha256' => hash('sha256', $confirmation),
                'article_id' => $articleId,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'body_hash' => (string) ($plan['body_hash'] ?? ''),
                'import_id' => (int) ($plan['import_id'] ?? 0),
                'claim_status' => (string) ($plan['claim_status'] ?? ''),
                'make_indexable' => $makeIndexable,
                'source' => 'controlled_codex_publish',
            ],
            reason: 'controlled_codex_article_publish',
            result: 'success',
        );

        return $articleId;
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
        $this->line('expected_confirmation='.(string) ($summary['expected_confirmation'] ?? ''));
        $this->line('make_indexable='.(($summary['make_indexable'] ?? false) ? '1' : '0'));
        $this->line('articles='.implode(',', (array) ($summary['article_ids'] ?? [])));
        $this->line('published_article_ids='.implode(',', (array) ($summary['published_article_ids'] ?? [])));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));

        foreach ((array) ($summary['articles'] ?? []) as $article) {
            if (! is_array($article)) {
                continue;
            }

            $this->line(sprintf(
                'article=%s locale=%s slug=%s ok=%s import_status=%s claim_status=%s media=%s references=%s graph=%s cta=%s faq=%s revision_status=%s',
                (string) ($article['article_id'] ?? ''),
                (string) ($article['locale'] ?? ''),
                (string) ($article['slug'] ?? ''),
                ($article['ok'] ?? false) ? '1' : '0',
                (string) ($article['import_status'] ?? ''),
                (string) ($article['claim_status'] ?? ''),
                (string) ($article['media_status'] ?? ''),
                (string) ($article['references_count'] ?? ''),
                (string) ($article['graph_status'] ?? ''),
                (string) ($article['cta_count'] ?? ''),
                (string) ($article['faq_count'] ?? ''),
                (string) ($article['working_revision_status'] ?? '')
            ));

            foreach ((array) ($article['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $this->line('article_error='.$this->issueLine($error));
                }
            }
        }

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
