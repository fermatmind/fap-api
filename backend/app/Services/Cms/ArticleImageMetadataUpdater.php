<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Support\Facades\DB;

final class ArticleImageMetadataUpdater
{
    private const REQUIRED_VARIANTS = ['hero', 'card', 'thumbnail', 'og', 'preload'];

    private const SAFETY_FLAGS = [
        'no_publish',
        'no_schema',
        'no_hreflang',
        'no_search',
        'no_sitemap_llms_change',
    ];

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options): array
    {
        $execute = (bool) ($options['execute'] ?? false);
        $dryRun = ! $execute;
        $errors = [];
        $warnings = [];
        $articleIds = $this->articleIds((string) ($options['article_ids'] ?? ''), $errors);
        $translationGroupId = trim((string) ($options['translation_group_id'] ?? ''));
        $metadataPath = trim((string) ($options['resolved_metadata'] ?? ''));

        if ($translationGroupId === '') {
            $errors[] = $this->issue('translation_group_id', 'translation_group_id_required', 'Expected translation_group_id is required.');
        }

        if ((bool) ($options['dry_run'] ?? false) && $execute) {
            $errors[] = $this->issue('dry_run', 'execute_dry_run_conflict', '--execute cannot be combined with --dry-run.');
        }

        if ($execute) {
            foreach (self::SAFETY_FLAGS as $flag) {
                if ((bool) ($options[$flag] ?? false) !== true) {
                    $errors[] = $this->issue($flag, 'required_safety_flag_missing', 'All no-side-effect safety flags are required for execute mode.');
                }
            }
        }

        $metadata = $this->readResolvedMetadata($metadataPath, $errors);
        $normalized = $this->normalizeResolvedMetadata($metadata, $errors);
        $articles = $this->resolveArticles($articleIds);
        $this->validateArticleLock($articles, $articleIds, $translationGroupId, $errors);

        $before = array_map(fn (Article $article): array => $this->snapshot($article), $articles);
        $after = [];
        foreach ($articles as $article) {
            $after[] = $this->plannedSnapshot($article, $normalized);
        }

        $protectedDiffs = $this->protectedDiffs($before, $after);
        if ($protectedDiffs !== []) {
            $errors[] = $this->issue('protected_fields', 'protected_field_would_change', 'Planned update would change a protected article field.', [
                'diffs' => $protectedDiffs,
            ]);
        }

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleIds, $translationGroupId, $metadataPath, $before, $after, $errors, $warnings);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_update_image_metadata', $articleIds, $translationGroupId, $metadataPath, $before, $after, [], $warnings);
        }

        DB::transaction(function () use ($articles, $normalized): void {
            foreach ($articles as $article) {
                $this->applyMetadata($article, $normalized);
            }
        });

        $fresh = $this->resolveArticles($articleIds);
        $afterWrite = array_map(fn (Article $article): array => $this->snapshot($article), $fresh);

        return $this->summary(true, false, 'updated_image_metadata', $articleIds, $translationGroupId, $metadataPath, $before, $afterWrite, [], $warnings);
    }

    /**
     * @param  list<int>  $ids
     * @return list<Article>
     */
    private function resolveArticles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Article> $articles */
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes()])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (Article $article): int => array_search((int) $article->id, $ids, true))
            ->values()
            ->all();

        return $articles;
    }

    /**
     * @param  list<Article>  $articles
     * @param  list<int>  $articleIds
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateArticleLock(array $articles, array $articleIds, string $translationGroupId, array &$errors): void
    {
        $foundIds = array_map(static fn (Article $article): int => (int) $article->id, $articles);
        foreach ($articleIds as $id) {
            if (! in_array($id, $foundIds, true)) {
                $errors[] = $this->issue('article_ids', 'article_not_found', 'Requested article id was not found.', ['article_id' => $id]);
            }
        }

        foreach ($articles as $article) {
            if ((string) $article->translation_group_id !== $translationGroupId) {
                $errors[] = $this->issue('article.'.$article->id.'.translation_group_id', 'translation_group_id_mismatch', 'Article translation_group_id does not match expected lock.', [
                    'article_id' => (int) $article->id,
                    'actual' => (string) $article->translation_group_id,
                ]);
            }

            if ((string) $article->status !== 'published' || ! (bool) $article->is_public || ! (int) $article->published_revision_id) {
                $errors[] = $this->issue('article.'.$article->id.'.status', 'article_not_published_public', 'Image metadata updates are limited to published public articles with a published revision.', [
                    'article_id' => (int) $article->id,
                ]);
            }

            if (! $article->seoMeta instanceof ArticleSeoMeta) {
                $errors[] = $this->issue('article.'.$article->id.'.seo_meta', 'article_seo_meta_missing', 'Article SEO meta row is required so canonical/schema/hreflang locks can be verified.', [
                    'article_id' => (int) $article->id,
                ]);

                continue;
            }

            $canonical = trim((string) $article->seoMeta->canonical_url);
            if ($canonical === '' || str_starts_with($canonical, '/ops/') || str_contains($canonical, '/article-preview/')) {
                $errors[] = $this->issue('article.'.$article->id.'.canonical_url', 'canonical_url_invalid', 'Article canonical URL must be present and public canonical.', [
                    'article_id' => (int) $article->id,
                    'canonical_url' => $canonical,
                ]);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function normalizeResolvedMetadata(array $metadata, array &$errors): array
    {
        if ($this->containsPlaceholder($metadata)) {
            $errors[] = $this->issue('resolved_metadata', 'placeholder_not_allowed', 'Resolved metadata must not contain CMS media placeholders.');
        }

        $coverUrl = $this->publicUrl($metadata['cover_image_url'] ?? null, 'cover_image_url', $errors);
        $ogUrl = $this->publicUrl($metadata['og_image_url'] ?? null, 'og_image_url', $errors);
        $twitterUrl = $this->publicUrl($metadata['twitter_image_url'] ?? ($metadata['og_image_url'] ?? null), 'twitter_image_url', $errors);
        $bodyVisualUrl = $this->optionalPublicUrl($metadata['body_visual_image_url'] ?? null, 'body_visual_image_url', $errors);

        $alt = trim((string) ($metadata['cover_image_alt'] ?? ''));
        if ($alt === '' || mb_strlen($alt) > 255) {
            $errors[] = $this->issue('cover_image_alt', 'cover_image_alt_invalid', 'cover_image_alt is required and must be <=255 characters.');
        }

        $width = (int) ($metadata['cover_image_width'] ?? 0);
        $height = (int) ($metadata['cover_image_height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            $errors[] = $this->issue('cover_dimensions', 'cover_dimensions_invalid', 'cover_image_width and cover_image_height must be positive integers.');
        }

        $variants = is_array($metadata['cover_image_variants'] ?? null) ? $metadata['cover_image_variants'] : [];
        foreach (self::REQUIRED_VARIANTS as $variantKey) {
            $variant = $variants[$variantKey] ?? null;
            if (! is_array($variant)) {
                $errors[] = $this->issue('cover_image_variants.'.$variantKey, 'cover_variant_missing', 'Required cover image variant is missing.', [
                    'variant' => $variantKey,
                ]);

                continue;
            }

            $variantUrl = $this->publicUrl($variant['url'] ?? null, 'cover_image_variants.'.$variantKey.'.url', $errors);
            $variants[$variantKey]['url'] = $variantUrl;
            $variants[$variantKey]['width'] = (int) ($variant['width'] ?? 0);
            $variants[$variantKey]['height'] = (int) ($variant['height'] ?? 0);
            if ((int) $variants[$variantKey]['width'] <= 0 || (int) $variants[$variantKey]['height'] <= 0) {
                $errors[] = $this->issue('cover_image_variants.'.$variantKey, 'cover_variant_dimensions_invalid', 'Variant width and height must be positive integers.', [
                    'variant' => $variantKey,
                ]);
            }
        }

        $coverMediaAssetKey = trim((string) ($metadata['cover_media_asset_key'] ?? ''));
        if ($coverMediaAssetKey === '') {
            $errors[] = $this->issue('cover_media_asset_key', 'cover_media_asset_key_required', 'cover_media_asset_key is required.');
        }

        $variants['editorial_package_v1'] = [
            'cover_media_asset_key' => $coverMediaAssetKey,
            'social_image_metadata' => is_array($metadata['social_image_metadata'] ?? null) ? $metadata['social_image_metadata'] : [],
            'body_visual_asset_key' => trim((string) ($metadata['body_visual_asset_key'] ?? '')),
            'body_visual_image_url' => $bodyVisualUrl,
            'body_visual_fallback_authorized' => (bool) ($metadata['body_visual_fallback_authorized'] ?? false),
            'image_metadata_updated_by' => 'articles:update-image-metadata',
        ];

        return [
            'cover_media_asset_key' => $coverMediaAssetKey,
            'cover_image_url' => $coverUrl,
            'cover_image_alt' => $alt,
            'cover_image_width' => $width,
            'cover_image_height' => $height,
            'cover_image_variants' => $variants,
            'og_image_url' => $ogUrl,
            'twitter_image_url' => $twitterUrl,
            'body_visual_image_url' => $bodyVisualUrl,
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function applyMetadata(Article $article, array $normalized): void
    {
        $article->forceFill([
            'cover_image_url' => $normalized['cover_image_url'],
            'cover_image_alt' => $normalized['cover_image_alt'],
            'cover_image_width' => $normalized['cover_image_width'],
            'cover_image_height' => $normalized['cover_image_height'],
            'cover_image_variants' => $normalized['cover_image_variants'],
        ])->saveQuietly();

        /** @var ArticleSeoMeta $seoMeta */
        $seoMeta = $article->seoMeta;
        $seoMeta->forceFill([
            'og_image_url' => $normalized['og_image_url'],
        ])->saveQuietly();
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Article $article): array
    {
        $article->loadMissing(['seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes()]);
        $seoMeta = $article->seoMeta;

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'title' => (string) $article->title,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'working_revision_id' => (int) $article->working_revision_id,
            'published_revision_id' => (int) $article->published_revision_id,
            'canonical_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null,
            'robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
            'seo_is_indexable' => $seoMeta instanceof ArticleSeoMeta ? (bool) $seoMeta->is_indexable : null,
            'schema_json_sha256' => $seoMeta instanceof ArticleSeoMeta ? hash('sha256', (string) json_encode($seoMeta->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) : null,
            'cover_image_url' => $article->cover_image_url,
            'cover_image_alt' => $article->cover_image_alt,
            'cover_image_width' => $article->cover_image_width,
            'cover_image_height' => $article->cover_image_height,
            'cover_image_variants' => $article->cover_image_variants,
            'og_image_url' => $seoMeta instanceof ArticleSeoMeta ? $seoMeta->og_image_url : null,
            'revision_count' => ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array<string,mixed>
     */
    private function plannedSnapshot(Article $article, array $normalized): array
    {
        $snapshot = $this->snapshot($article);
        $snapshot['cover_image_url'] = $normalized['cover_image_url'];
        $snapshot['cover_image_alt'] = $normalized['cover_image_alt'];
        $snapshot['cover_image_width'] = $normalized['cover_image_width'];
        $snapshot['cover_image_height'] = $normalized['cover_image_height'];
        $snapshot['cover_image_variants'] = $normalized['cover_image_variants'];
        $snapshot['og_image_url'] = $normalized['og_image_url'];

        return $snapshot;
    }

    /**
     * @param  list<array<string,mixed>>  $before
     * @param  list<array<string,mixed>>  $after
     * @return list<array<string,mixed>>
     */
    private function protectedDiffs(array $before, array $after): array
    {
        $protectedFields = [
            'locale',
            'slug',
            'translation_group_id',
            'title',
            'status',
            'is_public',
            'is_indexable',
            'sitemap_eligible',
            'llms_eligible',
            'working_revision_id',
            'published_revision_id',
            'canonical_url',
            'robots',
            'seo_is_indexable',
            'schema_json_sha256',
            'revision_count',
        ];

        $diffs = [];
        foreach ($before as $index => $beforeSnapshot) {
            $afterSnapshot = $after[$index] ?? [];
            foreach ($protectedFields as $field) {
                if (($beforeSnapshot[$field] ?? null) !== ($afterSnapshot[$field] ?? null)) {
                    $diffs[] = [
                        'article_id' => (int) ($beforeSnapshot['article_id'] ?? 0),
                        'field' => $field,
                    ];
                }
            }
        }

        return $diffs;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return list<int>
     */
    private function articleIds(string $raw, array &$errors): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (string $value): int => is_numeric(trim($value)) ? (int) trim($value) : 0,
            explode(',', $raw)
        ), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            $errors[] = $this->issue('article_ids', 'article_ids_required', 'At least one article id is required.');
        }

        return $ids;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function readResolvedMetadata(string $path, array &$errors): array
    {
        if ($path === '' || ! is_file($path)) {
            $errors[] = $this->issue('resolved_metadata', 'resolved_metadata_file_missing', 'Resolved image metadata JSON file is required.');

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            $errors[] = $this->issue('resolved_metadata', 'resolved_metadata_json_invalid', 'Resolved image metadata must be a JSON object.');

            return [];
        }

        return $decoded;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function publicUrl(mixed $value, string $field, array &$errors): ?string
    {
        $url = PublicMediaUrlGuard::sanitizeNullableUrl($value);
        if ($url === null || mb_strlen($url) > 255) {
            $errors[] = $this->issue($field, 'public_media_url_invalid', 'Image URL must be a public HTTPS Media Library/CDN URL <=255 characters.');
        }

        return $url;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function optionalPublicUrl(mixed $value, string $field, array &$errors): ?string
    {
        if (trim((string) $value) === '') {
            return null;
        }

        return $this->publicUrl($value, $field, $errors);
    }

    private function containsPlaceholder(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, '__CMS_MEDIA_LIBRARY_PLACEHOLDER__') || str_contains($value, '{{');
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->containsPlaceholder($nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param  list<int>  $articleIds
     * @param  list<array<string,mixed>>  $before
     * @param  list<array<string,mixed>>  $after
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function summary(bool $ok, bool $dryRun, string $action, array $articleIds, string $translationGroupId, string $metadataPath, array $before, array $after, array $errors, array $warnings): array
    {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => $ok && ! $dryRun,
            'article_ids' => $articleIds,
            'articles_count' => count($articleIds),
            'translation_group_id' => $translationGroupId,
            'resolved_metadata' => $metadataPath,
            'updates_scope' => [
                'article_fields' => [
                    'cover_image_url',
                    'cover_image_alt',
                    'cover_image_width',
                    'cover_image_height',
                    'cover_image_variants',
                ],
                'article_seo_meta_fields' => [
                    'og_image_url',
                ],
            ],
            'protected_holds' => [
                'no_publish' => true,
                'no_schema' => true,
                'no_hreflang' => true,
                'no_search' => true,
                'no_sitemap_llms_change' => true,
                'no_revision_create' => true,
            ],
            'before' => $before,
            'after' => $after,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
