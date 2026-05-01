<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleRevision;
use App\Models\ArticleTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ArticleService
{
    /**
     * @param  array<int,mixed>  $tags
     */
    public function createArticle(
        string $title,
        ?string $slug,
        string $locale,
        string $contentMd,
        ?int $categoryId,
        array $tags,
        int $orgId
    ): Article {
        $normalizedTitle = trim($title);
        if ($normalizedTitle === '') {
            throw new InvalidArgumentException('title is required.');
        }

        $normalizedLocale = $this->normalizeLocale($locale);
        $normalizedContentMd = trim($contentMd);
        if ($normalizedContentMd === '') {
            throw new InvalidArgumentException('content_md is required.');
        }

        $normalizedOrgId = max(0, $orgId);
        $resolvedSlug = $this->resolveUniqueSlug(
            $normalizedTitle,
            $slug,
            $normalizedOrgId,
            $normalizedLocale,
            null
        );
        $resolvedCategoryId = $this->resolveCategoryId($categoryId, $normalizedOrgId);
        $tagIds = $this->resolveTagIds($tags, $normalizedOrgId);

        return DB::transaction(function () use (
            $normalizedOrgId,
            $resolvedCategoryId,
            $resolvedSlug,
            $normalizedLocale,
            $normalizedTitle,
            $normalizedContentMd,
            $tagIds
        ): Article {
            $article = Article::query()->create([
                'org_id' => $normalizedOrgId,
                'category_id' => $resolvedCategoryId,
                'slug' => $resolvedSlug,
                'locale' => $normalizedLocale,
                'title' => $normalizedTitle,
                'content_md' => $normalizedContentMd,
                'status' => 'draft',
                'is_public' => false,
                'is_indexable' => true,
            ]);

            $this->syncArticleTags($article, $tagIds, $normalizedOrgId);
            $this->createRevision($article, 1, 'initial');

            return $article->fresh() ?? $article;
        });
    }

    /**
     * @param  array<string,mixed>  $fields
     * @param  array<int,mixed>|null  $tags
     */
    public function updateArticle(int $articleId, array $fields, ?array $tags = null): Article
    {
        if ($articleId <= 0) {
            throw new InvalidArgumentException('article_id must be positive.');
        }

        return DB::transaction(function () use ($articleId, $fields, $tags): Article {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('id', $articleId)
                ->lockForUpdate()
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException('article not found.');
            }

            $orgId = (int) $article->org_id;
            $nextLocale = array_key_exists('locale', $fields)
                ? $this->normalizeLocale((string) $fields['locale'])
                : (string) $article->locale;

            $nextTitle = array_key_exists('title', $fields)
                ? trim((string) $fields['title'])
                : (string) $article->title;
            if ($nextTitle === '') {
                throw new InvalidArgumentException('title is required.');
            }

            $nextContentMd = array_key_exists('content_md', $fields)
                ? trim((string) $fields['content_md'])
                : (string) $article->content_md;
            if ($nextContentMd === '') {
                throw new InvalidArgumentException('content_md is required.');
            }

            $slugInput = array_key_exists('slug', $fields)
                ? (string) ($fields['slug'] ?? '')
                : (string) $article->slug;

            $nextSlug = $this->resolveUniqueSlug(
                $nextTitle,
                $slugInput,
                $orgId,
                $nextLocale,
                (int) $article->id
            );

            if (array_key_exists('category_id', $fields)) {
                $rawCategoryId = $fields['category_id'];
                $fields['category_id'] = $rawCategoryId === null || $rawCategoryId === ''
                    ? null
                    : $this->resolveCategoryId((int) $rawCategoryId, $orgId);
            }

            $allowedFields = [
                'category_id',
                'author_admin_user_id',
                'author_name',
                'reviewer_name',
                'reading_minutes',
                'excerpt',
                'content_html',
                'cover_image_url',
                'cover_image_alt',
                'cover_image_width',
                'cover_image_height',
                'cover_image_variants',
                'related_test_slug',
                'voice',
                'voice_order',
                'is_indexable',
            ];

            $updates = [];
            foreach ($allowedFields as $field) {
                if (! array_key_exists($field, $fields)) {
                    continue;
                }

                $updates[$field] = $fields[$field];
            }

            $updates['slug'] = $nextSlug;
            $updates['locale'] = $nextLocale;
            $updates['title'] = $nextTitle;
            $updates['content_md'] = $nextContentMd;

            $article->fill($updates);
            $article->save();

            if (is_array($tags)) {
                $tagIds = $this->resolveTagIds($tags, $orgId);
                $this->syncArticleTags($article, $tagIds, $orgId);
            }

            $nextRevisionNo = $this->nextRevisionNo((int) $article->id, $orgId);
            $this->createRevision($article, $nextRevisionNo, 'update');

            return $article->fresh() ?? $article;
        });
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = trim($locale);

        return $normalized !== '' ? $normalized : 'en';
    }

    private function resolveUniqueSlug(
        string $title,
        ?string $slug,
        int $orgId,
        string $locale,
        ?int $excludeArticleId
    ): string {
        $requestedSlug = trim((string) $slug);
        $baseSlug = $requestedSlug !== ''
            ? Str::slug($requestedSlug)
            : Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'article';
        }

        $baseSlug = substr($baseSlug, 0, 127);
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->slugExists($candidate, $orgId, $locale, $excludeArticleId)) {
            $suffixPart = '-'.$suffix;
            $maxBaseLength = 127 - strlen($suffixPart);
            $candidate = substr($baseSlug, 0, max(1, $maxBaseLength)).$suffixPart;
            $suffix++;

            if ($suffix > 10000) {
                throw new RuntimeException('failed to generate unique slug.');
            }
        }

        return $candidate;
    }

    private function slugExists(string $slug, int $orgId, string $locale, ?int $excludeArticleId): bool
    {
        $query = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('locale', $locale)
            ->where('slug', $slug);

        if ($excludeArticleId !== null) {
            $query->where('id', '!=', $excludeArticleId);
        }

        return $query->exists();
    }

    private function resolveCategoryId(?int $categoryId, int $orgId): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        if ($categoryId <= 0) {
            throw new InvalidArgumentException('category_id must be a positive integer.');
        }

        $exists = ArticleCategory::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('id', $categoryId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('category does not exist for the specified org.');
        }

        return $categoryId;
    }

    /**
     * @param  array<int,mixed>  $tags
     * @return list<int>
     */
    private function resolveTagIds(array $tags, int $orgId): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tagId = (int) $tag;
            if ($tagId > 0) {
                $normalized[$tagId] = true;
            }
        }

        $tagIds = array_map('intval', array_keys($normalized));
        if ($tagIds === []) {
            return [];
        }

        $existing = ArticleTag::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        sort($existing);
        $expected = $tagIds;
        sort($expected);

        if ($existing !== $expected) {
            throw new InvalidArgumentException('one or more tags do not exist for the specified org.');
        }

        return $tagIds;
    }

    /**
     * @param  list<int>  $tagIds
     */
    private function syncArticleTags(Article $article, array $tagIds, int $orgId): void
    {
        if ($tagIds === []) {
            $article->tags()->sync([]);

            return;
        }

        $now = now();
        $syncPayload = [];
        foreach ($tagIds as $tagId) {
            $syncPayload[$tagId] = [
                'org_id' => $orgId,
                'created_at' => $now,
            ];
        }

        $article->tags()->sync($syncPayload);
    }

    private function nextRevisionNo(int $articleId, int $orgId): int
    {
        $latest = ArticleRevision::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('article_id', $articleId)
            ->orderByDesc('revision_no')
            ->lockForUpdate()
            ->first();

        if (! $latest instanceof ArticleRevision) {
            return 1;
        }

        return ((int) $latest->revision_no) + 1;
    }

    private function createRevision(Article $article, int $revisionNo, ?string $changeNote = null): ArticleRevision
    {
        $snapshotArticle = Article::query()
            ->withoutGlobalScopes()
            ->with(['category', 'tags', 'seoMeta'])
            ->where('id', (int) $article->id)
            ->first();

        $payload = $snapshotArticle instanceof Article
            ? $snapshotArticle->toArray()
            : $article->toArray();
        $payload['snapshot_at'] = now();

        return ArticleRevision::query()
            ->withoutGlobalScopes()
            ->create([
                'org_id' => (int) $article->org_id,
                'article_id' => (int) $article->id,
                'revision_no' => $revisionNo,
                'editor_admin_user_id' => $article->author_admin_user_id !== null
                    ? (int) $article->author_admin_user_id
                    : null,
                'title' => (string) $article->title,
                'excerpt' => $article->excerpt,
                'content_md' => (string) $article->content_md,
                'content_html' => $article->content_html,
                'change_note' => $changeNote,
                'payload_json' => $payload,
                'created_at' => now(),
            ]);
    }
}
