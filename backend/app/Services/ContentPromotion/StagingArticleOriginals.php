<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash;
use DomainException;
use Illuminate\Support\Facades\DB;

final class StagingArticleOriginals
{
    public const FILE = 'staging-article-originals.json';

    public const TAIWAN_DELTA_FILE = 'staging-article-originals-taiwan-delta.json';

    public const SCHEMA = 'fermatmind.staging_article_originals.v1';

    public static function package(string $bytes, string $file = self::FILE): array
    {
        [$expectedRows, $expectedRoots] = match ($file) {
            self::FILE => [17, 15],
            self::TAIWAN_DELTA_FILE => [4, 2],
            default => throw new DomainException('public_content_53_staging_article_original_package_invalid'),
        };
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $rows = $package['articles'] ?? [];
        if (($package['schema'] ?? null) !== self::SCHEMA || ! is_array($rows) || ! array_is_list($rows) || count($rows) !== $expectedRows
            || preg_match('/\A[a-f0-9]{40}\z/', $package['production_source_revision'] ?? '') !== 1) {
            throw new DomainException('public_content_53_staging_article_original_package_invalid');
        }

        $sources = [];
        $identities = [];
        $roots = [];
        $candidates = [];
        foreach ($rows as $row) {
            $source = $row['identity'] ?? [];
            $id = $source['id'] ?? null;
            $locale = $source['locale'] ?? null;
            $slug = $source['slug'] ?? '';
            $group = $source['translation_group_id'] ?? '';
            $key = $locale.':'.$slug;
            if (! is_int($id) || $id < 1 || isset($sources[$id]) || isset($identities[$key])
                || ! is_int($source['published_revision_id'] ?? null) || $source['published_revision_id'] < 1
                || ! in_array($locale, ['zh-CN', 'en'], true) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                || ! is_string($group) || $group === '' || strlen($group) > 64
                || ! array_key_exists('source_article_id', $source) || ! array_key_exists('translated_from_article_id', $source)
                || $source['source_article_id'] !== $source['translated_from_article_id']
                || ($row['reviewed_counterpart_binding_present'] ?? null) !== false
                || preg_match('/\A[a-f0-9]{64}\z/', $row['publicResponseSha256'] ?? '') !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $row['native_source_hash'] ?? '') !== 1
                || trim((string) ($row['published']['content_md'] ?? '')) === ''
                || ! is_array($row['public_projection']['faq'] ?? null) || ! is_array($row['public_projection']['ctas'] ?? null)) {
                throw new DomainException('public_content_53_staging_article_original_identity_invalid');
            }
            $parentId = $source['source_article_id'];
            if ($parentId === null) {
                if (isset($roots[$group]) || $source['source_locale'] !== $locale || $source['translation_status'] !== 'source') {
                    throw new DomainException('public_content_53_staging_article_original_parent_invalid');
                }
                $roots[$group] = $id;
            } elseif (($roots[$group] ?? null) !== $parentId || ($sources[$parentId]['locale'] ?? null) !== $source['source_locale']
                || $source['source_locale'] === $locale || $source['translation_status'] !== 'published') {
                throw new DomainException('public_content_53_staging_article_original_parent_invalid');
            }
            $sources[$id] = $source;
            $identities[$key] = true;
            $candidates[] = [
                'page_id' => 'STAGING-ARTICLE-ORIGINAL-'.$id,
                'kind' => 'article', 'identity' => ['org_id' => 0, 'slug' => $slug, 'locale' => $locale],
                'staging_article_original' => $row,
            ];
        }
        if (count($roots) !== $expectedRoots) {
            throw new DomainException('public_content_53_staging_article_original_package_invalid');
        }

