<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Support\ArticleSourceTargetSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

final class TraditionalArticleSourcePreflight
{
    public function assertSource(array $proof, bool $lock = false): Article
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('traditional_article_source_transaction_required');
        }

        $source = $this->readArticle($proof['source'], null, $lock);
        if (! $source->isSourceArticle()
            || (int) $source->working_revision_id !== (int) $source->published_revision_id
            || ! hash_equals((string) $source->source_version_hash, $source->computeSourceVersionHash())) {
            throw new DomainException('traditional_article_source_working_state_changed');
        }

        $this->readArticle($proof['existing_english'], $source, $lock);

        return $source;
    }

    public function assertTargetVacant(Article $source, bool $lock = false): void
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('traditional_article_source_transaction_required');
        }

        if (ArticleSourceTargetSnapshot::capture($source, $lock, 'zh-TW') !== []) {
            throw new DomainException('traditional_article_target_already_exists');
        }
    }

    private function readArticle(array $expected, ?Article $source, bool $lock): Article
    {
        $article = Article::query()->withoutGlobalScopes()->withTrashed()
            ->where('org_id', 0)->where('locale', $source === null ? 'zh-CN' : 'en')->where('slug', $expected['slug'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->sole();

        if ($article->trashed() || $article->lifecycle_state !== 'active'
            || $article->status !== 'published' || ! $article->is_public
            || (int) $article->id !== $expected['id']
            || (int) $article->published_revision_id !== $expected['published_revision_id']
            || $article->translation_group_id !== $expected['translation_group_id']
            || $article->source_locale !== 'zh-CN' || $article->source_article_id !== $source?->id
            || $article->translated_from_article_id !== $source?->id
            || (bool) $article->is_indexable !== $expected['is_indexable']
            || (bool) $article->sitemap_eligible !== $expected['sitemap_eligible']) {
            throw new DomainException('traditional_article_source_identity_changed');
        }

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->whereKey($article->published_revision_id)->where('org_id', 0)->where('article_id', $article->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->sole();
        $sourceId = (int) ($source?->id ?? $article->id);
        if (! $revision->isPubliclyReadableForArticle($article)
            || $revision->locale !== $article->locale || $revision->source_locale !== 'zh-CN'
            || $revision->translation_group_id !== $article->translation_group_id
            || (int) $revision->source_article_id !== $sourceId
            || ($source === null && $revision->source_version_hash !== $article->source_version_hash)) {
            throw new DomainException('traditional_article_source_revision_changed');
        }

        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('article_id', $article->id)->where('locale', $article->locale)
            ->when($lock, fn ($query) => $query->lockForUpdate())->sole();

        $snapshot = [
            'title' => $revision->title,
            'excerpt' => $revision->excerpt,
            'content_md' => $revision->content_md,
            'author_name' => $article->author_name,
            'cover_image_alt' => $article->cover_image_alt,
            ...$seo->only(['seo_title', 'seo_description', 'og_title', 'og_description']),
        ];

        $gate = data_get($seo->schema_json, 'editorial_package_v1.hreflang_gate_v1');
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
