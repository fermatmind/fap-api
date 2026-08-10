<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Article;

final class PublicArticleAttributionResolver
{
    /**
     * @param  array<string, mixed>|object  $attempt
     * @return array{article_id:int,slug:string,locale:string,canonical_path:string}|null
     */
    public function fromAttempt(array|object $attempt): ?array
    {
        $row = is_array($attempt)
            ? $attempt
            : (method_exists($attempt, 'getAttributes') ? (array) $attempt->getAttributes() : (array) $attempt);
        $summary = $this->decodeArray($row['answers_summary_json'] ?? null);
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];

        if (strtolower(trim((string) ($meta['source_page_type'] ?? ''))) !== 'article_detail') {
            return null;
        }

        $articleId = $this->positiveInteger($meta['content_id'] ?? null);
        $sourceSlug = strtolower(trim((string) ($meta['source_slug'] ?? '')));
        $landingPath = $this->publicPath($meta['landing_path'] ?? null);
        if ($articleId === null || ! $this->validSlug($sourceSlug) || $landingPath === null) {
            return null;
        }

        $article = $this->publicArticle($articleId);
        if ($article === null
            || $article['slug'] !== $sourceSlug
            || $article['canonical_path'] !== $landingPath
            || $article['locale'] !== $this->normalizeLocale($row['locale'] ?? null)) {
            return null;
        }

        return $article;
    }

    /**
     * @return array{article_id:int,slug:string,locale:string,canonical_path:string}|null
     */
    public function byPublicArticleId(int $articleId): ?array
    {
        return $articleId > 0 ? $this->publicArticle($articleId) : null;
    }

    /**
     * @return array{article_id:int,slug:string,locale:string,canonical_path:string}|null
     */
    private function publicArticle(int $articleId): ?array
    {
        /** @var Article|null $article */
        $article = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereKey($articleId)
            ->where('status', 'published')
            ->where('is_public', true)
            ->where(static function ($query): void {
                $query->whereNull('lifecycle_state')
                    ->orWhereNotIn('lifecycle_state', [
                        Article::LIFECYCLE_ARCHIVED,
                        Article::LIFECYCLE_SOFT_DELETED,
                    ]);
            })
            ->first(['id', 'slug', 'locale']);

        if ($article === null) {
            return null;
        }

        $slug = strtolower(trim((string) $article->slug));
        $locale = $this->normalizeLocale($article->locale);
        if (! $this->validSlug($slug) || ! in_array($locale, ['en', 'zh-CN'], true)) {
            return null;
        }

        return [
            'article_id' => (int) $article->id,
            'slug' => $slug,
            'locale' => $locale,
            'canonical_path' => ($locale === 'zh-CN' ? '/zh/articles/' : '/en/articles/').$slug,
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        $candidate = trim((string) $value);
        if (preg_match('/\A[1-9][0-9]*\z/', $candidate) !== 1) {
            return null;
        }

        $integer = filter_var($candidate, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function publicPath(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $candidate = trim((string) $value);
        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        $parts = @parse_url($candidate);
        if (! is_array($parts) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && $host !== 'fermatmind.com' && $host !== 'www.fermatmind.com') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        return preg_match('#\A/(?:en|zh)/articles/[a-z0-9]+(?:-[a-z0-9]+)*\z#', $path) === 1
            ? $path
            : null;
    }

    private function validSlug(string $slug): bool
    {
        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1;
    }

    private function normalizeLocale(mixed $value): string
    {
        $locale = strtolower(str_replace('_', '-', trim((string) $value)));

        return match (true) {
            $locale === 'en', str_starts_with($locale, 'en-') => 'en',
            $locale === 'zh', str_starts_with($locale, 'zh-') => 'zh-CN',
            default => 'unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
