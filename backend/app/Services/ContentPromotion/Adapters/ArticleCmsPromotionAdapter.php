<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentReleaseSnapshot;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\ContentPromotion\ArticleCmsPromotionAuthority;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\EqEnglishArticlePackage;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PromotionPhaseIdentity;
use App\Services\ContentPromotion\PromotionReceiptStore;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** @review-surface article */
final class ArticleCmsPromotionAdapter implements ExactPackagePromotionAdapter
{
    public function __construct(
        private readonly ArticleCmsPromotionAuthority $authority,
        private readonly PromotionRollbackSnapshotService $snapshots,
        private readonly ArticleMaterialDecisionService $materialDecisions,
        private readonly PromotionReceiptStore $receipts,
    ) {}

    public function id(): string
    {
        return 'w3_articles_article_cms_v2';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W3' && $subscope === ArticleCmsPromotionAuthority::CONTROL_SUBSCOPE;
    }

    public function preflight(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);

        return PromotionAdapterResultFactory::make($context, 0, count($package['targets']), 0, null, $this->zero()) + $this->eqEvidence($context);
    }

    public function draftImport(PromotionContext $context): array
    {
        $result = $this->isEqEnglishContext($context)
            ? DB::transaction(function () use ($context): array {
                $this->assertEqPreviousState($context, 'content_promotion_preflight_receipt');

                return $this->authority->importDraft($context);
            }, 3)
            : $this->authority->importDraft($context);

        // Candidate-only W3 rows have no English Article to snapshot until the
        // non-public draft is created. The publication snapshot remains the
        // sole rollback authority and is captured immediately before publish.
        return PromotionAdapterResultFactory::make($context, $result['created_count'], $result['readback_count'], 0, null, $this->zero(), [
            'created_count' => $result['created_count'], 'updated_count' => 0, 'unchanged_count' => $result['unchanged_count'],
        ]) + $this->eqEvidence($context);
    }

    public function publish(PromotionContext $context): array
    {
        if ($this->isEqEnglishContext($context)) {
            $result = DB::transaction(function () use ($context): array {
                $this->assertEqPreviousState($context, 'cms_draft_import_receipt');

                return $this->publishPackage($context);
            }, 3);
            try {
                $this->authority->invalidateDiscoverabilityCaches(false);
            } catch (Throwable $failure) {
                $this->rollback($context, $result['rollback_reference']);
                throw new DomainException('eq_english_cache_failed_rollback_succeeded', previous: $failure);
            }

            return $result;
        }

        return $this->publishPackage($context);
    }

    private function publishPackage(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $reference = $this->capture($context, $package, 'before_publication');
        $snapshot = $this->snapshots->resolve($context, $this->targets($package), 'article-cms', 'before_publication', $reference);
        $result = $this->authority->publish($context, (array) data_get($snapshot->meta_json, 'rows', []));

        return PromotionAdapterResultFactory::make($context, $result['changed_count'], $result['readback_count'], $result['readback_count'], $reference, $this->zero(), [
            'created_count' => 0, 'updated_count' => $result['changed_count'], 'unchanged_count' => $result['unchanged_count'],
        ]) + $this->eqEvidence($context);
    }

    public function liveQa(PromotionContext $context): array
    {
        $result = $this->authority->liveQa($context);

        return PromotionAdapterResultFactory::make($context, 0, $result['readback_count'], $result['readback_count'], null, $this->zero(), [
            'created_count' => 0, 'updated_count' => 0, 'unchanged_count' => $result['readback_count'],
        ]) + $this->eqEvidence($context);
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        if (preg_match('/\Acontent-release-snapshot:([1-9][0-9]*)\z/', $rollbackReference, $match) !== 1) {
            throw new DomainException('rollback_reference_invalid');
        }
        $candidate = ContentReleaseSnapshot::query()->find((int) $match[1]);
        $identities = $candidate instanceof ContentReleaseSnapshot ? (array) data_get($candidate->meta_json, 'target_identities', []) : [];
        $snapshot = $this->snapshots->resolve($context, PromotionTargetSet::fromIdentities($identities), 'article-cms', 'before_publication', $rollbackReference);
        if ($this->isEqEnglishContext($context)) {
            $this->assertEqSnapshot($context, $snapshot);
        }
        DB::transaction(function () use ($snapshot, $context, $rollbackReference): void {
            foreach ((array) data_get($snapshot->meta_json, 'rows', []) as $row) {
                if (! is_array($row) || (string) ($row['package_sha256'] ?? '') !== $context->packageSha256) {
                    throw new DomainException('article_promotion_rollback_row_invalid');
                }
                $article = Article::query()->withoutGlobalScopes()->lockForUpdate()->find((int) ($row['article_id'] ?? 0));
                $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->lockForUpdate()
                    ->where('authority_package_sha256', $context->packageSha256)
                    ->where('authority_asset_key', (string) ($row['asset_key'] ?? ''))->first();
                if ($article instanceof Article && $revision instanceof ArticleTranslationRevision && $this->isRestored($article, $revision, $row)) {
                    continue;
                }
                if (! $article instanceof Article || ! $revision instanceof ArticleTranslationRevision
                    || (int) $revision->article_id !== (int) $article->id
                    || (int) $article->published_revision_id !== (int) $revision->id
                    || $article->working_revision_id !== null) {
                    throw new DomainException('article_promotion_rollback_concurrent_publication');
                }
                $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->lockForUpdate()
                    ->where('org_id', $article->org_id)
                    ->where('article_id', $article->id)
                    ->where('locale', $article->locale)
                    ->first();
                if (($row['eq_english'] ?? false) === true) {
                    $this->assertEqProtectedState($article, $revision, $seo, $row);
                }
                $this->assertExpectedPublishedProjection($article, $revision, $seo, $row);
                foreach ((array) ($row['revision_statuses_before'] ?? []) as $revisionId => $status) {
                    if ((int) $revisionId === (int) $revision->id) {
                        continue;
                    }
                    $priorRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->lockForUpdate()->where('article_id', $article->id)->find((int) $revisionId);
                    $expectedStatus = (string) $status === ArticleTranslationRevision::STATUS_PUBLISHED ? ArticleTranslationRevision::STATUS_STALE : (string) $status;
                    if (! $priorRevision instanceof ArticleTranslationRevision || (string) $priorRevision->revision_status !== $expectedStatus) {
                        throw new DomainException('article_promotion_rollback_revision_drift');
                    }
                    $priorRevision->forceFill(['revision_status' => (string) $status])->saveQuietly();
                }
                $article->forceFill((array) ($row['article_before'] ?? []))->save();
                $seoBefore = (array) ($row['seo_before'] ?? []);
                if ((int) ($seoBefore['id'] ?? 0) > 0) {
                    if (! $seo instanceof ArticleSeoMeta || (int) $seo->article_id !== (int) $article->id) {
                        throw new DomainException('article_promotion_rollback_seo_meta_invalid');
                    }
                    $seo->forceFill((array) ($seoBefore['values'] ?? []))->saveQuietly();
                }
                $revision->forceFill([
                    'revision_status' => (string) ($row['package_revision_status_before'] ?? ArticleTranslationRevision::STATUS_APPROVED),
                    'published_at' => $row['package_revision_published_at_before'] ?? null,
                ])->saveQuietly();

                if ((string) $article->status === 'published' && (bool) $article->is_public) {
                    $restoredRevision = ArticleTranslationRevision::query()
                        ->withoutGlobalScopes()
                        ->where('article_id', $article->id)
                        ->lockForUpdate()
                        ->find((int) $article->published_revision_id);
                    if (! $restoredRevision instanceof ArticleTranslationRevision) {
                        throw new DomainException('article_promotion_rollback_revision_missing');
                    }
                    $this->materialDecisions->recordPublished(
                        $article,
                        $restoredRevision,
                        now(),
                        'rollback',
                        $rollbackReference,
                    );
                } else {
                    $this->materialDecisions->recordUnpublished(
                        $article,
                        now(),
                        $rollbackReference,
                    );
                }
            }
        }, 3);
        // A failed live-QA rollback must never retain a generation that exposed
        // these candidate rows while they were briefly public.
        $this->authority->invalidateDiscoverabilityCaches(false);
    }

    /** @param array{targets:list<array<string,mixed>>} $package */
    private function capture(PromotionContext $context, array $package, string $phase): string
    {
        $targets = $this->targets($package);
        if ($phase === 'before_publication') {
            $phaseKey = PromotionPhaseIdentity::idempotencyKey($context, $phase, $targets);
            $existing = ContentReleaseSnapshot::query()
                ->where('pack_id', 'article-cms')
                ->where('reason', 'content_promotion_before_publication')
                ->orderBy('id')
                ->get()
                ->first(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'phase_idempotency_key') === $phaseKey
                    && data_get($snapshot->meta_json, 'target_fingerprint') === $targets->fingerprint());
            if ($existing instanceof ContentReleaseSnapshot) {
                if ($this->isEqEnglishContext($context)) {
                    $this->assertEqSnapshot($context, $existing);
                }

                return 'content-release-snapshot:'.$existing->id;
            }
        }
        $rows = [];
        foreach ($package['targets'] as $target) {
            /** @var Article $article */
            $article = $target['article'];
            $statuses = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)
                ->whereIn('id', array_filter([(int) $article->published_revision_id, (int) $article->working_revision_id]))->pluck('revision_status', 'id')->all();
            $packageRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->where('authority_package_sha256', $context->packageSha256)
                ->where('authority_asset_key', $target['asset_key'])
                ->first();
            $seo = ArticleSeoMeta::query()->withoutGlobalScopes()
                ->where('org_id', $article->org_id)
                ->where('article_id', $article->id)
                ->where('locale', $article->locale)
                ->first();
            $publicationTimestamp = now()->startOfSecond()->toISOString();
            $rows[] = [
                ...($this->isEqEnglishContext($context) ? [
                    'eq_english' => true, 'protected_article_before' => $article->getAttributes(),
                    'protected_revision_before' => $packageRevision?->getAttributes(),
                    'protected_seo_before' => $seo?->getAttributes(),
                ] : []),
                'article_id' => $article->id, 'asset_key' => $target['asset_key'], 'package_sha256' => $context->packageSha256,
                'article_before' => $this->articleState($article), 'revision_statuses_before' => $statuses,
                'seo_before' => $seo instanceof ArticleSeoMeta ? ['id' => $seo->id, 'values' => $this->seoState($seo)] : [],
                'publication_timestamp' => $publicationTimestamp,
                'expected_public_projection' => $this->expectedPublishedProjection($article, $packageRevision, $target, $seo, $publicationTimestamp),
                'package_revision_status_before' => $packageRevision?->revision_status ?? ArticleTranslationRevision::STATUS_APPROVED,
                'package_revision_published_at_before' => $packageRevision?->published_at?->toISOString(),
            ];
        }

        return $this->snapshots->capture($context, $targets, 'article-cms', $phase, $rows, $targets->identities(), $this->isEqEnglishContext($context) ? [
            'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
            'executor_release_sha256' => $context->executorReleaseSha256,
        ] : []);
    }

    public function isEqEnglishContext(PromotionContext $context): bool
    {
        return $this->authority->isEqEnglishContext($context);
    }

    private function eqEvidence(PromotionContext $context): array
    {
        return $this->isEqEnglishContext($context) ? ['target_state_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($this->authority->eqEnglishState($context)))] : [];
    }

    private function assertEqPreviousState(PromotionContext $context, string $kind): void
    {
        $previous = $this->receipts->readPrevious($kind, $context)['receipt'];
        if (data_get($previous, 'workflow_run_id') !== $context->workflowRunId
            || data_get($previous, 'workflow_run_attempt') !== $context->workflowRunAttempt
            || data_get($previous, 'executor_release_sha256') !== $context->executorReleaseSha256) {
            throw new DomainException('eq_english_previous_workflow_mismatch');
        }
        $actual = hash('sha256', PromotionContextFactory::canonicalJson($this->authority->eqEnglishState($context, true)));
        if (! hash_equals($actual, (string) ($previous['target_state_sha256'] ?? ''))) {
            throw new DomainException('eq_english_prestate_drift');
        }
    }

    private function assertEqSnapshot(PromotionContext $context, ContentReleaseSnapshot $snapshot): void
    {
        $package = app(EqEnglishArticlePackage::class)->read($context);
        $targets = PromotionTargetSet::fromIdentities(array_column($package['candidates'], 'identity'));
        if (data_get($snapshot->meta_json, 'target_fingerprint') !== $targets->fingerprint()
            || data_get($snapshot->meta_json, 'workflow_run_id') !== $context->workflowRunId
            || data_get($snapshot->meta_json, 'workflow_run_attempt') !== $context->workflowRunAttempt
            || data_get($snapshot->meta_json, 'executor_release_sha256') !== $context->executorReleaseSha256) {
            throw new DomainException('eq_english_recovery_scope_mismatch');
        }
    }

    public function recoverFailedPublication(PromotionContext $context): bool
    {
        $package = app(EqEnglishArticlePackage::class)->read($context);
        $snapshots = ContentReleaseSnapshot::query()->where('pack_id', 'article-cms')
            ->where('reason', 'content_promotion_before_publication')->get()
            ->filter(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'source_commit') === $context->sourceCommit
                && data_get($snapshot->meta_json, 'package_sha256') === $context->packageSha256
                && data_get($snapshot->meta_json, 'workflow_run_id') === $context->workflowRunId
                && data_get($snapshot->meta_json, 'workflow_run_attempt') === $context->workflowRunAttempt
                && data_get($snapshot->meta_json, 'executor_release_sha256') === $context->executorReleaseSha256);
        if ($snapshots->count() > 1) {
            throw new DomainException('eq_english_recovery_snapshot_ambiguous');
        }
        if ($snapshots->isEmpty()) {
            foreach ($package['candidates'] as $row) {
                $article = Article::query()->withoutGlobalScopes()->withTrashed()->where($row['identity'])->first();
                if ($article?->is_public && $article->publishedRevision?->authority_package_sha256 === $context->packageSha256) {
                    throw new DomainException('eq_english_recovery_snapshot_missing');
                }
            }

            return false;
        }
        $this->rollback($context, 'content-release-snapshot:'.$snapshots->first()->id);

        return true;
    }

    private function assertEqProtectedState(Article $article, ArticleTranslationRevision $revision, ?ArticleSeoMeta $seo, array $row): void
    {
        foreach ([
            [$article->getAttributes(), $row['protected_article_before'] ?? [], ['status', 'is_public', 'published_at', 'published_revision_id', 'working_revision_id', 'translation_status', 'updated_at']],
            [$revision->getAttributes(), $row['protected_revision_before'] ?? [], ['revision_status', 'published_at', 'updated_at']],
            [$seo?->getAttributes() ?? [], $row['protected_seo_before'] ?? [], ['updated_at']],
        ] as [$actual, $before, $mutable]) {
            foreach ($mutable as $field) {
                unset($actual[$field], $before[$field]);
            }
            if (PromotionContextFactory::canonicalJson($actual) !== PromotionContextFactory::canonicalJson($before)) {
                throw new DomainException('eq_english_rollback_concurrent_change');
            }
        }
    }

    /** @param array{targets:list<array<string,mixed>>} $package */
    private function targets(array $package): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_map(static fn (array $target): array => $target['identity'], $package['targets']));
    }

    /** @return array<string,mixed> */
    private function articleState(Article $article): array
    {
        $state = [];
        foreach (['title', 'excerpt', 'content_md', 'content_html', 'working_revision_id', 'published_revision_id', 'translation_status', 'status', 'is_public', 'is_indexable', 'sitemap_eligible', 'llms_eligible', 'published_at', 'source_version_hash'] as $field) {
            $state[$field] = $article->getAttribute($field);
        }

        return $state;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function expectedPublishedProjection(Article $article, ?ArticleTranslationRevision $revision, array $target, ?ArticleSeoMeta $seo, string $publicationTimestamp): array
    {
        $snapshot = (array) $target['snapshot'];
        $candidate = $article->replicate();
        $candidate->forceFill(['title' => $snapshot['title'], 'excerpt' => $snapshot['excerpt'], 'content_md' => $snapshot['content_md'], 'content_html' => null]);
        $seoValues = $seo instanceof ArticleSeoMeta ? $this->seoState($seo) : [];
        if ($seo instanceof ArticleSeoMeta && ! ($target['candidate_only'] ?? false)) {
            $seoValues = ['seo_title' => $snapshot['seo_title'], 'seo_description' => $snapshot['seo_description'], 'og_title' => $snapshot['seo_title'], 'og_description' => $snapshot['seo_description']];
        }

        $projection = ['title' => $snapshot['title'], 'excerpt' => $snapshot['excerpt'], 'content_md' => $snapshot['content_md'], 'content_html' => null, 'source_version_hash' => $candidate->computeSourceVersionHash(), 'published_revision_id' => $revision?->id, 'working_revision_id' => null];
        if (($target['candidate_only'] ?? false) === true) {
            $projection += ['translation_status' => Article::TRANSLATION_STATUS_PUBLISHED, 'status' => 'published', 'is_public' => true, 'published_at' => $publicationTimestamp];
        }

        return ['article' => array_replace($this->articleProjection($article), $projection), 'revision' => ['id' => $revision?->id, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'published_at' => $publicationTimestamp], 'seo' => $seoValues];
    }

    /** @param array<string,mixed> $row */
    private function assertExpectedPublishedProjection(Article $article, ArticleTranslationRevision $revision, ?ArticleSeoMeta $seo, array $row): void
    {
        $actual = ['article' => $this->articleProjection($article), 'revision' => ['id' => $revision->id, 'revision_status' => $revision->revision_status, 'published_at' => $revision->published_at?->toISOString()], 'seo' => $seo instanceof ArticleSeoMeta ? $this->seoState($seo) : []];
        if (! hash_equals(PromotionContextFactory::canonicalJson((array) ($row['expected_public_projection'] ?? [])), PromotionContextFactory::canonicalJson($actual))) {
            throw new DomainException('article_promotion_rollback_public_projection_drift');
        }
    }

    /** @return array<string,mixed> */
    private function articleProjection(Article $article): array
    {
        return [
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $article->content_md,
            'content_html' => $article->content_html,
            'working_revision_id' => $article->working_revision_id,
            'published_revision_id' => $article->published_revision_id,
            'translation_status' => $article->translation_status,
            'status' => $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_at' => $article->published_at?->toISOString(),
            'source_version_hash' => $article->source_version_hash,
        ];
    }

    /** @param array<string,mixed> $row */
    private function isRestored(Article $article, ArticleTranslationRevision $revision, array $row): bool
    {
        $before = (array) ($row['article_before'] ?? []);
        $restored = (int) $article->published_revision_id === (int) ($before['published_revision_id'] ?? 0)
            && (int) $article->working_revision_id === (int) ($before['working_revision_id'] ?? 0)
            && (string) $revision->revision_status === (string) ($row['package_revision_status_before'] ?? ArticleTranslationRevision::STATUS_APPROVED);
        if ($restored && ($row['eq_english'] ?? false)) {
            if (PromotionContextFactory::canonicalJson($this->articleState($article)) !== PromotionContextFactory::canonicalJson($before)) {
                throw new DomainException('eq_english_rollback_concurrent_change');
            }
            $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $article->id)->where('locale', 'en')->lockForUpdate()->first();
            $this->assertEqProtectedState($article, $revision, $seo, $row);
        }

        return $restored;
    }

    /** @return array<string,mixed> */
    private function seoState(ArticleSeoMeta $seo): array
    {
        $state = [];
        foreach (['seo_title', 'seo_description', 'og_title', 'og_description'] as $field) {
            $state[$field] = $seo->getAttribute($field);
        }

        return $state;
    }

    /** @return array<string,int> */
    private function zero(): array
    {
        return ['indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0];
    }
}
