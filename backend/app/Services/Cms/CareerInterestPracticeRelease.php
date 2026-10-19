<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\EditorialReview;
use App\Services\Audit\AuditLogger;
use App\Services\ContentPromotion\PublicContent53FrontendRevalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Body-only, exact-package update via the native CMS revision and publication chain.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface editorial_review
 */
final class CareerInterestPracticeRelease
{
    public const ROOT = 'content_assets/career_interest_practice/20261019';

    public const SHA256 = '4359c2efc6ccc89a0a7ccfcc2e87dccd3a074f9e1267a65b96c733764b9694c9';

    public function publish(bool $dryRun = false): array
    {
        $bytes = file_get_contents(base_path(self::ROOT.'/package.json'));
        if (! hash_equals(self::SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('Career practice package drift.');
        }
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $bodies = [];
        foreach (['guide.md', 'article.md'] as $name) {
            $bodies[$name] = file_get_contents(base_path(self::ROOT.'/'.$name));
            if (! hash_equals($package['files'][$name], hash('sha256', $bodies[$name]))) {
                throw new RuntimeException('Career practice body digest drift.');
            }
        }
        $result = DB::transaction(function () use ($package, $bodies, $dryRun): array {
            $article = Article::withoutGlobalScopes()->withTrashed()->where($package['scope']['article'])->lockForUpdate()->first();
            $guide = CareerGuide::withoutGlobalScopes()->where($package['scope']['guide'])->lockForUpdate()->first();
            if (! $article && ! $guide && app()->environment(['testing', 'local'])) {
                return ['status' => 'absent', 'changed' => 0];
            }
            if (! $article || ! $guide || $article->trashed() || $article->source_article_id !== null || $article->translated_from_article_id !== null
                || $article->source_locale !== $article->locale
                || (! $article->isSourceArticle() && ! (app()->environment('staging') && $article->translation_status === 'published'))
                || $article->lifecycle_state !== Article::LIFECYCLE_ACTIVE || filled($article->lifecycle_note)
                || $article->status !== 'published' || ! $article->is_public
                || $guide->status !== 'published' || ! $guide->is_public || filled($guide->body_html)
                || (int) $article->working_revision_id !== (int) $article->published_revision_id) {
                throw new RuntimeException('Career practice identity, hold or working draft drift.');
            }
            $revision = ArticleTranslationRevision::withoutGlobalScopes()->whereKey($article->published_revision_id)->lockForUpdate()->first();
            $review = EditorialReview::withoutGlobalScopes()->where('org_id', 0)->where('content_type', 'article')
                ->where('content_id', $article->id)->lockForUpdate()->first();
            if (! $revision || $revision->article_id !== $article->id || $revision->revision_status !== 'published'
                || $revision->content_md !== $article->content_md
                || ($review && $review->workflow_state !== 'approved')) {
                throw new RuntimeException('Career practice published revision or editorial hold drift.');
            }
            if ($article->content_md === $bodies['article.md'] && $guide->body_md === $bodies['guide.md']
                && $revision->authority_package_sha256 === self::SHA256) {
                return ['status' => 'unchanged', 'changed' => 0];
            }
            $baseline = $package['baselines'][app()->environment('staging') ? 'staging' : 'prod'];
            if ((int) $revision->id !== $baseline['article_revision_id']
                || ! hash_equals($baseline['article_body_sha256'], hash('sha256', $article->content_md))
                || ! hash_equals($baseline['guide_body_sha256'], hash('sha256', $guide->body_md))) {
                throw new RuntimeException('Career practice baseline drift.');
            }
            $owner = (int) config('review_governance.solo_owner_admin_user_id');
            $reviews = app(CmsEditorialReviewAttestationService::class);
            if (! $reviews->isConfiguredSoloOwner($owner)
                || ! app(ArticleEditorialCompletenessGate::class)->inspect('zh-CN', $bodies['article.md'], ['body' => $bodies['article.md']])['ok']) {
                throw new RuntimeException('Career practice review policy or completeness failed.');
            }
            app(PublicContent53FrontendRevalidator::class)->assertConfigured();
            if ($dryRun) {
                return ['status' => 'ready', 'changed' => 0];
            }
            // Staging's locked legacy source predates source-status convergence.
            if (! $article->isSourceArticle()) {
                $article->forceFill(['translation_status' => Article::TRANSLATION_STATUS_SOURCE])->saveQuietly();
            }
            $beforeSeo = $article->seoMeta?->getAttributes();
            $previous = (int) $revision->id;
            $revision = app(ArticleTranslationRevisionWorkspace::class)->saveWorkingRevision($article, [
                'title' => $article->title, 'excerpt' => $article->excerpt, 'content_md' => $bodies['article.md'],
                'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            ], $owner);
            // Body-only: do not propagate historical revision SEO text into current metadata.
            $projection = clone $article;
            $projection->forceFill(['content_md' => $bodies['article.md'], 'content_html' => null]);
            $sourceHash = $projection->computeSourceVersionHash();
            $revision->forceFill(['seo_title' => null, 'seo_description' => null,
                'source_version_hash' => $sourceHash, 'translated_from_version_hash' => $sourceHash,
                'authority_source_package' => self::ROOT, 'authority_package_sha256' => self::SHA256,
                'authority_source_hash' => $package['files']['article.md'],
                'authority_metadata_json' => ['delivery' => 'automatic_body_only_migration']])->saveQuietly();
            app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision($article->fresh(), $owner, bindAttestation: false);
            $article = app(ArticlePublishService::class)->promoteExistingWorkingRevision(
                (int) $article->id, (int) $revision->id, $previous, 'career_interest_practice_body_update', dispatchFollowUp: false,
            );
            $article->refresh()->load(['publishedRevision', 'seoMeta']);
            $review ??= new EditorialReview(['org_id' => 0, 'content_type' => 'article', 'content_id' => $article->id]);
            $review->forceFill(['workflow_state' => 'approved', 'reviewed_at' => now(), 'last_transition_at' => now(),
                'note' => 'Owner-authorized exact body package '.self::SHA256])->save();
            $reviews->bindOrCreateApproved(null, 'cms_article_review', 'career-practice:'.$article->id.':revision:'.$revision->id,
                [['surface_id' => 'article', 'record' => $article], ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
                    ['surface_id' => 'editorial_review', 'record' => $review]], $owner, self::SHA256);
            $reviews->assertApprovedEvidence([['surface_id' => 'article', 'record' => $article],
                ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision]]);
            $guide->body_md = $bodies['guide.md'];
            $guide->save();
            CareerGuideWorkspace::createRevision($guide, 'Owner-authorized body update '.self::SHA256);
            if ($article->content_md !== $bodies['article.md'] || $guide->fresh()->body_md !== $bodies['guide.md']
                || $article->seoMeta?->getAttributes() !== $beforeSeo) {
                throw new RuntimeException('Career practice body or protected SEO readback failed.');
            }
            app(AuditLogger::class)->log(Request::create('/ops/career-interest-practice', 'POST'),
                'career_interest_practice_body_update', 'article', meta: ['article_id' => $article->id, 'guide_id' => $guide->id,
                    'package_sha256' => self::SHA256, 'previous_revision_id' => $previous,
                    'published_revision_id' => $revision->id, 'search_submission' => false], reason: 'Owner-authorized body-only practice links and example');

            return ['status' => 'published', 'changed' => 2];
        });
        if (! $dryRun && $result['status'] !== 'absent') {
            app(PublicContent53FrontendRevalidator::class)->revalidate([
                ['kind' => 'article', 'identity' => $package['scope']['article']],
            ]);
        }

        return $result;
    }
}
