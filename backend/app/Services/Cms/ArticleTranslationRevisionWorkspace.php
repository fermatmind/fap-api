<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Facades\DB;

/** @review-surface article_translation_revision */
final class ArticleTranslationRevisionWorkspace
{
    public function __construct(
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function revisionFormState(Article $article): array
    {
        $revision = $this->resolveWorkingRevision($article);

        return [
            'title' => $revision->title,
            'excerpt' => $revision->excerpt,
            'content_md' => $revision->content_md,
            'seo_title' => $revision->seo_title,
            'seo_description' => $revision->seo_description,
            'working_revision_status' => $revision->revision_status,
        ];
    }

    public function resolveWorkingRevision(Article $article): ArticleTranslationRevision
    {
        $article->loadMissing(['workingRevision', 'seoMeta', 'sourceCanonical.workingRevision']);

        $revision = $article->workingRevision;
        if (! $revision instanceof ArticleTranslationRevision) {
            return $this->createWorkingRevisionFromCanonical($article);
        }

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveWorkingRevision(Article $article, array $payload, ?int $adminUserId = null): ArticleTranslationRevision
    {
        return DB::transaction(function () use ($article, $payload, $adminUserId): ArticleTranslationRevision {
            /** @var Article $locked */
            $locked = Article::withoutGlobalScopes()
                ->with(['workingRevision', 'seoMeta', 'sourceCanonical.workingRevision'])
                ->lockForUpdate()
                ->findOrFail($article->getKey());

            $revision = $this->resolveWorkingRevision($locked);
            $revisionChanged = $this->revisionPayloadChanged($revision, $payload);
            $revisionStatus = $this->normalizeWritableStatus(
                $payload['working_revision_status'] ?? $revision->revision_status,
                (string) $revision->revision_status,
                $revisionChanged,
                $locked,
            );
            $now = now();

            $revisionPayload = [
                'org_id' => (int) $locked->org_id,
                'article_id' => (int) $locked->id,
                'source_article_id' => $this->sourceArticleIdFor($locked),
                'translation_group_id' => (string) $locked->translation_group_id,
                'locale' => (string) $locked->locale,
                'source_locale' => (string) ($locked->source_locale ?: $locked->locale),
                'revision_status' => $revisionStatus,
                'source_version_hash' => $locked->isSourceArticle() ? $this->hashForRevision($locked, [
                    'title' => $payload['title'] ?? $revision->title,
                    'excerpt' => $payload['excerpt'] ?? $revision->excerpt,
                    'content_md' => $payload['content_md'] ?? $revision->content_md,
                ]) : $revision->source_version_hash,
                'translated_from_version_hash' => $locked->isSourceArticle()
                    ? $this->hashForRevision($locked, [
                        'title' => $payload['title'] ?? $revision->title,
                        'excerpt' => $payload['excerpt'] ?? $revision->excerpt,
                        'content_md' => $payload['content_md'] ?? $revision->content_md,
                    ])
                    : $revision->translated_from_version_hash,
                'title' => (string) ($payload['title'] ?? $revision->title),
                'excerpt' => $payload['excerpt'] ?? $revision->excerpt,
                'content_md' => (string) ($payload['content_md'] ?? $revision->content_md),
                'seo_title' => $payload['seo_title'] ?? $revision->seo_title,
                'seo_description' => $payload['seo_description'] ?? $revision->seo_description,
                'created_by' => $revision->created_by ?: $adminUserId,
            ];
            $this->articleBodyHeadingGuard->assertNoBodyH1((string) $revisionPayload['content_md']);

            if ($revisionChanged && ! in_array($revisionStatus, [
                ArticleTranslationRevision::STATUS_APPROVED,
                ArticleTranslationRevision::STATUS_PUBLISHED,
                ArticleTranslationRevision::STATUS_SOURCE,
            ], true)) {
                $revisionPayload = array_merge($revisionPayload, [
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'approved_at' => null,
                    'published_at' => null,
                ]);
            }

            $statusChanged = $revisionStatus !== (string) $revision->revision_status;
            $forkPublished = ($revisionChanged || $statusChanged)
                && (int) $locked->published_revision_id === (int) $revision->id;
            if ($forkPublished) {
                $publishedRevision = $revision;
                $revision = $publishedRevision->replicate();
                $revisionPayload['revision_number'] = ((int) $locked->translationRevisions()->max('revision_number')) + 1;
                $revisionPayload['supersedes_revision_id'] = (int) $publishedRevision->id;
                $revisionPayload['created_by'] = $adminUserId;
                $revisionPayload['reviewed_by'] = null;
                $revisionPayload['reviewed_at'] = null;
                $revisionPayload['approved_at'] = null;
                $revisionPayload['published_at'] = null;
                // A fork is a new editorial candidate, not another copy of the immutable package asset.
                foreach (['authority_asset_key', 'authority_source_package', 'authority_source_hash', 'authority_package_sha256', 'authority_metadata_json'] as $field) {
                    $revisionPayload[$field] = null;
                }
            }

            $revision->forceFill($revisionPayload)->save();
            if ($forkPublished) {
                $locked->forceFill(['working_revision_id' => (int) $revision->id])->saveQuietly();
            }

            if ($revisionChanged || $statusChanged) {
                DB::table('articles')
                    ->where('id', $locked->id)
                    ->update(['updated_at' => $now]);
            }

            // An unpublished translation has no public projection to preserve. Keep its
            // saved draft projection aligned for the normal Ops preview/review/publish path.
            // Published targets and source articles retain their existing pointer protection.
            if ((int) $locked->org_id === 0 && ! $locked->isSourceArticle()
                && $locked->published_revision_id === null && $locked->status === 'draft' && ! $locked->is_public) {
                DB::table('articles')->where('id', $locked->id)->update([
                    'title' => $revision->title, 'excerpt' => $revision->excerpt,
                    'content_md' => $revision->content_md, 'content_html' => null,
                ]);
            }

            if ($locked->isSourceArticle()) {
                DB::table('articles')
                    ->where('id', $locked->id)
                    ->update([
                        'source_version_hash' => $revision->source_version_hash,
                        'updated_at' => $revisionChanged || $statusChanged ? $now : $locked->updated_at,
                    ]);
            }

            return $revision->refresh();
        });
    }

    /** Explicitly preserve an unpublished baseline before importing a replacement candidate. */
    public function forkPrivateWorkingRevision(Article $selected, string $expectedSnapshot, int $actor): ArticleTranslationRevision
    {
        abort_unless(\App\Filament\Ops\Support\ContentAccess::canWrite()
            && app(CmsEditorialReviewAttestationService::class)->isConfiguredSoloOwner($actor)
            && (int) auth((string) config('admin.guard', 'admin'))->id() === $actor, 403);
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== $actor, 403);

        return DB::transaction(function () use ($selected, $expectedSnapshot, $actor): ArticleTranslationRevision {
            $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())->lockForUpdate()->firstOrFail();
            \App\Support\ArticleSourceTargetSnapshot::assertPublicationHash($article, $expectedSnapshot, true);
            $old = ArticleTranslationRevision::withoutGlobalScopes()->whereKey($article->working_revision_id)->lockForUpdate()->firstOrFail();
            if ((int) $article->org_id !== 0 || $article->isSourceArticle() || $article->trashed() || $article->status !== 'draft'
                || $article->is_public || $article->published_revision_id !== null || $article->published_at !== null
                || (int) $old->org_id !== 0 || (int) $old->article_id !== (int) $article->id || $old->locale !== $article->locale
                || $old->translation_group_id !== $article->translation_group_id
                || (int) $old->source_article_id !== (int) $article->source_article_id
                || ! in_array($old->revision_status, [ArticleTranslationRevision::STATUS_MACHINE_DRAFT, ArticleTranslationRevision::STATUS_HUMAN_REVIEW], true)
                || $old->approved_at !== null || $old->published_at !== null) {
                throw new \RuntimeException('private_working_fork_not_available');
            }
            $revision = $old->replicate();
            $revision->forceFill(['revision_number' => (int) ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number') + 1,
                'supersedes_revision_id' => (int) $old->id, 'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
                'created_by' => $actor, 'reviewed_by' => null, 'reviewed_at' => null, 'approved_at' => null, 'published_at' => null,
                'authority_asset_key' => null, 'authority_source_package' => null, 'authority_source_hash' => null,
                'authority_package_sha256' => null, 'authority_metadata_json' => null])->save();
            $article->forceFill(['working_revision_id' => (int) $revision->id])->saveQuietly();
            app(\App\Services\Audit\AuditLogger::class)->log(\Illuminate\Http\Request::create('/ops/articles/fork-private-working', 'POST'),
                'article_private_working_revision_forked', 'article', (string) $article->id,
                ['resource_org_id' => 0, 'previous_working_revision_id' => (int) $old->id,
                    'new_working_revision_id' => (int) $revision->id, 'before_snapshot_sha256' => $expectedSnapshot],
                reason: 'preserve_private_draft_before_edit', result: 'success');

            return $revision;
        });
    }

