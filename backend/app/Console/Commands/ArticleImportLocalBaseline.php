<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ArticleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

final class ArticleImportLocalBaseline extends Command
{
    private const SUPPORTED_LOCALES = ['en', 'zh-CN'];

    protected $signature = 'articles:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--locale=* : Import only specific locale(s)}
        {--article=* : Import only specific article slug(s)}
        {--upsert : Update existing records instead of create-missing only}
        {--status=published : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed article baseline content into Article CMS tables.';

    public function handle(
        ArticleService $articleService,
        ArticlePublishService $articlePublishService,
    ): int {
        try {
            $status = trim((string) $this->option('status'));
            if (! in_array($status, ['draft', 'published'], true)) {
                throw new RuntimeException(sprintf(
                    'Unsupported --status value: %s',
                    $status,
                ));
            }

            $sourceDir = $this->resolveSourceDir(
                $this->option('source-dir') !== null
                    ? (string) $this->option('source-dir')
                    : null,
            );
            $selectedLocales = $this->normalizeSelectedLocales((array) $this->option('locale'));
            $selectedArticles = $this->normalizeSelectedArticles((array) $this->option('article'));

            $documents = $this->readDocuments($sourceDir, $selectedLocales);
            $articles = $this->normalizeArticles($documents, $selectedArticles);
            $summary = $this->importRows(
                $articles,
                [
                    'dry_run' => (bool) $this->option('dry-run'),
                    'upsert' => (bool) $this->option('upsert'),
                    'status' => $status,
                ],
                $articleService,
                $articlePublishService,
            );

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('locales_selected='.($selectedLocales === [] ? 'all' : implode(',', $selectedLocales)));
            $this->line('articles_selected='.($selectedArticles === [] ? 'all' : implode(',', $selectedArticles)));
            $this->line('dry_run='.((bool) $this->option('dry-run') ? '1' : '0'));
            $this->line('upsert='.((bool) $this->option('upsert') ? '1' : '0'));
            $this->line('status_mode='.$status);
            $this->line('files_found='.(string) count($documents));
            $this->line('articles_found='.(string) $summary['articles_found']);
            $this->line('will_create='.(string) $summary['will_create']);
            $this->line('will_update='.(string) $summary['will_update']);
            $this->line('will_skip='.(string) $summary['will_skip']);
            $this->line('errors_count='.(string) $summary['errors_count']);

            $this->info((bool) $this->option('dry-run') ? 'dry-run complete' : 'import complete');

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveSourceDir(?string $sourceDir = null): string
    {
        $candidate = trim((string) $sourceDir);

        if ($candidate === '') {
            $candidate = base_path('../content_baselines/articles');
        } elseif (! str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
            $candidate = base_path($candidate);
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException(sprintf(
                'Article baseline source directory not found: %s',
                $candidate,
            ));
        }

        return $resolved;
    }

    /**
     * @param  array<int, string>  $selectedLocales
     * @return array<int, array{file: string, payload: array<string, mixed>}>
     */
    private function readDocuments(string $sourceDir, array $selectedLocales = []): array
    {
        $files = $selectedLocales === []
            ? glob(rtrim($sourceDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'articles.*.json') ?: []
            : array_map(
                static fn (string $locale): string => rtrim($sourceDir, DIRECTORY_SEPARATOR)
                    .DIRECTORY_SEPARATOR
                    .sprintf('articles.%s.json', $locale),
                $selectedLocales,
            );

        if ($files === []) {
            throw new RuntimeException(sprintf(
                'No article baseline files found in %s.',
                $sourceDir,
            ));
        }

        sort($files);

        $documents = [];
        foreach ($files as $file) {
            if (! is_file($file)) {
                throw new RuntimeException(sprintf(
                    'Article baseline file missing: %s',
                    $file,
                ));
            }

            $raw = file_get_contents($file);
            if (! is_string($raw) || trim($raw) === '') {
                throw new RuntimeException(sprintf(
                    'Article baseline file is empty: %s',
                    $file,
                ));
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new RuntimeException(sprintf(
                    'Article baseline file is not valid JSON: %s',
                    $file,
                ));
            }

            $documents[] = [
                'file' => $file,
                'payload' => $decoded,
            ];
        }

        return $documents;
    }

    /**
     * @param  array<int, array{file: string, payload: array<string, mixed>}>  $documents
     * @param  array<int, string>  $selectedArticles
     * @return array<int, array<string, mixed>>
     */
    private function normalizeArticles(array $documents, array $selectedArticles = []): array
    {
        $rows = [];
        $seen = [];

        foreach ($documents as $document) {
            $file = (string) ($document['file'] ?? 'unknown');
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $documentLocale = $this->normalizeLocale($meta['locale'] ?? null, $file);
            $articles = $payload['articles'] ?? null;

            if (! is_array($articles)) {
                throw new RuntimeException(sprintf(
                    'Baseline file %s must contain an articles array.',
                    $file,
                ));
            }

            foreach ($articles as $index => $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(sprintf(
                        'Baseline file %s contains a non-object article row at index %d.',
                        $file,
                        $index,
                    ));
                }

                $normalized = $this->normalizeArticleRow($row, $documentLocale, $file, (int) $index);
                if ($selectedArticles !== [] && ! in_array((string) $normalized['slug'], $selectedArticles, true)) {
                    continue;
                }

                $unique = (string) $normalized['locale'].'|'.(string) $normalized['slug'];
                if (isset($seen[$unique])) {
                    throw new RuntimeException(sprintf(
                        'Duplicate article slug %s for locale %s in baseline file %s.',
                        (string) $normalized['slug'],
                        (string) $normalized['locale'],
                        $file,
                    ));
                }

                $seen[$unique] = true;
                $rows[] = $normalized;
            }
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [$left['locale'], $left['slug']]
                <=> [$right['locale'], $right['slug']],
        );

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeArticleRow(
        array $row,
        string $documentLocale,
        string $file,
        int $index,
    ): array {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if ($slug === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing slug at articles[%d].',
                $file,
                $index,
            ));
        }

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing title for slug=%s.',
                $file,
                $slug,
            ));
        }

