<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ArticleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

        return [
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'excerpt' => $excerpt,
            'content_md' => $contentMd,
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
                $existing = $articleService->createArticle(
                    (string) $row['title'],
                    (string) $row['slug'],
                    (string) $row['locale'],
                    (string) $row['content_md'],
                    null,
                    [],
                    0,
                );
            }

            $article = $articleService->updateArticle((int) $existing->id, [
                'title' => (string) $desired['title'],
                'slug' => (string) $desired['slug'],
                'locale' => (string) $desired['locale'],
                'excerpt' => (string) $desired['excerpt'],
                'content_md' => (string) $desired['content_md'],
                'content_html' => null,
                'is_indexable' => (bool) $desired['is_indexable'],
            ]);

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
}
