<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleReviewedCounterpartService;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash;
use DomainException;
use Illuminate\Support\Facades\DB;

final class TraditionalArticleSourcePreflight
{
    public function assertSource(array $proof, bool $lock = false, bool $allowStagingPreparedRoot = false): Article
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('traditional_article_source_transaction_required');
        }

        $sourceLocale = $proof['source']['locale'] ?? 'zh-CN';
        if (! in_array($sourceLocale, ['zh-CN', 'en'], true)) {
            throw new DomainException('traditional_article_source_locale_invalid');
        }
        $publishedProjection = ($proof['source_snapshot_kind'] ?? null) === 'published_projection';
        $source = $this->readArticle($proof['source'], $sourceLocale, null, $lock, $publishedProjection || $sourceLocale === 'en',
            $allowStagingPreparedRoot && $publishedProjection && $sourceLocale === 'zh-CN');
        // Staging can hold a published root copied from the prepared workflow.
        // readArticle has already checked null parents, source locale and the exact public revision.
        $preparedRoot = $allowStagingPreparedRoot
            && $source->translation_status === Article::TRANSLATION_STATUS_APPROVED;
        if ((! $source->isSourceArticle() && ! $preparedRoot)
            || (int) $source->working_revision_id !== (int) $source->published_revision_id
            || ! hash_equals((string) $source->source_version_hash, $source->computeSourceVersionHash())) {
            throw new DomainException('traditional_article_source_working_state_changed');
        }

        if (isset($proof['editorial_projection_sha256'])) {
            $response = app(ArticleController::class)->publicDetailProjection($source);
            $editorial = TraditionalArticlePublication::editorialProjection($response);
            if (CanonicalTranslationPayloadHash::hash($editorial) !== $proof['editorial_projection_sha256']
                || TraditionalArticlePublication::ctaTargets($editorial['ctas']) !== ($proof['editorial_cta_targets'] ?? null)) {
                throw new DomainException('traditional_article_source_editorial_drift');
            }
        }

        if ($sourceLocale === 'en') {
            if (! array_key_exists('existing_chinese', $proof)) {
                throw new DomainException('traditional_article_english_source_chinese_counterpart');
            }
            $independentChinese = $proof['existing_chinese'];
            if ($independentChinese !== null) {
                $chinese = $this->readArticle($independentChinese, 'zh-CN', null, $lock, publishedProjection: true);
                $counterparts = app(ArticleReviewedCounterpartService::class);
                if ($chinese->slug !== $source->slug || $chinese->translation_group_id === $source->translation_group_id
                    || $chinese->content_md === $source->content_md
                    || $counterparts->binding($source) !== null || $counterparts->binding($chinese) !== null) {
                    throw new DomainException('traditional_article_english_source_chinese_counterpart');
                }
            }
            if ($this->hasPublishedCounterpart($source, 'zh-CN', $independentChinese === null ? [$source->slug] : [], $lock)) {
                throw new DomainException('traditional_article_english_source_chinese_counterpart');
            }

            return $source;
        }

        $english = $proof['existing_english'];
        if ($english === null) {
            $this->assertStagingEnglishAbsent($source, $proof['unpublished_english_slug'] ?? null, $allowStagingPreparedRoot, $lock);
        } else {
            $englishParent = TraditionalArticlePublication::englishSourceId($proof);
            $this->readArticle($english, 'en', $englishParent === null ? null : $source, $lock, $publishedProjection);
        }

        return $source;
    }

    public function assertTargetVacant(Article $source, bool $lock = false, ?string $targetSlug = null): void
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('traditional_article_source_transaction_required');
        }

        if (ArticleSourceTargetSnapshot::capture($source, $lock, 'zh-TW', $targetSlug) !== []) {
            throw new DomainException('traditional_article_target_already_exists');
        }
    }

    private function assertStagingEnglishAbsent(Article $source, ?string $slug, bool $staging, bool $lock): void
    {
        if (! $staging || $slug === null || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            throw new DomainException('traditional_article_staging_english_absence_invalid');
        }

        if ($this->hasPublishedCounterpart($source, 'en', [$source->slug, $slug], $lock)) {
            throw new DomainException('traditional_article_staging_english_now_published');
        }
    }

    private function hasPublishedCounterpart(Article $source, string $locale, array $slugs, bool $lock): bool
    {
        return Article::query()->withoutGlobalScopes()->where('org_id', 0)->where('locale', $locale)
            ->whereNull('deleted_at')->published()
            ->where(static fn ($query) => $query->whereIn('slug', $slugs)
                ->orWhere('source_article_id', $source->id)->orWhere('translation_group_id', $source->translation_group_id))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null;
    }

    private function readArticle(array $expected, string $locale, ?Article $source, bool $lock, bool $publishedProjection = false, bool $allowPreparedRevisionHash = false): Article
    {
        $sourceLocale = $source?->locale ?? $locale;
        $article = Article::query()->withoutGlobalScopes()->withTrashed()
            ->where('org_id', 0)->where('locale', $locale)->where('slug', $expected['slug'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->sole();

        if ($article->trashed() || $article->lifecycle_state !== 'active'
            || $article->status !== 'published' || ! $article->is_public
            || (int) $article->id !== $expected['id']
            || (int) $article->published_revision_id !== $expected['published_revision_id']
            || $article->translation_group_id !== $expected['translation_group_id']
            || $article->source_locale !== $sourceLocale || $article->source_article_id !== $source?->id
            || $article->translated_from_article_id !== $source?->id
            || (bool) $article->is_indexable !== $expected['is_indexable']
            || (bool) $article->sitemap_eligible !== $expected['sitemap_eligible']) {
            throw new DomainException('traditional_article_source_identity_changed');
        }

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->whereKey($article->published_revision_id)->where('org_id', 0)->where('article_id', $article->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->sole();
        $sourceId = (int) ($source?->id ?? $article->id);
        // Prepared staging roots can retain the import hash on their published revision.
        // Require an exact package binding and still verify the current root hash and all published fields.
        $expectedRevisionHash = $article->source_version_hash;
        if ($allowPreparedRevisionHash && $source === null
            && $article->translation_status === Article::TRANSLATION_STATUS_APPROVED
            && $revision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED
            && isset($expected['prepared_revision_source_hash'])) {
            $expectedRevisionHash = $expected['prepared_revision_source_hash'];
        }
        if (! $revision->isPubliclyReadableForArticle($article)
            || $revision->locale !== $article->locale || $revision->source_locale !== $sourceLocale
            || $revision->translation_group_id !== $article->translation_group_id
            || (int) $revision->source_article_id !== $sourceId
            || ($source === null && $revision->source_version_hash !== $expectedRevisionHash)) {
            throw new DomainException('traditional_article_source_revision_changed');
        }

        $seoRows = ArticleSeoMeta::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('article_id', $article->id)->where('locale', $article->locale)
            ->when($lock, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        if ($seoRows->count() !== (($expected['seo_present'] ?? true) === false ? 0 : 1)) {
            throw new DomainException('traditional_article_source_seo_state_changed');
        }
        $seo = $seoRows->first();

        $snapshot = [
            'title' => $revision->title,
            'excerpt' => $revision->excerpt,
            'content_md' => $revision->content_md,
            'author_name' => $article->author_name,
            'cover_image_alt' => $article->cover_image_alt,
            ...($seo?->only(['seo_title', 'seo_description', 'og_title', 'og_description'])
                ?? array_fill_keys(['seo_title', 'seo_description', 'og_title', 'og_description'], null)),
        ];

        if ($publishedProjection) {
            // Reader-facing SEO title and description belong to the locked published revision.
            $article->setRelation('publishedRevision', $revision)->setRelation('seoMeta', $seo);
            $public = app(ArticleController::class)->publicDetailProjection($article)['article'];
            $snapshot = array_replace($snapshot, array_intersect_key($public, $snapshot), array_intersect_key($public['seo_meta'] ?? [], $snapshot));
        }
        $gate = $publishedProjection
            ? ($public['seo_meta']['hreflang_gate_v1'] ?? null)
            : data_get($seo?->schema_json, 'editorial_package_v1.hreflang_gate_v1');
        $gate = is_array($gate) ? array_intersect_key($gate, array_flip(['enabled', 'policy', 'reason'])) : null;
        $snapshot = array_map(static fn (?string $value): ?string => $value === null ? null : hash('sha256', $value), $snapshot);
        $expectedSnapshot = $expected['snapshot_sha256'];
        $expectedGate = $expected['hreflang_gate'];
        ksort($snapshot);
        ksort($expectedSnapshot);
        if (is_array($gate)) {
            ksort($gate);
        }
        if (is_array($expectedGate)) {
            ksort($expectedGate);
        }
        if ($snapshot !== $expectedSnapshot
            || $article->title !== $revision->title || $article->excerpt !== $revision->excerpt
            || $article->content_md !== $revision->content_md
            || $gate !== $expectedGate) {
            throw new DomainException('traditional_article_source_payload_changed');
        }

        return $article;
    }
}