    public function isWorkingRevisionStale(Article $article): bool
    {
        if ($article->isSourceArticle()) {
            return false;
        }

        $revision = $article->workingRevision;
        if (! $revision instanceof ArticleTranslationRevision) {
            return $article->isTranslationStale();
        }

        $sourceHash = $this->sourceVersionHashFor($article);

        return filled($sourceHash)
            && filled($revision->translated_from_version_hash)
            && ! hash_equals((string) $sourceHash, (string) $revision->translated_from_version_hash)
            && ! app(ArticlePublishedSourceReviewService::class)->hasCurrentReview($article)
            && ! app(ArticlePublishedSourceReviewService::class)->hasCurrentIndependentReview($article);
    }

    public function sourceVersionHashFor(Article $article): ?string
    {
        $source = $article->isSourceArticle() ? $article : $article->sourceArticle();
        if (! $source instanceof Article) {
            return null;
        }

        $source->loadMissing('workingRevision');
        if ($source->workingRevision instanceof ArticleTranslationRevision
            && filled($source->workingRevision->source_version_hash)) {
            return (string) $source->workingRevision->source_version_hash;
        }

        return $source->source_version_hash;
    }

    public function shortRevision(?ArticleTranslationRevision $revision): string
    {
        if (! $revision instanceof ArticleTranslationRevision) {
            return '';
        }

        $status = str_replace('-', '_', (string) $revision->revision_status);

        return '#'.$revision->id.' · r'.$revision->revision_number.' · '.__('ops.status.'.$status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hashForRevision(Article $article, array $payload): string
    {
        return Article::sourceVersionHashFromPayload([
            'locale' => $article->locale,
            'title' => $payload['title'] ?? null,
            'excerpt' => $payload['excerpt'] ?? null,
            'content_md' => $payload['content_md'] ?? null,
            'content_html' => $article->content_html,
            'cover_image_alt' => $article->cover_image_alt,
            'related_test_slug' => $article->related_test_slug,
            'voice' => $article->voice,
            'voice_order' => $article->voice_order,
        ]);
    }

    private function createWorkingRevisionFromCanonical(Article $article): ArticleTranslationRevision
    {
        return DB::transaction(function () use ($article): ArticleTranslationRevision {
            $revisionNumber = ((int) $article->translationRevisions()->max('revision_number')) + 1;
            $revision = ArticleTranslationRevision::query()->create($this->canonicalRevisionPayload($article, $revisionNumber));

            DB::table('articles')
                ->where('id', $article->id)
                ->update(['working_revision_id' => $revision->id]);

            $article->forceFill(['working_revision_id' => $revision->id])->setRelation('workingRevision', $revision);

            return $revision;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalRevisionPayload(Article $article, int $revisionNumber): array
    {
        $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
        $ownHash = $this->hashForRevision($article, [
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $article->content_md,
        ]);

        return [
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => $this->sourceArticleIdFor($article),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => $revisionNumber,
            'revision_status' => $this->normalizeStatus($article->translation_status),
            'source_version_hash' => $article->isSourceArticle() ? $ownHash : $article->translated_from_version_hash,
            'translated_from_version_hash' => $article->isSourceArticle()
                ? $ownHash
                : $article->translated_from_version_hash,
            'supersedes_revision_id' => null,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => $seoMeta?->seo_title,
            'seo_description' => $seoMeta?->seo_description,
        ];
    }

    private function sourceArticleIdFor(Article $article): int
    {
        if ($article->isSourceArticle()) {
            return (int) $article->id;
        }

        return (int) ($article->source_article_id ?: $article->translated_from_article_id ?: $article->id);
    }

    private function normalizeStatus(?string $status): string
    {
        $status = trim((string) $status);

        return in_array($status, ArticleTranslationRevision::statuses(), true)
            ? $status
            : ArticleTranslationRevision::STATUS_MACHINE_DRAFT;
    }

    private function normalizeWritableStatus(
        mixed $requestedStatus,
        string $currentStatus,
        bool $revisionChanged,
        Article $article,
    ): string {
        $requestedStatus = $this->normalizeStatus(is_string($requestedStatus) ? $requestedStatus : null);
        $currentStatus = $this->normalizeStatus($currentStatus);

        if (
            $article->isSourceArticle()
            && $currentStatus === ArticleTranslationRevision::STATUS_SOURCE
            && $requestedStatus === ArticleTranslationRevision::STATUS_SOURCE
        ) {
            return ArticleTranslationRevision::STATUS_SOURCE;
        }

        if ($revisionChanged && in_array($currentStatus, [
            ArticleTranslationRevision::STATUS_APPROVED,
            ArticleTranslationRevision::STATUS_PUBLISHED,
        ], true)) {
            return ArticleTranslationRevision::STATUS_HUMAN_REVIEW;
        }

        if (in_array($requestedStatus, [
            ArticleTranslationRevision::STATUS_APPROVED,
            ArticleTranslationRevision::STATUS_PUBLISHED,
            ArticleTranslationRevision::STATUS_SOURCE,
        ], true) && $requestedStatus !== $currentStatus) {
            return $currentStatus === ArticleTranslationRevision::STATUS_APPROVED
                ? ArticleTranslationRevision::STATUS_APPROVED
                : ArticleTranslationRevision::STATUS_HUMAN_REVIEW;
        }

        return $requestedStatus;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function revisionPayloadChanged(ArticleTranslationRevision $revision, array $payload): bool
    {
        foreach (['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            if ((string) ($payload[$field] ?? '') !== (string) ($revision->{$field} ?? '')) {
                return true;
            }
        }

        return false;
    }
}
