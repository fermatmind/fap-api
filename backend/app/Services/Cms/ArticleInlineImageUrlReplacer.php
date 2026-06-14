<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Facades\DB;

final class ArticleInlineImageUrlReplacer
{
    private const SAFETY_FLAGS = [
        'no_publish',
        'no_schema',
        'no_hreflang',
        'no_search',
        'no_sitemap_llms_change',
    ];

    private const PRIVATE_MARKERS = [
        '/result',
        '/results',
        '/orders',
        '/order',
        '/share',
        '/pay',
        '/payment',
        '/history',
        '/take',
        'result_id',
        'order_id',
        'payment_id',
        'report_id',
        'user_id',
        'token',
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
        $oldUrl = trim((string) ($options['old_url'] ?? ''));
        $newUrl = trim((string) ($options['new_url'] ?? ''));

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

        $this->validateUrlFragment($oldUrl, 'old_url', $errors, allowExistingLegacy: true);
        $this->validateUrlFragment($newUrl, 'new_url', $errors, allowExistingLegacy: false);

        if ($oldUrl !== '' && $newUrl !== '' && $oldUrl === $newUrl) {
            $errors[] = $this->issue('new_url', 'replacement_url_same_as_old', 'The replacement URL must differ from the old URL.');
        }

        $articles = $this->resolveArticles($articleIds);
        $this->validateArticleLock($articles, $articleIds, $translationGroupId, $errors);

        $plans = [];
        foreach ($articles as $article) {
            $plans[] = $this->planArticle($article, $oldUrl, $newUrl, $errors);
        }

        $before = array_map(static fn (array $plan): array => $plan['before'], $plans);
        $after = array_map(static fn (array $plan): array => $plan['after'], $plans);

        $protectedDiffs = $this->protectedDiffs($before, $after);
        if ($protectedDiffs !== []) {
            $errors[] = $this->issue('protected_fields', 'protected_field_would_change', 'Planned update would change a protected article field.', [
                'diffs' => $protectedDiffs,
            ]);
        }

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $articleIds, $translationGroupId, $oldUrl, $newUrl, $before, $after, $plans, $errors, $warnings);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_replace_inline_image_url', $articleIds, $translationGroupId, $oldUrl, $newUrl, $before, $after, $plans, [], $warnings);
        }

        DB::transaction(function () use ($plans): void {
            foreach ($plans as $plan) {
                $this->applyPlan($plan);
            }
        });

        $freshArticles = $this->resolveArticles($articleIds);
        $afterWritePlans = [];
        foreach ($freshArticles as $article) {
            $afterWritePlans[] = $this->planArticle($article, $oldUrl, $newUrl, $warnings, postWriteSnapshot: true);
        }

        $afterWrite = array_map(static fn (array $plan): array => $plan['before'], $afterWritePlans);

        return $this->summary(true, false, 'replaced_inline_image_url', $articleIds, $translationGroupId, $oldUrl, $newUrl, $before, $afterWrite, $plans, [], $warnings);
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
            ->with([
                'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            ])
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
                $errors[] = $this->issue('article.'.$article->id.'.status', 'article_not_published_public', 'Inline image replacement is limited to published public articles with a published revision.', [
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

            if (! $article->workingRevision instanceof ArticleTranslationRevision || ! $article->publishedRevision instanceof ArticleTranslationRevision) {
                $errors[] = $this->issue('article.'.$article->id.'.revision', 'current_revision_missing', 'Both working and published revisions are required for inline image replacement.', [
                    'article_id' => (int) $article->id,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateUrlFragment(string $value, string $field, array &$errors, bool $allowExistingLegacy): void
    {
        if ($value === '') {
            $errors[] = $this->issue($field, 'url_required', 'Both old and new image URLs or URL fragments are required.');

            return;
        }

        if (str_contains($value, '__CMS_MEDIA_LIBRARY_PLACEHOLDER__') || str_contains($value, '{{')) {
            $errors[] = $this->issue($field, 'placeholder_not_allowed', 'Image URL replacement values must not contain placeholders.');
        }

        $normalized = strtolower($value);
        foreach (self::PRIVATE_MARKERS as $marker) {
            if (str_contains($normalized, strtolower($marker))) {
                $errors[] = $this->issue($field, 'private_url_marker_not_allowed', 'Image URL replacement values must not contain private route or sensitive query markers.', [
                    'marker' => $marker,
                ]);
            }
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            if (! preg_match('/^https:\/\/(?:api|assets)\.fermatmind\.com\//i', $value)) {
                $errors[] = $this->issue($field, 'public_media_url_invalid', 'Absolute image URLs must use the FermatMind public media hosts.');
            }

            return;
        }

        if (str_contains($value, '://')) {
            $errors[] = $this->issue($field, 'public_media_url_invalid', 'Image URL replacement value must be an HTTPS FermatMind media URL or a relative media URL fragment.');
        }

        if (! $allowExistingLegacy && str_contains($normalized, 'articleriasecexplanationcoverv1')) {
            $errors[] = $this->issue($field, 'legacy_image_url_not_allowed_for_replacement', 'Replacement URL must not point to the legacy RIASEC fallback image.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function planArticle(Article $article, string $oldUrl, string $newUrl, array &$errors, bool $postWriteSnapshot = false): array
    {
        $article->loadMissing([
            'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ]);

        $before = $this->snapshot($article);
        $after = $before;
        $surfaces = [];

        $articleContent = (string) $article->content_md;
        $articleReplacement = $this->replaceExactlyOnce($articleContent, $oldUrl, $newUrl);
        $surfaces['article.content_md'] = [
            'replacement_count' => $articleReplacement['count'],
            'before_sha256' => hash('sha256', $articleContent),
            'after_sha256' => hash('sha256', $articleReplacement['content']),
            'diff' => $this->inlineDiff($articleContent, $articleReplacement['content'], $oldUrl, $newUrl),
        ];

        if (! $postWriteSnapshot && $articleReplacement['count'] !== 1) {
            $errors[] = $this->issue('article.'.$article->id.'.content_md', 'inline_image_replacement_count_not_one', 'Article content_md must contain exactly one old inline image URL occurrence.', [
                'article_id' => (int) $article->id,
                'replacement_count' => $articleReplacement['count'],
            ]);
        }

        foreach (['workingRevision', 'publishedRevision'] as $relationName) {
            $revision = $article->{$relationName};
            if (! $revision instanceof ArticleTranslationRevision) {
                continue;
            }

            $content = (string) $revision->content_md;
            $replacement = $this->replaceExactlyOnce($content, $oldUrl, $newUrl);
            $surfaceKey = $relationName.'.content_md';
            $surfaces[$surfaceKey] = [
                'revision_id' => (int) $revision->id,
                'replacement_count' => $replacement['count'],
                'before_sha256' => hash('sha256', $content),
                'after_sha256' => hash('sha256', $replacement['content']),
                'diff' => $this->inlineDiff($content, $replacement['content'], $oldUrl, $newUrl),
            ];

            if (! $postWriteSnapshot && $replacement['count'] !== 1) {
                $errors[] = $this->issue('article.'.$article->id.'.'.$surfaceKey, 'inline_image_replacement_count_not_one', 'Current revision content_md must contain exactly one old inline image URL occurrence.', [
                    'article_id' => (int) $article->id,
                    'revision_id' => (int) $revision->id,
                    'surface' => $surfaceKey,
                    'replacement_count' => $replacement['count'],
                ]);
            }
        }

        $after['content_md_sha256'] = hash('sha256', $articleReplacement['content']);
        $after['source_version_hash'] = Article::sourceVersionHashFromPayload([
            'locale' => $article->locale,
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $articleReplacement['content'],
            'content_html' => $article->content_html,
            'cover_image_alt' => $article->cover_image_alt,
            'related_test_slug' => $article->related_test_slug,
            'voice' => $article->voice,
            'voice_order' => $article->voice_order,
        ]);

        return [
            'article_id' => (int) $article->id,
            'article' => $article,
            'before' => $before,
            'after' => $after,
            'replacement_content_md' => $articleReplacement['content'],
            'surfaces' => $surfaces,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function applyPlan(array $plan): void
    {
        /** @var Article $article */
        $article = $plan['article'];
        $contentMd = (string) $plan['replacement_content_md'];
        $sourceVersionHash = (string) data_get($plan, 'after.source_version_hash');

        $article->forceFill([
            'content_md' => $contentMd,
            'source_version_hash' => $sourceVersionHash,
        ])->saveQuietly();
        $article->refresh();

        $revisionIds = array_values(array_unique(array_filter([
            (int) $article->working_revision_id,
            (int) $article->published_revision_id,
        ])));

        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->where('article_id', (int) $article->id)
            ->whereIn('id', $revisionIds)
            ->update([
                'content_md' => $contentMd,
                'source_version_hash' => $sourceVersionHash,
                'translated_from_version_hash' => $article->translated_from_version_hash,
            ]);
    }

    /**
     * @return array{content:string,count:int}
     */
    private function replaceExactlyOnce(string $content, string $oldUrl, string $newUrl): array
    {
        $count = $oldUrl === '' ? 0 : substr_count($content, $oldUrl);

        return [
            'content' => $count === 1 ? str_replace($oldUrl, $newUrl, $content) : $content,
            'count' => $count,
        ];
    }

    /**
     * @return array<string,string|null>
     */
    private function inlineDiff(string $before, string $after, string $oldUrl, string $newUrl): array
    {
        return [
            'before_line' => $this->firstLineContaining($before, $oldUrl),
            'after_line' => $this->firstLineContaining($after, $newUrl),
        ];
    }

    private function firstLineContaining(string $content, string $needle): ?string
    {
        if ($needle === '') {
            return null;
        }

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (str_contains((string) $line, $needle)) {
                return mb_substr((string) $line, 0, 500);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Article $article): array
    {
        $article->loadMissing([
            'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ]);
        $seoMeta = $article->seoMeta;

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'title' => (string) $article->title,
            'excerpt_sha256' => hash('sha256', (string) $article->excerpt),
            'content_md_sha256' => hash('sha256', (string) $article->content_md),
            'content_html_sha256' => hash('sha256', (string) $article->content_html),
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'working_revision_id' => (int) $article->working_revision_id,
            'published_revision_id' => (int) $article->published_revision_id,
            'source_version_hash' => (string) $article->source_version_hash,
            'canonical_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null,
            'robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
            'seo_is_indexable' => $seoMeta instanceof ArticleSeoMeta ? (bool) $seoMeta->is_indexable : null,
            'schema_json_sha256' => $seoMeta instanceof ArticleSeoMeta ? hash('sha256', (string) json_encode($seoMeta->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) : null,
            'cover_image_url' => $article->cover_image_url,
            'cover_image_variants_sha256' => hash('sha256', (string) json_encode($article->cover_image_variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
            'og_image_url' => $seoMeta instanceof ArticleSeoMeta ? $seoMeta->og_image_url : null,
            'revision_count' => ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->count(),
        ];
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
            'excerpt_sha256',
            'content_html_sha256',
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
            'cover_image_url',
            'cover_image_variants_sha256',
            'og_image_url',
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
     * @param  list<array<string,mixed>>  $plans
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function summary(bool $ok, bool $dryRun, string $action, array $articleIds, string $translationGroupId, string $oldUrl, string $newUrl, array $before, array $after, array $plans, array $errors, array $warnings): array
    {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => $ok && ! $dryRun,
            'article_ids' => $articleIds,
            'articles_count' => count($articleIds),
            'translation_group_id' => $translationGroupId,
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'updates_scope' => [
                'article_fields' => [
                    'content_md',
                    'source_version_hash',
                ],
                'article_translation_revision_fields' => [
                    'content_md',
                    'source_version_hash',
                    'translated_from_version_hash',
                ],
            ],
            'protected_holds' => [
                'no_publish' => true,
                'no_schema' => true,
                'no_hreflang' => true,
                'no_search' => true,
                'no_sitemap_llms_change' => true,
                'no_revision_create' => true,
                'metadata_only' => false,
                'body_text_only_url_replacement' => true,
            ],
            'replacement_plan' => array_map(static fn (array $plan): array => [
                'article_id' => $plan['article_id'],
                'surfaces' => $plan['surfaces'],
            ], $plans),
            'before' => $before,
            'after' => $after,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
