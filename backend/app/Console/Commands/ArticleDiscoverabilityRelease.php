<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ArticleDiscoverabilityRelease extends Command
{
    protected $signature = 'articles:discoverability-release
        {--article-id= : Exact article id to lock}
        {--expected-slug= : Expected article slug lock}
        {--confirm= : Exact user confirmation phrase for execute mode}
        {--dry-run : Validate and plan without writing}
        {--execute : Release sitemap/llms eligibility}
        {--json : Emit a JSON summary}
        {--no-content-change : Required execute-mode hold: do not modify article content}
        {--no-publish : Required execute-mode hold: do not publish or promote}
        {--no-search : Required execute-mode hold: do not submit search channels}
        {--no-schema-hreflang : Required execute-mode hold: do not modify schema or hreflang gates}
        {--no-revalidation : Required execute-mode hold: do not revalidate frontend paths}';

    protected $description = 'Safely release sitemap and llms eligibility for one already-published locked article.';

    public function handle(AuditLogger $auditLogger): int
    {
        try {
            $summary = $this->buildSummary($auditLogger);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(AuditLogger $auditLogger): array
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $articleId = (int) $this->option('article-id');
        $expectedSlug = trim((string) $this->option('expected-slug'));
        $expectedConfirmation = $this->expectedConfirmation($articleId, $expectedSlug);
        $confirmation = trim((string) $this->option('confirm'));
        $errors = [];

        if ((bool) $this->option('dry-run') && $execute) {
            $errors[] = $this->issue('dry_run', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }
        if ($execute) {
            foreach (['no-content-change', 'no-publish', 'no-search', 'no-schema-hreflang', 'no-revalidation'] as $flag) {
                if ((bool) $this->option($flag) !== true) {
                    $errors[] = $this->issue($flag, 'required_safety_flag_missing', 'All no-side-effect safety flags are required for execute mode.');
                }
            }
            if (! hash_equals($expectedConfirmation, $confirmation)) {
                $errors[] = $this->issue(
                    'confirm',
                    'confirmation_mismatch',
                    'Exact confirmation phrase is required before discoverability release.',
                    ['expected_confirmation' => $expectedConfirmation],
                );
            }
        }

        if ($articleId <= 0) {
            $errors[] = $this->issue('article_id', 'article_id_required', '--article-id is required.');
        }
        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected_slug', 'expected_slug_required', '--expected-slug is required.');
        }

        $plan = $articleId > 0 ? $this->preflight($articleId, $expectedSlug) : null;
        if ($plan === null) {
            $errors[] = $this->issue('article_id', 'article_not_found', 'Article was not found.');
        } else {
            foreach ((array) ($plan['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $errors[] = $error;
                }
            }
        }

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleId, $expectedSlug, $expectedConfirmation, $plan, $errors);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_release_article_discoverability', $articleId, $expectedSlug, $expectedConfirmation, $plan, []);
        }

        $executedPlan = DB::transaction(function () use ($articleId, $expectedSlug): array {
            $lockedPlan = $this->preflight($articleId, $expectedSlug, lockForUpdate: true);
            if ($lockedPlan === null) {
                throw new RuntimeException('planned article disappeared before discoverability release.');
            }

            $errors = (array) ($lockedPlan['errors'] ?? []);
            if ($errors !== []) {
                $codes = collect($errors)
                    ->map(static fn (mixed $error): string => is_array($error) ? (string) ($error['code'] ?? '') : '')
                    ->filter()
                    ->implode(',');

                throw new RuntimeException('discoverability release preflight failed before write: '.$codes);
            }

            DB::table('articles')
                ->where('id', $articleId)
                ->update([
                    'sitemap_eligible' => true,
                    'llms_eligible' => true,
                    'updated_at' => now(),
                ]);

            return $this->preflight($articleId, $expectedSlug) ?? $lockedPlan;
        });

        $auditLogger->log(
            Request::create('/ops/articles/discoverability-release', 'POST'),
            'articles_discoverability_release',
            'article',
            (string) $articleId,
            [
                'command' => 'articles:discoverability-release',
                'article_id' => $articleId,
                'slug' => $expectedSlug,
                'confirmation_sha256' => hash('sha256', $confirmation),
                'updates_scope' => ['articles.sitemap_eligible', 'articles.llms_eligible'],
                'no_content_change' => true,
                'no_publish' => true,
                'no_search' => true,
                'no_schema_hreflang' => true,
                'no_revalidation' => true,
                'before' => $plan['before'] ?? null,
                'after' => $executedPlan['after'] ?? null,
            ],
            reason: 'controlled_article_discoverability_release',
            result: 'success',
        );

        return $this->summary(true, false, 'released_article_discoverability', $articleId, $expectedSlug, $expectedConfirmation, $executedPlan, []);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function preflight(int $articleId, string $expectedSlug, bool $lockForUpdate = false): ?array
    {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            ])
            ->when($lockForUpdate, static fn ($query) => $query->lockForUpdate())
            ->find($articleId);

        if (! $article instanceof Article) {
            return null;
        }

        $before = $this->snapshot($article);
        $after = $before;
        $after['sitemap_eligible'] = true;
        $after['llms_eligible'] = true;
        $errors = [];

        if ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('article.slug', 'expected_slug_mismatch', 'Article slug does not match expected lock.');
        }
        if ((string) $article->status !== 'published') {
            $errors[] = $this->issue('article.status', 'article_not_published', 'Article must already be published.');
        }
        if (! (bool) $article->is_public) {
            $errors[] = $this->issue('article.is_public', 'article_not_public', 'Article must already be public.');
        }
        if (! (bool) $article->is_indexable) {
            $errors[] = $this->issue('article.is_indexable', 'article_not_indexable', 'Article must already be indexable.');
        }
        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $errors[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_releasable', 'Archived or soft-deleted articles cannot be released to sitemap/llms.');
        }
        if (method_exists($article, 'trashed') && $article->trashed()) {
            $errors[] = $this->issue('article.deleted_at', 'article_soft_deleted', 'Soft-deleted articles cannot be released to sitemap/llms.');
        }

        $revision = $article->publishedRevision;
        if (! $revision instanceof ArticleTranslationRevision) {
            $errors[] = $this->issue('article.published_revision_id', 'published_revision_missing', 'Article must have a published revision.');
        } elseif ((string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $errors[] = $this->issue('published_revision.revision_status', 'published_revision_status_invalid', 'Published revision must have published status.');
        }

        $seoMeta = $article->seoMeta;
        if (! $seoMeta instanceof ArticleSeoMeta) {
            $errors[] = $this->issue('seo_meta', 'seo_meta_missing', 'Article SEO meta must exist before sitemap/llms release.');
        } else {
            if (! (bool) $seoMeta->is_indexable) {
                $errors[] = $this->issue('seo_meta.is_indexable', 'seo_meta_not_indexable', 'SEO meta must already be indexable.');
            }
            if ((string) $seoMeta->robots !== 'index,follow') {
                $errors[] = $this->issue('seo_meta.robots', 'seo_meta_robots_not_index_follow', 'SEO meta robots must be index,follow.');
            }

            $expectedCanonicalPath = $this->canonicalPathForArticle($article);
            $actualCanonicalPath = $this->pathFromCanonical((string) $seoMeta->canonical_url);
            if ($actualCanonicalPath !== $expectedCanonicalPath) {
                $errors[] = $this->issue(
                    'seo_meta.canonical_url',
                    'canonical_path_mismatch',
                    'SEO meta canonical must match the locked article route.',
                    [
                        'expected_canonical_path' => $expectedCanonicalPath,
                        'actual_canonical_path' => $actualCanonicalPath,
                    ],
                );
            }
        }

        return [
            'article_id' => $articleId,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'canonical_path' => $this->canonicalPathForArticle($article),
            'already_released' => (bool) $article->sitemap_eligible && (bool) $article->llms_eligible,
            'before' => $before,
            'after' => $after,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Article $article): array
    {
        $revision = $article->publishedRevision;
        $seoMeta = $article->seoMeta;

        return [
            'article_id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_revision_id' => $article->published_revision_id === null ? null : (int) $article->published_revision_id,
            'published_revision_status' => $revision instanceof ArticleTranslationRevision ? (string) $revision->revision_status : null,
            'content_md_sha256' => hash('sha256', (string) $article->content_md),
            'content_html_sha256' => hash('sha256', (string) $article->content_html),
            'seo_meta_exists' => $seoMeta instanceof ArticleSeoMeta,
            'seo_robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
            'seo_is_indexable' => $seoMeta instanceof ArticleSeoMeta ? (bool) $seoMeta->is_indexable : null,
            'canonical_path' => $seoMeta instanceof ArticleSeoMeta ? $this->pathFromCanonical((string) $seoMeta->canonical_url) : null,
            'schema_json_sha256' => $seoMeta instanceof ArticleSeoMeta
                ? hash('sha256', (string) json_encode($seoMeta->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))
                : null,
        ];
    }

    private function expectedConfirmation(int $articleId, string $expectedSlug): string
    {
        return "I explicitly approve articles:discoverability-release execute for article id {$articleId} slug {$expectedSlug} after dry-run passes.";
    }

    private function canonicalPathForArticle(Article $article): string
    {
        $locale = (string) $article->locale;
        $prefix = str_starts_with($locale, 'zh') ? '/zh' : '/en';

        return $prefix.'/articles/'.(string) $article->slug;
    }

    private function pathFromCanonical(string $canonical): string
    {
        $path = parse_url($canonical, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $canonical;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(bool $ok, bool $dryRun, string $action, int $articleId, string $expectedSlug, string $expectedConfirmation, ?array $plan, array $errors): array
    {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => $ok && ! $dryRun,
            'article_id' => $articleId,
            'expected_slug' => $expectedSlug,
            'expected_confirmation' => $expectedConfirmation,
            'updates_scope' => ['articles.sitemap_eligible', 'articles.llms_eligible'],
            'protected_holds' => [
                'no_content_change' => true,
                'no_publish' => true,
                'no_search' => true,
                'no_schema_hreflang' => true,
                'no_revalidation' => true,
            ],
            'external_search_submission_attempted' => false,
            'schema_hreflang_write_attempted' => false,
            'content_write_attempted' => false,
            'publish_attempted' => false,
            'revalidation_attempted' => false,
            'plan' => $plan,
            'errors' => $errors,
        ];
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
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        $articleId = (int) $this->option('article-id');
        $expectedSlug = trim((string) $this->option('expected-slug'));

        return $this->summary(false, ! (bool) $this->option('execute'), 'will_skip', $articleId, $expectedSlug, $this->expectedConfirmation($articleId, $expectedSlug), null, [[
            'field' => 'command',
            'code' => $code,
            'message' => $message,
        ]]);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? ''));
        $this->line('article_id='.(string) ($summary['article_id'] ?? ''));
        $this->line('expected_slug='.(string) ($summary['expected_slug'] ?? ''));
        $this->line('expected_confirmation='.(string) ($summary['expected_confirmation'] ?? ''));
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
