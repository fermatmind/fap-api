<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Ops\Support\ContentReleaseFollowUp;
use App\Models\Article;
use App\Services\Cms\ContentReleasePathPlanner;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

final class ContentReleaseRevalidate extends Command
{
    protected $signature = 'content-release:revalidate
        {--type=article : Content type to revalidate}
        {--article-id= : Article id when --type=article}
        {--article-ids= : Comma-separated article ids when --type=article-taxonomy}
        {--expected-slugs= : Comma-separated expected slugs in article-id order for identity locks}
        {--include-index= : Article index path to include for taxonomy-only revalidation}
        {--source=manual_revalidate : Safe audit/source label}
        {--dry-run : Plan paths without posting to configured revalidation endpoints}
        {--execute : Dispatch configured frontend revalidation endpoints}
        {--json : Emit safe machine-readable JSON}';

    protected $description = 'Safely plan or dispatch content-release revalidation without exposing revalidation tokens.';

    public function handle(ContentReleasePathPlanner $pathPlanner): int
    {
        $summary = $this->summary($pathPlanner);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(ContentReleasePathPlanner $pathPlanner): array
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $type = trim((string) $this->option('type'));
        $issues = [];

        if ((bool) $this->option('dry-run') && $execute) {
            $issues[] = 'execute_dry_run_conflict';
        }

        if ($type === 'article') {
            return $this->articleSummary($pathPlanner, $execute, $dryRun, $issues);
        }

        if ($type === 'article-taxonomy') {
            return $this->articleTaxonomySummary($execute, $dryRun, $issues);
        }

        $issues[] = 'unsupported_type';

        return $this->blockedSummary($type, $dryRun, $issues);
    }

