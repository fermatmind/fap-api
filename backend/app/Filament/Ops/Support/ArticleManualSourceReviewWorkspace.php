<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** @review-surface article_translation_revision */
final class ArticleManualSourceReviewWorkspace
{
    public function prepare(Article $selected): array
    {
        return DB::transaction(function () use ($selected): array {
            [$target, $source, $revision] = $this->selected($selected, true);

            return [
                'target_hash' => ArticleSourceTargetSnapshot::publicationHash($target, true),
                'source_hash' => ArticleSourceTargetSnapshot::publicationHash($source, true),
                'source_reviewed' => false,
                'review_context' => json_encode([
                    'source_article_id' => (int) $source->id,
                    'source_published_revision_id' => (int) $source->published_revision_id,
                    'source_preview' => ArticleWorkspace::previewUrl($source, (int) $source->working_revision_id),
                    'target_article_id' => (int) $target->id,
                    'target_working_revision_id' => (int) $revision->id,
                    'target_preview' => ArticleWorkspace::previewUrl($target, (int) $revision->id),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        });
    }

    public function confirm(Article $selected, array $data): void
    {
        abort_unless(ContentAccess::canWrite(), 403);
        if (($data['source_reviewed'] ?? false) !== true) {
            $this->reject('source_review_required');
        }

        DB::transaction(function () use ($selected, $data): void {
            if (! \App\Support\SchemaBaseline::hasTable('audit_logs')) {
                $this->reject('source_review_audit_unavailable');
            }
            [$target, $source, $revision] = $this->selected($selected, true);
            try {
                ArticleSourceTargetSnapshot::assertPublicationHash($target, (string) ($data['target_hash'] ?? ''), true);
                ArticleSourceTargetSnapshot::assertPublicationHash($source, (string) ($data['source_hash'] ?? ''), true);
            } catch (\RuntimeException) {
                $this->reject('source_or_target_changed');
            }

            $oldHash = $revision->translated_from_version_hash;
            $sourceHash = (string) $source->workingRevision->source_version_hash;
            $revision->forceFill([
                'source_version_hash' => $sourceHash,
                'translated_from_version_hash' => $sourceHash,
            ])->save();

            app(AuditLogger::class)->log(Request::create('/ops/articles/manual-source-review', 'POST'),
                'article_manual_source_reviewed', 'article', (string) $target->id, [
                    'source_article_id' => (int) $source->id,
                    'source_revision_id' => (int) $source->published_revision_id,
                    'target_working_revision_id' => (int) $revision->id,
                    'source_state_sha256' => $data['source_hash'],
                    'target_state_sha256' => $data['target_hash'],
                    'previous_source_version_hash' => $oldHash,
                    'reviewed_source_version_hash' => $sourceHash,
                    'target_body_sha256' => hash('sha256', (string) $revision->content_md),
                    'reviewer_admin_id' => (int) auth((string) config('admin.guard', 'admin'))->id(),
                ], reason: 'manual_translation_source_review', result: 'success');
        });
    }

    /** @return array{Article, Article, ArticleTranslationRevision} */
    private function selected(Article $selected, bool $lock = false): array
    {
        abort_unless(ContentAccess::canWrite(), 403);
        $guard = (string) config('admin.guard', 'admin');
        abort_unless((int) auth($guard)->id() > 0, 403);
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) auth($guard)->id(), 403);

        $target = Article::withoutGlobalScopes()->findOrFail($selected->getKey());
        $rows = Article::withoutGlobalScopes()->whereIn('id', [$target->id, $target->source_article_id])
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $target = $rows->get($target->id);
        $source = $rows->get($target?->source_article_id);
        if (! $target instanceof Article || ! $source instanceof Article || $target->trashed() || $source->trashed()
            || (int) $target->org_id !== 0 || (int) $source->org_id !== 0 || ! $source->isSourceArticle()
            || ! in_array($target->locale, ['en', 'zh-CN'], true) || $target->locale === $source->locale
            || $target->source_locale !== $source->locale || $target->translation_group_id !== $source->translation_group_id
            || $target->slug !== $source->slug
            || (int) $target->translated_from_article_id !== (int) $source->id
            || ! $source->is_public || $source->status !== 'published'
            || ! $source->published_revision_id || $source->working_revision_id !== $source->published_revision_id
            || ! $target->working_revision_id || $target->working_revision_id === $target->published_revision_id) {
            $this->reject('manual_source_review_not_available');
        }

        $revisions = ArticleTranslationRevision::withoutGlobalScopes()
            ->whereIn('id', [$target->working_revision_id, $source->published_revision_id])->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $revision = $revisions->get($target->working_revision_id);
        $sourceRevision = $revisions->get($source->published_revision_id);
        if (! $revision instanceof ArticleTranslationRevision || ! $sourceRevision instanceof ArticleTranslationRevision
            || (int) $revision->article_id !== (int) $target->id || (int) $revision->org_id !== 0
            || $revision->locale !== $target->locale || $revision->source_locale !== $source->locale
            || (int) $revision->source_article_id !== (int) $source->id
            || $revision->translation_group_id !== $source->translation_group_id
            || $revision->revision_status !== ArticleTranslationRevision::STATUS_HUMAN_REVIEW
            || $revision->approved_at !== null || $revision->published_at !== null || $revision->reviewed_by !== null
            || (int) $sourceRevision->article_id !== (int) $source->id || (int) $sourceRevision->org_id !== 0
            || $sourceRevision->locale !== $source->locale || $sourceRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
            || $sourceRevision->translation_group_id !== $source->translation_group_id
            || (int) $sourceRevision->source_article_id !== (int) $source->id
            || ! filled($sourceRevision->source_version_hash)
            || ! hash_equals((string) $source->source_version_hash, (string) $sourceRevision->source_version_hash)
            || hash_equals((string) $revision->translated_from_version_hash, (string) $sourceRevision->source_version_hash)) {
            $this->reject('manual_source_review_not_available');
        }
        $source->setRelation('workingRevision', $sourceRevision);
        $target->setRelation('workingRevision', $revision);

        return [$target, $source, $revision];
    }

    private function reject(string $code): never
    {
        throw ValidationException::withMessages(['source_reviewed' => $code]);
    }
}
