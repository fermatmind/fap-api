<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Events\PublicAuthorityChanged;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Models\Article;
use App\Services\Cms\ArticleSeoService;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\BufferedOutput;

/** @review-surface article */
final class ArticleControlledPublicationWorkspace
{
    public function prepareSeoRelease(Article $selected): array
    {
        $article = $this->seoReleaseArticle($selected);

        return [
            'target_hash' => ArticleSourceTargetSnapshot::publicationHash($article),
            'preflight' => json_encode($this->seoReleasePlans($article, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'public_page_reviewed' => false,
        ];
    }

    /** Release one standalone, already-published article through both native commands. */
    public function releaseSeo(Article $selected, array $data): array
    {
        $article = $this->seoReleaseArticle($selected);
        if (($data['public_page_reviewed'] ?? false) !== true) {
            $this->reject('public_page_review_required');
        }
        if (! ContentReleaseFollowUp::articleCacheConfigured()) {
            $this->reject('article_cache_configuration_required');
        }
        $committed = false;
        try {
            Event::defer(function () use ($article, $data, &$committed): void {
                DB::transaction(function () use ($article, $data): void {
                    try {
                        ArticleSourceTargetSnapshot::assertPublicationHash($article, (string) ($data['target_hash'] ?? ''), true);
                    } catch (\RuntimeException $exception) {
                        if ($exception->getMessage() !== 'article_publication_state_changed') {
                            throw $exception;
                        }
                        $this->reject('article_publication_state_changed');
                    }
                    $locked = $this->seoReleaseArticle($article, true);
                    foreach ($this->seoReleasePlans($locked, false) as $plan) {
                        if (! ($plan['ok'] ?? false)) {
                            $this->reject(implode(', ', array_column((array) ($plan['errors'] ?? []), 'code')) ?: 'native_seo_release_rejected');
                        }
                    }
                    app(\App\Services\Audit\AuditLogger::class)->log(\Illuminate\Http\Request::create('/ops/articles/native-seo-release', 'POST'), 'article_native_seo_released', 'article', (string) $locked->id,
                        ['published_revision_id' => $locked->published_revision_id, 'before_sha256' => $data['target_hash'],
                            'schema' => ['Article', 'BreadcrumbList'], 'faq_schema' => 'hold',
                            'hreflang_policy' => 'no_verified_reciprocal_counterpart', 'search_submission' => false],
                        reason: 'native_article_seo_release', result: 'success');
                });
                $committed = true;
            }, [PublicAuthorityChanged::class]);
        } catch (\Throwable $exception) {
            if (! $committed) {
                throw $exception;
            }

            return ['ok' => false, 'seo_release' => 'committed', 'cache_closeout' => 'failed'];
        }
        try {
            app(\App\Services\SEO\SeoDiscoverabilityCacheInvalidator::class)->flushArticleDiscoverabilityCaches();
            ContentReleaseFollowUp::dispatchArticlePathsStrict($article->fresh(['publishedRevision', 'seoMeta']), 'article_native_seo_released', includeDiscoverability: true);

            return ['ok' => true, 'seo_release' => 'committed', 'cache_closeout' => 'success'];
        } catch (\Throwable) {
            return ['ok' => false, 'seo_release' => 'committed', 'cache_closeout' => 'failed'];
        }
    }

    private function seoReleaseArticle(Article $selected, bool $lock = false): Article
    {
        $article = $this->selected($selected);
        if ($article->status !== 'published' || ! $article->is_public || ! $article->is_indexable
            || $article->published_revision_id === null || $article->working_revision_id !== $article->published_revision_id
            || ($article->sitemap_eligible && $article->llms_eligible)) {
            $this->reject('article_seo_release_state_invalid');
        }
        $siblings = Article::withoutGlobalScopes()->where('org_id', 0)
            ->where('translation_group_id', $article->translation_group_id)->whereKeyNot($article->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id']);
        if ($siblings->isNotEmpty()) {
            $this->reject('article_seo_single_locale_required');
        }

        return $article;
    }

    private function seoReleasePlans(Article $article, bool $dryRun): array
    {
        $gate = ['--article-ids' => (string) $article->id, '--translation-group-id' => (string) $article->translation_group_id,
            '--expected-slugs' => (string) $article->slug, '--enable-article-schema' => true,
            '--enable-breadcrumb-schema' => true, '--hold-faq-schema' => true,
            '--no-hreflang-policy' => true, '--hreflang-policy-reason' => 'no_verified_reciprocal_counterpart', '--json' => true];
        $feeds = ['--article-id' => (string) $article->id, '--expected-slug' => (string) $article->slug, '--json' => true];
        if ($dryRun) {
            $gate['--dry-run'] = $feeds['--dry-run'] = true;
        } else {
            $gate += ['--execute' => true, '--no-publish' => true, '--no-search' => true,
                '--no-sitemap-llms-change' => true, '--no-content-change' => true, '--no-revalidation' => true];
            $feeds += ['--execute' => true, '--no-content-change' => true, '--no-publish' => true,
                '--no-search' => true, '--no-schema-hreflang' => true, '--no-revalidation' => true,
                '--confirm' => "I explicitly approve articles:discoverability-release execute for article id {$article->id} slug {$article->slug} after dry-run passes."];
        }
        $plans = [];
        foreach (['articles:seo-gate-rollout' => $gate, 'articles:discoverability-release' => $feeds] as $command => $options) {
            $output = new BufferedOutput;
            Artisan::call($command, $options, $output);
            $plan = json_decode(trim($output->fetch()), true, 64, JSON_THROW_ON_ERROR);
            $plans[] = $plan;
            if (! ($plan['ok'] ?? false)) {
                break;
            }
        }

        return $plans;
    }

    public function prepareCanonical(Article $selected): array
    {
        $article = $this->canonicalArticle($selected);

        return ['target_hash' => ArticleSourceTargetSnapshot::publicationHash($article),
            'canonical_url' => app(ArticleSeoService::class)->buildCanonicalUrl((string) $article->slug, (string) $article->locale), 'canonical_reviewed' => false];
    }

    /** Fill a missing explicit canonical without saving a working revision. */
    public function fillCanonical(Article $selected, array $data): array
    {
        $article = $this->canonicalArticle($selected);
        $canonical = app(ArticleSeoService::class)->buildCanonicalUrl((string) $article->slug, (string) $article->locale);
        if (($data['canonical_reviewed'] ?? false) !== true || ($data['canonical_url'] ?? null) !== $canonical) {
            $this->reject('canonical_review_required');
        }
        if (! ContentReleaseFollowUp::articleCacheConfigured()) {
            $this->reject('article_cache_configuration_required');
        }
        DB::transaction(function () use ($article, $data, $canonical): void {
            try {
                ArticleSourceTargetSnapshot::assertPublicationHash($article, (string) ($data['target_hash'] ?? ''), true);
            } catch (\RuntimeException $exception) {
                $this->reject($exception->getMessage());
            }
            $locked = $this->canonicalArticle($article);
            app(\App\Filament\Ops\Resources\ArticleResource\Support\ArticleSeoMetaWorkspace::class)
                ->save($locked, ['canonical_url' => $canonical]);
            app(\App\Services\Audit\AuditLogger::class)->log(request(), 'article_missing_canonical_filled', 'article', (string) $locked->id,
                ['published_revision_id' => $locked->published_revision_id, 'before_sha256' => $data['target_hash'], 'canonical_url' => $canonical],
                reason: 'native_article_missing_canonical', result: 'success');
        });
        try {
            ContentReleaseFollowUp::dispatchArticlePathsStrict($article->fresh(['publishedRevision', 'seoMeta']), 'article_missing_canonical_filled');

            return ['ok' => true, 'metadata' => 'committed', 'cache_closeout' => 'success'];
        } catch (\Throwable) {
            return ['ok' => false, 'metadata' => 'committed', 'cache_closeout' => 'failed'];
        }
    }

    private function canonicalArticle(Article $selected): Article
    {
        $article = $this->selected($selected);
        if ($article->status !== 'published' || ! $article->is_public || $article->published_revision_id === null
            || $article->working_revision_id !== $article->published_revision_id || $article->seoMeta === null
            || filled($article->seoMeta->canonical_url)
            || (bool) $article->seoMeta->is_indexable !== (bool) $article->is_indexable
            || blank(app(ArticleSeoService::class)->buildCanonicalUrl((string) $article->slug, (string) $article->locale))) {
            $this->reject('missing_canonical_state_required');
        }

        return $article;
    }

    public function prepareCoverAlt(Article $selected): array
    {
        $article = $this->selected($selected);
        abort_unless($article->is_public && $article->published_revision_id !== null, 422);

        return ['target_hash' => ArticleSourceTargetSnapshot::publicationHash($article), 'cover_image_alt' => $article->cover_image_alt];
    }

    /** Use the existing media-only updater; retain body, pointers and source identity. */
    public function updateCoverAlt(Article $selected, array $data): array
    {
        $article = $this->selected($selected);
        $alt = trim((string) ($data['cover_image_alt'] ?? ''));
        if ($alt === '' || mb_strlen($alt) > 255) {
            throw ValidationException::withMessages(['cover_image_alt' => __('validation.between.string', ['attribute' => 'alt', 'min' => 1, 'max' => 255])]);
        }
        if (! ContentReleaseFollowUp::articleCacheConfigured()) {
            throw ValidationException::withMessages(['cover_image_alt' => 'article_cache_configuration_required']);
        }
        DB::transaction(function () use ($article, $data, $alt): void {
            try {
                ArticleSourceTargetSnapshot::assertPublicationHash($article, (string) ($data['target_hash'] ?? ''), true);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['cover_image_alt' => $exception->getMessage()]);
            }
            $record = $article->fresh(['seoMeta']);
            app(\App\Services\Cms\ArticleImageMetadataUpdater::class)->updateCoverAlt($record, $alt);
            app(\App\Services\Audit\AuditLogger::class)->log(request(), 'article_cover_alt_updated', 'article', (string) $record->id,
                ['published_revision_id' => $record->published_revision_id, 'before_sha256' => hash('sha256', (string) $record->cover_image_alt), 'after_sha256' => hash('sha256', $alt)],
                reason: 'native_article_media_metadata', result: 'success');
        });
        try {
            ContentReleaseFollowUp::dispatchArticlePathsStrict($article->fresh(['publishedRevision', 'seoMeta']), 'article_cover_alt_updated');

            return ['ok' => true, 'metadata' => 'committed', 'cache_closeout' => 'success'];
        } catch (\Throwable) {
            return ['ok' => false, 'metadata' => 'committed', 'cache_closeout' => 'failed'];
        }
    }

    public function prepare(Article $selected): array
    {
        $article = $this->selected($selected);
        $hash = ArticleSourceTargetSnapshot::publicationHash($article);
        $plan = $this->run($article, $hash, true, false, true);

        return [
            'target_hash' => $hash,
            'preflight' => json_encode([
                'article_id' => (int) $article->id,
                'locale' => $article->locale,
                'working_revision_id' => $article->working_revision_id,
                'published_revision_id' => $article->published_revision_id,
                'preview_url' => ArticleWorkspace::previewUrl($article, (int) $article->working_revision_id),
                'ok' => $plan['ok'],
                'errors' => $plan['errors'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'preview_approved' => false,
            'acknowledge_claims' => false,
            'make_indexable' => false,
        ];
    }

    public function publish(Article $selected, array $data): array
    {
        $article = $this->selected($selected);
        if (($data['preview_approved'] ?? false) !== true) {
            $this->reject('preview_approval_required');
        }
        $hash = (string) ($data['target_hash'] ?? '');
        try {
            ArticleSourceTargetSnapshot::assertPublicationHash($article, $hash);
        } catch (\RuntimeException) {
            $this->reject('article_publication_state_changed');
        }
        $summary = $this->run($article, $hash, (bool) ($data['make_indexable'] ?? false), (bool) ($data['acknowledge_claims'] ?? false), false);
        // A committed promotion with failed cache closeout must never be offered
        // as an uncommitted retry. The native command owns this distinction.
        if (! ($summary['ok'] ?? false) && ($summary['publication'] ?? '') !== 'committed') {
            $codes = array_column((array) ($summary['errors'] ?? []), 'code');
            $this->reject(implode(', ', $codes) ?: 'native_publication_rejected');
        }

        return $summary;
    }

    private function selected(Article $selected): Article
    {
        abort_unless(ContentAccess::canRelease(), 403);
        $guard = (string) config('admin.guard', 'admin');
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) auth($guard)->id(), 403);
        $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())->firstOrFail();
        if ((int) $article->org_id !== 0 || ! in_array($article->locale, ['en', 'zh-CN'], true)
            || $article->deleted_at !== null || $article->working_revision_id === null) {
            $this->reject('selected_article_not_supported');
        }
        $article->load(['workingRevision', 'seoMeta']);

        return $article;
    }

    private function run(Article $article, string $hash, bool $makeIndexable, bool $acknowledgeClaims, bool $dryRun): array
    {
        $options = ['--expected-article-state-sha256' => $hash, '--json' => true];
        if ($dryRun) {
            $options['--dry-run'] = true;
        }
        if ($article->published_revision_id === null) {
            $command = 'articles:publish-controlled';
            $options['--article'] = [(string) $article->id];
            $options['--revalidate-after-commit'] = true;
            if ($makeIndexable) {
                $options['--make-indexable'] = true;
            }
            if ($acknowledgeClaims) {
                $options['--ack-claim-warning'] = [(string) $article->id];
            }
            if (! $dryRun) {
                $options['--confirm'] = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";
            }
        } else {
            $command = 'articles:promote-existing-working-revision';
            $options += [
                '--article-id' => (string) $article->id,
                '--working-revision-id' => (string) $article->working_revision_id,
                '--current-published-revision-id' => (string) $article->published_revision_id,
                '--translation-group-id' => (string) $article->translation_group_id,
                '--expected-slug' => (string) $article->slug,
                '--expected-canonical' => (string) (filled($article->seoMeta?->canonical_url) ? $article->seoMeta->canonical_url
                    : app(ArticleSeoService::class)->buildCanonicalUrl((string) $article->slug, (string) $article->locale)),
                '--revalidate-after-commit' => true,
            ];
            if ($acknowledgeClaims) {
                $options['--ack-claim-warning'] = (string) $article->id;
            }
            if (! $dryRun) {
                $options += [
                    '--execute' => true, '--preview-approved' => true,
                    '--schema-hold' => true, '--hreflang-hold' => true,
                    '--search-hold' => true, '--no-sitemap' => true, '--no-llms' => true,
                    '--confirm' => "I explicitly approve Codex to promote article id {$article->id} working revision {$article->working_revision_id} after preflight passes.",
                ];
            }
        }
        $output = new BufferedOutput;
        Artisan::call($command, $options, $output);

        return json_decode(trim($output->fetch()), true, 64, JSON_THROW_ON_ERROR);
    }

    private function reject(string $code): never
    {
        throw ValidationException::withMessages(['preview_approved' => __('ops.resources.articles.controlled_publish.rejected', ['code' => $code])]);
    }
}