    /**
     * @param  list<string>  $issues
     * @return array<string,mixed>
     */
    private function articleSummary(ContentReleasePathPlanner $pathPlanner, bool $execute, bool $dryRun, array $issues): array
    {
        $articleId = (int) $this->option('article-id');

        if ($articleId <= 0) {
            $issues[] = 'article_id_required';
        }

        $article = null;
        if ($articleId > 0) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes()])
                ->find($articleId);

            if (! $article instanceof Article) {
                $issues[] = 'article_not_found';
            }
        }

        $paths = $article instanceof Article ? $pathPlanner->paths('article', $article) : [];
        $issues = $this->validateExecuteRuntime($execute, $issues);
        $ok = $issues === [];
        $action = $execute ? 'revalidation_dispatched' : 'would_revalidate_content_release_paths';

        if (! $ok) {
            $action = 'will_skip';
        } elseif ($execute && $article instanceof Article) {
            ContentReleaseFollowUp::dispatch(
                'article',
                $article,
                $this->safeSource(),
                Request::create('/ops/content-release/revalidate-command', 'POST')
            );
        }

        return $this->baseSummary($ok, $dryRun, $action, 'article', $paths, $issues) + [
            'article_id' => $articleId > 0 ? $articleId : null,
            'article_ids' => $articleId > 0 ? [$articleId] : [],
        ];
    }

    /**
     * @param  list<string>  $issues
     * @return array<string,mixed>
     */
    private function articleTaxonomySummary(bool $execute, bool $dryRun, array $issues): array
    {
        $articleIds = $this->integerList((string) $this->option('article-ids'), 'article_ids', $issues);
        $expectedSlugs = $this->stringList((string) $this->option('expected-slugs'));
        $includeIndex = trim((string) $this->option('include-index'));

        if ($articleIds === []) {
            $issues[] = 'article_ids_required';
        }
        if ($expectedSlugs !== [] && count($expectedSlugs) !== count($articleIds)) {
            $issues[] = 'expected_slug_count_mismatch';
        }
        if ($includeIndex === '') {
            $issues[] = 'include_index_required';
        } elseif (! in_array($includeIndex, ['/zh/articles', '/en/articles'], true)) {
            $issues[] = 'include_index_not_allowed';
        }

        $articles = $this->articles($articleIds);
        $foundIds = array_map(static fn (Article $article): int => (int) $article->id, $articles);
        foreach ($articleIds as $articleId) {
            if (! in_array($articleId, $foundIds, true)) {
                $issues[] = 'article_not_found';
            }
        }

        $paths = $includeIndex !== '' && in_array($includeIndex, ['/zh/articles', '/en/articles'], true)
            ? [$includeIndex]
            : [];
        $indexLocale = str_starts_with($includeIndex, '/zh/') ? 'zh' : (str_starts_with($includeIndex, '/en/') ? 'en' : null);
        $articleSummaries = [];

        foreach ($articles as $index => $article) {
            $slug = trim((string) $article->slug);
            $locale = $this->localeSegment((string) $article->locale);
            $expectedSlug = $expectedSlugs[$index] ?? null;

            if ($expectedSlug !== null && $slug !== $expectedSlug) {
                $issues[] = 'expected_slug_mismatch';
            }
            if ($slug === '' || ! $this->isCanonicalSlug($slug)) {
                $issues[] = 'article_slug_not_canonical';
            }
            if ($indexLocale !== null && $locale !== $indexLocale) {
                $issues[] = 'include_index_locale_mismatch';
            }

            if ($slug !== '' && $this->isCanonicalSlug($slug)) {
                $paths[] = "/{$locale}/articles/{$slug}";
            }

            $articleSummaries[] = [
                'id' => (int) $article->id,
                'slug' => $slug,
                'locale' => (string) $article->locale,
                'canonical_path' => $slug !== '' && $this->isCanonicalSlug($slug) ? "/{$locale}/articles/{$slug}" : null,
            ];
        }

        $paths = array_values(array_unique($paths));
        $issues = $this->validateExecuteRuntime($execute, $issues);
        $ok = $issues === [];
        $action = $execute ? 'taxonomy_only_revalidation_dispatched' : 'would_revalidate_article_taxonomy_paths';

        if (! $ok) {
            $action = 'will_skip';
        } elseif ($execute) {
            ContentReleaseFollowUp::dispatchExplicitPaths(
                'article-taxonomy',
                $this->taxonomyBatchRecord($articleIds, $indexLocale ?? 'zh'),
                $paths,
                $this->safeSource(),
                Request::create('/ops/content-release/revalidate-command', 'POST'),
                [
                    'article_ids' => $articleIds,
                    'path_scope' => 'taxonomy_only',
                ]
            );
        }

        return $this->baseSummary($ok, $dryRun, $action, 'article-taxonomy', $paths, $issues) + [
            'article_id' => null,
            'article_ids' => $articleIds,
            'articles' => $articleSummaries,
            'include_index' => $includeIndex !== '' ? $includeIndex : null,
            'allowed_path_scope' => 'taxonomy_only',
            'excluded_path_classes' => ['home', 'llms', 'topics', 'tests', 'search', 'schema_hreflang', 'sitemap'],
            'sitemap_llms_mutation_attempted' => false,
            'schema_hreflang_write_attempted' => false,
        ];
    }

    /**
     * @param  list<string>  $issues
     * @return list<string>
     */
    private function validateExecuteRuntime(bool $execute, array $issues): array
    {
        if ($execute && $this->cacheInvalidationUrls() === []) {
            $issues[] = 'cache_invalidation_urls_missing';
        }

        if ($execute && ! $this->cacheInvalidationSecretPresent()) {
            $issues[] = 'cache_invalidation_secret_missing';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $issues
     * @return array<string,mixed>
     */
    private function baseSummary(bool $ok, bool $dryRun, string $action, string $type, array $paths, array $issues): array
    {
        return [
            'runtime' => 'content_release_revalidate',
            'status' => $ok ? 'success' : 'blocked',
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'type' => $type,
            'paths' => $paths,
            'endpoint_count' => count($this->cacheInvalidationUrls()),
            'token_present' => $this->cacheInvalidationSecretPresent(),
            'token_output' => false,
            'external_search_submission_attempted' => false,
            'search_submission_attempted' => false,
            'live_submission_attempted' => false,
            'secrets_read_from_environment_by_operator' => false,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @param  list<string>  $issues
     * @return array<string,mixed>
     */
    private function blockedSummary(string $type, bool $dryRun, array $issues): array
    {
        return $this->baseSummary(false, $dryRun, 'will_skip', $type, [], $issues) + [
            'article_id' => null,
            'article_ids' => [],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<Article>
     */
    private function articles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Article> $articles */
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (Article $article): int => array_search((int) $article->id, $ids, true))
            ->values()
            ->all();

        return $articles;
    }

    /**
     * @param  list<string>  $issues
     * @return list<int>
     */
    private function integerList(string $value, string $field, array &$issues): array
    {
        $items = $this->stringList($value);
        $ids = [];

        foreach ($items as $item) {
            if (! ctype_digit($item) || (int) $item <= 0) {
                $issues[] = $field.'_invalid';

                continue;
            }
            $ids[] = (int) $item;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function stringList(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ), static fn (string $item): bool => $item !== ''));
    }

    private function localeSegment(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }

    private function isCanonicalSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1;
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function taxonomyBatchRecord(array $articleIds, string $locale): object
    {
        return (object) [
            'id' => 0,
            'org_id' => 0,
            'title' => 'Article taxonomy revalidation '.implode(',', $articleIds),
            'slug' => 'article-taxonomy',
            'locale' => $locale === 'zh' ? 'zh-CN' : 'en',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now(),
        ];
    }

    /**
     * @return list<string>
     */
    private function cacheInvalidationUrls(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('ops.content_release_observability.cache_invalidation_urls', [])
        )));
    }

    private function cacheInvalidationSecretPresent(): bool
    {
        return trim((string) config('ops.content_release_observability.cache_invalidation_secret', '')) !== '';
    }

    private function safeSource(): string
    {
        $source = preg_replace('/[^A-Za-z0-9:_@.-]/', '_', trim((string) $this->option('source'))) ?: 'manual_revalidate';

        return substr($source, 0, 128);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach (['status', 'dry_run', 'action', 'type', 'article_id', 'endpoint_count', 'token_present', 'token_output'] as $key) {
            $value = $summary[$key] ?? null;
            $this->line($key.'='.$this->stringValue($value));
        }
        $this->line('paths='.$this->stringValue($summary['paths'] ?? []));
        $this->line('issues='.$this->stringValue($summary['issues'] ?? []));
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
