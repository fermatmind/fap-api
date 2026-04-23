<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\ArticleMachineTranslationProvider;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\EditorialReview;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ArticleTranslationWorkflowService
{
    public const PUBLIC_EDITORIAL_ORG_ID = 0;

    public function __construct(
        private readonly ArticleMachineTranslationProvider $provider,
        private readonly ArticleTranslationRevisionWorkspace $workspace,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function canGenerateMachineDraft(): bool
    {
        return $this->provider->isConfigured();
    }

    public function machineDraftUnavailableReason(): ?string
    {
        return $this->provider->unavailableReason();
    }

    /**
     * @return array{article:Article,revision:ArticleTranslationRevision,created_article:bool}
     */
    public function createMachineDraft(Article $source, string $targetLocale, ?int $adminUserId = null): array
    {
        $targetLocale = $this->normalizeLocale($targetLocale);
        $this->assertProviderConfigured();

        if (! $source->isSourceArticle()) {
            throw new ArticleTranslationWorkflowException('Create translation draft must start from a source article.', [
                'source article is not canonical source',
            ]);
        }

        return DB::transaction(function () use ($source, $targetLocale, $adminUserId): array {
            /** @var Article $lockedSource */
            $lockedSource = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'seoMeta'])
                ->lockForUpdate()
                ->findOrFail($source->id);

            $existingTarget = $this->targetFor($lockedSource, $targetLocale);
            if ($existingTarget instanceof Article) {
                throw new ArticleTranslationWorkflowException('Target locale already exists. Use re-sync from source instead.', [
                    'target locale already exists',
                ]);
            }

            $payload = $this->provider->translate($lockedSource, $targetLocale);
            $sourceHash = $this->sourceHashFor($lockedSource);
            $target = $this->createTargetArticle($lockedSource, $targetLocale, $payload, $sourceHash);
            $revision = $this->createMachineRevision($target, $lockedSource, $payload, $sourceHash, null, $adminUserId);

            $target->forceFill([
                'working_revision_id' => (int) $revision->id,
                'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
            ])->saveQuietly();

            ArticleSeoMeta::query()->updateOrCreate(
                ['article_id' => (int) $target->id],
                [
                    'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
                    'locale' => $targetLocale,
                    'seo_title' => $payload['seo_title'] ?? null,
                    'seo_description' => $payload['seo_description'] ?? null,
                    'is_indexable' => false,
                ],
            );

            $this->log('article_translation_draft_created', $target, [
                'source_article_id' => (int) $lockedSource->id,
                'revision_id' => (int) $revision->id,
                'target_locale' => $targetLocale,
                'source_version_hash' => $sourceHash,
            ]);

            return [
                'article' => $target->fresh(['workingRevision', 'publishedRevision', 'seoMeta']),
                'revision' => $revision->refresh(),
                'created_article' => true,
            ];
        });
    }

    /**
     * @return array{article:Article,revision:ArticleTranslationRevision,archived_revision_id:int|null}
     */
    public function resyncFromSource(Article $target, ?int $adminUserId = null): array
    {
        $this->assertProviderConfigured();

        if ($target->isSourceArticle()) {
            throw new ArticleTranslationWorkflowException('Re-sync requires a target translation article.', [
                'target article is source',
            ]);
        }

        return DB::transaction(function () use ($target, $adminUserId): array {
            /** @var Article $lockedTarget */
            $lockedTarget = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'publishedRevision', 'sourceCanonical.workingRevision', 'seoMeta'])
                ->lockForUpdate()
                ->findOrFail($target->id);
            $source = $lockedTarget->sourceArticle();

            if (! $source instanceof Article || ! $source->isSourceArticle()) {
                throw new ArticleTranslationWorkflowException('Target translation is missing a valid source article.', [
                    'source linkage invalid',
                ]);
            }

            $source->loadMissing(['workingRevision', 'seoMeta']);
            $payload = $this->provider->translate($source, (string) $lockedTarget->locale);
            $sourceHash = $this->sourceHashFor($source);
            $supersedesRevisionId = $lockedTarget->working_revision_id ? (int) $lockedTarget->working_revision_id : null;
            $archivedRevisionId = $this->markSupersededWorkingRevisionStale($lockedTarget);
            $revision = $this->createMachineRevision($lockedTarget, $source, $payload, $sourceHash, $supersedesRevisionId, $adminUserId);

            $lockedTarget->forceFill([
                'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
                'source_article_id' => (int) $source->id,
                'translated_from_article_id' => (int) $source->id,
                'translation_group_id' => (string) $source->translation_group_id,
                'source_locale' => (string) $source->locale,
                'translated_from_version_hash' => $sourceHash,
                'working_revision_id' => (int) $revision->id,
                'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
            ])->saveQuietly();

            ArticleSeoMeta::query()->updateOrCreate(
                ['article_id' => (int) $lockedTarget->id],
                [
                    'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
                    'locale' => (string) $lockedTarget->locale,
                    'seo_title' => $payload['seo_title'] ?? null,
                    'seo_description' => $payload['seo_description'] ?? null,
                    'is_indexable' => false,
                ],
            );

            $this->log('article_translation_resynced', $lockedTarget, [
                'source_article_id' => (int) $source->id,
                'revision_id' => (int) $revision->id,
                'supersedes_revision_id' => $supersedesRevisionId,
                'source_version_hash' => $sourceHash,
            ]);

            return [
                'article' => $lockedTarget->fresh(['workingRevision', 'publishedRevision', 'seoMeta']),
                'revision' => $revision->refresh(),
                'archived_revision_id' => $archivedRevisionId,
            ];
        });
    }

    public function promoteToHumanReview(Article $target): ArticleTranslationRevision
    {
        return DB::transaction(function () use ($target): ArticleTranslationRevision {
            $locked = $this->lockTarget($target);
            $revision = $this->workingRevisionOrFail($locked);

            if ($revision->revision_status !== ArticleTranslationRevision::STATUS_MACHINE_DRAFT) {
                throw new ArticleTranslationWorkflowException('Only machine_draft revisions can be promoted to human_review.', [
                    'working revision is not machine_draft',
                ]);
            }
            if ($this->workspace->isWorkingRevisionStale($locked)) {
                throw new ArticleTranslationWorkflowException('Stale translations must be re-synced before human review.', [
                    'working revision is stale',
                ]);
            }

            $revision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                'reviewed_at' => $revision->reviewed_at ?? now(),
            ])->save();
            $locked->forceFill([
                'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            ])->saveQuietly();

            $this->log('article_translation_promoted_human_review', $locked, [
                'revision_id' => (int) $revision->id,
            ]);

            return $revision->refresh();
        });
    }

    public function approveTranslation(Article $target): ArticleTranslationRevision
    {
        return DB::transaction(function () use ($target): ArticleTranslationRevision {
            $locked = $this->lockTarget($target);
            $revision = $this->workingRevisionOrFail($locked);

            if ($revision->revision_status !== ArticleTranslationRevision::STATUS_HUMAN_REVIEW) {
                throw new ArticleTranslationWorkflowException('Only human_review revisions can be approved.', [
                    'working revision is not human_review',
                ]);
            }
            $blockers = $this->preflight($locked)['blockers'];
            if ($blockers !== []) {
                throw new ArticleTranslationWorkflowException('Translation approval is blocked by preflight issues.', $blockers);
            }

            $revision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'approved_at' => $revision->approved_at ?? now(),
            ])->save();
            $locked->forceFill([
                'translation_status' => Article::TRANSLATION_STATUS_APPROVED,
            ])->saveQuietly();
            $this->markEditorialApproved($locked);

            $this->log('article_translation_approved', $locked, [
                'revision_id' => (int) $revision->id,
            ]);

            return $revision->refresh();
        });
    }

    public function archiveStaleRevision(Article $target): ArticleTranslationRevision
    {
        return DB::transaction(function () use ($target): ArticleTranslationRevision {
            $locked = $this->lockTarget($target);
            $revision = $this->workingRevisionOrFail($locked);

            if ((int) $locked->published_revision_id === (int) $revision->id) {
                throw new ArticleTranslationWorkflowException('Published revisions cannot be archived from the translation console.', [
                    'working revision is published',
                ]);
            }

            if (! $this->workspace->isWorkingRevisionStale($locked)
                && $revision->revision_status !== ArticleTranslationRevision::STATUS_STALE) {
                throw new ArticleTranslationWorkflowException('Only stale working revisions can be archived.', [
                    'working revision is not stale',
                ]);
            }

            $revision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_ARCHIVED,
            ])->save();
            $locked->forceFill([
                'translation_status' => $locked->published_revision_id
                    ? Article::TRANSLATION_STATUS_PUBLISHED
                    : Article::TRANSLATION_STATUS_ARCHIVED,
                'working_revision_id' => $locked->published_revision_id ?: $revision->id,
            ])->saveQuietly();

            $this->log('article_translation_stale_revision_archived', $locked, [
                'revision_id' => (int) $revision->id,
            ]);

            return $revision->refresh();
        });
    }

    /**
     * @return array{ok:bool,blockers:list<string>}
     */
    public function preflight(Article $target): array
    {
        $target->loadMissing(['workingRevision', 'publishedRevision', 'sourceCanonical.workingRevision', 'seoMeta']);
        $blockers = [];

        if ($target->isSourceArticle()) {
            $blockers[] = 'target article is source';
        }
        if ((int) $target->org_id !== self::PUBLIC_EDITORIAL_ORG_ID) {
            $blockers[] = 'target article org mismatch';
        }
        if (! filled($target->locale)) {
            $blockers[] = 'target locale missing';
        }

        $workingRevision = $target->workingRevision;
        if (! $workingRevision instanceof ArticleTranslationRevision) {
            $blockers[] = 'working revision missing';
        } else {
            $blockers = array_merge($blockers, $this->revisionOwnershipBlockers($target, $workingRevision, 'working revision'));
            if ($workingRevision->revision_status === ArticleTranslationRevision::STATUS_STALE) {
                $blockers[] = 'working revision is stale';
            }
            if ($workingRevision->revision_status === ArticleTranslationRevision::STATUS_ARCHIVED) {
                $blockers[] = 'working revision is archived';
            }
        }

        if ($this->workspace->isWorkingRevisionStale($target)) {
            $blockers[] = 'working revision is stale';
        }

        $source = $target->sourceArticle();
        if (! $source instanceof Article || ! $source->isSourceArticle()) {
            $blockers[] = 'source canonical invalid';
        } else {
            if ((int) $target->source_article_id !== (int) $source->id) {
                $blockers[] = 'source_article_id mismatch';
            }
            if ((string) $target->source_locale !== (string) $source->locale) {
                $blockers[] = 'source_locale mismatch';
            }
            if ((string) $target->translation_group_id !== (string) $source->translation_group_id) {
                $blockers[] = 'translation_group mismatch';
            }
            if ($workingRevision instanceof ArticleTranslationRevision
                && ! $this->referencesPreserved($source, $workingRevision)) {
                $blockers[] = 'references/citations presence check failed';
            }
        }

        $seoMeta = $target->seoMeta;
        if ($seoMeta instanceof ArticleSeoMeta && (int) $seoMeta->org_id !== self::PUBLIC_EDITORIAL_ORG_ID) {
            $blockers[] = 'seo_meta org mismatch';
        }

        return [
            'ok' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    public function publishTranslation(Article $target, string $source = 'translation_ops_console'): ArticleTranslationRevision
    {
        return DB::transaction(function () use ($target, $source): ArticleTranslationRevision {
            $locked = $this->lockTarget($target);
            $revision = $this->workingRevisionOrFail($locked);
            $preflight = $this->preflight($locked);

            if (! $preflight['ok']) {
                throw new ArticleTranslationWorkflowException('Translation publish preflight failed.', $preflight['blockers']);
            }
            if ($revision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
                throw new ArticleTranslationWorkflowException('Only approved translation revisions can be published.', [
                    'working revision is not approved',
                ]);
            }

            $this->markEditorialApproved($locked);

            $revision->forceFill([
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'published_at' => $revision->published_at ?? now(),
            ])->save();
            $locked->forceFill([
                'status' => 'published',
                'is_public' => true,
                'published_at' => $locked->published_at ?? now(),
                'published_revision_id' => (int) $revision->id,
                'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            ])->saveQuietly();

            ContentReleaseAudit::log('article', $locked->fresh(), $source);
            $this->log('article_translation_published', $locked, [
                'revision_id' => (int) $revision->id,
                'published_revision_id' => (int) $revision->id,
            ]);

            return $revision->refresh();
        });
    }

    private function assertProviderConfigured(): void
    {
        if (! $this->provider->isConfigured()) {
            throw new ArticleTranslationWorkflowException((string) $this->provider->unavailableReason(), [
                'machine translation provider unavailable',
            ]);
        }
    }

    /**
     * @param  array{title:string,excerpt:string|null,content_md:string,seo_title:string|null,seo_description:string|null}  $payload
     */
    private function createTargetArticle(Article $source, string $targetLocale, array $payload, string $sourceHash): Article
    {
        $target = Article::query()->create([
            'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
            'slug' => (string) $source->slug,
            'locale' => $targetLocale,
            'translation_group_id' => (string) $source->translation_group_id,
            'source_locale' => (string) $source->locale,
            'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
            'translated_from_article_id' => (int) $source->id,
            'source_article_id' => (int) $source->id,
            'translated_from_version_hash' => $sourceHash,
            'title' => (string) $payload['title'],
            'excerpt' => $payload['excerpt'] ?? null,
            'content_md' => (string) $payload['content_md'],
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
        ]);

        DB::table('articles')
            ->where('id', (int) $target->id)
            ->update([
                'source_version_hash' => $sourceHash,
                'translated_from_version_hash' => $sourceHash,
            ]);

        return $target->fresh();
    }

    /**
     * @param  array{title:string,excerpt:string|null,content_md:string,seo_title:string|null,seo_description:string|null}  $payload
     */
    private function createMachineRevision(
        Article $target,
        Article $source,
        array $payload,
        string $sourceHash,
        ?int $supersedesRevisionId,
        ?int $adminUserId
    ): ArticleTranslationRevision {
        $revisionNumber = ((int) $target->translationRevisions()->max('revision_number')) + 1;

        return ArticleTranslationRevision::query()->create([
            'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
            'article_id' => (int) $target->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => (string) $target->locale,
            'source_locale' => (string) $source->locale,
            'revision_number' => $revisionNumber,
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $sourceHash,
            'supersedes_revision_id' => $supersedesRevisionId,
            'title' => (string) $payload['title'],
            'excerpt' => $payload['excerpt'] ?? null,
            'content_md' => (string) $payload['content_md'],
            'seo_title' => $payload['seo_title'] ?? null,
            'seo_description' => $payload['seo_description'] ?? null,
            'created_by' => $adminUserId,
        ]);
    }

    private function targetFor(Article $source, string $targetLocale): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', self::PUBLIC_EDITORIAL_ORG_ID)
            ->where('translation_group_id', (string) $source->translation_group_id)
            ->where('source_article_id', (int) $source->id)
            ->where('locale', $targetLocale)
            ->first();
    }

    private function markSupersededWorkingRevisionStale(Article $target): ?int
    {
        $revision = $target->workingRevision;
        if (! $revision instanceof ArticleTranslationRevision) {
            return null;
        }
        if ((int) $target->published_revision_id === (int) $revision->id) {
            return null;
        }

        $revision->forceFill([
            'revision_status' => ArticleTranslationRevision::STATUS_STALE,
        ])->save();

        return (int) $revision->id;
    }

    private function lockTarget(Article $target): Article
    {
        /** @var Article $locked */
        $locked = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'sourceCanonical.workingRevision', 'seoMeta'])
            ->lockForUpdate()
            ->findOrFail($target->id);

        if ($locked->isSourceArticle()) {
            throw new ArticleTranslationWorkflowException('This action requires a target translation article.', [
                'target article is source',
            ]);
        }

        return $locked;
    }

    private function workingRevisionOrFail(Article $target): ArticleTranslationRevision
    {
        $revision = $target->workingRevision;
        if (! $revision instanceof ArticleTranslationRevision) {
            throw new ArticleTranslationWorkflowException('This translation does not have a working revision.', [
                'working revision missing',
            ]);
        }

        return $revision;
    }

    /**
     * @return list<string>
     */
    private function revisionOwnershipBlockers(Article $target, ArticleTranslationRevision $revision, string $label): array
    {
        $blockers = [];
        if ((int) $revision->org_id !== self::PUBLIC_EDITORIAL_ORG_ID) {
            $blockers[] = "{$label} org mismatch";
        }
        if ((int) $revision->article_id !== (int) $target->id) {
            $blockers[] = "{$label} article mismatch";
        }
        if ((string) $revision->locale !== (string) $target->locale) {
            $blockers[] = "{$label} locale mismatch";
        }
        if ((string) $revision->translation_group_id !== (string) $target->translation_group_id) {
            $blockers[] = "{$label} group mismatch";
        }

        return $blockers;
    }

    private function referencesPreserved(Article $source, ArticleTranslationRevision $targetRevision): bool
    {
        $sourceText = implode("\n", [
            (string) $source->title,
            (string) $source->excerpt,
            (string) $source->content_md,
        ]);
        $targetText = implode("\n", [
            (string) $targetRevision->title,
            (string) $targetRevision->excerpt,
            (string) $targetRevision->content_md,
        ]);

        if (! $this->hasReferenceMarker($sourceText)) {
            return true;
        }

        return $this->hasReferenceMarker($targetText);
    }

    private function hasReferenceMarker(string $text): bool
    {
        return preg_match('/(references|reference|citations|citation|doi\\b|doi\\.org|参考文献|引用|https?:\\/\\/)/iu', $text) === 1;
    }

    private function markEditorialApproved(Article $target): void
    {
        $guard = (string) config('admin.guard', 'admin');
        $actor = auth($guard)->user();
        $actorId = is_object($actor) && is_numeric(data_get($actor, 'id')) ? (int) data_get($actor, 'id') : null;

        EditorialReview::withoutGlobalScopes()->updateOrCreate(
            [
                'content_type' => 'article',
                'content_id' => (int) $target->id,
            ],
            [
                'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
                'workflow_state' => EditorialReviewAudit::STATE_APPROVED,
                'reviewed_by_admin_user_id' => $actorId,
                'reviewed_at' => now(),
                'last_transition_at' => now(),
            ],
        );
    }

    private function sourceHashFor(Article $source): string
    {
        $source->loadMissing('workingRevision');

        if ($source->workingRevision instanceof ArticleTranslationRevision
            && filled($source->workingRevision->source_version_hash)) {
            return (string) $source->workingRevision->source_version_hash;
        }

        return (string) $source->source_version_hash;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            throw new ArticleTranslationWorkflowException('Target locale is required.', [
                'target locale missing',
            ]);
        }

        return $locale;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function log(string $action, Article $article, array $meta = []): void
    {
        $boundRequest = app()->bound('request') ? app('request') : null;
        $request = $boundRequest instanceof Request
            ? $boundRequest
            : Request::create('/ops/article-translation-ops', 'POST');

        $this->auditLogger->log(
            $request,
            $action,
            'article_translation',
            (string) $article->id,
            array_merge([
                'article_id' => (int) $article->id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'translation_group_id' => (string) $article->translation_group_id,
            ], $meta),
            reason: 'article_translation_ops',
            result: 'success',
        );
    }
}