        $excerpt = trim((string) ($row['excerpt'] ?? ''));
        if ($excerpt === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing excerpt for slug=%s.',
                $file,
                $slug,
            ));
        }

        $contentMd = trim((string) ($row['content_md'] ?? ''));
        if ($contentMd === '') {
            throw new RuntimeException(sprintf(
                'Baseline file %s is missing content_md for slug=%s.',
                $file,
                $slug,
            ));
        }

        $locale = array_key_exists('locale', $row)
            ? $this->normalizeLocale($row['locale'], $file)
            : $documentLocale;
        if ($locale !== $documentLocale) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has locale mismatch for slug=%s.',
                $file,
                $slug,
            ));
        }

        $publishedAt = $this->normalizeNullableDateString($row['published_at'] ?? null, $file, $slug);
        $coverImageVariants = $this->normalizeImageVariants($row['cover_image_variants'] ?? null, $file, $slug);

        return [
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'excerpt' => $excerpt,
            'content_md' => $contentMd,
            'author_name' => $this->normalizeNullableString($row['author_name'] ?? null),
            'reviewer_name' => $this->normalizeNullableString($row['reviewer_name'] ?? null),
            'reading_minutes' => $this->normalizeNullablePositiveInteger($row['reading_minutes'] ?? null, $file, $slug, 'reading_minutes'),
            'cover_image_url' => $this->normalizeNullableString($row['cover_image_url'] ?? null),
            'cover_image_alt' => $this->normalizeNullableString($row['cover_image_alt'] ?? null),
            'cover_image_width' => $this->normalizeNullablePositiveInteger($row['cover_image_width'] ?? null, $file, $slug, 'cover_image_width'),
            'cover_image_height' => $this->normalizeNullablePositiveInteger($row['cover_image_height'] ?? null, $file, $slug, 'cover_image_height'),
            'cover_image_variants' => $coverImageVariants,
            'related_test_slug' => $this->normalizeNullableSlug($row['related_test_slug'] ?? null),
            'voice' => $this->normalizeNullableString($row['voice'] ?? null),
            'voice_order' => $this->normalizeNullablePositiveInteger($row['voice_order'] ?? null, $file, $slug, 'voice_order'),
            'category' => $this->normalizeNullableString($row['category'] ?? null),
            'tags' => $this->normalizeStringList($row['tags'] ?? []),
            'status' => $this->normalizeStatus($row['status'] ?? 'published', $file, $slug),
            'is_public' => (bool) ($row['is_public'] ?? true),
            'is_indexable' => (bool) ($row['is_indexable'] ?? true),
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{dry_run: bool, upsert: bool, status: string}  $options
     * @return array<string, int>
     */
    private function importRows(
        array $rows,
        array $options,
        ArticleService $articleService,
        ArticlePublishService $articlePublishService,
    ): array {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $upsert = (bool) ($options['upsert'] ?? false);
        $statusMode = (string) ($options['status'] ?? 'published');

        $summary = [
            'articles_found' => count($rows),
            'will_create' => 0,
            'will_update' => 0,
            'will_skip' => 0,
            'errors_count' => 0,
        ];

        $planned = [];
        foreach ($rows as $row) {
            $existing = $this->findExistingArticle((string) $row['slug'], (string) $row['locale']);
            $desired = $this->desiredState($row, $statusMode);

            if (! $existing instanceof Article) {
                $planned[] = ['action' => 'create', 'row' => $row, 'desired' => $desired, 'existing' => null];
                $summary['will_create']++;

                continue;
            }

            if (! $upsert) {
                $planned[] = ['action' => 'skip', 'row' => $row, 'desired' => $desired, 'existing' => $existing];
                $summary['will_skip']++;

                continue;
            }

            if ($this->sameState($existing, $desired)) {
                $planned[] = ['action' => 'skip', 'row' => $row, 'desired' => $desired, 'existing' => $existing];
                $summary['will_skip']++;

                continue;
            }

            $planned[] = ['action' => 'update', 'row' => $row, 'desired' => $desired, 'existing' => $existing];
            $summary['will_update']++;
        }

        if ($dryRun) {
            return $summary;
        }

        foreach ($planned as $operation) {
            if ((string) $operation['action'] === 'skip') {
                continue;
            }

            $row = (array) $operation['row'];
            $desired = (array) $operation['desired'];
            $existing = $operation['existing'] instanceof Article ? $operation['existing'] : null;

            if (! $existing instanceof Article) {
                $categoryId = $this->resolveCategoryId($row);
                $tagIds = $this->resolveTagIds($row);
                $existing = $articleService->createArticle(
                    (string) $row['title'],
                    (string) $row['slug'],
                    (string) $row['locale'],
                    (string) $row['content_md'],
                    $categoryId,
                    $tagIds,
                    0,
                );
            }

            $categoryId = $this->resolveCategoryId($row);
            $tagIds = $this->resolveTagIds($row);
            $article = $articleService->updateArticle((int) $existing->id, [
                'title' => (string) $desired['title'],
                'slug' => (string) $desired['slug'],
                'locale' => (string) $desired['locale'],
                'category_id' => $categoryId,
                'excerpt' => (string) $desired['excerpt'],
                'content_md' => (string) $desired['content_md'],
                'content_html' => null,
                'author_name' => $desired['author_name'],
                'reviewer_name' => $desired['reviewer_name'],
                'reading_minutes' => $desired['reading_minutes'],
                'cover_image_url' => $desired['cover_image_url'],
                'cover_image_alt' => $desired['cover_image_alt'],
                'cover_image_width' => $desired['cover_image_width'],
                'cover_image_height' => $desired['cover_image_height'],
                'cover_image_variants' => $desired['cover_image_variants'],
                'related_test_slug' => $desired['related_test_slug'],
                'voice' => $desired['voice'],
                'voice_order' => $desired['voice_order'],
                'is_indexable' => (bool) $desired['is_indexable'],
            ], $tagIds);

            if ((string) $desired['status'] === 'published') {
                if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
                    $article = $articlePublishService->publishArticle((int) $article->id);
                }

                if (is_string($desired['published_at']) && $desired['published_at'] !== '') {
                    $article = $articleService->updateArticle((int) $article->id, [
                        'status' => 'published',
                        'is_public' => true,
                        'published_at' => $desired['published_at'],
                    ]);
                }
            } else {
                if ((string) $article->status !== 'draft' || (bool) $article->is_public) {
                    $article = $articlePublishService->unpublishArticle((int) $article->id);
                }

                $articleService->updateArticle((int) $article->id, [
                    'status' => 'draft',
                    'is_public' => false,
                    'published_at' => null,
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function desiredState(array $row, string $statusMode): array
    {
        $status = $statusMode;
        $isPublic = $statusMode === 'published';

        return [
            'slug' => (string) $row['slug'],
            'locale' => (string) $row['locale'],
            'title' => (string) $row['title'],
            'excerpt' => (string) $row['excerpt'],
            'content_md' => (string) $row['content_md'],
            'author_name' => $row['author_name'],
            'reviewer_name' => $row['reviewer_name'],
            'reading_minutes' => $row['reading_minutes'],
            'cover_image_url' => $row['cover_image_url'],
            'cover_image_alt' => $row['cover_image_alt'],
            'cover_image_width' => $row['cover_image_width'],
            'cover_image_height' => $row['cover_image_height'],
            'cover_image_variants' => $row['cover_image_variants'],
            'related_test_slug' => $row['related_test_slug'],
            'voice' => $row['voice'],
            'voice_order' => $row['voice_order'],
            'category' => $row['category'],
            'tags' => $row['tags'],
            'status' => $status,
            'is_public' => $isPublic,
            'is_indexable' => (bool) $row['is_indexable'],
            'published_at' => $status === 'published'
                ? (is_string($row['published_at']) && $row['published_at'] !== '' ? $row['published_at'] : null)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $desired
     */
    private function sameState(Article $article, array $desired): bool
    {
        if ((string) $article->title !== (string) $desired['title']) {
            return false;
        }
        if ((string) ($article->excerpt ?? '') !== (string) $desired['excerpt']) {
            return false;
        }
        if ((string) $article->content_md !== (string) $desired['content_md']) {
            return false;
        }
        if ((string) ($article->author_name ?? '') !== (string) ($desired['author_name'] ?? '')) {
            return false;
        }
        if ((string) ($article->reviewer_name ?? '') !== (string) ($desired['reviewer_name'] ?? '')) {
            return false;
        }
        if (($article->reading_minutes !== null ? (int) $article->reading_minutes : null) !== ($desired['reading_minutes'] ?? null)) {
            return false;
        }
        if ((string) ($article->cover_image_url ?? '') !== (string) ($desired['cover_image_url'] ?? '')) {
            return false;
        }
        if ((string) ($article->cover_image_alt ?? '') !== (string) ($desired['cover_image_alt'] ?? '')) {
            return false;
        }
        if (($article->cover_image_width !== null ? (int) $article->cover_image_width : null) !== ($desired['cover_image_width'] ?? null)) {
            return false;
        }
        if (($article->cover_image_height !== null ? (int) $article->cover_image_height : null) !== ($desired['cover_image_height'] ?? null)) {
            return false;
        }
        if (($article->cover_image_variants ?? null) !== ($desired['cover_image_variants'] ?? null)) {
            return false;
        }
        if ((string) ($article->related_test_slug ?? '') !== (string) ($desired['related_test_slug'] ?? '')) {
            return false;
        }
        if ((string) ($article->voice ?? '') !== (string) ($desired['voice'] ?? '')) {
            return false;
        }
        if (($article->voice_order !== null ? (int) $article->voice_order : null) !== ($desired['voice_order'] ?? null)) {
            return false;
        }
        if ($this->currentCategoryName($article) !== ($desired['category'] ?? null)) {
            return false;
        }
        if ($this->currentTagNames($article) !== ($desired['tags'] ?? [])) {
            return false;
        }
        if ((bool) $article->is_indexable !== (bool) $desired['is_indexable']) {
            return false;
        }
        if ((string) $article->status !== (string) $desired['status']) {
            return false;
        }
        if ((bool) $article->is_public !== (bool) $desired['is_public']) {
            return false;
        }

        $desiredPublishedAt = is_string($desired['published_at']) ? $desired['published_at'] : null;
        $currentPublishedAt = $article->published_at?->copy()->utc()->toISOString();

        return $desiredPublishedAt === $currentPublishedAt;
    }

    private function findExistingArticle(string $slug, string $locale): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first();
    }

    private function resolveCategoryId(array $row): ?int
    {
        $name = $this->normalizeNullableString($row['category'] ?? null);
        if ($name === null) {
            return null;
        }

        $category = ArticleCategory::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                [
                    'org_id' => 0,
                    'name' => $name,
                ],
                [
                    'slug' => $this->resolveUniqueTaxonomySlug(ArticleCategory::class, $name),
                    'description' => null,
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            );

        return (int) $category->id;
    }

    /**
     * @return list<int>
     */
    private function resolveTagIds(array $row): array
    {
        $ids = [];
        foreach ($this->normalizeStringList($row['tags'] ?? []) as $name) {
            $tag = ArticleTag::query()
                ->withoutGlobalScopes()
                ->firstOrCreate(
                    [
                        'org_id' => 0,
                        'name' => $name,
                    ],
                    [
                        'slug' => $this->resolveUniqueTaxonomySlug(ArticleTag::class, $name),
                        'is_active' => true,
                    ],
                );
            $ids[] = (int) $tag->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  class-string<ArticleCategory|ArticleTag>  $modelClass
     */
    private function resolveUniqueTaxonomySlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = substr(md5($name), 0, 12);
        }

        $base = substr($base, 0, 127);
        $candidate = $base;
        $suffix = 2;

        while ($modelClass::query()->withoutGlobalScopes()->where('org_id', 0)->where('slug', $candidate)->exists()) {
            $suffixPart = '-'.$suffix;
            $candidate = substr($base, 0, max(1, 127 - strlen($suffixPart))).$suffixPart;
            $suffix++;
        }

        return $candidate;
    }

    private function currentCategoryName(Article $article): ?string
    {
        if ($article->category_id === null) {
            return null;
        }

        $name = ArticleCategory::query()
            ->withoutGlobalScopes()
            ->where('id', (int) $article->category_id)
            ->value('name');

        return $this->normalizeNullableString($name);
    }

    /**
     * @return list<string>
     */
    private function currentTagNames(Article $article): array
    {
        return ArticleTag::query()
            ->withoutGlobalScopes()
            ->select('article_tags.name')
            ->join('article_tag_map', 'article_tag_map.tag_id', '=', 'article_tags.id')
            ->where('article_tag_map.org_id', 0)
            ->where('article_tag_map.article_id', (int) $article->id)
            ->orderBy('article_tags.name')
            ->pluck('article_tags.name')
            ->map(fn ($name): string => (string) $name)
            ->filter(fn (string $name): bool => trim($name) !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $selectedLocales
     * @return array<int, string>
     */
    private function normalizeSelectedLocales(array $selectedLocales): array
    {
        $normalized = [];

        foreach ($selectedLocales as $locale) {
            $candidate = trim((string) $locale);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === 'zh') {
                $candidate = 'zh-CN';
            }
            if (! in_array($candidate, self::SUPPORTED_LOCALES, true)) {
                throw new RuntimeException(sprintf(
                    'Unsupported locale selection: %s',
                    $candidate,
                ));
            }
            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<int, string>  $selectedArticles
     * @return array<int, string>
     */
    private function normalizeSelectedArticles(array $selectedArticles): array
    {
        $normalized = [];

        foreach ($selectedArticles as $slug) {
            $candidate = strtolower(trim((string) $slug));
            if ($candidate === '') {
                continue;
            }

            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
    }

    private function normalizeLocale(mixed $locale, string $file): string
    {
        $candidate = trim((string) $locale);
        if ($candidate === 'zh') {
            $candidate = 'zh-CN';
        }
        if (! in_array($candidate, self::SUPPORTED_LOCALES, true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has unsupported locale: %s',
                $file,
                $candidate,
            ));
        }

        return $candidate;
    }

    private function normalizeStatus(mixed $status, string $file, string $slug): string
    {
        $normalized = strtolower(trim((string) $status));
        if (! in_array($normalized, ['draft', 'published'], true)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid status=%s for slug=%s.',
                $file,
                (string) $status,
                $slug,
            ));
        }

        return $normalized;
    }

    private function normalizeNullableDateString(mixed $value, string $file, string $slug): ?string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === '') {
            return null;
        }

        try {
            return Carbon::parse($candidate)->utc()->toISOString();
        } catch (\Throwable) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid published_at=%s for slug=%s.',
                $file,
                $candidate,
                $slug,
            ));
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeNullableSlug(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);

        return $normalized !== null ? strtolower($normalized) : null;
    }

    private function normalizeNullablePositiveInteger(mixed $value, string $file, string $slug, string $field): ?int
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $candidate) !== 1 || (int) $candidate <= 0) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid %s=%s for slug=%s.',
                $file,
                $field,
                $candidate,
                $slug,
            ));
        }

        return (int) $candidate;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = $this->normalizeNullableString($item);
            if ($normalized === null) {
                continue;
            }
            $items[$normalized] = $normalized;
        }

        sort($items);

        return array_values($items);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeImageVariants(mixed $value, string $file, string $slug): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_array($value)) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has invalid cover_image_variants for slug=%s.',
                $file,
                $slug,
            ));
        }

        return $value !== [] ? $value : null;
    }
}
