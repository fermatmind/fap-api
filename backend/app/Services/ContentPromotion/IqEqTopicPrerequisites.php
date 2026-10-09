<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only prerequisites: Topic copy must not promise unpublished IQ pages. */
final class IqEqTopicPrerequisites
{
    public function __construct(
        private readonly IqPublicArticlePackage $articles,
        private readonly IqPublicEntryPackage $entry,
    ) {}

    public function assertPublished(bool $lock = false): void
    {
        foreach ($this->articles->read(base_path(), IqPublicArticlePackage::SHA256) as $row) {
            $matches = Article::query()->withoutGlobalScopes()->withTrashed()->where($row['identity'])->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $article = $matches->first();
            if ($matches->count() !== 1 || ! $article || $article->trashed() || ! $article->is_public || $article->status !== 'published'
                || ! $article->published_at || $article->published_at->isFuture()
                || ($article->scheduled_at && $article->scheduled_at->isFuture())) {
                throw new DomainException('iq_eq_topic_required_article_unpublished');
            }
            $article->setRelation('seoMeta', $article->seoMeta()->when($lock, fn ($query) => $query->lockForUpdate())->first());
            $article->setRelation('publishedRevision', $article->publishedRevision()->when($lock, fn ($query) => $query->lockForUpdate())->first());
            foreach (['title', 'excerpt', 'content_md'] as $field) {
                if ($article->{$field} !== $row['snapshot'][$field]) {
                    throw new DomainException('iq_eq_topic_required_article_copy_drift');
                }
            }
            if ($article->seoMeta?->seo_title !== $row['snapshot']['seo_title']
                || $article->seoMeta?->seo_description !== $row['snapshot']['seo_description']
                || $article->seoMeta?->og_title !== $row['snapshot']['seo_title']
                || $article->seoMeta?->og_description !== $row['snapshot']['seo_description']) {
                throw new DomainException('iq_eq_topic_required_article_seo_drift');
            }
            $revision = $article->publishedRevision;
            $metadata = data_get($article->seoMeta?->schema_json, 'editorial_package_v1');
            $source = Article::query()->withoutGlobalScopes()->where(['org_id' => 0, 'slug' => $article->slug, 'locale' => 'zh-CN'])->when($lock, fn ($query) => $query->lockForUpdate())->first();
            $binding = data_get($metadata, 'iq_article_exact_package_v1');
            if (! $revision || (int) $revision->article_id !== (int) $article->id || (int) $revision->org_id !== 0
                || $revision->locale !== $article->locale || $revision->revision_status !== 'published'
                || $revision->authority_package_sha256 !== IqPublicArticlePackage::SHA256
                || $revision->authority_source_package !== IqPublicArticlePackage::PACKAGE
                || $revision->authority_asset_key !== $article->locale.':'.$article->slug
                || $revision->authority_source_hash !== $row['snapshot_sha256']
                || ! $source || $revision->source_version_hash !== $source->computeSourceVersionHash()
                || $article->source_version_hash !== $article->computeSourceVersionHash() || $article->content_html !== null
                || ($article->locale === 'en' && $revision->translated_from_version_hash !== $source->computeSourceVersionHash())
                || ! is_array($binding) || ($binding['package_sha256'] ?? null) !== IqPublicArticlePackage::SHA256
                || ($binding['revision_id'] ?? null) !== (int) $revision->id || ($binding['snapshot_sha256'] ?? null) !== $row['snapshot_sha256']
                || PromotionContextFactory::canonicalJson((array) data_get($revision->authority_metadata_json, 'snapshot')) !== PromotionContextFactory::canonicalJson($row['snapshot'])
                || PromotionContextFactory::canonicalJson((array) data_get($metadata, 'answer_surface_v1.faq_items')) !== PromotionContextFactory::canonicalJson($row['snapshot']['faq_items'])) {
                throw new DomainException('iq_eq_topic_required_article_authority_drift');
            }
        }
        $iq = $this->currentScale(IqPublicEntryPackage::CODE, $lock);
        $eq = $this->currentScale('EQ_60', $lock);
        foreach ([[$iq, IqPublicEntryPackage::SLUG], [$eq, 'eq-test-emotional-intelligence-assessment']] as [$scale, $slug]) {
            if (! is_array($scale) || ($scale['primary_slug'] ?? null) !== $slug
                || ! ((bool) ($scale['is_public'] ?? false)) || ! ((bool) ($scale['is_active'] ?? false))) {
                throw new DomainException('iq_eq_topic_required_scale_unavailable');
            }
        }
        foreach ($this->entry->read(base_path(), IqPublicEntryPackage::SHA256) as $row) {
            foreach ($row['patch'] as $field => $value) {
                if (PromotionContextFactory::canonicalJson((array) data_get($iq, 'content_i18n_json.'.$row['key'].'.'.$field)) !== PromotionContextFactory::canonicalJson((array) $value)) {
                    throw new DomainException('iq_eq_topic_required_iq_entry_copy_drift');
                }
            }
        }
    }

    /** Read authority rows directly; a derived public cache cannot authorize publication. */
    private function currentScale(string $code, bool $lock): ?array
    {
        $tables = config('fap.scales_registry.use_v2', true) ? ['scales_registry_v2', 'scales_registry'] : ['scales_registry'];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->where('org_id', 0)->where('code', $code)
                ->when($lock, fn ($query) => $query->lockForUpdate())->get();
            if ($rows->count() > 1) {
                throw new DomainException('iq_eq_topic_required_scale_identity_collision');
            }
            $record = $rows->first();
            if (! $record || ! ((bool) $record->is_public) || ! ((bool) $record->is_active)) {
                continue;
            }
            $row = (array) $record;
            $row['content_i18n_json'] = is_string($record->content_i18n_json)
                ? json_decode($record->content_i18n_json, true, 512, JSON_THROW_ON_ERROR) : $record->content_i18n_json;

            return $row;
        }

        return null;
    }
}
