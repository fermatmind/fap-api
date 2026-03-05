<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ArticlePublishService
{
    public function publishArticle(int $articleId): Article
    {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        return DB::transaction(function () use ($articleId): Article {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            $this->assertPublishable($article);

            $article->status = 'published';
            $article->is_public = true;
            $article->published_at = now();
            $article->save();

            return $article->fresh() ?? $article;
        });
    }

    public function unpublishArticle(int $articleId): Article
    {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        return DB::transaction(function () use ($articleId): Article {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            $article->status = 'draft';
            $article->is_public = false;
            $article->save();

            return $article->fresh() ?? $article;
        });
    }

    private function assertPublishable(Article $article): void
    {
        if (trim((string) $article->slug) === '') {
            throw new InvalidArgumentException('slug must exist before publish.');
        }

        if (trim((string) $article->title) === '') {
            throw new InvalidArgumentException('title must exist before publish.');
        }

        if (trim((string) $article->content_md) === '') {
            throw new InvalidArgumentException('content_md must exist before publish.');
        }
    }
}
