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

    /** A published pair can need a current review even when its historical hashes match. */
    public function needsCurrentReview(Article $selected): bool
    {
        try {
            $this->pair($selected);

            return ! $this->hasCurrentReview($selected);
        } catch (Throwable) {
            return false;
        }
    }

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

    /** Review an unpublished independent adaptation against an explicit public source, never its private working draft. */
    public function prepareIndependent(Article $selected): array
    {
        $this->authorize();

        return DB::transaction(function () use ($selected): array {
            [$target, $source, $revision, $published] = $this->independentPair($selected, true);
            $this->require($target->published_revision_id === null
                && $revision->revision_status === ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                'independent_source_review_not_available');

            return ['pair_hash' => $this->independentHash($target, $source, $revision, $published, true),
                'source_reviewed' => false, 'review_notes' => '',
                'review_context' => json_encode([
                    'review_mode' => 'independent_editorial_adaptation',
                    'source_article_id' => (int) $source->id,
                    'source_published_revision_id' => (int) $published->id,
                    'source_unpublished_working_revision_id' => $source->working_revision_id !== $published->id ? $source->working_revision_id : null,
                    'source_preview' => ArticleWorkspace::publishedSourcePreviewUrl($source),
                    'target_article_id' => (int) $target->id,
                    'target_working_revision_id' => (int) $revision->id,
                    'target_preview' => ArticleWorkspace::previewUrl($target, (int) $revision->id),
                    'historical_source_hash_retained' => $revision->translated_from_version_hash,
                    'private_source_draft_reviewed_or_approved' => false,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        });
    }

    public function confirmIndependent(Article $selected, array $data): void
    {
        $this->authorize();
        $notes = trim((string) ($data['review_notes'] ?? ''));
        $this->require(($data['source_reviewed'] ?? false) === true && mb_strlen($notes) >= 20 && mb_strlen($notes) <= 6000,
            'independent_source_review_and_notes_required');
        DB::transaction(function () use ($selected, $data, $notes): void {
            $this->require(\App\Support\SchemaBaseline::hasTable('audit_logs'), 'independent_source_audit_unavailable');
            [$target, $source, $revision, $published] = $this->independentPair($selected, true);
            $this->require($target->published_revision_id === null && $revision->revision_status === ArticleTranslationRevision::STATUS_HUMAN_REVIEW
                && $revision->approved_at === null && $revision->published_at === null && $revision->reviewed_by === null,
                'independent_source_review_not_available');
            $hash = $this->independentHash($target, $source, $revision, $published, true);
            $this->require(hash_equals($hash, (string) ($data['pair_hash'] ?? '')), 'source_or_target_changed');
            $actor = (int) auth((string) config('admin.guard', 'admin'))->id();
            $attestation = app(CmsEditorialReviewAttestationService::class)->bindOrCreateApproved(null,
                'cms_article_independent_source_review', $this->independentIdentity($target, $source),
                $this->resources($target, $source, $revision, $published), $actor, $hash);
            $request = Request::create('/ops/articles/independent-source-review', 'POST');
            $request->attributes->set('org_id', 0);
            app(AuditLogger::class)->log($request, 'article_independent_source_reviewed', 'article', (string) $target->id, [
                'resource_org_id' => 0, 'attestation_id' => (int) $attestation->id, 'pair_sha256' => $hash,
                'source_article_id' => (int) $source->id, 'source_published_revision_id' => (int) $published->id,
                'source_working_revision_id' => (int) $source->working_revision_id, 'target_working_revision_id' => (int) $revision->id,
                'review_notes' => $notes, 'review_mode' => 'independent_editorial_adaptation',
                'historical_source_hash_rewritten' => false, 'private_source_draft_approved' => false, 'published' => false,
            ], reason: 'independent_editorial_public_source_review', result: 'success');
            $this->require($this->hasCurrentIndependentReview($target), 'independent_source_review_readback_failed');
        });
    }

    /** Exact review remains valid through the target's normal approve/publish outputs, not through edits. */
    public function hasCurrentIndependentReview(Article $selected): bool
    {
        try {
            [$target, $source, $revision, $published] = $this->independentPair($selected);
            $hash = $this->independentHash($target, $source, $revision, $published);
            $record = ReviewAttestation::query()->where('scope_type', 'cms_article_independent_source_review')
                ->where('scope_identity', $this->independentIdentity($target, $source))->where('package_sha256', $hash)
                ->where('review_mode', 'solo_owner')->where('review_source', (string) config('review_governance.attestation.review_source'))
                ->where('decision', 'approved_all')->where('target_count', 4)
                ->where('attested_by_admin_user_id', (int) config('review_governance.solo_owner_admin_user_id'))->latest('id')->first();
            if (! $record || ! AuditLog::withoutGlobalScopes()->where('action', 'article_independent_source_reviewed')
                ->where('target_type', 'article')->where('target_id', (string) $target->id)->where('result', 'success')
                ->where('actor_admin_id', $record->attested_by_admin_user_id)->where('meta_json->resource_org_id', 0)
                ->where('meta_json->attestation_id', (int) $record->id)->where('meta_json->pair_sha256', $hash)->exists()) {
                return false;
            }
            $adapter = app(CmsEditorialReviewAttestationService::class);
            $resources = $this->resources($target, $source, $revision, $published);
            $adapter->preflight($record->canonical_evidence_json, $resources, $hash);
            if ($record->targetEvidences->count() !== 4) {
                return false;
            }
            foreach ($adapter->targets($resources) as $expected) {
                if ($record->targetEvidences->where('target_identity', $expected['target_identity'])
                    ->where('target_sha256', $expected['target_sha256'])->where('target_decision', 'approved')->count() !== 1) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function independentPair(Article $selected, bool $lock = false): array
    {
        $initial = Article::withoutGlobalScopes()->findOrFail($selected->getKey());
        $rows = Article::withoutGlobalScopes()->whereIn('id', [$initial->id, $initial->source_article_id])->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $target = $rows->get($initial->id);
        $source = $rows->get($target?->source_article_id);
        $this->require($target instanceof Article && $source instanceof Article && $source->isSourceArticle() && ! $target->isSourceArticle()
            && (int) $target->org_id === 0 && (int) $source->org_id === 0 && ! $target->trashed() && ! $source->trashed()
            && in_array($source->locale, ['en', 'zh-CN'], true) && in_array($target->locale, ['en', 'zh-CN'], true)
            && $source->locale !== $target->locale && $target->source_locale === $source->locale
            && (int) $target->translated_from_article_id === (int) $source->id && filled($source->translation_group_id)
            && $target->translation_group_id === $source->translation_group_id
            && $source->status === 'published' && $source->is_public && $source->published_at !== null && ! $source->published_at->isFuture()
            && $source->working_revision_id !== null && $source->published_revision_id !== null
            && (($target->published_revision_id === null && $target->status === 'draft' && ! $target->is_public)
                || ($target->working_revision_id === $target->published_revision_id && $target->status === 'published'
                    && $target->is_public && $target->published_at !== null && ! $target->published_at->isFuture())),
            'independent_source_pair_invalid');
        foreach ([$source, $target] as $article) {
            $this->require(! in_array($article->lifecycle_state, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true),
                'independent_source_lifecycle_invalid');
        }
        $this->require(\App\Models\ArticleCategory::withoutGlobalScopes()->whereKey($target->category_id)->where('org_id', 0)->exists()
            && ! DB::table('article_tag_map as links')->leftJoin('article_tags as tags', 'tags.id', '=', 'links.tag_id')
                ->where('links.article_id', $target->id)->where(function ($query): void {
                    $query->where('links.org_id', '!=', 0)->orWhereNull('links.org_id')->orWhereNull('tags.id')
                        ->orWhere('tags.org_id', '!=', 0)->orWhereNull('tags.org_id');
                })->exists(), 'independent_source_taxonomy_invalid');
        $revisions = ArticleTranslationRevision::withoutGlobalScopes()->whereIn('id', [$target->working_revision_id, $source->published_revision_id, $source->working_revision_id])
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $revision = $revisions->get($target->working_revision_id);
        $published = $revisions->get($source->published_revision_id);
        $working = $revisions->get($source->working_revision_id);
        foreach ([[$target, $revision], [$source, $published], [$source, $working]] as [$article, $r]) {
            $this->require($r instanceof ArticleTranslationRevision && (int) $r->org_id === 0 && (int) $r->article_id === (int) $article->id
                && $r->locale === $article->locale && $r->source_locale === $source->locale
                && (int) $r->source_article_id === (int) $source->id && $r->translation_group_id === $source->translation_group_id,
                'independent_source_revision_invalid');
        }
        $this->require(in_array($revision->revision_status, [ArticleTranslationRevision::STATUS_HUMAN_REVIEW, ArticleTranslationRevision::STATUS_APPROVED, ArticleTranslationRevision::STATUS_PUBLISHED], true)
            && in_array($published->revision_status, [ArticleTranslationRevision::STATUS_SOURCE, ArticleTranslationRevision::STATUS_PUBLISHED], true)
            && preg_match('/^[a-f0-9]{64}$/D', (string) $published->source_version_hash) === 1,
            'independent_source_revision_invalid');
        foreach ([[$source, $published], [$target, $revision]] as [$article, $r]) {
            foreach (['title', 'excerpt', 'content_md'] as $field) {
                $this->require($article->getRawOriginal($field) === $r->getRawOriginal($field), 'independent_source_projection_mismatch');
            }
        }

        // publishTranslation projects the revision SEO into both search and OG fields.
        // Refuse a different reviewed OG now, before approval/publication can overwrite it.
        $seo = \App\Models\ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $target->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $this->require($seo->count() === 1, 'independent_source_publication_projection_mismatch');
        foreach (['title', 'description'] as $field) {
            $value = $revision->{'seo_'.$field};
            if (filled($value)) {
                $this->require($seo->first()->{'seo_'.$field} === $value && $seo->first()->{'og_'.$field} === $value,
                    'independent_source_publication_projection_mismatch');
            }
        }

        return [$target, $source, $revision, $published];
    }

    private function independentIdentity(Article $target, Article $source): string
    {
        return 'article:'.$target->id.':revision:'.$target->working_revision_id.':source:'.$source->id.':published:'.$source->published_revision_id;
    }

    private function independentHash(Article $target, Article $source, ArticleTranslationRevision $revision, ArticleTranslationRevision $published, bool $lock = false): string
    {
        $seo = \App\Models\ArticleSeoMeta::withoutGlobalScopes()->where('article_id', $target->id)->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(function ($row) use ($target): array {
                $this->require((int) $row->org_id === 0 && $row->locale === $target->locale, 'independent_source_seo_invalid');

                // These are outputs of the subsequent native publication/schema release only.
                $attributes = array_diff_key($row->getAttributes(), array_fill_keys(['updated_at', 'robots', 'is_indexable'], true));
                $schema = $row->schema_json;
                if (is_array($schema) && is_array($schema['editorial_package_v1'] ?? null)) {
                    $schema['editorial_package_v1'] = array_diff_key($schema['editorial_package_v1'], array_fill_keys([
                        'article_schema_enabled', 'breadcrumb_schema_enabled', 'faq_schema_enabled', 'schema_hold', 'hreflang_gate',
                    ], true));
                    if ($schema['editorial_package_v1'] === []) {
                        unset($schema['editorial_package_v1']);
                    }
                }
                $attributes['schema_json'] = $schema === [] ? null : $schema;

                return $attributes;
            })->all();

        return Hash::hash(['source' => \App\Console\Commands\ArticleForkPrivateTranslationLinks::sourceHash($source, $lock),
            'source_tags' => ArticleSourceTargetSnapshot::tagsHash($source, $lock),
            'source_working_revision_id' => $source->working_revision_id, 'source_published_revision_id' => $source->published_revision_id,
            'target_working_revision_id' => $target->working_revision_id,
            'resources' => app(CmsEditorialReviewAttestationService::class)->targets($this->resources($target, $source, $revision, $published)),
            'target_seo' => $seo, 'target_tags' => ArticleSourceTargetSnapshot::tagsHash($target, $lock),
            'target_category' => \App\Models\ArticleCategory::withoutGlobalScopes()->whereKey($target->category_id)
                ->when($lock, fn ($q) => $q->lockForUpdate())->first()?->getAttributes(),
            'target_tag_records' => DB::table('article_tags')->whereIn('id', DB::table('article_tag_map')->where('article_id', $target->id)->select('tag_id'))
                ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(fn ($row): array => (array) $row)->all(),
            'target_test_edges' => DB::table('article_test_edges')->where('article_id', $target->id)->orderBy('id')
                ->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(fn ($row): array => (array) $row)->all(),
        ]);
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
            && (int) $target->translated_from_article_id === (int) $source->id,
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

        if ($target->slug !== $source->slug) {
            $this->require($this->hasPublishedLocalizedPair($target, $source), 'published_source_pair_invalid');
        }

        return [$target, $source, $targetRevision, $sourceRevision];
    }

    private function hasPublishedLocalizedPair(Article $target, Article $source): bool
    {
        $counterparts = app(ArticleReviewedCounterpartService::class);
        $ids = Article::withoutGlobalScopes()->where('org_id', 0)
            ->where('translation_group_id', $source->translation_group_id)
            ->whereIn('locale', [$source->locale, $target->locale])
            ->publiclyIndexable()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $expected = [(int) $source->id, (int) $target->id];
        sort($expected, SORT_NUMERIC);
        if ($ids !== $expected) {
            return false;
        }
        foreach ([$source, $target] as $article) {
            if (! $counterparts->eligible($article)
                || data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled') !== true
                || data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_hold')) {
                return false;
            }
            $binding = $counterparts->resolve($article);
            if ($binding !== null && array_map(fn (Article $member): int => (int) $member->id, $binding) !== $expected) {
                return false;
            }
        }

        return true;
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
