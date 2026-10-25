<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\ReviewAttestation;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** @review-surface article_translation_revision */
final class ArticlePublishedSourceReviewService
{
    private const SCOPE = 'cms_article_source_review';

    public function prepare(Article $selected): array
    {
        $this->authorize();

        return DB::transaction(function () use ($selected): array {
            [$target, $source, $targetRevision, $sourceRevision] = $this->pair($selected, true);

            return [
                'pair_hash' => $this->pairHash($target, $source, $targetRevision, $sourceRevision, true),
                'source_reviewed' => false,
                'review_context' => json_encode([
                    'source_article_id' => (int) $source->id,
                    'source_published_revision_id' => (int) $sourceRevision->id,
                    'source_preview' => ArticleWorkspace::previewUrl($source, (int) $sourceRevision->id),
                    'target_article_id' => (int) $target->id,
                    'target_working_revision_id' => (int) $targetRevision->id,
                    'target_preview' => ArticleWorkspace::previewUrl($target, (int) $targetRevision->id),
                    'historical_source_hash' => $targetRevision->translated_from_version_hash,
                    'current_source_hash' => $sourceRevision->source_version_hash,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        });
    }

    public function confirm(Article $selected, array $data): void
    {
        $this->authorize();
        $this->require(($data['source_reviewed'] ?? false) === true, 'published_source_review_required');
        DB::transaction(function () use ($selected, $data): void {
            $this->require(\App\Support\SchemaBaseline::hasTable('audit_logs'), 'published_source_review_audit_unavailable');
            [$target, $source, $targetRevision, $sourceRevision] = $this->pair($selected, true);
            $hash = $this->pairHash($target, $source, $targetRevision, $sourceRevision, true);
            $this->require(hash_equals($hash, (string) ($data['pair_hash'] ?? '')), 'source_or_target_changed');
            $actor = (int) auth((string) config('admin.guard', 'admin'))->id();
            $attestation = app(CmsEditorialReviewAttestationService::class)->bindOrCreateApproved(
                null, self::SCOPE, $this->identity($target, $source),
                $this->resources($target, $source, $targetRevision, $sourceRevision), $actor, $hash,
            );
            $request = Request::create('/ops/articles/published-source-review', 'POST');
            $request->attributes->set('org_id', 0);
            app(AuditLogger::class)->log($request, 'article_published_source_reviewed', 'article', (string) $target->id, [
                'resource_org_id' => (int) $target->org_id,
                'attestation_id' => (int) $attestation->id,
                'pair_sha256' => $hash,
                'source_article_id' => (int) $source->id,
                'source_revision_id' => (int) $sourceRevision->id,
                'target_published_revision_id' => (int) $targetRevision->id,
                'historical_source_hash' => $targetRevision->translated_from_version_hash,
                'reviewed_current_source_hash' => $sourceRevision->source_version_hash,
                'content_changed' => false,
                'published' => false,
                'historical_source_hash_rewritten' => false,
            ], reason: 'published_translation_current_source_review', result: 'success');
            $this->require($this->auditExists($target, $attestation, $hash), 'published_source_review_audit_missing');
            $this->require(hash_equals($hash, $this->pairHash($target, $source, $targetRevision, $sourceRevision, true)), 'published_source_review_readback_changed');
        });
    }

    /** A current, complete pair attestation supplements historical provenance; it never rewrites it. */
    public function hasCurrentReview(Article $selected): bool
    {
        try {
            [$target, $source, $targetRevision, $sourceRevision] = $this->pair($selected);
            $hash = $this->pairHash($target, $source, $targetRevision, $sourceRevision);
            $record = ReviewAttestation::query()->where('scope_type', self::SCOPE)
                ->where('scope_identity', $this->identity($target, $source))->where('package_sha256', $hash)
                ->where('review_mode', 'solo_owner')->where('review_source', (string) config('review_governance.attestation.review_source'))
                ->where('decision', 'approved_all')->where('target_count', 4)
                ->where('attested_by_admin_user_id', (int) config('review_governance.solo_owner_admin_user_id'))
                ->latest('id')->first();
            if (! $record || ! $this->auditExists($target, $record, $hash)) {
                return false;
            }
            $resources = $this->resources($target, $source, $targetRevision, $sourceRevision);
            $adapter = app(CmsEditorialReviewAttestationService::class);
            $adapter->preflight($record->canonical_evidence_json, $resources, $hash);
            $evidence = $record->targetEvidences;
            if ($evidence->count() !== 4) {
                return false;
            }
            foreach ($adapter->targets($resources) as $expected) {
                if ($evidence->where('target_identity', $expected['target_identity'])
                    ->where('target_sha256', $expected['target_sha256'])->where('target_decision', 'approved')->count() !== 1) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function authorize(): void
    {
        abort_unless(ContentAccess::canWrite(), 403);
        $guard = (string) config('admin.guard', 'admin');
        $actor = (int) auth($guard)->id();
        abort_unless(app(CmsEditorialReviewAttestationService::class)->isConfiguredSoloOwner($actor), 403);
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== $actor, 403);
    }

    private function pair(Article $selected, bool $lock = false): array
    {
        $initial = Article::withoutGlobalScopes()->findOrFail($selected->getKey());
        $rows = Article::withoutGlobalScopes()->whereIn('id', [$initial->id, $initial->source_article_id])->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $target = $rows->get($initial->id);
        $source = $rows->get($target?->source_article_id);
        $this->require($target instanceof Article && $source instanceof Article
            && $source->isSourceArticle() && ! $target->isSourceArticle()
            && $target->source_locale === $source->locale && in_array($source->locale, ['en', 'zh-CN'], true)
            && in_array($target->locale, ['en', 'zh-CN'], true) && $target->locale !== $source->locale
            && filled($source->translation_group_id) && $target->translation_group_id === $source->translation_group_id
            && $target->slug === $source->slug && (int) $target->translated_from_article_id === (int) $source->id,
            'published_source_pair_invalid');
        foreach ([$target, $source] as $article) {
            $this->require((int) $article->org_id === 0 && ! $article->trashed()
                && $article->status === 'published' && $article->is_public && $article->published_at !== null && ! $article->published_at->isFuture()
                && ! in_array($article->lifecycle_state, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true)
                && $article->published_revision_id !== null && $article->working_revision_id === $article->published_revision_id,
                'published_source_pair_invalid');
        }
        $revisions = ArticleTranslationRevision::withoutGlobalScopes()->whereIn('id', [$target->published_revision_id, $source->published_revision_id])
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $targetRevision = $revisions->get($target->published_revision_id);
        $sourceRevision = $revisions->get($source->published_revision_id);
        foreach ([[$target, $targetRevision], [$source, $sourceRevision]] as [$article, $revision]) {
            $this->require($revision instanceof ArticleTranslationRevision && (int) $revision->org_id === 0
                && (int) $revision->article_id === (int) $article->id && $revision->locale === $article->locale
                && $revision->translation_group_id === $article->translation_group_id
                && $revision->isPubliclyReadableForArticle($article) && $revision->published_at !== null && ! $revision->published_at->isFuture()
                && $revision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED
                && $revision->source_locale === $source->locale && (int) $revision->source_article_id === (int) $source->id,
                'published_source_revision_invalid');
            foreach (['title', 'excerpt', 'content_md'] as $field) {
                $this->require($article->getRawOriginal($field) === $revision->getRawOriginal($field), 'published_source_projection_mismatch');
            }
        }
        $this->require(preg_match('/^[a-f0-9]{64}$/D', (string) $sourceRevision->source_version_hash) === 1
            && hash_equals((string) $source->source_version_hash, (string) $sourceRevision->source_version_hash), 'published_source_hash_invalid');

        return [$target, $source, $targetRevision, $sourceRevision];
    }

    private function pairHash(Article $target, Article $source, ArticleTranslationRevision $targetRevision, ArticleTranslationRevision $sourceRevision, bool $lock = false): string
    {
        return Hash::hash([
            'source' => ArticleSourceTargetSnapshot::publicationHash($source, $lock),
            'target' => ArticleSourceTargetSnapshot::publicationHash($target, $lock),
            'source_published' => $sourceRevision->getAttributes(),
            'target_published' => $targetRevision->getAttributes(),
        ]);
    }

    private function identity(Article $target, Article $source): string
    {
        return 'article:'.$target->id.':published:'.$target->published_revision_id.':source:'.$source->id.':published:'.$source->published_revision_id;
    }

    private function resources(Article $target, Article $source, ArticleTranslationRevision $targetRevision, ArticleTranslationRevision $sourceRevision): array
    {
        return [
            ['surface_id' => 'article', 'record' => $target], ['surface_id' => 'article', 'record' => $source],
            ['surface_id' => 'article_translation_revision', 'record' => $targetRevision], ['surface_id' => 'article_translation_revision', 'record' => $sourceRevision],
        ];
    }

    private function auditExists(Article $target, ReviewAttestation $record, string $hash): bool
    {
        return AuditLog::withoutGlobalScopes()->where('meta_json->resource_org_id', 0)->where('action', 'article_published_source_reviewed')
            ->where('target_type', 'article')->where('target_id', (string) $target->id)->where('result', 'success')
            ->where('actor_admin_id', $record->attested_by_admin_user_id)
            ->where('meta_json->attestation_id', (int) $record->id)->where('meta_json->pair_sha256', $hash)->exists();
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['source_reviewed' => $code]);
        }
    }
}
