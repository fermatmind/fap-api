<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ArticleSeoService
{
    public function generateSeoMeta(int $articleId): ArticleSeoMeta
    {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        return DB::transaction(function () use ($articleId): ArticleSeoMeta {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            $locale = trim((string) $article->locale);
            if ($locale === '') {
                $locale = 'en';
            }

            $title = trim((string) $article->title);
            $descSource = trim((string) ($article->excerpt ?? ''));
            if ($descSource === '') {
                $descSource = $this->extractDescription((string) $article->content_md);
            }

            $seoTitle = Str::limit($title, 60, '');
            $seoDescription = Str::limit($this->normalizeWhitespace($descSource), 160, '');
            $canonicalUrl = $this->buildCanonicalUrl($locale, (string) $article->slug);
            $ogTitle = Str::limit($title, 90, '');
            $ogDescription = Str::limit($this->normalizeWhitespace($descSource), 200, '');

            return ArticleSeoMeta::query()
                ->withoutGlobalScopes()
                ->updateOrCreate(
                    [
                        'org_id' => (int) $article->org_id,
                        'article_id' => (int) $article->id,
                        'locale' => $locale,
                    ],
                    [
                        'seo_title' => $seoTitle,
                        'seo_description' => $seoDescription,
                        'canonical_url' => $canonicalUrl,
                        'og_title' => $ogTitle,
                        'og_description' => $ogDescription,
                        'is_indexable' => (bool) $article->is_indexable,
                        'robots' => (bool) $article->is_indexable ? 'index,follow' : 'noindex,nofollow',
                    ]
                );
        });
    }

    private function extractDescription(string $contentMd): string
    {
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/u', ' ', $contentMd);
        if (! is_string($text)) {
            $text = $contentMd;
        }

        $text = preg_replace('/\[[^\]]+\]\(([^)]+)\)/u', '$1', $text);
        if (! is_string($text)) {
            $text = $contentMd;
        }

        $text = preg_replace('/[#>*_~\-]+/u', ' ', $text);
        if (! is_string($text)) {
            return $this->normalizeWhitespace($contentMd);
        }

        return $this->normalizeWhitespace($text);
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : trim($value);
    }

    private function buildCanonicalUrl(string $locale, string $slug): ?string
    {
        $baseUrl = rtrim((string) config('app.url', ''), '/');
        $slug = trim($slug);

        if ($baseUrl === '' || $slug === '') {
            return null;
        }

        return $baseUrl.'/articles/'.rawurlencode($slug);
    }
}
