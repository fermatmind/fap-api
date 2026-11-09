<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash;
use DomainException;
use Illuminate\Support\Facades\DB;

/** @review-surface article */
final class TraditionalArticlePublication
{
    public const SCHEMA = 'fermatmind.traditional_article.v1';

    public static function package(string $bytes, string $reviewBytes, string $environment): array
    {
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $review = json_decode($reviewBytes, true, 64, JSON_THROW_ON_ERROR);
        $mapping = $package['mapping'] ?? [];
        $proofs = $package['source_expectations'] ?? [];
        $identity = $mapping['target_identity'] ?? [];
        $slug = $identity['slug'] ?? '';
        if (($package['schema'] ?? null) !== self::SCHEMA
            || ($mapping['review_sha256'] ?? null) !== hash('sha256', $reviewBytes)
            || ($review['targetLocale'] ?? null) !== 'zh-TW'
            || ($identity['locale'] ?? null) !== 'zh-TW' || ($identity['org_id'] ?? null) !== 0
            || ($identity['source_locale'] ?? null) !== 'zh-CN'
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
            || array_keys($proofs) !== ['staging', 'production'] || ! isset($proofs[$environment])) {
            throw new DomainException('traditional_article_package_invalid');
        }

        $revision = $mapping['translation_revision'];
        $article = $mapping['article_metadata'];
        $seo = $mapping['seo_metadata'];
        $allowedArticle = ['cover_image_url', 'cover_image_width', 'cover_image_height', 'cover_image_variants',
            'related_test_slug', 'author_name', 'cover_image_alt'];
        if (array_diff(array_keys($article), $allowedArticle) !== []
            || ($proofs['production']['source']['id'] ?? null) !== $identity['source_article_id']
            || ($proofs['staging']['source']['id'] ?? null) !== $identity['source_article_id']) {
            throw new DomainException('traditional_article_package_protected_fields');
        }

        $reviewed = [
            '/article/title' => $revision['title'], '/article/excerpt' => $revision['excerpt'],
            '/article/content_md' => $revision['content_md'], '/article/author_name' => $article['author_name'],
            '/article/cover_image_alt' => $article['cover_image_alt'], '/article/seo_meta/seo_title' => $revision['seo_title'],
            '/article/seo_meta/seo_description' => $revision['seo_description'],
            '/article/seo_meta/og_title' => $seo['og_title'], '/article/seo_meta/og_description' => $seo['og_description'],
            '/article/category/name' => $mapping['taxonomy_display']['category']['label'],
        ];
        foreach ($mapping['taxonomy_display']['tags'] as $index => $tag) {
            $reviewed['/article/tags/'.$index.'/name'] = $tag['label'];
        }
        if (count($reviewed) !== count($review['fields'] ?? [])) {
            throw new DomainException('traditional_article_review_coverage_invalid');
        }
        foreach ($reviewed as $path => $text) {
            if (! is_string($text) || trim($text) === '' || $text !== ($review['fields'][$path]['text'] ?? null)) {
                throw new DomainException('traditional_article_reviewed_copy_changed');
            }
        }
        foreach ($proofs as $name => $proof) {
            foreach (['source', 'existing_english'] as $role) {
                $origin = $mapping['environment_source_preflight'][$name][$role] ?? [];
                $record = $proof[$role] ?? [];
                if (($record['slug'] ?? null) !== $slug || ($record['translation_group_id'] ?? null) !== $identity['translation_group_id']
                    || ($record['id'] ?? null) !== ($origin['id'] ?? null)
                    || ($record['published_revision_id'] ?? null) !== ($origin['published_revision_id'] ?? null)
                    || ($record['snapshot_sha256']['content_md'] ?? null) !== ($origin['public_body_sha256'] ?? null)) {
                    throw new DomainException('traditional_article_source_proof_invalid');
                }
            }
        }

        return ['candidates' => [[
            'page_id' => 'TRADITIONAL-ARTICLE-'.$slug,
            'kind' => 'article',
            'identity' => ['org_id' => 0, 'slug' => $slug, 'locale' => 'zh-TW'],
            'new_traditional_article' => true,
            'before' => [], 'staging_before' => [],
            'source_identity' => $identity,
            'source_expectations' => $proofs[$environment],
            'allow_staging_prepared_root' => $environment === 'staging',
            'article_metadata' => $article,
            'taxonomy_display' => $mapping['taxonomy_display'],
            'snapshot' => [
                'title' => $revision['title'], 'excerpt' => $revision['excerpt'], 'content_md' => $revision['content_md'],
                'author_name' => $article['author_name'], 'cover_image_alt' => $article['cover_image_alt'],
                'seo_text' => ['seo_title' => $revision['seo_title'], 'seo_description' => $revision['seo_description'],
                    'og_title' => $seo['og_title'], 'og_description' => $seo['og_description'], 'og_image_url' => $seo['og_image_url']],
            ],
        ]]];
    }

