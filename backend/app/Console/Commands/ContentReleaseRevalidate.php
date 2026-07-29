<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Ops\Support\ContentReleaseFollowUp;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
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
        {--expected-published-revision-ids= : Comma-separated published revision ids in article-id order}
        {--expected-state-sha256= : Execute-only exact taxonomy state SHA256}
        {--expected-content-set-sha256= : Execute-only exact published content-set SHA256}
        {--require-state-lock : Require revision, state, and content locks for taxonomy revalidation}
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
        $expectedPublishedRevisionIds = $this->integerList(
            (string) $this->option('expected-published-revision-ids'),
            'expected_published_revision_ids',
            $issues,
        );
        $expectedStateSha256 = trim((string) $this->option('expected-state-sha256'));
        $expectedContentSetSha256 = trim((string) $this->option('expected-content-set-sha256'));
        $requireStateLock = (bool) $this->option('require-state-lock');
        $includeIndex = trim((string) $this->option('include-index'));

        if ($articleIds === []) {
            $issues[] = 'article_ids_required';
        }
        if ($expectedSlugs !== [] && count($expectedSlugs) !== count($articleIds)) {
            $issues[] = 'expected_slug_count_mismatch';
        }
        if ($expectedPublishedRevisionIds !== [] && count($expectedPublishedRevisionIds) !== count($articleIds)) {
            $issues[] = 'expected_published_revision_id_count_mismatch';
        }
        if ($requireStateLock && count($expectedPublishedRevisionIds) !== count($articleIds)) {
            $issues[] = 'expected_published_revision_ids_required';
        }
        if ($requireStateLock && $execute && ! $this->isSha256($expectedStateSha256)) {
            $issues[] = 'expected_state_sha256_required';
        }
        if ($requireStateLock && $execute && ! $this->isSha256($expectedContentSetSha256)) {
            $issues[] = 'expected_content_set_sha256_required';
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
        $contentRows = [];
        $stateRows = [];

        foreach ($articles as $index => $article) {
            $slug = trim((string) $article->slug);
            $locale = $this->localeSegment((string) $article->locale);
            $expectedSlug = $expectedSlugs[$index] ?? null;
            $expectedPublishedRevisionId = $expectedPublishedRevisionIds[$index] ?? null;
            $publishedRevision = $article->publishedRevision;
            $seoMeta = $article->seoMeta;

            if ($expectedSlug !== null && $slug !== $expectedSlug) {
                $issues[] = 'expected_slug_mismatch';
            }
            if ($slug === '' || ! $this->isCanonicalSlug($slug)) {
                $issues[] = 'article_slug_not_canonical';
            }
            if ($indexLocale !== null && $locale !== $indexLocale) {
                $issues[] = 'include_index_locale_mismatch';
            }
            if ($expectedPublishedRevisionId !== null
                && (int) ($article->published_revision_id ?? 0) !== $expectedPublishedRevisionId) {
                $issues[] = 'expected_published_revision_id_mismatch';
            }
            if ($requireStateLock) {
                if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
                    $issues[] = 'article_not_publicly_published';
                }
                if (! $publishedRevision instanceof ArticleTranslationRevision
                    || (int) $publishedRevision->article_id !== (int) $article->id
                    || (int) $publishedRevision->org_id !== (int) $article->org_id
                    || (string) $publishedRevision->locale !== (string) $article->locale
                    || (string) $publishedRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
                    $issues[] = 'published_revision_lock_invalid';
                }
                if (! $seoMeta instanceof ArticleSeoMeta) {
                    $issues[] = 'seo_meta_missing';
                }
                if ($publishedRevision instanceof ArticleTranslationRevision
                    && (
                        (string) $article->title !== (string) $publishedRevision->title
                        || (string) $article->excerpt !== (string) $publishedRevision->excerpt
                        || (string) $article->content_md !== (string) $publishedRevision->content_md
                        || (string) ($seoMeta?->seo_title ?? '') !== (string) $publishedRevision->seo_title
                        || (string) ($seoMeta?->seo_description ?? '') !== (string) $publishedRevision->seo_description
                    )) {
                    $issues[] = 'published_projection_content_mismatch';
                }
            }

            if ($slug !== '' && $this->isCanonicalSlug($slug)) {
                $paths[] = "/{$locale}/articles/{$slug}";
            }

            $contentRow = [
                'article_id' => (int) $article->id,
                'published_revision_id' => (int) ($article->published_revision_id ?? 0),
                'title_sha256' => $this->valueHash((string) $article->title),
                'excerpt_sha256' => $this->valueHash((string) $article->excerpt),
                'content_sha256' => $this->valueHash((string) $article->content_md),
                'seo_title_sha256' => $this->valueHash((string) ($seoMeta?->seo_title ?? '')),
                'seo_description_sha256' => $this->valueHash((string) ($seoMeta?->seo_description ?? '')),
            ];
            $contentRows[] = $contentRow;
            $stateRows[] = $contentRow + [
                'slug' => $slug,
                'locale' => (string) $article->locale,
                'translation_group_id' => (string) $article->translation_group_id,
                'working_revision_id' => (int) ($article->working_revision_id ?? 0),
                'article_status' => (string) $article->status,
                'published_revision_status' => $publishedRevision instanceof ArticleTranslationRevision
                    ? (string) $publishedRevision->revision_status
                    : null,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'canonical_sha256' => $this->valueHash((string) ($seoMeta?->canonical_url ?? '')),
                'robots' => (string) ($seoMeta?->robots ?? ''),
                'seo_is_indexable' => (bool) ($seoMeta?->is_indexable ?? false),
            ];
            $articleSummaries[] = [
                'id' => (int) $article->id,
                'slug' => $slug,
                'locale' => (string) $article->locale,
                'published_revision_id' => (int) ($article->published_revision_id ?? 0),
                'canonical_path' => $slug !== '' && $this->isCanonicalSlug($slug) ? "/{$locale}/articles/{$slug}" : null,
            ];
        }

        $paths = array_values(array_unique($paths));
        $stateSha256 = $this->deterministicHash($stateRows);
        $contentSetSha256 = $this->deterministicHash($contentRows);
        if ($requireStateLock && $execute && $this->isSha256($expectedStateSha256)
            && ! hash_equals($expectedStateSha256, $stateSha256)) {
            $issues[] = 'expected_state_sha256_mismatch';
        }
        if ($requireStateLock && $execute && $this->isSha256($expectedContentSetSha256)
            && ! hash_equals($expectedContentSetSha256, $contentSetSha256)) {
            $issues[] = 'expected_content_set_sha256_mismatch';
        }
        $issues = $this->validateExecuteRuntime($execute, $issues);
        $ok = $issues === [];
        $action = $execute ? 'taxonomy_only_revalidation_dispatched' : 'would_revalidate_article_taxonomy_paths';

        if ($ok && $execute) {
            try {
                ContentReleaseFollowUp::dispatchExplicitPaths(
                    'article-taxonomy',
                    $this->taxonomyBatchRecord($articleIds, $indexLocale ?? 'zh'),
                    $paths,
                    $this->safeSource(),
                    Request::create('/ops/content-release/revalidate-command', 'POST'),
                    [
                        'article_ids' => $articleIds,
                        'path_scope' => 'taxonomy_only',
                    ],
                    broadcast: false,
                    throwOnFailure: true,
                );
            } catch (\Throwable) {
                $issues[] = 'revalidation_dispatch_failed';
                $ok = false;
            }
        }
        if (! $ok) {
            $action = 'will_skip';
        }

        return $this->baseSummary($ok, $dryRun, $action, 'article-taxonomy', $paths, $issues) + [
            'article_id' => null,
            'article_ids' => $articleIds,
            'articles' => $articleSummaries,
            'include_index' => $includeIndex !== '' ? $includeIndex : null,
            'state_lock_required' => $requireStateLock,
            'state_sha256' => $stateSha256,
            'content_set_sha256' => $contentSetSha256,
            'allowed_path_scope' => 'taxonomy_only',
            'excluded_path_classes' => ['home', 'llms', 'topics', 'tests', 'search', 'schema_hreflang', 'sitemap'],
            'sitemap_llms_mutation_attempted' => false,
            'schema_hreflang_write_attempted' => false,
            'broadcast_attempted' => false,
            'cms_authority_write_count' => 0,
            'database_authority_write_count' => 0,
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

    private function isSha256(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    private function valueHash(string $value): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($value)));
    }

    private function deterministicHash(mixed $value): string
    {
        return hash(
            'sha256',
            (string) json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
        );
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
        return trim((string) config('ops.content_release_observability.hmac_revalidation_secret', '')) !== ''
            || trim((string) config('ops.content_release_observability.cache_invalidation_secret', '')) !== '';
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
