<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\EditorialReview;
use App\Services\Audit\AuditLogger;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Exact bilingual update through native Article revision, review and promotion services.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface editorial_review
 */
final class EnneagramArticleRefresh
{
    public const PACKAGE = 'content_assets/enneagram_public/articles/20261015-explained-v1/package.json';

    public const PACKAGE_SHA256 = '5fe459bc8907968d7b52268671251da3f4d272f7d841603b6e8cb187cfc352f6';

    private const SLUG = 'enneagram-personality-test-explained';

    private const GROUP = 'tg_article_enneagram_personality_test_explained_2026v1';

    public function __construct(
        private readonly ArticleTranslationRevisionWorkspace $workspace,
        private readonly ArticleTranslationWorkflowService $workflow,
        private readonly ArticlePublishService $publisher,
        private readonly CmsEditorialReviewAttestationService $reviews,
        private readonly ArticleEditorialCompletenessGate $completeness,
        private readonly ArticleSeoGateRollout $seoGates,
    ) {}

    public function publish(bool $dryRun = false): array
    {
        $package = $this->package();
        $result = DB::transaction(function () use ($package, $dryRun): array {
            $articles = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)
                ->where('slug', self::SLUG)->whereIn('locale', ['zh-CN', 'en'])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('locale');
            if ($articles->isEmpty()) {
                return ['status' => 'absent', 'changed' => 0];
            }
            if ($articles->count() !== 2) {
                throw new RuntimeException('Enneagram Article pair is incomplete.');
            }
            $replay = 0;
            foreach (['zh-CN' => 51, 'en' => 190] as $locale => $id) {
                $article = $articles[$locale];
                $article->setRelation('publishedRevision', ArticleTranslationRevision::withoutGlobalScopes()
                    ->whereKey($article->published_revision_id)->lockForUpdate()->first());
                $article->setRelation('workingRevision', ArticleTranslationRevision::withoutGlobalScopes()
                    ->whereKey($article->working_revision_id)->lockForUpdate()->first());
                $article->setRelation('seoMeta', ArticleSeoMeta::withoutGlobalScopes()->where('org_id', 0)
                    ->where('article_id', $id)->where('locale', $locale)->lockForUpdate()->first());
                $revision = $article->publishedRevision;
                if ((int) $article->id !== $id || $article->trashed() || $article->lifecycle_state !== Article::LIFECYCLE_ACTIVE
                    || filled($article->lifecycle_note) || $article->status !== 'published' || ! $article->is_public
                    || ! $article->published_at || $article->published_at->isFuture()
                    || $article->translation_group_id !== self::GROUP
                    || $article->source_locale !== 'zh-CN'
                    || ($locale === 'en' ? (int) $article->source_article_id !== 51 : $article->source_article_id !== null)
                    || ! $revision || (int) $revision->org_id !== 0 || (int) $revision->article_id !== $id || $revision->locale !== $locale
                    || $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
                    || ! $revision->approved_at || ! $revision->published_at || $revision->published_at->isFuture()
                    || ! $article->workingRevision || (int) $article->working_revision_id !== (int) $article->published_revision_id
                    || ($article->seoMeta && $article->seoMeta->canonical_url !== $package['candidates'][$locale]['canonical'])
                    || $article->content_md !== $revision->content_md) {
                    throw new RuntimeException('Enneagram Article identity, hold, revision or draft drift: '.$locale);
                }
                $replay += $revision->authority_package_sha256 === self::PACKAGE_SHA256 ? 1 : 0;
            }
            if ($replay === 2) {
                $this->assertPublished($articles, $package);

                return ['status' => 'unchanged', 'changed' => 0];
            }
            if ($replay !== 0 || ! $this->matchesBaseline($articles, $package['baselines'])) {
                throw new RuntimeException('Enneagram Article exact baseline drift.');
            }
            $owner = (int) config('review_governance.solo_owner_admin_user_id');
            if (! $this->reviews->isConfiguredSoloOwner($owner)) {
                throw new RuntimeException('Enneagram Article native review policy is unavailable.');
            }
            app(PublicContent53FrontendRevalidator::class)->assertConfigured();
            foreach ($articles as $locale => $article) {
                $candidate = $package['candidates'][$locale];
                if (! $this->completeness->inspect($locale, $candidate['content_md'], ['body' => $candidate['content_md']])['ok']) {
                    throw new RuntimeException('Enneagram Article editorial completeness failed.');
                }
            }
            if ($dryRun) {
                return ['status' => 'ready', 'changed' => 0];
            }
            // The independently inspected staging source predates source-status convergence.
            $articles['zh-CN']->forceFill(['translation_status' => Article::TRANSLATION_STATUS_SOURCE])->saveQuietly();
            foreach ($articles as $locale => $article) {
                if (! $article->seoMeta) {
                    $seo = app(ArticleSeoService::class)->generateSeoMeta((int) $article->id);
                    $seo->forceFill($package['missing_seo'][$locale])->saveQuietly();
                    $article->setRelation('seoMeta', $seo);
                }
            }
            // All data writes, new approvals and publication pointers commit together.
            // Native workspace forks the public revision; no operator draft is overwritten.
            foreach (['zh-CN', 'en'] as $locale) {
                $article = $articles[$locale];
                $previous = (int) $article->published_revision_id;
                $revision = $this->workspace->saveWorkingRevision($article, [
                    'title' => $article->title, 'excerpt' => $article->excerpt,
                    'content_md' => $package['candidates'][$locale]['content_md'],
                    'seo_title' => $article->seoMeta->seo_title,
                    'seo_description' => $article->seoMeta->seo_description,
                    'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                ], $owner);
                $sourceHash = (string) $articles['zh-CN']->fresh()->source_version_hash;
                $revision->forceFill([
                    'source_version_hash' => $sourceHash, 'translated_from_version_hash' => $sourceHash,
                    'authority_source_package' => self::PACKAGE, 'authority_package_sha256' => self::PACKAGE_SHA256,
                    'authority_source_hash' => $package['candidates'][$locale]['content_sha256'],
                    'authority_metadata_json' => ['review' => $package['review'], 'delivery' => 'automatic_trunk_migration'],
                ])->saveQuietly();
                if ($locale === 'en') {
                    $article->fresh()->forceFill(['translated_from_version_hash' => $sourceHash])->saveQuietly();
                }
                // The owner has authorized this automatic, reviewed exact-package transition.
                // Final content fingerprints are bound after native public projection below.
                try {
                    $this->workflow->approveEditorialWorkingRevision($article->fresh(), $owner, bindAttestation: false);
                } catch (ArticleTranslationWorkflowException $error) {
                    throw new RuntimeException('Enneagram Article native review blocked: '.implode('; ', $error->blockers), previous: $error);
                }
                $articles[$locale] = $this->publisher->promoteExistingWorkingRevision(
                    (int) $article->id, (int) $revision->id, $previous,
                    'enneagram_reviewed_bilingual_article_refresh', dispatchFollowUp: false,
                );
                $article = $articles[$locale];
                $article->refresh()->load('publishedRevision');
                $editorial = EditorialReview::withoutGlobalScopes()->where('content_type', 'article')
                    ->where('content_id', $article->id)->lockForUpdate()->first()
                    ?? new EditorialReview(['org_id' => 0, 'content_type' => 'article', 'content_id' => $article->id]);
                $editorial->forceFill(['workflow_state' => 'approved', 'reviewed_by_admin_user_id' => null,
                    'reviewed_at' => now(), 'last_transition_at' => now(),
                    'note' => 'Automatic exact-package review: '.self::PACKAGE_SHA256])->save();
                $this->reviews->bindOrCreateApproved(null, 'cms_article_review',
                    'enneagram-bilingual:'.$article->id.':revision:'.$article->published_revision_id,
                    [['surface_id' => 'article', 'record' => $article],
                        ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
                        ['surface_id' => 'editorial_review', 'record' => $editorial]],
                    $owner, self::PACKAGE_SHA256);
                $this->reviews->assertApprovedEvidence([
                    ['surface_id' => 'article', 'record' => $article],
                    ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
                ]);
                $schema = $article->seoMeta->schema_json ?? [];
                $faq = data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items', []);
                foreach ($faq as &$item) {
                    if ($item['question'] === $package['candidates'][$locale]['faq']['question']) {
                        $item['answer'] = $package['candidates'][$locale]['faq']['answer'];
                    }
                }
                unset($item);
                if ($faq !== []) {
                    data_set($schema, 'editorial_package_v1.answer_surface_v1.faq_items', $faq);
                    $article->seoMeta->forceFill(['schema_json' => $schema])->saveQuietly();
                }
            }
            // Content and discoverability remain separate transitions in the same bounded lane.
            $english = $articles['en'];
            $english->forceFill(['is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true])->saveQuietly();
            $english->seoMeta->forceFill(['is_indexable' => true, 'robots' => 'index,follow'])->save();
            $gate = $this->seoGates->run([
                'article_ids' => '51,190', 'expected_slugs' => self::SLUG.','.self::SLUG,
                'translation_group_id' => self::GROUP, 'enable_hreflang' => true, 'execute' => true,
                'no_publish' => true, 'no_search' => true, 'no_sitemap_llms_change' => true,
                'no_content_change' => true, 'no_revalidation' => true,
            ]);
            if (! $gate['ok']) {
                throw new RuntimeException('Enneagram Article native reciprocal SEO gate failed.');
            }
            $this->assertPublished($articles, $package);
            foreach ($articles as $article) {
                $article->refresh()->load(['publishedRevision', 'seoMeta']);
                // Retain promotion history and append the final FAQ/SEO/feed state.
                app(ArticleMaterialDecisionService::class)->recordPublished($article, $article->publishedRevision, now());
            }
            app(AuditLogger::class)->log(Request::create('/ops/articles/enneagram-bilingual-refresh', 'POST'),
                'enneagram_bilingual_article_refresh', 'article', meta: [
                    'actor' => 'automatic_trunk_migration', 'article_ids' => [51, 190],
                    'package_sha256' => self::PACKAGE_SHA256, 'review' => $package['review'],
                    'source_revision_id' => $articles['zh-CN']->published_revision_id,
                    'target_revision_id' => $articles['en']->published_revision_id,
                    'search_submission' => false, 'media_changed' => false,
                ], reason: 'reviewed_enneagram_bilingual_article_update');

            return ['status' => 'published', 'changed' => 2];
        });
        if (! $dryRun && $result['status'] !== 'absent') {
            // Also close a prior post-commit cache failure without replaying data writes.
            app(SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches(false);
            // Existing signed consumer validates nonempty acknowledgements for both
            // exact detail paths and their article-detail/article-seo cache tags.
            app(PublicContent53FrontendRevalidator::class)->revalidate(array_map(
                static fn (string $locale): array => ['kind' => 'article', 'identity' => [
                    'org_id' => 0, 'locale' => $locale, 'slug' => self::SLUG,
                ]], ['zh-CN', 'en'],
            ));
            $this->revalidateDiscoverability();
        }

        return $result;
    }

    private function revalidateDiscoverability(): void
    {
        // Reuse the configured signed endpoint and its existing Article path planner.
        // Including llms-full invalidates and rebuilds its independent shared artifact.
        $paths = ['/sitemap.xml', '/llms.txt', '/llms-full.txt'];
        $body = json_encode(['content' => ['type' => 'article', 'slug' => self::SLUG, 'locale' => 'en'],
            'cache_signal' => ['paths' => $paths]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $secret = (string) config('ops.content_release_observability.hmac_revalidation_secret');
        $response = Http::acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()->withHeaders([
            'X-FM-Content-Release-Timestamp' => $timestamp, 'X-FM-Content-Release-Nonce' => $nonce,
            'X-FM-Content-Release-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret),
        ])->withBody($body, 'application/json')->post((string) config('ops.content_release_observability.hmac_revalidation_url'));
        $receipt = $response->json();
        $expected = [...$paths, '/zh/articles', '/en/articles', '/zh/articles/'.self::SLUG, '/en/articles/'.self::SLUG];
        if (! $response->successful() || ($receipt['ok'] ?? null) !== true
            || ($receipt['revalidated_paths'] ?? null) !== $expected || ($receipt['rejected_paths'] ?? null) !== []
            || ($receipt['invalidated_tags'] ?? null) !== ['article-detail:en:'.self::SLUG, 'article-seo:en:'.self::SLUG]) {
            throw new RuntimeException('Enneagram Article discovery cache acknowledgement failed.');
        }
    }

    private function package(): array
    {
        $bytes = file_get_contents(base_path(self::PACKAGE));
        if (! is_string($bytes) || ! hash_equals(self::PACKAGE_SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('Enneagram Article package digest mismatch.');
        }
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if ($package['schema'] !== 'enneagram.bilingual_article_refresh.v1'
            || $package['review']['decision'] !== 'PASS' || $package['review']['role'] !== 'fm_independent_reviewer'
            || array_keys($package['candidates']) !== ['zh-CN', 'en']) {
            throw new RuntimeException('Enneagram Article package scope or review mismatch.');
        }
        foreach ($package['candidates'] as $candidate) {
            if (! hash_equals($candidate['content_sha256'], hash('sha256', $candidate['content_md']))) {
                throw new RuntimeException('Enneagram Article candidate digest mismatch.');
            }
        }

        return $package;
    }

    private function matchesBaseline($articles, array $baselines): bool
    {
        foreach ($baselines as $baseline) {
            $matches = true;
            foreach ($articles as $locale => $article) {
                $expected = $baseline[$locale];
                $editorial = EditorialReview::withoutGlobalScopes()->where('org_id', 0)->where('content_type', 'article')
                    ->where('content_id', $article->id)->lockForUpdate()->first();
                foreach (['published_revision_id', 'working_revision_id', 'source_version_hash', 'translated_from_version_hash'] as $field) {
                    $matches = $matches && $expected[$field] === $article->$field;
                }
                $matches = $matches && hash('sha256', $article->content_md) === $expected['article_body_sha256']
                    && hash('sha256', $article->publishedRevision->content_md) === $expected['published_body_sha256']
                    && ($article->seoMeta === null) === $expected['seo_missing']
                    && $article->translation_status === $expected['translation_status']
                    && $editorial?->workflow_state === $expected['editorial_review_state']
                    && ! filled($editorial?->note)
                    && ($article->seoMeta === null || hash('sha256', (string) $article->seoMeta->getRawOriginal('schema_json')) === $expected['seo_schema_sha256']);
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function assertPublished($articles, array $package): void
    {
        $source = $articles['zh-CN']->fresh();
        foreach ($articles as $locale => $article) {
            $article->refresh()->load(['publishedRevision', 'seoMeta']);
            $revision = $article->publishedRevision;
            if ($article->content_md !== $package['candidates'][$locale]['content_md']
                || $revision->content_md !== $article->content_md || $revision->authority_package_sha256 !== self::PACKAGE_SHA256
                || $revision->source_version_hash !== $source->source_version_hash
                || $revision->translated_from_version_hash !== $source->source_version_hash
                || ($locale === 'en' && $article->translated_from_version_hash !== $source->source_version_hash)
                || ! $article->is_indexable || ! $article->sitemap_eligible || ! $article->llms_eligible
                || ! $article->seoMeta->is_indexable || $article->seoMeta->robots !== 'index,follow') {
                throw new RuntimeException('Enneagram Article public readback failed.');
            }
            $this->reviews->assertApprovedEvidence([
                ['surface_id' => 'article', 'record' => $article],
                ['surface_id' => 'article_translation_revision', 'record' => $revision],
            ]);
        }
    }
}