    public function read(array $row, bool $lock): array
    {
        $guard = app(TraditionalArticleSourcePreflight::class);
        $source = $guard->assertSource($row['source_expectations'], $lock, ($row['allow_staging_prepared_root'] ?? false) === true);
        $target = Article::query()->withoutGlobalScopes()->withTrashed()
            ->where($row['identity'])->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($target === null) {
            $guard->assertTargetVacant($source, $lock);
        } else {
            $targets = ArticleSourceTargetSnapshot::capture($source, $lock, 'zh-TW');
            if (count($targets) !== 1 || $targets[0]['article_id'] !== $target->id) {
                throw new DomainException('traditional_article_target_collision');
            }
        }

        $revision = $target === null ? null : ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('article_id', $target->id)->where('org_id', 0)->whereKey($target->working_revision_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $retained = $target !== null && ! $target->trashed() && $target->status === 'draft'
            && ! $target->is_public && $target->published_revision_id === null
            && $target->source_article_id === $source->id
            && $revision?->revision_status === ArticleTranslationRevision::STATUS_APPROVED
            && $revision->authority_source_package === PublicContent53Package::PACKAGE
            && $revision->authority_asset_key === $row['page_id']
            && $revision->authority_package_sha256 === $row['package_sha256'];

        return [
            'table' => 'articles',
            'traditional_article' => true,
            'missing' => $target === null,
            'retained_draft' => $retained,
            'source_revision' => (int) $source->published_revision_id,
            'source_state_sha256' => ArticleSourceTargetSnapshot::publicationHash($source, $lock),
            'target_id' => $target?->id,
            'target_state_sha256' => $target === null ? null : ArticleSourceTargetSnapshot::publicationHash($target, $lock),
        ];
    }

    public function assertBaseline(array $state): void
    {
        if (! $state['missing'] && ! $state['retained_draft']) {
            throw new DomainException('traditional_article_target_collision');
        }
    }

    public function publish(array $row, array $before, PromotionContext $context): bool
    {
        $current = $this->read($row, true);
        if ($current !== $before) {
            throw new DomainException('traditional_article_concurrent_state');
        }
        $this->assertBaseline($current);
        $source = Article::query()->withoutGlobalScopes()->findOrFail($row['source_identity']['source_article_id']);
        $copy = $row['snapshot'];
        $seo = $copy['seo_text'];
        unset($copy['seo_text']);
        $tags = DB::table('article_tag_map')->where('article_id', $source->id)->where('org_id', 0)
            ->orderBy('tag_id')->lockForUpdate()->pluck('tag_id')->map(fn ($id): int => (int) $id)->all();
        $expectedTags = array_column($row['taxonomy_display']['tags'], 'id');
        sort($expectedTags);
        if ($tags !== $expectedTags || $source->category_id !== $row['taxonomy_display']['category']['id']) {
            throw new DomainException('traditional_article_taxonomy_changed');
        }

        $target = $current['missing'] ? new Article : Article::query()->withoutGlobalScopes()->findOrFail($current['target_id']);
        $articleCopy = $row['identity'] + $copy + $row['article_metadata'] + [
            'category_id' => $source->category_id, 'reading_minutes' => $source->reading_minutes,
            'source_locale' => $source->locale, 'source_article_id' => $source->id, 'translated_from_article_id' => $source->id,
            'translation_group_id' => $source->translation_group_id, 'translated_from_version_hash' => $source->source_version_hash,
            'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false,
        ];
        $revisionCopy = array_intersect_key($copy, array_flip(['title', 'excerpt', 'content_md']))
            + array_intersect_key($seo, array_flip(['seo_title', 'seo_description']));
        $seoCopy = [
            'org_id' => 0, 'locale' => 'zh-TW', 'canonical_url' => 'https://fermatmind.com/zh-TW/articles/'.$row['identity']['slug'],
            'is_indexable' => false, 'robots' => 'noindex,follow', ...$seo,
        ];
        $revision = $current['missing'] ? null : $this->retainedRevision(
            $target, $articleCopy, $revisionCopy, $seoCopy, $expectedTags, $current['source_state_sha256'],
        );
        $target->forceFill($articleCopy + [
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED, 'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE, 'published_at' => now(), 'is_public' => true,
        ])->save();
        if ($current['missing']) {
            foreach ($tags as $tag) {
                DB::table('article_tag_map')->insert(['org_id' => 0, 'article_id' => $target->id, 'tag_id' => $tag]);
            }
        }
        $revision ??= ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'article_id' => $target->id, 'source_article_id' => $source->id,
            'locale' => 'zh-TW', 'source_locale' => $source->locale, 'translation_group_id' => $source->translation_group_id,
            'source_version_hash' => $source->source_version_hash, 'translated_from_version_hash' => $source->source_version_hash,
            'revision_number' => (int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $target->id)->max('revision_number') + 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'supersedes_revision_id' => $target->working_revision_id,
            'title' => $copy['title'], 'excerpt' => $copy['excerpt'], 'content_md' => $copy['content_md'],
            'seo_title' => $seo['seo_title'], 'seo_description' => $seo['seo_description'],
            'authority_source_package' => PublicContent53Package::PACKAGE, 'authority_asset_key' => $row['page_id'],
            'authority_package_sha256' => $context->packageSha256, 'authority_source_hash' => $current['source_state_sha256'],
            'authority_metadata_json' => ['source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId,
                'approval_kind' => 'verified_automated_exact_package'],
            'approved_at' => now(), 'published_at' => now(),
        ]);
        if (! $current['missing']) {
            $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'published_at' => now()])->saveQuietly();
        }
        $target->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::query()->withoutGlobalScopes()->updateOrCreate(['article_id' => $target->id], $seoCopy);
        app(ArticleMaterialDecisionService::class)->recordPublished($target, $revision, now());

        return $current['missing'];
    }

    private function retainedRevision(Article $target, array $articleCopy, array $revisionCopy, array $seoCopy, array $tags, string $sourceState): ArticleTranslationRevision
    {
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($target->working_revision_id)
            ->where('org_id', 0)->where('article_id', $target->id)->lockForUpdate()->sole();
        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('org_id', 0)->where('article_id', $target->id)
            ->where('locale', $target->locale)->lockForUpdate()->sole();
        $targetTags = DB::table('article_tag_map')->where('org_id', 0)->where('article_id', $target->id)
            ->orderBy('tag_id')->lockForUpdate()->pluck('tag_id')->map(fn ($id): int => (int) $id)->all();
        if (! CanonicalTranslationPayloadHash::sameValue($target->only(array_keys($articleCopy)), $articleCopy)
            || $revision->only(array_keys($revisionCopy)) !== $revisionCopy
            || $revision->authority_source_hash !== $sourceState
            || $revision->source_article_id !== $target->source_article_id
            || $revision->source_locale !== $target->source_locale
            || $revision->translation_group_id !== $target->translation_group_id
            || $revision->locale !== $target->locale
            || $revision->source_version_hash !== $target->translated_from_version_hash
            || $revision->translated_from_version_hash !== $target->translated_from_version_hash
            || $seo->only(array_keys($seoCopy)) !== $seoCopy || ! empty($seo->schema_json) || $targetTags !== $tags) {
            throw new DomainException('traditional_article_retained_draft_changed');
        }

        return $revision;
    }

    public function restore(array $row, array $published): void
    {
        $target = Article::query()->withoutGlobalScopes()->findOrFail($published['target_id']);
        $revision = $target->publishedRevision;
        if ($revision?->authority_package_sha256 !== $row['package_sha256'] || $revision->authority_asset_key !== $row['page_id']) {
            throw new DomainException('traditional_article_rollback_identity');
        }
        $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_APPROVED])->saveQuietly();
        $target->forceFill(['status' => 'draft', 'is_public' => false, 'published_revision_id' => null])->saveQuietly();
        app(ArticleMaterialDecisionService::class)->recordUnpublished($target, now());
        if (! $this->read($row, true)['retained_draft']) {
            throw new DomainException('traditional_article_rollback_readback');
        }
    }
}