        return ['candidates' => $candidates];
    }

    public function read(array $candidate, bool $lock): array
    {
        $article = $this->article($candidate, $lock);

        return [
            'table' => 'articles',
            'missing' => $article === null,
            'id' => $article?->id,
            'retained_draft' => $article !== null && $this->retainedDraft($article, $candidate),
            'fingerprint' => $article === null ? null : ArticleSourceTargetSnapshot::publicationHash($article, $lock),
        ];
    }

    public function assertBaseline(array $candidate, array $state): void
    {
        if (PublicContent53Package::environment() === 'staging') {
            if (! $state['missing'] && ! $state['retained_draft']) {
                throw new DomainException('public_content_53_staging_article_original_conflict');
            }

            return;
        }
        $this->assertPublished([$candidate]);
    }

    public function assertPublished(array $candidates): void
    {
        $staging = PublicContent53Package::environment() === 'staging';
        $mappedIds = [];
        foreach ($candidates as $candidate) {
            $row = $candidate['staging_article_original'];
            $source = $row['identity'];
            $article = $this->article($candidate, false);
            $parent = $source['source_article_id'];
            $expectedParent = $parent === null || ! $staging ? $parent : ($mappedIds[$parent] ?? false);
            if ($article === null || $article->trashed() || ! $article->is_public || $article->status !== 'published'
                || $article->published_revision_id === null || $article->published_revision_id !== $article->working_revision_id
                || $article->translation_group_id !== $source['translation_group_id']
                || $article->source_locale !== $source['source_locale'] || $article->translation_status !== $source['translation_status']
                || $article->source_article_id !== $expectedParent || $article->translated_from_article_id !== $expectedParent
                || (! $staging && ($article->id !== $source['id'] || $article->published_revision_id !== $source['published_revision_id']
                    || $article->source_version_hash !== $row['native_source_hash']))) {
                throw new DomainException('public_content_53_staging_article_original_identity_drift');
            }
            $this->assertPublicProjection($article, $row);
            $mappedIds[$source['id']] = $article->id;
        }
    }

    private function article(array $candidate, bool $lock): ?Article
    {
        return Article::query()->withoutGlobalScopes()->withTrashed()->where($candidate['identity'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    private function retainedDraft(Article $article, array $candidate): bool
    {
        $revision = $article->workingRevision;

        return ! $article->trashed() && $article->status === 'draft' && ! $article->is_public
            && $article->published_revision_id === null
            && $revision?->revision_status === ArticleTranslationRevision::STATUS_APPROVED
            && $revision->authority_source_package === PublicContent53Package::PACKAGE
            && $revision->authority_asset_key === $candidate['page_id']
            && $revision->authority_package_sha256 === $candidate['package_sha256']
            && data_get($revision->authority_metadata_json, 'retained_fingerprint') === $this->retainedFingerprint($article, $revision);
    }

    private function retainedFingerprint(Article $article, ArticleTranslationRevision $revision): string
    {
        $article = $article->fresh(['seoMeta']);
        $revision = $revision->fresh();

        return CanonicalTranslationPayloadHash::hash([
            'article' => $article->getRawOriginal(),
            'revision' => array_diff_key($revision->getRawOriginal(), ['authority_metadata_json' => true, 'updated_at' => true]),
            'seo' => $article->seoMeta?->getRawOriginal(),
            'tags' => ArticleSourceTargetSnapshot::tagsHash($article),
        ]);
    }

    /** Recreate published original-language article projections in staging only. */
    public function create(array $candidates): void
    {
        if (PublicContent53Package::environment() !== 'staging' || DB::transactionLevel() < 1) {
            throw new DomainException('public_content_53_staging_article_original_write_invalid');
        }

        $groups = [];
        $existing = [];
        foreach ($candidates as $candidate) {
            $identity = $candidate['staging_article_original']['identity'];
            $groups[] = $identity['translation_group_id'];
            $article = $this->article($candidate, true);
            if ($article !== null && ! $this->retainedDraft($article, $candidate)) {
                throw new DomainException('public_content_53_staging_article_original_conflict');
            }
            if ($article !== null) {
                $existing[$identity['id']] = $article;
            }
        }
        if (Article::query()->withoutGlobalScopes()->withTrashed()->where('org_id', 0)
            ->whereIn('translation_group_id', array_unique($groups))->whereNotIn('id', array_map(fn (Article $article) => $article->id, $existing))
            ->lockForUpdate()->exists()) {
            throw new DomainException('public_content_53_staging_article_original_group_conflict');
        }

        $created = [];
        foreach ($candidates as $candidate) {
            $row = $candidate['staging_article_original'];
            $identity = $row['identity'];
            $parentId = $identity['source_article_id'];
            $parent = $parentId === null ? null : ($created[$parentId] ?? throw new DomainException('public_content_53_staging_article_original_parent_missing'));
            $article = $existing[$identity['id']] ?? null;
            if ($article === null) {
                $article = $this->writeArticle($candidate, $parent);
            } else {
                $revision = $article->workingRevision;
                $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED])->saveQuietly();
                $article->forceFill(['status' => 'published', 'is_public' => true, 'published_revision_id' => $revision->id])->saveQuietly();
                $article->unsetRelation('publishedRevision');
            }
            $created[$identity['id']] = $article;
        }
        $this->assertPublished($candidates);
    }

    public function restore(array $candidate): void
    {
        if (PublicContent53Package::environment() !== 'staging' || DB::transactionLevel() < 1) {
            throw new DomainException('public_content_53_staging_article_original_write_invalid');
        }
        $article = $this->article($candidate, true);
        $revision = $article?->publishedRevision;
        if ($revision?->authority_asset_key !== $candidate['page_id']
            || $revision->authority_source_package !== PublicContent53Package::PACKAGE
            || $revision->authority_package_sha256 !== $candidate['package_sha256']) {
            throw new DomainException('public_content_53_staging_article_original_rollback_identity');
        }
        $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_APPROVED])->saveQuietly();
        $article->forceFill(['status' => 'draft', 'is_public' => false, 'published_revision_id' => null])->saveQuietly();
        $revision->forceFill(['authority_metadata_json' => [
            'retained_fingerprint' => $this->retainedFingerprint($article, $revision),
        ]])->saveQuietly();
        $article->unsetRelation('workingRevision');
        if (! $this->retainedDraft($article, $candidate)) {
            throw new DomainException('public_content_53_staging_article_original_rollback_readback');
        }
    }

    private function writeArticle(array $candidate, ?Article $parent): Article
    {
        $row = $candidate['staging_article_original'];
        $identity = $candidate['identity'];
        $metadata = $row['article'];
        $source = $row['published'];
        $category = $row['taxonomy']['category'];
        $variants = $metadata['public_cover_image_variants'];
        if ($row['variant_editorial'] !== []) {
            $variants['editorial_package_v1'] = $row['variant_editorial'];
        }
        $article = new Article;
        $article->forceFill($identity + $this->fields($metadata, [
            'author_name', 'cover_image_url', 'cover_image_width', 'cover_image_height', 'cover_image_alt',
            'reading_minutes', 'related_test_slug', 'voice', 'voice_order', 'published_at',
        ]) + $this->fields($source, ['title', 'excerpt', 'content_md']) + [
            'source_locale' => $row['identity']['source_locale'],
            'translation_group_id' => $row['identity']['translation_group_id'],
            'translation_status' => $parent === null ? Article::TRANSLATION_STATUS_SOURCE : Article::TRANSLATION_STATUS_PUBLISHED,
            'source_article_id' => $parent?->id, 'translated_from_article_id' => $parent?->id,
            'translated_from_version_hash' => $parent?->source_version_hash,
            'category_id' => $category === null ? null : $this->taxonomy(ArticleCategory::class, $category),
            'cover_image_variants' => $variants, 'status' => 'published', 'is_public' => true, 'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false,
        ])->save();
        if ($metadata['author_name'] === null) {
            $article->forceFill(['author_name' => null])->saveQuietly();
        }
        foreach ($row['taxonomy']['tags'] as $tag) {
            DB::table('article_tag_map')->insert(['org_id' => 0, 'article_id' => $article->id, 'tag_id' => $this->taxonomy(ArticleTag::class, $tag)]);
        }

        $root = $parent ?? $article;
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create($this->fields($source, [
            'title', 'excerpt', 'content_md', 'seo_title', 'seo_description',
        ]) + [
            'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $root->id, 'locale' => $article->locale,
            'source_locale' => $root->locale, 'translation_group_id' => $article->translation_group_id,
            'source_version_hash' => $root->source_version_hash, 'translated_from_version_hash' => $root->source_version_hash,
            'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'published_at' => $article->published_at,
            'authority_source_package' => PublicContent53Package::PACKAGE, 'authority_asset_key' => $candidate['page_id'],
            'authority_package_sha256' => $candidate['package_sha256'], 'authority_source_hash' => $row['native_source_hash'],
        ]);
        $article->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::query()->withoutGlobalScopes()->create($this->fields($row['seo'], [
            'seo_title', 'seo_description', 'og_title', 'og_description', 'og_image_url', 'canonical_url',
        ]) + [
            'org_id' => 0, 'article_id' => $article->id, 'locale' => $article->locale, 'robots' => 'noindex,follow', 'is_indexable' => false,
            'schema_json' => $row['seo_editorial'] === [] ? [] : ['editorial_package_v1' => $row['seo_editorial']],
        ]);

        return $article;
    }

    private function taxonomy(string $model, array $source): int
    {
        $row = $model::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => $source['slug']], ['name' => $source['name'], 'is_active' => true]
        );
        if (! $row->is_active) {
            throw new DomainException('public_content_53_staging_article_original_taxonomy_inactive');
        }

        return $row->id;
    }

    private function fields(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }

    private function assertPublicProjection(Article $article, array $row): void
    {
        $result = app(ArticleController::class)->publicDetailProjection($article);
        $public = $result['article'];
        $tags = array_column($public['tags'], 'slug');
        $expectedTags = array_column($row['taxonomy']['tags'], 'slug');
        sort($tags);
        sort($expectedTags);
        if (! CanonicalTranslationPayloadHash::sameValue($this->fields($public, ['title', 'excerpt', 'content_md']),
            $this->fields($row['published'], ['title', 'excerpt', 'content_md']))
            || $public['author_name'] !== $row['article']['author_name'] || $public['cover_image_alt'] !== $row['article']['cover_image_alt']
            || ($public['category']['slug'] ?? null) !== ($row['taxonomy']['category']['slug'] ?? null) || $tags !== $expectedTags
            || ! CanonicalTranslationPayloadHash::sameValue($result['answer_surface_v1']['faq_blocks'], $row['public_projection']['faq'])
            || ! CanonicalTranslationPayloadHash::sameValue($result['landing_surface_v1']['cta_bundle'], $row['public_projection']['ctas'])
            || (PublicContent53Package::environment() === 'staging'
                && ($public['is_indexable'] || $public['sitemap_eligible'] || $public['llms_eligible']))) {
            throw new DomainException('public_content_53_staging_article_original_projection_drift');
        }
        foreach (['og_title', 'og_description', 'og_image_url'] as $field) {
            if ($row['seo'][$field] !== null && $public['seo_meta'][$field] !== $row['seo'][$field]) {
                throw new DomainException('public_content_53_staging_article_original_projection_drift');
            }
        }
    }
}
