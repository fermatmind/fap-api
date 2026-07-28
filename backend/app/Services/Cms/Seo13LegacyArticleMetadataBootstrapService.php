<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Seo13LegacyArticleMetadataBootstrapService
{
    public const TARGET_COUNT = 5;

    public const SEO_META_WRITE_COUNT = 5;

    public const CATEGORY_WRITE_COUNT = 5;

    public const TAG_MAPPING_WRITE_COUNT = 21;

    /**
     * @var list<array{
     *   article_id:int,
     *   slug:string,
     *   published_revision_id:int,
     *   working_revision_id:int,
     *   canonical_url:string,
     *   category:array{field:string,value:string},
     *   tags:list<string>
     * }>
     */
    private const TARGETS = [
        [
            'article_id' => 5,
            'slug' => 'iq-test-growth-guide',
            'published_revision_id' => 5,
            'working_revision_id' => 444,
            'canonical_url' => 'https://fermatmind.com/zh/articles/iq-test-growth-guide',
            'category' => ['field' => 'slug', 'value' => 'ability-cognition'],
            'tags' => ['iq-test', 'intelligence-test', 'online-iq-test', 'formal-iq-assessment', 'cognitive-self-assessment'],
        ],
        [
            'article_id' => 6,
            'slug' => 'iq-test-narrative-portrait',
            'published_revision_id' => 6,
            'working_revision_id' => 443,
            'canonical_url' => 'https://fermatmind.com/zh/articles/iq-test-narrative-portrait',
            'category' => ['field' => 'slug', 'value' => 'ability-cognition'],
            'tags' => ['iq-test', 'intelligence-test', 'online-iq-test', 'formal-iq-assessment', 'cognitive-self-assessment'],
        ],
        [
            'article_id' => 7,
            'slug' => 'iq-test-tool-guide',
            'published_revision_id' => 7,
            'working_revision_id' => 442,
            'canonical_url' => 'https://fermatmind.com/zh/articles/iq-test-tool-guide',
            'category' => ['field' => 'slug', 'value' => 'ability-cognition'],
            'tags' => ['iq-test', 'intelligence-test', 'online-iq-test', 'formal-iq-assessment', 'cognitive-self-assessment'],
        ],
        [
            'article_id' => 9,
            'slug' => 'mbti-growth-guide',
            'published_revision_id' => 9,
            'working_revision_id' => 441,
            'canonical_url' => 'https://fermatmind.com/zh/articles/mbti-growth-guide',
            'category' => ['field' => 'name', 'value' => '人格心理学'],
            'tags' => ['mbti', '16-personality-types-zh', '892eefb1a4f4'],
        ],
        [
            'article_id' => 10,
            'slug' => 'mbti-narrative-portrait',
            'published_revision_id' => 10,
            'working_revision_id' => 440,
            'canonical_url' => 'https://fermatmind.com/zh/articles/mbti-narrative-portrait',
            'category' => ['field' => 'name', 'value' => '人格心理学'],
            'tags' => ['mbti', '16-personality-types-zh', '892eefb1a4f4'],
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        return $this->snapshot(lockForUpdate: false);
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(string $expectedStateSha256): array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $expectedStateSha256) !== 1) {
            throw new RuntimeException('expected_state_sha256_invalid');
        }

        $result = DB::transaction(function () use ($expectedStateSha256): array {
            $before = $this->snapshot(lockForUpdate: true);
            if (($before['ok'] ?? false) !== true) {
                throw new RuntimeException('bootstrap_preflight_rejected');
            }
            if (($before['repair_required'] ?? false) !== true || ($before['apply_supported'] ?? false) !== true) {
                throw new RuntimeException('bootstrap_not_required');
            }
            if (! hash_equals($expectedStateSha256, (string) ($before['state_sha256'] ?? ''))) {
                throw new RuntimeException('bootstrap_state_drift');
            }

            $writes = [
                'seo_meta_write_count' => 0,
                'category_write_count' => 0,
                'tag_mapping_write_count' => 0,
                'audit_write_count' => 0,
            ];

            foreach ((array) $before['rows'] as $row) {
                $articleId = (int) $row['article_id'];
                $article = Article::query()
                    ->withoutGlobalScopes()
                    ->whereKey($articleId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $categoryWrites = DB::table('articles')
                    ->where('id', $articleId)
                    ->where('org_id', (int) $article->org_id)
                    ->whereNull('category_id')
                    ->update(['category_id' => (int) $row['desired_category_id']]);
                if ($categoryWrites !== 1) {
                    throw new RuntimeException('bootstrap_category_write_count_mismatch');
                }
                $writes['category_write_count'] += $categoryWrites;

                ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                    'org_id' => (int) $article->org_id,
                    'article_id' => $articleId,
                    'locale' => 'zh-CN',
                    'seo_title' => (string) $row['desired_seo_title'],
                    'seo_description' => (string) $row['desired_seo_description'],
                    'canonical_url' => (string) $row['canonical_url'],
                    'og_title' => (string) $row['desired_seo_title'],
                    'og_description' => (string) $row['desired_seo_description'],
                    'og_image_url' => (string) $row['desired_og_image_url'],
                    'robots' => 'index,follow',
                    'schema_json' => null,
                    'is_indexable' => true,
                ]);
                $writes['seo_meta_write_count']++;

                $tagMap = [];
                foreach ((array) $row['desired_tag_ids'] as $tagId) {
                    $tagMap[(int) $tagId] = ['org_id' => (int) $article->org_id];
                }
                $article->tags()->sync($tagMap);
                $writes['tag_mapping_write_count'] += count($tagMap);
            }

            $orgIds = collect($before['rows'])
                ->pluck('org_id')
                ->unique()
                ->values();
            if ($orgIds->count() !== 1) {
                throw new RuntimeException('bootstrap_org_lock_mismatch');
            }

            AuditLog::query()->withoutGlobalScopes()->create([
                'org_id' => (int) $orgIds->first(),
                'actor_admin_id' => null,
                'action' => 'seo13_legacy_article_metadata_bootstrap',
                'target_type' => 'article_batch',
                'target_id' => 'seo13-legacy-metadata-5',
                'meta_json' => [
                    'target_count' => self::TARGET_COUNT,
                    'target_set_sha256' => (string) $before['target_set_sha256'],
                    'preflight_state_sha256' => (string) $before['state_sha256'],
                    'seo_meta_write_count' => $writes['seo_meta_write_count'],
                    'category_write_count' => $writes['category_write_count'],
                    'tag_mapping_write_count' => $writes['tag_mapping_write_count'],
                    'article_body_write_count' => 0,
                    'revision_write_count' => 0,
                    'publication_write_count' => 0,
                    'discoverability_write_count' => 0,
                    'search_submission_count' => 0,
                ],
                'ip' => null,
                'user_agent' => 'seo13-legacy-metadata-bootstrap',
                'request_id' => '',
                'reason' => 'seo13_atomic_promotion_legacy_metadata_prerequisite',
                'result' => 'success',
                'created_at' => now(),
            ]);
            $writes['audit_write_count']++;

            if ($writes !== [
                'seo_meta_write_count' => self::SEO_META_WRITE_COUNT,
                'category_write_count' => self::CATEGORY_WRITE_COUNT,
                'tag_mapping_write_count' => self::TAG_MAPPING_WRITE_COUNT,
                'audit_write_count' => 1,
            ]) {
                throw new RuntimeException('bootstrap_write_count_mismatch');
            }

            $after = $this->snapshot(lockForUpdate: true);
            if (($after['ok'] ?? false) !== true
                || ($after['repair_required'] ?? true) !== false
                || ($after['readback_complete'] ?? false) !== true) {
                throw new RuntimeException('bootstrap_readback_failed');
            }

            return ['before' => $before, 'after' => $after, 'writes' => $writes];
        });

        return [
            'before' => $result['before'],
            'after' => $result['after'],
            'writes' => $result['writes'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(bool $lockForUpdate): array
    {
        $rows = [];
        $errors = [];
        $missingCount = 0;
        $completeCount = 0;

        foreach (self::TARGETS as $target) {
            $query = Article::query()
                ->withoutGlobalScopes()
                ->whereKey($target['article_id']);
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            $article = $query->first();
            if (! $article instanceof Article) {
                $errors[] = $this->issue($target['article_id'], 'article_not_found');

                continue;
            }

            $publishedRevision = $this->revision(
                $target['published_revision_id'],
                $target['article_id'],
                $lockForUpdate,
            );
            $workingRevision = $this->revision(
                $target['working_revision_id'],
                $target['article_id'],
                $lockForUpdate,
            );
            $seoMeta = $this->seoMeta($article, $lockForUpdate);
            $category = $this->category($article, $target['category'], $lockForUpdate);
            $tags = $this->tags($article, $target['tags'], $lockForUpdate);
            $currentTagIds = $article->tags()
                ->withoutGlobalScopes()
                ->orderBy('article_tags.id')
                ->pluck('article_tags.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $desiredSeoTitle = trim((string) $article->title);
            $desiredSeoDescription = trim((string) $article->excerpt);
            $desiredOgImageUrl = trim((string) $article->cover_image_url);
            $desiredTagIds = array_map(
                static fn (ArticleTag $tag): int => (int) $tag->id,
                $tags,
            );
            sort($desiredTagIds);
            sort($currentTagIds);

            foreach ($this->identityErrors($article, $publishedRevision, $workingRevision, $target) as $code) {
                $errors[] = $this->issue($target['article_id'], $code);
            }
            if (! $category instanceof ArticleCategory) {
                $errors[] = $this->issue($target['article_id'], 'desired_category_not_found');
            }
            if (count($tags) !== count($target['tags'])) {
                $errors[] = $this->issue($target['article_id'], 'desired_tag_set_not_found');
            }
            if ($desiredSeoTitle === '' || $desiredSeoDescription === '' || $desiredOgImageUrl === '') {
                $errors[] = $this->issue($target['article_id'], 'desired_seo_source_missing');
            }

            $isMissing = $seoMeta === null
                && $article->category_id === null
                && $currentTagIds === [];
            $isComplete = $seoMeta instanceof ArticleSeoMeta
                && $category instanceof ArticleCategory
                && (int) $article->category_id === (int) $category->id
                && $currentTagIds === $desiredTagIds
                && $this->seoMetaMatches(
                    $seoMeta,
                    $article,
                    $target,
                    $desiredSeoTitle,
                    $desiredSeoDescription,
                    $desiredOgImageUrl,
                );

            if (! $isMissing && ! $isComplete) {
                $errors[] = $this->issue($target['article_id'], 'legacy_metadata_partial_or_drifted');
            }
            $missingCount += $isMissing ? 1 : 0;
            $completeCount += $isComplete ? 1 : 0;

            $rows[] = [
                'article_id' => (int) $article->id,
                'org_id' => (int) $article->org_id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'translation_group_id' => (string) $article->translation_group_id,
                'published_revision_id' => (int) ($article->published_revision_id ?? 0),
                'working_revision_id' => (int) ($article->working_revision_id ?? 0),
                'working_revision_status' => (string) ($workingRevision?->revision_status ?? ''),
                'article_status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'article_title_hash' => $this->textHash((string) $article->title),
                'article_excerpt_hash' => $this->textHash((string) $article->excerpt),
                'article_body_hash' => $this->textHash((string) $article->content_md),
                'published_revision_title_hash' => $this->textHash((string) ($publishedRevision?->title ?? '')),
                'published_revision_excerpt_hash' => $this->textHash((string) ($publishedRevision?->excerpt ?? '')),
                'published_revision_body_hash' => $this->textHash((string) ($publishedRevision?->content_md ?? '')),
                'working_revision_title_hash' => $this->textHash((string) ($workingRevision?->title ?? '')),
                'working_revision_excerpt_hash' => $this->textHash((string) ($workingRevision?->excerpt ?? '')),
                'working_revision_body_hash' => $this->textHash((string) ($workingRevision?->content_md ?? '')),
                'working_revision_seo_title_hash' => $this->textHash((string) ($workingRevision?->seo_title ?? '')),
                'working_revision_seo_description_hash' => $this->textHash((string) ($workingRevision?->seo_description ?? '')),
                'canonical_url' => $target['canonical_url'],
                'seo_meta_present' => $seoMeta instanceof ArticleSeoMeta,
                'current_category_id' => $article->category_id !== null ? (int) $article->category_id : null,
                'current_tag_ids' => $currentTagIds,
                'desired_category_id' => $category?->id !== null ? (int) $category->id : 0,
                'desired_tag_ids' => $desiredTagIds,
                'desired_seo_title' => $desiredSeoTitle,
                'desired_seo_description' => $desiredSeoDescription,
                'desired_og_image_url' => $desiredOgImageUrl,
                'desired_seo_title_hash' => $this->textHash($desiredSeoTitle),
                'desired_seo_description_hash' => $this->textHash($desiredSeoDescription),
                'desired_og_image_url_hash' => $this->textHash($desiredOgImageUrl),
                'metadata_state' => $isMissing ? 'missing' : ($isComplete ? 'complete' : 'drifted'),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['article_id'] <=> $right['article_id']);
        usort($errors, static fn (array $left, array $right): int => [$left['article_id'], $left['code']] <=> [$right['article_id'], $right['code']]);

        $stateRows = array_map(static function (array $row): array {
            unset(
                $row['desired_seo_title'],
                $row['desired_seo_description'],
                $row['desired_og_image_url'],
            );

            return $row;
        }, $rows);

        $targetSet = array_map(static fn (array $target): array => [
            'article_id' => $target['article_id'],
            'slug' => $target['slug'],
            'published_revision_id' => $target['published_revision_id'],
            'working_revision_id' => $target['working_revision_id'],
            'canonical_url' => $target['canonical_url'],
            'category' => $target['category'],
            'tags' => $target['tags'],
        ], self::TARGETS);

        return [
            'ok' => $errors === [] && ($missingCount === self::TARGET_COUNT || $completeCount === self::TARGET_COUNT),
            'target_count' => self::TARGET_COUNT,
            'missing_count' => $missingCount,
            'complete_count' => $completeCount,
            'repair_required' => $missingCount === self::TARGET_COUNT,
            'apply_supported' => $errors === [] && $missingCount === self::TARGET_COUNT,
            'readback_complete' => $errors === [] && $completeCount === self::TARGET_COUNT,
            'target_set_sha256' => $this->deterministicHash($targetSet),
            'state_sha256' => $this->deterministicHash($stateRows),
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    private function revision(int $revisionId, int $articleId, bool $lockForUpdate): ?ArticleTranslationRevision
    {
        $query = ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($revisionId)
            ->where('article_id', $articleId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function seoMeta(Article $article, bool $lockForUpdate): ?ArticleSeoMeta
    {
        $query = ArticleSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('article_id', (int) $article->id)
            ->where('org_id', (int) $article->org_id)
            ->where('locale', (string) $article->locale);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array{field:string,value:string}  $lock
     */
    private function category(Article $article, array $lock, bool $lockForUpdate): ?ArticleCategory
    {
        $query = ArticleCategory::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $article->org_id)
            ->where('is_active', true)
            ->where($lock['field'], $lock['value']);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  list<string>  $slugs
     * @return list<ArticleTag>
     */
    private function tags(Article $article, array $slugs, bool $lockForUpdate): array
    {
        $query = ArticleTag::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $article->org_id)
            ->where('is_active', true)
            ->whereIn('slug', $slugs)
            ->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var list<ArticleTag> $tags */
        $tags = $query->get()->all();

        return $tags;
    }

    /**
     * @param  array<string,mixed>  $target
     * @return list<string>
     */
    private function identityErrors(
        Article $article,
        ?ArticleTranslationRevision $publishedRevision,
        ?ArticleTranslationRevision $workingRevision,
        array $target,
    ): array {
        $errors = [];
        if ((string) $article->slug !== (string) $target['slug']) {
            $errors[] = 'slug_lock_mismatch';
        }
        if ((string) $article->locale !== 'zh-CN') {
            $errors[] = 'locale_lock_mismatch';
        }
        if ((string) $article->translation_group_id !== 'article-'.$target['article_id']) {
            $errors[] = 'translation_group_lock_mismatch';
        }
        if ((int) ($article->published_revision_id ?? 0) !== (int) $target['published_revision_id']
            || ! $publishedRevision instanceof ArticleTranslationRevision
            || (string) $publishedRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $errors[] = 'published_revision_lock_mismatch';
        } elseif ((int) $publishedRevision->org_id !== (int) $article->org_id
            || (string) $publishedRevision->locale !== 'zh-CN'
            || (string) $publishedRevision->translation_group_id !== (string) $article->translation_group_id) {
            $errors[] = 'published_revision_identity_mismatch';
        } elseif ((string) $publishedRevision->title !== (string) $article->title
            || (string) $publishedRevision->excerpt !== (string) $article->excerpt
            || (string) $publishedRevision->content_md !== (string) $article->content_md) {
            $errors[] = 'published_projection_content_mismatch';
        }
        if ((int) ($article->working_revision_id ?? 0) !== (int) $target['working_revision_id']
            || ! $workingRevision instanceof ArticleTranslationRevision
            || (string) $workingRevision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
            $errors[] = 'working_revision_lock_mismatch';
        } elseif ((int) $workingRevision->org_id !== (int) $article->org_id
            || (string) $workingRevision->locale !== 'zh-CN'
            || (string) $workingRevision->translation_group_id !== (string) $article->translation_group_id) {
            $errors[] = 'working_revision_identity_mismatch';
        }
        if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
            $errors[] = 'publication_state_mismatch';
        }
        if (! (bool) $article->is_indexable) {
            $errors[] = 'indexability_state_mismatch';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $target
     */
    private function seoMetaMatches(
        ArticleSeoMeta $seoMeta,
        Article $article,
        array $target,
        string $seoTitle,
        string $seoDescription,
        string $ogImageUrl,
    ): bool {
        return (int) $seoMeta->org_id === (int) $article->org_id
            && (string) $seoMeta->locale === 'zh-CN'
            && (string) $seoMeta->seo_title === $seoTitle
            && (string) $seoMeta->seo_description === $seoDescription
            && (string) $seoMeta->canonical_url === (string) $target['canonical_url']
            && (string) $seoMeta->og_title === $seoTitle
            && (string) $seoMeta->og_description === $seoDescription
            && (string) $seoMeta->og_image_url === $ogImageUrl
            && (string) $seoMeta->robots === 'index,follow'
            && $seoMeta->schema_json === null
            && (bool) $seoMeta->is_indexable === true;
    }

    /**
     * @return array{article_id:int,code:string}
     */
    private function issue(int $articleId, string $code): array
    {
        return ['article_id' => $articleId, 'code' => $code];
    }

    private function textHash(string $value): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", trim($value)));
    }

    private function deterministicHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
