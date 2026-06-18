<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ArticleTaxonomyHygiene extends Command
{
    private const EXECUTE_SAFETY_FLAGS = [
        'no_content_change',
        'no_publish',
        'no_search',
        'no_schema_hreflang',
        'no_sitemap_llms_change',
        'no_revalidation',
    ];

    private const ARTICLE_CATEGORY_TARGETS = [
        40 => '职业探索',
        48 => '职业探索',
        50 => '能力与认知',
        51 => '人格心理学',
        52 => '职业决策',
    ];

    protected $signature = 'articles:taxonomy-hygiene
        {--article-ids= : Comma-separated article ids to lock}
        {--expected-slugs= : Comma-separated expected slugs in article-id order}
        {--dry-run : Validate and plan without writing}
        {--execute : Execute the bounded taxonomy write}
        {--json : Emit JSON}
        {--no-content-change : Required for execute; confirms no body/editorial content mutation}
        {--no-publish : Required for execute; confirms no publish/promote action}
        {--no-search : Required for execute; confirms no search submission}
        {--no-schema-hreflang : Required for execute; confirms no schema/hreflang writes}
        {--no-sitemap-llms-change : Required for execute; confirms no sitemap/llms mutation}
        {--no-revalidation : Required for execute; confirms no cache revalidation}';

    protected $description = 'Controlled article taxonomy hygiene with article identity locks, dry-run, side-effect guards, and audit logging.';

    public function handle(): int
    {
        $payload = $this->buildPayload();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ((bool) ($payload['ok'] ?? false)) {
            $this->info((string) ($payload['action'] ?? 'ok'));
        } else {
            $this->error((string) ($payload['action'] ?? 'failed'));
        }

        return (bool) ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(): array
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $errors = [];
        $warnings = [];
        $articleIds = $this->articleIds((string) $this->option('article-ids'), $errors);
        $expectedSlugs = $this->expectedSlugs((string) $this->option('expected-slugs'), $errors);

        if ((bool) $this->option('dry-run') && $execute) {
            $errors[] = $this->issue('dry_run', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }
        if ($articleIds !== [] && $expectedSlugs !== [] && count($expectedSlugs) !== count($articleIds)) {
            $errors[] = $this->issue('expected_slugs', 'expected_slug_count_mismatch', 'expected-slugs must match article-ids count.');
        }
        if ($articleIds === []) {
            $errors[] = $this->issue('article_ids', 'article_ids_required', '--article-ids is required.');
        }
        if ($expectedSlugs === []) {
            $errors[] = $this->issue('expected_slugs', 'expected_slugs_required', '--expected-slugs is required.');
        }
        foreach ($articleIds as $id) {
            if (! array_key_exists($id, self::ARTICLE_CATEGORY_TARGETS)) {
                $errors[] = $this->issue('article_ids', 'article_not_in_taxonomy_hygiene_scope', 'Article id is not in the approved taxonomy hygiene scope.', ['article_id' => $id]);
            }
        }
        if ($execute) {
            foreach (self::EXECUTE_SAFETY_FLAGS as $flag) {
                if ((bool) $this->option(str_replace('_', '-', $flag)) !== true) {
                    $errors[] = $this->issue($flag, 'required_safety_flag_missing', 'All no-side-effect safety flags are required for execute mode.');
                }
            }
        }

        $articles = $this->resolveArticles($articleIds);
        $this->validateLocks($articles, $articleIds, $expectedSlugs, $errors);
        $categoryPlan = $this->categoryPlan($articleIds, $errors, $warnings);
        $before = array_map(fn (Article $article): array => $this->articleSnapshot($article), $articles);
        $after = array_map(fn (Article $article): array => $this->plannedArticleSnapshot($article, $categoryPlan), $articles);

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleIds, $before, $after, $categoryPlan, $errors, $warnings);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_apply_article_taxonomy_hygiene', $articleIds, $before, $after, $categoryPlan, [], $warnings);
        }

        DB::transaction(function () use ($articleIds, $expectedSlugs, &$after, &$categoryPlan): void {
            $errors = [];
            $warnings = [];
            $locked = $this->resolveArticles($articleIds, true);
            $this->validateLocks($locked, $articleIds, $expectedSlugs, $errors);
            $categoryPlan = $this->categoryPlan($articleIds, $errors, $warnings, true);
            if ($errors !== []) {
                throw new \RuntimeException('Article taxonomy hygiene lock changed during execute.');
            }

            $targetCategories = $categoryPlan['target_categories'];
            foreach ($locked as $article) {
                $targetName = self::ARTICLE_CATEGORY_TARGETS[(int) $article->id];
                $target = $targetCategories[$targetName] ?? null;
                if (! is_array($target) || ! is_int($target['id'])) {
                    throw new \RuntimeException('Target category could not be resolved during execute.');
                }
                $article->forceFill(['category_id' => $target['id']])->save();
            }

            $careerExploration = ArticleCategory::query()->withoutGlobalScopes()->find(11);
            if ($careerExploration instanceof ArticleCategory) {
                $careerExploration->forceFill(['name' => '职业探索'])->save();
            }

            $seoArticles = $this->seoArticlesCategory();
            if ($seoArticles instanceof ArticleCategory && $this->remainingSeoArticlesReferences($articleIds) === 0) {
                $seoArticles->forceFill(['is_active' => false])->save();
            }

            $fresh = $this->resolveArticles($articleIds);
            $after = array_map(fn (Article $article): array => $this->articleSnapshot($article), $fresh);
            $this->writeAudit($fresh, $categoryPlan);
        });

        return $this->summary(true, false, 'applied_article_taxonomy_hygiene', $articleIds, $before, $after, $categoryPlan, [], $warnings);
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return list<int>
     */
    private function articleIds(string $value, array &$errors): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ), static fn (string $item): bool => $item !== ''));

        $parsed = [];
        foreach ($ids as $id) {
            if (! ctype_digit($id) || (int) $id <= 0) {
                $errors[] = $this->issue('article_ids', 'invalid_article_id', 'Article ids must be positive integers.', ['value' => $id]);

                continue;
            }
            $parsed[] = (int) $id;
        }

        return $parsed;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return list<string>
     */
    private function expectedSlugs(string $value, array &$errors): array
    {
        $slugs = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ), static fn (string $item): bool => $item !== ''));

        foreach ($slugs as $slug) {
            if ($slug !== Str::slug($slug)) {
                $errors[] = $this->issue('expected_slugs', 'invalid_expected_slug', 'Expected slugs must already be canonical slugs.', ['value' => $slug]);
            }
        }

        return $slugs;
    }

    /**
     * @param  list<int>  $ids
     * @return list<Article>
     */
    private function resolveArticles(array $ids, bool $lock = false): array
    {
        if ($ids === []) {
            return [];
        }

        $query = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'category' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'tags' => static fn ($relation) => $relation->withoutGlobalScopes(),
            ])
            ->whereIn('id', $ids);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var list<Article> $articles */
        $articles = $query->get()
            ->sortBy(static fn (Article $article): int => array_search((int) $article->id, $ids, true))
            ->values()
            ->all();

        return $articles;
    }

    /**
     * @param  list<Article>  $articles
     * @param  list<int>  $articleIds
     * @param  list<string>  $expectedSlugs
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateLocks(array $articles, array $articleIds, array $expectedSlugs, array &$errors): void
    {
        $foundIds = array_map(static fn (Article $article): int => (int) $article->id, $articles);
        foreach ($articleIds as $id) {
            if (! in_array($id, $foundIds, true)) {
                $errors[] = $this->issue('article_ids', 'article_not_found', 'Requested article id was not found.', ['article_id' => $id]);
            }
        }

        foreach ($articles as $index => $article) {
            $expectedSlug = $expectedSlugs[$index] ?? null;
            if ($expectedSlug !== null && (string) $article->slug !== $expectedSlug) {
                $errors[] = $this->issue('article.'.$article->id.'.slug', 'expected_slug_mismatch', 'Article slug does not match expected identity lock.', [
                    'article_id' => (int) $article->id,
                    'actual' => (string) $article->slug,
                    'expected' => $expectedSlug,
                ]);
            }
            if ((int) $article->org_id !== 0 || (string) $article->locale !== 'zh-CN') {
                $errors[] = $this->issue('article.'.$article->id.'.locale', 'article_scope_mismatch', 'Taxonomy hygiene is limited to org 0 zh-CN articles.', [
                    'article_id' => (int) $article->id,
                    'org_id' => (int) $article->org_id,
                    'locale' => (string) $article->locale,
                ]);
            }
            if ((string) $article->status !== 'published' || ! (bool) $article->is_public || (int) $article->published_revision_id <= 0) {
                $errors[] = $this->issue('article.'.$article->id.'.status', 'article_not_published_public', 'Taxonomy hygiene is limited to published public articles with a published revision.', [
                    'article_id' => (int) $article->id,
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $articleIds
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function categoryPlan(array $articleIds, array &$errors, array &$warnings, bool $execute = false): array
    {
        $careerExploration = ArticleCategory::query()->withoutGlobalScopes()->find(11);
        if (! $careerExploration instanceof ArticleCategory) {
            $errors[] = $this->issue('category.11', 'category_not_found', 'Category id 11 is required for 职业探索.');
        } elseif ((string) $careerExploration->slug !== 'career-exploration') {
            $errors[] = $this->issue('category.11.slug', 'category_slug_mismatch', 'Category id 11 must keep slug career-exploration.', [
                'actual' => (string) $careerExploration->slug,
            ]);
        }

        $targets = [];
        foreach (array_unique(array_values(self::ARTICLE_CATEGORY_TARGETS)) as $name) {
            $category = $name === '职业探索'
                ? $careerExploration
                : ArticleCategory::query()->withoutGlobalScopes()->where('org_id', 0)->where('name', $name)->first();

            if (! $category instanceof ArticleCategory && $name === '能力与认知' && $execute) {
                $category = ArticleCategory::query()->withoutGlobalScopes()->create([
                    'org_id' => 0,
                    'slug' => 'ability-cognition',
                    'name' => '能力与认知',
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            }

            if (! $category instanceof ArticleCategory && $name !== '能力与认知') {
                $errors[] = $this->issue('category.'.$name, 'target_category_not_found', 'Required target category was not found.', [
                    'category_name' => $name,
                ]);
            }

            $targets[$name] = [
                'id' => $category instanceof ArticleCategory ? (int) $category->id : null,
                'name' => $name,
                'slug' => $category instanceof ArticleCategory ? (string) $category->slug : ($name === '能力与认知' ? 'ability-cognition' : null),
                'exists' => $category instanceof ArticleCategory,
                'would_create' => ! $category instanceof ArticleCategory && $name === '能力与认知',
            ];
        }

        $seoArticles = $this->seoArticlesCategory();
        $remainingSeoArticlesReferences = $this->remainingSeoArticlesReferences($articleIds);
        if ($seoArticles instanceof ArticleCategory && $remainingSeoArticlesReferences > 0) {
            $warnings[] = $this->issue('category.seo-articles', 'seo_articles_references_remaining', 'SEO Articles category still has public article references outside this hygiene scope.', [
                'remaining_public_references' => $remainingSeoArticlesReferences,
            ]);
        }

        return [
            'category_11' => [
                'before' => $careerExploration instanceof ArticleCategory ? $this->categorySnapshot($careerExploration) : null,
                'after' => $careerExploration instanceof ArticleCategory ? array_merge($this->categorySnapshot($careerExploration), ['name' => '职业探索']) : null,
            ],
            'target_categories' => $targets,
            'seo_articles_category' => [
                'before' => $seoArticles instanceof ArticleCategory ? $this->categorySnapshot($seoArticles) : null,
                'after' => $seoArticles instanceof ArticleCategory
                    ? array_merge($this->categorySnapshot($seoArticles), ['is_active' => $remainingSeoArticlesReferences === 0 ? false : (bool) $seoArticles->is_active])
                    : null,
                'remaining_public_references_after_plan' => $remainingSeoArticlesReferences,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $categoryPlan
     * @return array<string,mixed>
     */
    private function plannedArticleSnapshot(Article $article, array $categoryPlan): array
    {
        $snapshot = $this->articleSnapshot($article);
        $targetName = self::ARTICLE_CATEGORY_TARGETS[(int) $article->id] ?? null;
        $target = is_string($targetName) ? ($categoryPlan['target_categories'][$targetName] ?? null) : null;
        if (is_array($target)) {
            $snapshot['category'] = [
                'id' => $target['id'],
                'slug' => $target['slug'],
                'name' => $target['name'],
            ];
        }

        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    private function articleSnapshot(Article $article): array
    {
        return [
            'id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
            'content_hash' => hash('sha256', (string) $article->content_md),
            'category' => $article->category instanceof ArticleCategory ? $this->categorySnapshot($article->category) : null,
            'first_visible_tags' => $article->tags->take(3)->map(static fn ($tag): array => [
                'id' => (int) $tag->id,
                'slug' => (string) $tag->slug,
                'name' => (string) $tag->name,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function categorySnapshot(ArticleCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'slug' => (string) $category->slug,
            'name' => (string) $category->name,
            'is_active' => (bool) $category->is_active,
        ];
    }

    private function seoArticlesCategory(): ?ArticleCategory
    {
        return ArticleCategory::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where(static function ($query): void {
                $query->where('slug', 'seo-articles')->orWhere('name', 'SEO Articles');
            })
            ->first();
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function remainingSeoArticlesReferences(array $articleIds): int
    {
        $category = $this->seoArticlesCategory();
        if (! $category instanceof ArticleCategory) {
            return 0;
        }

        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('category_id', (int) $category->id)
            ->where('locale', 'zh-CN')
            ->where('status', 'published')
            ->where('is_public', true)
            ->whereNotIn('id', $articleIds)
            ->count();
    }

    /**
     * @param  list<Article>  $articles
     * @param  array<string,mixed>  $categoryPlan
     */
    private function writeAudit(array $articles, array $categoryPlan): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'articles_taxonomy_hygiene',
            'target_type' => 'articles',
            'target_id' => implode(',', array_map(static fn (Article $article): string => (string) $article->id, $articles)),
            'meta_json' => [
                'command' => 'articles:taxonomy-hygiene',
                'article_ids' => array_map(static fn (Article $article): int => (int) $article->id, $articles),
                'category_plan' => $categoryPlan,
                'no_content_change' => true,
                'no_publish' => true,
                'no_search' => true,
                'no_schema_hreflang' => true,
                'no_sitemap_llms_change' => true,
                'no_revalidation' => true,
            ],
            'ip' => null,
            'user_agent' => 'artisan',
            'request_id' => null,
            'reason' => 'seo_article_taxonomy_hygiene',
            'result' => 'success',
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  list<int>  $articleIds
     * @param  list<array<string,mixed>>  $before
     * @param  list<array<string,mixed>>  $after
     * @param  array<string,mixed>  $categoryPlan
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function summary(
        bool $ok,
        bool $dryRun,
        string $action,
        array $articleIds,
        array $before,
        array $after,
        array $categoryPlan,
        array $errors,
        array $warnings
    ): array {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => $ok,
            'article_ids' => $articleIds,
            'safety_flags' => [
                'content_change' => false,
                'publish_or_promote' => false,
                'search_submission' => false,
                'schema_hreflang_write' => false,
                'sitemap_llms_mutation' => false,
                'revalidation' => false,
            ],
            'before' => $before,
            'after' => $after,
            'category_plan' => $categoryPlan,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $context = []): array
    {
        return array_merge([
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ], $context);
    }
}
