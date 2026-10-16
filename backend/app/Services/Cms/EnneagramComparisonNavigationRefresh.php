<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\EditorialReview;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Navigation in the existing Article164/224 pair; no new publication eligibility.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface editorial_review
 */
final class EnneagramComparisonNavigationRefresh
{
    public const PACKAGE = 'content_assets/enneagram_public/articles/20261016-comparison-navigation-v1/package.json';

    public const PACKAGE_SHA256 = '44982f64334b34c85e12155c6ca68ea040b16bca5df26eda8134e99e387a209f';

    private const SLUG = 'big-five-vs-enneagram-traits-and-motivation-stories';

    private const IDS = ['zh-CN' => 164, 'en' => 224];

    public function __construct(
        private readonly ArticleTranslationRevisionWorkspace $workspace,
        private readonly ArticleTranslationWorkflowService $workflow,
        private readonly ArticlePublishService $publisher,
        private readonly CmsEditorialReviewAttestationService $reviews,
        private readonly PublicContent53FrontendRevalidator $revalidator,
    ) {}

    public function publish(bool $dryRun = false): array
    {
        $bytes = file_get_contents(base_path(self::PACKAGE));
        if (! is_string($bytes) || ! hash_equals(self::PACKAGE_SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('Comparison navigation package drift.');
        }
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (($package['schema'] ?? null) !== 'enneagram.comparison_navigation.v1') {
            throw new RuntimeException('Comparison navigation package scope drift.');
        }
        $package['_environment'] = app()->environment('staging') ? 'staging' : 'production';
        $package['candidates'] = $package['candidates'][$package['_environment']];
        $package['baselines'] = $package['baselines'][$package['_environment']];
        if (array_keys($package['candidates']) !== array_keys(self::IDS)) {
            throw new RuntimeException('Comparison navigation package locale drift.');
        }
        foreach ($package['candidates'] as $candidate) {
            if (! hash_equals($candidate['content_sha256'], hash('sha256', $candidate['content_md']))) {
                throw new RuntimeException('Comparison navigation body digest drift.');
            }
        }

        $result = DB::transaction(function () use ($package, $dryRun): array {
            $articles = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)
                ->whereIn('id', array_values(self::IDS))->orderBy('id')->lockForUpdate()->get()->keyBy('locale');
            if ($articles->isEmpty() && app()->environment(['local', 'testing'])) {
                return ['status' => 'absent', 'changed' => 0];
            }
            $initializeEnglish = $package['_environment'] === 'staging' && $articles->count() === 1
                && (int) $articles->get('zh-CN')?->id === 164;
            if ($initializeEnglish) {
                $this->assertStagingSource($package);
            }
            $ready = $articles->count() === 2 || $initializeEnglish;
            foreach (self::IDS as $locale => $id) {
                $article = $articles->get($locale);
                if (! $article) {
                    if ($initializeEnglish && $locale === 'en') {
                        continue;
                    }
                    $ready = false;

                    continue;
                }
                $article->setRelation('publishedRevision', ArticleTranslationRevision::withoutGlobalScopes()
                    ->whereKey($article->published_revision_id)->lockForUpdate()->first());
                $article->setRelation('workingRevision', ArticleTranslationRevision::withoutGlobalScopes()
                    ->whereKey($article->working_revision_id)->lockForUpdate()->first());
                $article->setRelation('seoMeta', ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $id)
                    ->where('org_id', 0)->where('locale', $locale)->lockForUpdate()->first());
                $readable = Article::withoutGlobalScopes()->publiclyReadable()->whereKey($id)->exists();
                $ready = $ready && $readable && ! $article->trashed()
                    && $article->slug === self::SLUG && (int) $article->id === $id
                    && ! filled($article->lifecycle_note) && $article->seoMeta && $article->workingRevision
                    && (int) $article->working_revision_id === (int) $article->published_revision_id
                    && $article->content_md === $article->publishedRevision?->content_md;
            }
            if (! $ready) {
                throw new RuntimeException('Comparison navigation public pair or working pointer drift.');
            }

            $editorial = [];
            foreach ($articles as $locale => $article) {
                $id = self::IDS[$locale];
                $editorial[$locale] = EditorialReview::withoutGlobalScopes()->where('org_id', 0)
                    ->where('content_type', 'article')->where('content_id', $id)->lockForUpdate()->first();
                if ((! $initializeEnglish && ! $editorial[$locale])
                    || ($editorial[$locale] && $editorial[$locale]->workflow_state !== 'approved')
                    || (! $article->publishedRevision->approved_at && ! $article->publishedRevision->reviewed_at)) {
                    throw new RuntimeException('Comparison navigation existing editorial hold.');
                }
            }
            $publicSeo = $this->publicSeoTargets($package);
            $repairStagingSeo = false;
            $replay = $articles->filter(fn (Article $a): bool => $a->publishedRevision->authority_package_sha256 === self::PACKAGE_SHA256)->count();
            if ($replay === 2) {
                $this->assertPublished($articles, $package);

                $repairStagingSeo = $this->stagingSeoRepairRequired($articles, $package, $publicSeo);
                if (! $repairStagingSeo) {
                    $this->assertPublicSeo($articles, $publicSeo);

                    return ['status' => 'unchanged', 'changed' => 0];
                }
            }
            if ($replay !== 0 && ! $repairStagingSeo) {
                throw new RuntimeException('Comparison navigation partial pair drift.');
            }
            $before = [];
            foreach ($articles as $locale => $article) {
                $id = self::IDS[$locale];
                $expected = $package['baselines'][$locale];
                if ($repairStagingSeo) {
                    // Only the attested, exact old staging projection may
                    // re-enter native publication after its body was delivered.
                    $expected['content_md'] = $package['candidates'][$locale]['content_md'];
                    $expected['content_html'] = null;
                    $expected['reading_minutes'] = $package['candidates'][$locale]['reading_minutes'];
                    $expected['published_revision_id'] = (int) $article->published_revision_id;
                }
                if (! Hash::sameValue($this->values($article, array_keys($expected)), $expected)) {
                    throw new RuntimeException('Comparison navigation exact baseline drift: '.$locale);
                }
                $before[$locale] = ['article' => $article->getAttributes(), 'seo' => $article->seoMeta->getAttributes()];
            }
            foreach ($package['candidates'] as $locale => $candidate) {
                if (! app(ArticleEditorialCompletenessGate::class)->inspect($locale,
                    $candidate['content_md'], ['content_md' => $candidate['content_md']])['ok']) {
                    throw new RuntimeException('Comparison navigation completeness failed.');
                }
            }
            $owner = (int) config('review_governance.solo_owner_admin_user_id');
            if (! $this->reviews->isConfiguredSoloOwner($owner)) {
                throw new RuntimeException('Comparison navigation native review policy unavailable.');
            }
            $this->revalidator->assertConfigured();
            if ($dryRun) {
                return ['status' => 'ready', 'changed' => 0];
            }
            foreach (self::IDS as $locale => $id) {
                if ($initializeEnglish && $locale === 'en') {
                    $articles[$locale] = $this->initializeEnglishDraft($package, $articles['zh-CN']->fresh());
                }
                $article = $articles[$locale]->fresh(['seoMeta']);
                $candidate = $package['candidates'][$locale];
                $previous = (int) $article->published_revision_id;
                if ($locale === 'zh-CN' && ! $article->isSourceArticle()) {
                    // The verified older staging source has approved rather
                    // than source status, with both source pointers null.
                    if (! $initializeEnglish || $article->source_locale !== 'zh-CN' || $article->source_article_id !== null
                        || $article->translated_from_article_id !== null
                        || ! in_array($article->translation_status, ['approved', 'published'], true)) {
                        throw new RuntimeException('Comparison navigation Chinese source linkage drift.');
                    }
                    $article->forceFill(['translation_status' => Article::TRANSLATION_STATUS_SOURCE])->saveQuietly();
                }
                $revision = $this->workspace->saveWorkingRevision($article, [
                    'title' => $article->title, 'excerpt' => $article->excerpt,
                    'content_md' => $candidate['content_md'], 'seo_title' => $publicSeo[$locale]['seo_title'],
                    'seo_description' => $publicSeo[$locale]['seo_description'],
                    'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                ], $owner);
                $sourceHash = $locale === 'zh-CN' ? $revision->source_version_hash
                    : $articles['zh-CN']->fresh()->source_version_hash;
                $revision->forceFill(['seo_title' => $publicSeo[$locale]['seo_title'],
                    'seo_description' => $publicSeo[$locale]['seo_description'],
                    'source_version_hash' => $sourceHash, 'translated_from_version_hash' => $sourceHash,
                    'authority_source_package' => self::PACKAGE, 'authority_package_sha256' => self::PACKAGE_SHA256,
                    'authority_source_hash' => $candidate['content_sha256'],
                    'authority_metadata_json' => ['delivery' => 'automatic_trunk_migration',
                        'reading_time_rule' => 'existing_web_reader_weighted_words_220',
                        'review_required_by' => $package['review_required_by']]])->saveQuietly();
                $article = $article->fresh();
                $article->forceFill(['reading_minutes' => $candidate['reading_minutes'],
                    ...($locale === 'en' ? ['translated_from_version_hash' => $sourceHash] : [])])->saveQuietly();
                $this->workflow->approveEditorialWorkingRevision($article->fresh(), $owner, bindAttestation: false);
                $article = $previous > 0
                    ? $this->publisher->promoteExistingWorkingRevision($id, (int) $revision->id, $previous,
                        'enneagram_comparison_navigation_refresh', dispatchFollowUp: false)
                    : $this->publisher->publishArticle($id, 'enneagram_comparison_staging_initialization', dispatchFollowUp: false);
                if ($locale === 'en') {
                    $article->forceFill(['translation_status' => $before[$locale]['article']['translation_status']
                        ?? Article::TRANSLATION_STATUS_PUBLISHED])->saveQuietly();
                }
                $article->refresh()->load(['publishedRevision', 'seoMeta']);
                if (isset($before[$locale])) {
                    // Revision-backed public SEO and canonical DB SEO may
                    // differ in legacy rows. Preserve both original authorities.
                    $article->seoMeta->forceFill([
                        'seo_title' => $before[$locale]['seo']['seo_title'],
                        'seo_description' => $before[$locale]['seo']['seo_description'],
                        'og_title' => $before[$locale]['seo']['og_title'],
                        'og_description' => $before[$locale]['seo']['og_description'],
                    ])->saveQuietly();
                }
                if (! ($editorial[$locale] ?? null)) {
                    if (! $initializeEnglish) {
                        throw new RuntimeException('Comparison navigation existing review disappeared.');
                    }
                    $editorial[$locale] = new EditorialReview(['org_id' => 0, 'content_type' => 'article', 'content_id' => $id]);
                    $editorial[$locale]->forceFill(['workflow_state' => 'approved', 'reviewed_by_admin_user_id' => null,
                        'reviewed_at' => now(), 'last_transition_at' => now(),
                        'note' => 'Reviewed exact comparison navigation package: '.self::PACKAGE_SHA256])->save();
                } else {
                    // The new revision has just passed native approval. Renew
                    // its existing approved review's timestamps so the native
                    // English preflight does not treat the new Chinese body as
                    // newer than its review. Keep state, assignments and note.
                    $editorial[$locale]->forceFill(['reviewed_at' => now(), 'last_transition_at' => now()])->saveQuietly();
                }
                $this->reviews->bindOrCreateApproved(null, 'cms_article_review',
                    'enneagram-comparison:'.$id.':revision:'.$article->published_revision_id,
                    [['surface_id' => 'article', 'record' => $article],
                        ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
                        ['surface_id' => 'editorial_review', 'record' => $editorial[$locale]]], $owner, self::PACKAGE_SHA256);
                $articles[$locale] = $article;
            }
            $this->assertPublished($articles, $package);
            $this->assertPublicSeo($articles, $publicSeo);
            foreach ($articles as $locale => $article) {
                if ($initializeEnglish && $locale === 'en') {
                    app(ArticleMaterialDecisionService::class)->recordPublished($article, $article->publishedRevision, now());

                    continue;
                }
                $after = ['article' => $article->getAttributes(), 'seo' => $article->seoMeta->getAttributes()];
                foreach (['content_md', 'content_html', 'reading_minutes', 'source_version_hash', 'translated_from_version_hash',
                    'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                    unset($before[$locale]['article'][$field], $after['article'][$field]);
                }
                if ($initializeEnglish && $locale === 'zh-CN') {
                    unset($before[$locale]['article']['translation_status'], $after['article']['translation_status']);
                }
                unset($before[$locale]['seo']['updated_at'], $after['seo']['updated_at']);
                if (! Hash::sameValue($before[$locale], $after)) {
                    $fields = [];
                    foreach ($before[$locale] as $surface => $values) {
                        foreach ($values as $field => $value) {
                            if (! Hash::sameValue($value, $after[$surface][$field] ?? null)) {
                                $fields[] = $surface.':'.$field;
                            }
                        }
                    }
                    throw new RuntimeException('Comparison navigation non-target field drift: '.implode(',', $fields));
                }
                app(ArticleMaterialDecisionService::class)->recordPublished($article, $article->publishedRevision, now());
            }

            return ['status' => 'published', 'changed' => 2];
        });
        if (! $dryRun && in_array($result['status'], ['published', 'unchanged'], true)) {
            // Post-commit failure can be closed out idempotently without a new
            // revision, changing eligibility, or submitting a search URL.
            app(SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches(false);
            $this->revalidator->revalidate(array_map(static fn (string $locale): array => [
                'kind' => 'article', 'identity' => ['org_id' => 0, 'locale' => $locale, 'slug' => self::SLUG],
            ], array_keys(self::IDS)));
            $this->revalidateLists();
        }

        return $result;
    }

    private function assertPublished($articles, array $package): void
    {
        foreach (self::IDS as $locale => $id) {
            $article = $articles[$locale]->fresh(['publishedRevision', 'seoMeta']);
            $candidate = $package['candidates'][$locale];
            $expected = $package['baselines'][$locale];
            foreach (['content_md', 'content_html', 'reading_minutes', 'published_revision_id'] as $field) {
                unset($expected[$field]);
            }
            if (! Hash::sameValue($this->values($article, array_keys($expected)), $expected)
                || $article->content_md !== $candidate['content_md'] || $article->content_html !== null
                || $article->reading_minutes !== $candidate['reading_minutes']
                || $article->publishedRevision->content_md !== $candidate['content_md']
                || $article->publishedRevision->authority_package_sha256 !== self::PACKAGE_SHA256) {
                throw new RuntimeException('Comparison navigation published pair readback failed.');
            }
            $this->reviews->assertApprovedEvidence([
                ['surface_id' => 'article', 'record' => $article],
                ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
            ]);
        }
    }

    private function publicSeoTargets(array $package): array
    {
        if ($package['_environment'] === 'staging') {
            return ['zh-CN' => array_intersect_key($package['staging_source_snapshot']['revisions'][0],
                array_flip(['seo_title', 'seo_description'])),
                'en' => array_intersect_key($package['staging_initial_english']['seo'],
                    array_flip(['seo_title', 'seo_description']))];
        }
        $targets = [];
        foreach (self::IDS as $locale => $id) {
            $baseline = $package['baselines'][$locale];
            $revision = ArticleTranslationRevision::withoutGlobalScopes()
                ->whereKey($baseline['published_revision_id'])->lockForUpdate()->first();
            if (! $revision || (int) $revision->org_id !== 0 || (int) $revision->article_id !== $id
                || $revision->locale !== $locale || $revision->content_md !== $baseline['content_md']) {
                throw new RuntimeException('Comparison navigation original public SEO authority drift.');
            }
            $targets[$locale] = $this->values($revision, ['seo_title', 'seo_description']);
        }

        return $targets;
    }

    private function stagingSeoRepairRequired($articles, array $package, array $publicSeo): bool
    {
        if ($package['_environment'] !== 'staging') {
            return false;
        }
        $fields = ['seo_title', 'seo_description', 'og_title', 'og_description'];
        $originalDb = $package['staging_source_snapshot']['seo'][0];
        foreach (['zh-CN' => $originalDb, 'en' => $package['staging_initial_english']['seo']] as $locale => $expected) {
            if (! Hash::sameValue($this->values($articles[$locale]->seoMeta, $fields),
                array_intersect_key($expected, array_flip($fields)))) {
                throw new RuntimeException('Comparison navigation staging canonical SEO drift.');
            }
        }
        if (! Hash::sameValue($this->values($articles['en']->publishedRevision, ['seo_title', 'seo_description']), $publicSeo['en'])) {
            throw new RuntimeException('Comparison navigation staging English public SEO drift.');
        }
        $current = $this->values($articles['zh-CN']->publishedRevision, ['seo_title', 'seo_description']);
        if (Hash::sameValue($current, $publicSeo['zh-CN'])) {
            return false;
        }
        if (! Hash::sameValue($current, array_intersect_key($originalDb, array_flip(['seo_title', 'seo_description'])))) {
            throw new RuntimeException('Comparison navigation staging unknown public SEO drift.');
        }

        foreach (['zh-CN' => 715, 'en' => 716] as $locale => $revisionId) {
            if ((int) $articles[$locale]->published_revision_id !== $revisionId) {
                throw new RuntimeException('Comparison navigation staging repair revision drift.');
            }
        }

        return true;
    }

    private function assertPublicSeo($articles, array $publicSeo): void
    {
        foreach ($articles as $locale => $article) {
            if (! Hash::sameValue($this->values($article->fresh(['publishedRevision'])->publishedRevision,
                ['seo_title', 'seo_description']), $publicSeo[$locale])) {
                throw new RuntimeException('Comparison navigation public SEO projection drift.');
            }
        }
    }

    private function values($model, array $keys): array
    {
        return json_decode(json_encode($model->only($keys), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    }

    private function assertStagingSource(array $package): void
    {
        if (! app()->environment('staging') || Article::withoutGlobalScopes()->withTrashed()->whereKey(224)->exists()
            || Article::withoutGlobalScopes()->withTrashed()
                ->where('slug', self::SLUG)->where('locale', 'en')->exists()) {
            throw new RuntimeException('Comparison navigation staging English collision.');
        }
        $snapshot = $package['staging_source_snapshot'];
        foreach (['articles' => 'id', 'article_translation_revisions' => 'article_id',
            'article_seo_meta' => 'article_id', 'editorial_reviews' => 'content_id'] as $table => $key) {
            $query = DB::table($table)->where('org_id', 0)->whereIn($key, [164, 224]);
            if ($table === 'editorial_reviews') {
                $query->where('content_type', 'article');
            }
            $rows = json_decode(json_encode($query->orderBy('id')->lockForUpdate()->get(), JSON_THROW_ON_ERROR), true);
            $sourceKey = ['article_translation_revisions' => 'revisions', 'article_seo_meta' => 'seo'][$table] ?? $table;
            if (! Hash::sameValue($rows, $snapshot[$sourceKey])) {
                throw new RuntimeException('Comparison navigation exact staging source drift.');
            }
        }
    }

    private function initializeEnglishDraft(array $package, Article $source): Article
    {
        if (! app()->environment('staging') || ! $source->isSourceArticle()
            || Article::withoutGlobalScopes()->withTrashed()->whereKey(224)->exists()) {
            throw new RuntimeException('Comparison navigation staging draft scope drift.');
        }
        $fields = $package['staging_initial_english'];
        $seo = $fields['seo'];
        unset($fields['seo']);
        $article = new Article;
        $article->forceFill($fields + ['translated_from_article_id' => 164,
            'translated_from_version_hash' => $source->source_version_hash,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'content_md' => $package['candidates']['en']['content_md'], 'content_html' => null,
            'status' => 'draft', 'is_public' => false, 'lifecycle_state' => Article::LIFECYCLE_ACTIVE])->saveQuietly();
        $meta = new ArticleSeoMeta;
        $meta->forceFill($seo + ['article_id' => 224])->saveQuietly();

        return $article;
    }

    private function revalidateLists(): void
    {
        // Reuse the existing Article list/llms cache acknowledgement; eligibility
        // remains owned by CMS, with no search submission or new public URL.
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
        if (! $response->successful() || ($receipt['ok'] ?? null) !== true
            || ($receipt['revalidated_paths'] ?? null) !== [...$paths, '/zh/articles', '/en/articles',
                '/zh/articles/'.self::SLUG, '/en/articles/'.self::SLUG]
            || ($receipt['rejected_paths'] ?? null) !== []
            || ($receipt['invalidated_tags'] ?? null) !== ['article-detail:en:'.self::SLUG, 'article-seo:en:'.self::SLUG]) {
            throw new RuntimeException('Comparison navigation reader cache acknowledgement failed.');
        }
    }
}
