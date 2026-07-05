<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublishService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ArticleIqMethodPagesPublish extends Command
{
    private const SCHEMA_VERSION = 'fermatmind.iq_method_pages.publish.v1';

    private const PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01';

    private const REVIEW_APPROVAL_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01';

    protected $signature = 'articles:iq-method-pages-publish
        {--article-lock=* : Exact lock in slug:article_id:working_revision_id form}
        {--confirm= : Exact confirmation phrase required with --execute}
        {--execute : Publish the locked CMS drafts while keeping noindex/sitemap/llms disabled}
        {--json : Emit a JSON summary}';

    protected $description = 'Controlled publish workflow for the seven zh-CN IQ method CMS Articles while preserving noindex discoverability gates.';

    public function handle(ArticlePublishService $publisher): int
    {
        try {
            $summary = $this->publish($publisher);
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
    private function publish(ArticlePublishService $publisher): array
    {
        $execute = (bool) $this->option('execute');
        $issues = [];
        $locks = $this->parseLocks($issues);
        $expectedConfirmation = $this->expectedConfirmation($locks);

        if ($execute && ! hash_equals($expectedConfirmation, trim((string) $this->option('confirm')))) {
            $issues[] = $this->issue('confirm', 'confirmation_mismatch', 'Exact confirmation phrase is required before IQ method page publish writes.', [
                'expected_confirmation' => $expectedConfirmation,
            ]);
        }

        $plans = $this->plansFromLocks($locks, $issues);
        $published = [];

        if ($issues === [] && $execute) {
            $published = DB::transaction(function () use ($plans, $publisher, $expectedConfirmation): array {
                return $this->publishPlans($plans, $publisher, $expectedConfirmation);
            });
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $issues === [],
            'status' => $issues === [] ? ($execute ? 'published_noindex' : 'dry_run_pass') : 'blocked',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'generated_at' => now()->toIso8601String(),
            'pr_id' => self::PR_ID,
            'source_review_approval_pr_id' => self::REVIEW_APPROVAL_PR_ID,
            'expected_article_count' => 7,
            'article_locks' => array_values($locks),
            'expected_confirmation' => $expectedConfirmation,
            'articles' => $plans,
            'published_articles' => $published,
            'issues' => $issues,
            'side_effects' => [
                'db_write' => $execute && $issues === [],
                'cms_update' => $execute && $issues === [],
                'publish' => $execute && $issues === [],
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'search' => false,
                'deploy' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,array{slug:string,article_id:int,working_revision_id:int}>
     */
    private function parseLocks(array &$issues): array
    {
        $locks = [];

        foreach ((array) $this->option('article-lock') as $index => $raw) {
            $parts = explode(':', trim((string) $raw));
            if (count($parts) !== 3 || ! is_numeric($parts[1]) || ! is_numeric($parts[2])) {
                $issues[] = $this->issue('article_lock.'.$index, 'invalid_article_lock', 'Lock must be slug:article_id:working_revision_id.');

                continue;
            }

            $slug = trim($parts[0]);
            $locks[$slug] = [
                'slug' => $slug,
                'article_id' => (int) $parts[1],
                'working_revision_id' => (int) $parts[2],
            ];
        }

        if (count($locks) !== 7) {
            $issues[] = $this->issue('article_lock', 'article_lock_count_mismatch', 'Exactly seven article locks are required.');
        }

        return $locks;
    }

    /**
     * @param  array<string,array{slug:string,article_id:int,working_revision_id:int}>  $locks
     * @param  list<array<string,mixed>>  $issues
     * @return list<array<string,mixed>>
     */
    private function plansFromLocks(array $locks, array &$issues): array
    {
        $plans = [];

        foreach ($locks as $lock) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'seoMeta'])
                ->whereKey($lock['article_id'])
                ->first();
            $revision = $article?->workingRevision instanceof ArticleTranslationRevision ? $article->workingRevision : null;
            $seo = $article?->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
            $blocked = $this->preflight($lock, $article, $revision, $seo);

            foreach ($blocked as $issue) {
                $issues[] = $issue;
            }

            $plans[] = [
                'slug' => $lock['slug'],
                'article_id' => $lock['article_id'],
                'working_revision_id' => $lock['working_revision_id'],
                'status' => (string) ($article?->status ?? ''),
                'working_revision_status' => (string) ($revision?->revision_status ?? ''),
                'is_public' => (bool) ($article?->is_public ?? false),
                'is_indexable' => (bool) ($article?->is_indexable ?? false),
                'robots' => (string) ($seo?->robots ?? ''),
                'sitemap_eligible' => (bool) ($article?->sitemap_eligible ?? false),
                'llms_eligible' => (bool) ($article?->llms_eligible ?? false),
                'publish' => true,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'ok' => $blocked === [],
                'blocked_by' => $blocked,
            ];
        }

        return $plans;
    }

    /**
     * @param  array{slug:string,article_id:int,working_revision_id:int}  $lock
     * @return list<array<string,mixed>>
     */
    private function preflight(array $lock, ?Article $article, ?ArticleTranslationRevision $revision, ?ArticleSeoMeta $seo): array
    {
        $issues = [];

        if (! $article instanceof Article) {
            return [$this->issue($lock['slug'].'.article', 'article_not_found', 'Article not found.')];
        }

        if ((string) $article->slug !== $lock['slug']) {
            $issues[] = $this->issue($lock['slug'].'.slug', 'article_slug_lock_mismatch', 'Article slug does not match lock.');
        }
        if ((string) $article->locale !== 'zh-CN') {
            $issues[] = $this->issue($lock['slug'].'.locale', 'locale_not_zh_cn', 'IQ method page publish only accepts zh-CN articles.');
        }
        if ((int) ($article->working_revision_id ?? 0) !== $lock['working_revision_id']) {
            $issues[] = $this->issue($lock['slug'].'.working_revision_id', 'working_revision_lock_mismatch', 'Working revision lock does not match article.');
        }
        if ((string) $article->status !== 'review_pending') {
            $issues[] = $this->issue($lock['slug'].'.status', 'article_not_review_pending', 'Article must be review_pending before IQ publish.');
        }
        if ((bool) $article->is_public || $article->published_at !== null || $article->published_revision_id !== null) {
            $issues[] = $this->issue($lock['slug'].'.publish_state', 'article_already_public_or_published', 'Article must not already be public or published.');
        }
        if ((bool) $article->is_indexable || (bool) $article->sitemap_eligible || (bool) $article->llms_eligible) {
            $issues[] = $this->issue($lock['slug'].'.discoverability', 'discoverability_not_disabled', 'Indexability, sitemap, and llms must remain disabled before publish.');
        }

        if (! $revision instanceof ArticleTranslationRevision) {
            $issues[] = $this->issue($lock['slug'].'.working_revision', 'working_revision_missing', 'Working revision missing.');
        } elseif ((int) $revision->id !== $lock['working_revision_id']
            || (int) $revision->article_id !== (int) $article->id
            || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED
            || (int) ($revision->reviewed_by ?? 0) <= 0
            || $revision->reviewed_at === null
            || $revision->approved_at === null) {
            $issues[] = $this->issue($lock['slug'].'.working_revision', 'working_revision_not_approved_for_publish', 'Working revision must match the lock and include review approval metadata.');
        }

        if (! $seo instanceof ArticleSeoMeta) {
            $issues[] = $this->issue($lock['slug'].'.seo', 'seo_meta_missing', 'SEO meta missing.');
        } else {
            if ((string) $seo->robots !== 'noindex,follow' || (bool) $seo->is_indexable) {
                $issues[] = $this->issue($lock['slug'].'.seo', 'seo_not_noindex', 'SEO meta must remain noindex before IQ publish.');
            }
            if ((string) data_get($seo->schema_json, 'editorial_package_v1.review_approval_v1.pr_id') !== self::REVIEW_APPROVAL_PR_ID) {
                $issues[] = $this->issue($lock['slug'].'.review_approval', 'review_approval_metadata_missing', 'Review approval metadata missing from SEO schema.');
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string,mixed>>  $plans
     * @return list<array<string,mixed>>
     */
    private function publishPlans(array $plans, ArticlePublishService $publisher, string $confirmation): array
    {
        $published = [];
        $now = now();

        foreach ($plans as $plan) {
            $articleId = (int) $plan['article_id'];
            $publisher->publishArticle($articleId, 'iq_method_pages_controlled_publish');

            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
                ->whereKey($articleId)
                ->lockForUpdate()
                ->firstOrFail();
            $seo = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
            if (! $seo instanceof ArticleSeoMeta) {
                throw new RuntimeException('seo meta missing after IQ publish.');
            }

            $schema = is_array($seo->schema_json) ? $seo->schema_json : [];
            data_set($schema, 'editorial_package_v1.publish_v1', [
                'pr_id' => self::PR_ID,
                'source_review_approval_pr_id' => self::REVIEW_APPROVAL_PR_ID,
                'confirmation_sha256' => hash('sha256', $confirmation),
                'published_at' => $article->published_at?->toIso8601String() ?? $now->toIso8601String(),
                'indexability_allowed' => false,
                'sitemap_llms_allowed' => false,
            ]);

            $article->forceFill([
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
            ])->save();

            $seo->forceFill([
                'schema_json' => $schema,
                'robots' => 'noindex,follow',
                'is_indexable' => false,
            ])->save();

            $article->refresh();
            $seo->refresh();
            if ((string) $article->status !== 'published'
                || ! (bool) $article->is_public
                || (bool) $article->is_indexable
                || (bool) $article->sitemap_eligible
                || (bool) $article->llms_eligible
                || (string) $seo->robots !== 'noindex,follow'
                || (bool) $seo->is_indexable) {
                throw new RuntimeException('IQ publish postcondition failed: public published article must remain noindex and sitemap/llms disabled.');
            }

            $published[] = [
                'slug' => (string) $article->slug,
                'article_id' => (int) $article->id,
                'published_revision_id' => (int) $article->published_revision_id,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'robots' => (string) $seo->robots,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
            ];
        }

        return $published;
    }

    /**
     * @param  array<string,array{slug:string,article_id:int,working_revision_id:int}>  $locks
     */
    private function expectedConfirmation(array $locks): string
    {
        $ids = collect($locks)
            ->sortKeys()
            ->map(static fn (array $lock): string => $lock['slug'].':'.$lock['article_id'].':'.$lock['working_revision_id'])
            ->implode(',');

        return 'I explicitly approve IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01 to publish IQ method page article locks ['.$ids.'] without indexability, sitemap, llms, search, or deploy.';
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $context = []): array
    {
        return array_filter([
            'field' => $field,
            'code' => $code,
            'message' => $message,
            'context' => $context === [] ? null : $context,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => ! (bool) $this->option('execute'),
            'execute' => (bool) $this->option('execute'),
            'pr_id' => self::PR_ID,
            'issues' => [$this->issue('command', $code, $message)],
            'side_effects' => [
                'db_write' => false,
                'cms_update' => false,
                'publish' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'search' => false,
                'deploy' => false,
            ],
        ];
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
        $this->line('status='.(string) ($summary['status'] ?? 'blocked'));
        $this->line('dry_run='.(($summary['dry_run'] ?? true) ? '1' : '0'));
        $this->line('execute='.(($summary['execute'] ?? false) ? '1' : '0'));
        $this->line('published_count='.(string) count((array) ($summary['published_articles'] ?? [])));

        foreach ((array) ($summary['issues'] ?? []) as $issue) {
            if (is_array($issue)) {
                $this->line(sprintf(
                    'issue=%s:%s:%s',
                    (string) ($issue['field'] ?? 'unknown'),
                    (string) ($issue['code'] ?? 'unknown'),
                    (string) ($issue['message'] ?? ''),
                ));
            }
        }
    }
}
