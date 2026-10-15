<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\EditorialReview;
use App\Services\Audit\AuditLogger;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use App\Services\SeoIntel\UrlTruth\IncrementalUrlTruthSyncService;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Exact Article68/69 text update; existing search gates and public identity are preserved.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface editorial_review
 */
final class EnneagramWorkplaceArticleRefresh
{
    public const PACKAGE = 'content_assets/enneagram_public/articles/20261016-workplace-v1/package.json';

    public const PACKAGE_SHA256 = '7c69957baa03ff8f1db01fefa40349500b27d5401b78107b10745f2571fc6e95';

    private const SLUG = 'enneagram-workplace-friction-core-motivations';

    private const GROUP = 'tg_enneagram_workplace_friction_core_motivations_2026v1';

    public function __construct(
        private readonly ArticleTranslationRevisionWorkspace $workspace,
        private readonly ArticleTranslationWorkflowService $workflow,
        private readonly ArticlePublishService $publisher,
        private readonly CmsEditorialReviewAttestationService $reviews,
        private readonly ArticleEditorialCompletenessGate $completeness,
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
                throw new RuntimeException('Workplace Article pair is incomplete.');
            }
            $replay = 0;
            foreach (['zh-CN' => 68, 'en' => 69] as $locale => $id) {
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
                    || $article->translation_group_id !== self::GROUP || ! $article->seoMeta
                    || ! $revision || (int) $revision->org_id !== 0 || (int) $revision->article_id !== $id || $revision->locale !== $locale
                    || $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
                    || ! $revision->approved_at || ! $revision->published_at
                    || $revision->published_at->isFuture() || ! $article->workingRevision
                    || (int) $article->working_revision_id !== (int) $article->published_revision_id
                    || $article->content_md !== $revision->content_md) {
                    throw new RuntimeException('Workplace Article identity, hold or working revision drift: '.$locale);
                }
                $replay += $revision->authority_package_sha256 === self::PACKAGE_SHA256 ? 1 : 0;
            }
            if ($replay === 2) {
                $this->assertPublished($articles, $package);

                return ['status' => 'unchanged', 'changed' => 0];
            }
            $baseline = $replay === 0 ? $this->baseline($articles, $package) : null;
            if ($baseline === null) {
                throw new RuntimeException('Workplace Article exact baseline drift.');
            }
            $owner = (int) config('review_governance.solo_owner_admin_user_id');
            if (! $this->reviews->isConfiguredSoloOwner($owner)) {
                throw new RuntimeException('Workplace Article native review policy is unavailable.');
            }
            app(PublicContent53FrontendRevalidator::class)->assertConfigured();
            foreach ($articles as $locale => $article) {
                $candidate = $package['candidates'][$locale];
                if (! $this->completeness->inspect($locale, $candidate['content_md'], $candidate)['ok']) {
                    throw new RuntimeException('Workplace Article editorial completeness failed.');
                }
            }
            if ($dryRun) {
                return ['status' => 'ready', 'changed' => 0];
            }
            // The exact older staging baseline has a self-sourced English row.
            // Converge only this selected pair before the native workspace forks it.
            $articles['zh-CN']->forceFill(['translation_status' => Article::TRANSLATION_STATUS_SOURCE])->saveQuietly();
            $articles['en']->forceFill(['source_article_id' => 68, 'translated_from_article_id' => 68,
                'source_locale' => 'zh-CN'])->saveQuietly();
            foreach (['zh-CN', 'en'] as $locale) {
                $article = $articles[$locale]->fresh(['seoMeta']);
                $previous = (int) $article->published_revision_id;
                $candidate = $package['candidates'][$locale];
                $revision = $this->workspace->saveWorkingRevision($article, [
                    'title' => $article->title, 'excerpt' => $candidate['excerpt'],
                    'content_md' => $candidate['content_md'], 'seo_title' => $article->seoMeta->seo_title,
                    'seo_description' => $article->seoMeta->seo_description,
                    'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                ], $owner);
                $sourceHash = (string) $articles['zh-CN']->fresh()->source_version_hash;
                if ($locale === 'zh-CN') {
                    $projection = clone $article;
                    $projection->forceFill(['excerpt' => $candidate['excerpt'],
                        'content_md' => $candidate['content_md'], 'content_html' => null]);
                    $sourceHash = $projection->computeSourceVersionHash();
                }
                $revision->forceFill(['source_version_hash' => $sourceHash, 'translated_from_version_hash' => $sourceHash,
                    'authority_source_package' => self::PACKAGE, 'authority_package_sha256' => self::PACKAGE_SHA256,
                    'authority_source_hash' => $candidate['content_sha256'],
                    'authority_metadata_json' => ['review' => $package['review'], 'baseline' => $baseline,
                        'delivery' => 'automatic_trunk_migration']])->saveQuietly();
                if ($locale === 'en') {
                    $article->fresh()->forceFill(['translated_from_version_hash' => $sourceHash])->saveQuietly();
                }
                $this->workflow->approveEditorialWorkingRevision($article->fresh(), $owner, bindAttestation: false);
                $articles[$locale] = $this->publisher->promoteExistingWorkingRevision(
                    (int) $article->id, (int) $revision->id, $previous,
                    'enneagram_workplace_reviewed_bilingual_refresh', dispatchFollowUp: false,
                );
                $article = $articles[$locale];
                // Only the body fingerprint changes; FAQ/CTA and every explicit gate stay intact.
                $schema = $article->seoMeta->schema_json;
                data_set($schema, 'editorial_package_v1.body_hash', $candidate['content_sha256']);
                $article->seoMeta->forceFill(['schema_json' => $schema])->saveQuietly();
                $editorial = EditorialReview::withoutGlobalScopes()->where('org_id', 0)->where('content_type', 'article')
                    ->where('content_id', $article->id)->lockForUpdate()->first()
                    ?? new EditorialReview(['org_id' => 0, 'content_type' => 'article', 'content_id' => $article->id]);
                $editorial->forceFill(['workflow_state' => 'approved', 'reviewed_by_admin_user_id' => null,
                    'reviewed_at' => now(), 'last_transition_at' => now(),
                    'note' => 'Automatic exact-package review: '.self::PACKAGE_SHA256])->save();
                $this->reviews->bindOrCreateApproved(null, 'cms_article_review',
                    'enneagram-workplace:'.$article->id.':revision:'.$article->published_revision_id,
                    [['surface_id' => 'article', 'record' => $article],
                        ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
                        ['surface_id' => 'editorial_review', 'record' => $editorial]], $owner, self::PACKAGE_SHA256);
            }
            $this->assertPublished($articles, $package);
            foreach ($articles as $article) {
                $article->refresh()->load(['publishedRevision', 'seoMeta']);
                app(ArticleMaterialDecisionService::class)->recordPublished($article, $article->publishedRevision, now());
            }
            app(AuditLogger::class)->log(Request::create('/ops/articles/enneagram-workplace-refresh', 'POST'),
                'enneagram_workplace_bilingual_refresh', 'article', meta: [
                    'actor' => 'automatic_trunk_migration', 'article_ids' => [68, 69],
                    'package_sha256' => self::PACKAGE_SHA256, 'review' => $package['review'],
                    'source_revision_id' => $articles['zh-CN']->published_revision_id,
                    'target_revision_id' => $articles['en']->published_revision_id,
                    'search_submission' => false, 'discoverability_changed' => false, 'media_changed' => false,
                ], reason: 'reviewed_enneagram_workplace_text_update');

            return ['status' => 'published', 'changed' => 2];
        });
        if (! $dryRun && $result['status'] !== 'absent') {
            app(SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches(false);
            app(PublicContent53FrontendRevalidator::class)->revalidate(array_map(
                static fn (string $locale): array => ['kind' => 'article', 'identity' => [
                    'org_id' => 0, 'locale' => $locale, 'slug' => self::SLUG,
                ]], ['zh-CN', 'en'],
            ));
            $this->revalidateReaders();
            if ($result['status'] === 'unchanged' && config('seo_intel.enabled', false)) {
                // A committed promotion may have lost its remaining after-commit
                // callbacks. Reuse the native idempotent handler/readback without
                // republishing or releasing a possibly pending queue unique lock.
                foreach (['zh-CN' => 68, 'en' => 69] as $locale => $id) {
                    $article = Article::withoutGlobalScopes()->findOrFail($id);
                    $revision = hash('sha256', json_encode(['article', (string) $id, $locale,
                        (string) $article->status, (bool) $article->is_public,
                        (int) $article->published_revision_id, $article->updated_at?->toIso8601String()],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    (new SyncPublicAuthorityUrlTruth('article', (string) $id, $locale, $revision,
                        'authority_revision', ! (bool) config('seo_intel.write_enabled', false)))
                        ->handle(app(IncrementalUrlTruthSyncService::class));
                }
            }
        }

        return $result;
    }

    private function package(): array
    {
        $bytes = file_get_contents(base_path(self::PACKAGE));
        if (! is_string($bytes) || ! hash_equals(self::PACKAGE_SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('Workplace Article package digest mismatch.');
        }
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if ($package['schema'] !== 'enneagram.workplace_article_refresh.v1'
            || $package['review']['decision'] !== 'PASS' || $package['review']['role'] !== 'fm_independent_reviewer'
            || array_keys($package['candidates']) !== ['zh-CN', 'en']) {
            throw new RuntimeException('Workplace Article package scope or review mismatch.');
        }
        foreach ($package['candidates'] as $candidate) {
            if (! hash_equals($candidate['content_sha256'], hash('sha256', $candidate['content_md']))) {
                throw new RuntimeException('Workplace Article candidate digest mismatch.');
            }
        }

        return $package;
    }

    private function baseline($articles, array $package): ?string
    {
        foreach ($package['baselines'] as $name => $baseline) {
            $matches = true;
            foreach ($articles as $locale => $article) {
                $expected = $baseline[$locale];
                $editorial = EditorialReview::withoutGlobalScopes()->where('org_id', 0)->where('content_type', 'article')
                    ->where('content_id', $article->id)->lockForUpdate()->first();
                $matches = $matches && Hash::sameValue($this->values($article, array_keys($expected['article'])), $expected['article'])
                    && Hash::sameValue($this->values($article->publishedRevision, array_keys($expected['revision'])), $expected['revision'])
                    && Hash::sameValue($article->seoMeta->getAttributes(), $expected['seo'])
                    && $editorial?->workflow_state === $expected['editorial_review_state'] && ! filled($editorial?->note);
            }
            if ($matches) {
                return $name;
            }
        }

        return null;
    }

    private function values($model, array $keys): array
    {
        return json_decode(json_encode($model->only($keys), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    }

    private function assertPublished($articles, array $package): void
    {
        $source = $articles['zh-CN']->fresh();
        foreach ($articles as $locale => $article) {
            $article->refresh()->load(['publishedRevision', 'seoMeta']);
            $revision = $article->publishedRevision;
            $baseline = data_get($revision->authority_metadata_json, 'baseline');
            $expected = $package['baselines'][$baseline][$locale] ?? null;
            if (! $expected || $article->content_md !== $package['candidates'][$locale]['content_md']
                || $article->excerpt !== $package['candidates'][$locale]['excerpt']
                || $revision->content_md !== $article->content_md || $revision->excerpt !== $article->excerpt
                || $revision->authority_package_sha256 !== self::PACKAGE_SHA256
                || $revision->source_version_hash !== $source->source_version_hash
                || $revision->translated_from_version_hash !== $source->source_version_hash
                || ($locale === 'en' && ($article->translated_from_version_hash !== $source->source_version_hash
                    || (int) $article->source_article_id !== 68 || $article->source_locale !== 'zh-CN'))) {
                throw new RuntimeException('Workplace Article public readback failed.');
            }
            $protected = array_diff_key($expected['article'], array_flip(['excerpt', 'content_md', 'content_html',
                'source_article_id', 'source_locale', 'translation_status', 'translated_from_article_id',
                'source_version_hash', 'translated_from_version_hash', 'working_revision_id', 'published_revision_id', 'updated_at']));
            $seo = $article->seoMeta->getAttributes();
            $oldSeo = $expected['seo'];
            unset($seo['updated_at'], $oldSeo['updated_at']);
            $oldSchema = json_decode($oldSeo['schema_json'], true, 64, JSON_THROW_ON_ERROR);
            data_set($oldSchema, 'editorial_package_v1.body_hash', $package['candidates'][$locale]['content_sha256']);
            $oldSeo['schema_json'] = $oldSchema;
            $seo['schema_json'] = $article->seoMeta->schema_json;
            if (! Hash::sameValue($protected, $this->values($article, array_keys($protected))) || ! Hash::sameValue($oldSeo, $seo)) {
                throw new RuntimeException('Workplace Article protected metadata or gate drift.');
            }
            $this->reviews->assertApprovedEvidence([
                ['surface_id' => 'article', 'record' => $article],
                ['surface_id' => 'article_translation_revision', 'record' => $revision],
            ]);
        }
    }

    private function revalidateReaders(): void
    {
        // Body/excerpt updates affect existing lists and the independent llms-full artifact.
        // This invalidates derived readers without releasing any CMS discovery gate.
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
            throw new RuntimeException('Workplace Article reader cache acknowledgement failed.');
        }
    }
}
