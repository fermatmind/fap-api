<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentReleaseSnapshot;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\EqPublicArticlePackage;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PromotionReceiptStore;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** @review-surface article */
final class EqNewSourceArticlePromotionAdapter implements ExactPackagePromotionAdapter
{
    public const SUBSCOPE = 'EQ-NEW-SOURCE-ARTICLES';

    private const PACK = 'eq-new-source-articles';

    public function __construct(
        private readonly EqPublicArticlePackage $packages,
        private readonly PromotionReceiptStore $receipts,
        private readonly PromotionRollbackSnapshotService $snapshots,
        private readonly ArticleMaterialDecisionService $decisions,
        private readonly SeoDiscoverabilityCacheInvalidator $cache,
        private readonly ArticleController $publicApi,
    ) {}

    public function id(): string
    {
        return 'eq_new_source_articles_v1';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W3' && $subscope === self::SUBSCOPE;
    }

    public function preflight(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $state = $this->state($rows, false);
        foreach ($rows as $row) {
            $this->assertOwnedDraft($row, $state[$this->key($row)], $context);
        }

        return $this->result($context, 0, 0, null, $this->hash($state));
    }

    public function draftImport(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->receipts->readPrevious('content_promotion_preflight_receipt', $context);
        $written = 0;
        $state = DB::transaction(function () use ($rows, $context, $previous, &$written): array {
            $before = $this->state($rows, true);
            $this->assertReceiptState($previous, $before, $context);
            foreach ($rows as $row) {
                $this->assertOwnedDraft($row, $before[$this->key($row)], $context);
                $article = $this->article($row, true);
                if ($article && (string) $article->workingRevision?->authority_package_sha256 === $context->packageSha256) {
                    $this->assertProjection($row, $article, $context, false);

                    continue;
                }
                if (! $article) {
                    $article = Article::query()->withoutGlobalScopes()->create([
                        ...$row['identity'], 'source_locale' => 'zh-CN',
                        'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                        'author_name' => 'FermatMind',
                        'title' => $row['snapshot']['title'], 'excerpt' => $row['snapshot']['excerpt'],
                        'content_md' => $row['snapshot']['content_md'], 'status' => 'draft',
                        'is_public' => false, 'is_indexable' => false,
                        'sitemap_eligible' => false, 'llms_eligible' => false,
                    ]);
                }
                $snapshot = $row['snapshot'];
                $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                    'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
                    'translation_group_id' => $article->translation_group_id,
                    'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
                    'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number')) + 1,
                    'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                    'source_version_hash' => $article->computeSourceVersionHash(),
                    'supersedes_revision_id' => $article->working_revision_id,
                    'authority_asset_key' => $this->key($row),
                    'authority_source_package' => EqPublicArticlePackage::PACKAGE,
                    'authority_source_hash' => $this->hash($snapshot),
                    'authority_package_sha256' => $context->packageSha256,
                    'authority_metadata_json' => [
                        'snapshot' => $snapshot, 'approval_kind' => 'verified_automated_exact_package',
                        'independent_review_input' => $row['independent_review_input'],
                        'independent_review_output' => $row['independent_review_output'],
                        'source_commit' => $context->sourceCommit,
                    ],
                    ...$snapshot,
                    // This is automated package approval, not human review.
                    // No human actor, attestation, reviewed_at or MFA is invented.
                    'approved_at' => now(),
                ]);
                $article->forceFill(['working_revision_id' => $revision->id])->saveQuietly();
                $seo = $article->seoMeta()->first();
                if (! $seo) {
                    ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                        'org_id' => 0, 'article_id' => $article->id, 'locale' => 'zh-CN',
                        'seo_title' => $snapshot['seo_title'], 'seo_description' => $snapshot['seo_description'],
                        'is_indexable' => false,
                    ]);
                }
                $this->assertProjection($row, $article->fresh(), $context, false);
                $written++;
            }

            return $this->state($rows, true);
        }, 3);

        return $this->result($context, $written, 0, null, $this->hash($state));
    }

    public function publish(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->receipts->readPrevious('cms_draft_import_receipt', $context);
        $reference = null;
        $state = DB::transaction(function () use ($rows, $context, $previous, &$reference): array {
            $before = $this->state($rows, true);
            $this->assertReceiptState($previous, $before, $context);
            $reference = $this->snapshots->capture($context, $this->targets($rows), self::PACK, 'before_publication', array_values($before), array_column($rows, 'identity'), [
                'workflow_run_id' => $context->workflowRunId,
                'workflow_run_attempt' => $context->workflowRunAttempt,
                'executor_release_sha256' => $context->executorReleaseSha256,
            ]);
            foreach ($rows as $row) {
                $article = $this->article($row, true);
                $this->assertProjection($row, $article, $context, false);
                $revision = $article->workingRevision;
                $publishedAt = now();
                $revision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'published_at' => $publishedAt])->saveQuietly();
                $article->forceFill([
                    'status' => 'published', 'is_public' => true, 'published_at' => $publishedAt,
                    'published_revision_id' => $revision->id,
                ])->save();
                // Keep the exact source working=published relationship required
                // by the existing prepared-English-draft importer.
                $this->decisions->recordPublished($article, $revision, $publishedAt);
                ContentReleaseAudit::log('article', $article, self::PACK, false);
                $this->assertProjection($row, $article->fresh(), $context, true);
            }

            return $this->state($rows, true);
        }, 3);
        try {
            $this->cache->flushArticleDiscoverabilityCaches(false);
        } catch (Throwable $error) {
            $this->rollback($context, (string) $reference);
            throw new DomainException('eq_source_post_publish_failed_rollback_succeeded', previous: $error);
        }

        return $this->result($context, 3, 3, $reference, $this->hash($state));
    }

    public function liveQa(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        foreach ($rows as $row) {
            $this->assertProjection($row, $this->article($row, false), $context, true);
            $response = $this->publicApi->show(Request::create('/api/v0.5/articles/'.$row['identity']['slug'], 'GET', ['locale' => 'zh-CN', 'org_id' => 0]), $row['identity']['slug']);
            $payload = $response->getData(true);
            if ($response->getStatusCode() !== 200 || ($payload['ok'] ?? null) !== true) {
                throw new DomainException('eq_source_public_api_unavailable');
            }
            foreach (['title', 'excerpt', 'content_md'] as $field) {
                if (data_get($payload, 'article.'.$field) !== $row['snapshot'][$field]) {
                    throw new DomainException('eq_source_public_api_payload_drift');
                }
            }
            if (data_get($payload, 'seo_surface_v1.title') !== $row['snapshot']['seo_title']
                || data_get($payload, 'seo_surface_v1.description') !== $row['snapshot']['seo_description']) {
                throw new DomainException('eq_source_public_api_metadata_drift');
            }
        }

        return $this->result($context, 0, 3, null, $this->hash($this->state($rows, false)));
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $rows = $this->rows($context);
        $snapshot = $this->snapshots->resolve($context, $this->targets($rows), self::PACK, 'before_publication', $rollbackReference);
        if (data_get($snapshot->meta_json, 'workflow_run_id') !== $context->workflowRunId
            || data_get($snapshot->meta_json, 'workflow_run_attempt') !== $context->workflowRunAttempt
            || data_get($snapshot->meta_json, 'executor_release_sha256') !== $context->executorReleaseSha256) {
            throw new DomainException('eq_source_recovery_workflow_mismatch');
        }
        DB::transaction(function () use ($snapshot, $rows, $context, $rollbackReference): void {
            $before = array_values((array) data_get($snapshot->meta_json, 'rows', []));
            foreach ($rows as $index => $row) {
                $article = $this->article($row, true);
                $saved = $before[$index] ?? null;
                if (! is_array($saved) || ! $article) {
                    throw new DomainException('eq_source_rollback_identity_invalid');
                }
                $actual = $this->state([$row], true)[$this->key($row)];
                // A previous failure handler may already have restored the exact
                // snapshot. Retry invalidation without rewriting business rows.
                if ($this->hash($actual) === $this->hash($saved)) {
                    continue;
                }
                $this->assertProjection($row, $article, $context, true);
                if ((int) ($saved['article']['id'] ?? 0) !== (int) $article->id) {
                    throw new DomainException('eq_source_rollback_identity_invalid');
                }
                // Refuse changes to protected fields, including indexability,
                // source/group identity and non-package working revisions.
                $protected = $article->getAttributes();
                $original = $saved['article'];
                foreach (['status', 'is_public', 'published_at', 'published_revision_id', 'updated_at'] as $field) {
                    unset($protected[$field], $original[$field]);
                }
                if ($this->hash($protected) !== $this->hash($original)) {
                    throw new DomainException('eq_source_rollback_concurrent_change');
                }
                $currentRevision = $article->workingRevision->getAttributes();
                $oldRevision = $saved['revision'];
                foreach (['revision_status', 'published_at', 'updated_at'] as $field) {
                    unset($currentRevision[$field], $oldRevision[$field]);
                }
                $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $article->id)->where('locale', 'zh-CN')->lockForUpdate()->first();
                if ($this->hash($currentRevision) !== $this->hash($oldRevision)
                    || $this->hash($seo?->getAttributes() ?? []) !== $this->hash($saved['seo'] ?? [])) {
                    throw new DomainException('eq_source_rollback_concurrent_change');
                }
                ArticleTranslationRevision::withoutTimestamps(function () use ($article, $saved): void {
                    $article->workingRevision->setRawAttributes($saved['revision']);
                    $article->workingRevision->saveQuietly();
                });
                Article::withoutTimestamps(function () use ($article, $saved): void {
                    $article->setRawAttributes($saved['article']);
                    $article->saveQuietly();
                });
                $this->decisions->recordUnpublished($article, now(), $rollbackReference);
            }
        }, 3);
        $this->cache->flushArticleDiscoverabilityCaches(false);
    }

    /** Recover only the immutable snapshot created by this exact execution. */
    public function recoverFailedPublication(PromotionContext $context): bool
    {
        $rows = $this->rows($context);
        $snapshots = ContentReleaseSnapshot::query()
            ->where('pack_id', self::PACK)
            ->where('reason', 'content_promotion_before_publication')
            ->get()->filter(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'source_commit') === $context->sourceCommit
                && data_get($snapshot->meta_json, 'package_sha256') === $context->packageSha256
                && data_get($snapshot->meta_json, 'idempotency_key') === $context->idempotencyKey
                && data_get($snapshot->meta_json, 'workflow_run_id') === $context->workflowRunId
                && data_get($snapshot->meta_json, 'workflow_run_attempt') === $context->workflowRunAttempt);
        if ($snapshots->count() > 1) {
            throw new DomainException('eq_source_recovery_snapshot_ambiguous');
        }
        if ($snapshots->isEmpty()) {
            foreach ($rows as $row) {
                $article = $this->article($row, false);
                if ($article?->is_public && $article->workingRevision?->authority_package_sha256 === $context->packageSha256) {
                    throw new DomainException('eq_source_recovery_snapshot_missing');
                }
            }

            return false;
        }
        $this->rollback($context, 'content-release-snapshot:'.$snapshots->first()->id);

        return true;
    }

    private function rows(PromotionContext $context): array
    {
        if (! $this->supports($context->lane, $context->subscope) || $context->expectedRowCount !== 3
            || realpath($context->packageDirectory) !== realpath(base_path(EqPublicArticlePackage::PACKAGE))) {
            throw new DomainException('eq_source_context_invalid');
        }
        $package = $this->packages->read(base_path(), $context->packageSha256);

        return array_values(array_filter($package['candidates'], static fn (array $row): bool => $row['identity']['locale'] === 'zh-CN'));
    }

    private function article(array $row, bool $lock): ?Article
    {
        $query = Article::query()->withoutGlobalScopes()->withTrashed()->where($row['identity']);
        $articles = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($articles->count() > 1) {
            throw new DomainException('eq_source_identity_collision');
        }

        return $articles->first();
    }

    private function state(array $rows, bool $lock): array
    {
        $state = [];
        foreach ($rows as $row) {
            $article = $this->article($row, $lock);
            $revision = $article?->working_revision_id
                ? ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->whereKey($article->working_revision_id)->when($lock, static fn ($query) => $query->lockForUpdate())->first()
                : null;
            $seo = $article ? ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $article->id)->where('locale', 'zh-CN')->when($lock, static fn ($query) => $query->lockForUpdate())->first() : null;
            $state[$this->key($row)] = ['identity' => $row['identity'], 'article' => $article?->getAttributes(), 'revision' => $revision?->getAttributes(), 'seo' => $seo?->getAttributes()];
        }

        return $state;
    }

    private function assertOwnedDraft(array $row, array $state, PromotionContext $context): void
    {
        if ($state['article'] === null) {
            return;
        }
        $article = $state['article'];
        $revision = $state['revision'];
        if (($article['status'] ?? null) !== 'draft' || (int) ($article['is_public'] ?? 0) !== 0
            || ($article['published_revision_id'] ?? null) !== null || ($article['deleted_at'] ?? null) !== null
            || ($article['source_article_id'] ?? null) !== null || ($article['source_locale'] ?? null) !== 'zh-CN'
            || ($article['lifecycle_state'] ?? null) !== 'active'
            || (int) ($article['is_indexable'] ?? 0) !== 0 || (int) ($article['sitemap_eligible'] ?? 0) !== 0 || (int) ($article['llms_eligible'] ?? 0) !== 0
            || ! in_array($revision['revision_status'] ?? null, [ArticleTranslationRevision::STATUS_MACHINE_DRAFT, ArticleTranslationRevision::STATUS_APPROVED], true)) {
            throw new DomainException('eq_source_foreign_or_unpublishable_draft');
        }
        if ($revision['revision_status'] === ArticleTranslationRevision::STATUS_APPROVED
            && (($revision['authority_package_sha256'] ?? null) !== $context->packageSha256
                || ($revision['authority_asset_key'] ?? null) !== $this->key($row))) {
            throw new DomainException('eq_source_foreign_or_unpublishable_draft');
        }
        foreach (['title', 'excerpt', 'content_md'] as $field) {
            if (($article[$field] ?? null) !== $row['snapshot'][$field] || ($revision[$field] ?? null) !== $row['snapshot'][$field]) {
                throw new DomainException('eq_source_draft_payload_drift');
            }
        }
        foreach (['seo_title', 'seo_description'] as $field) {
            if (($revision[$field] ?? null) !== $row['snapshot'][$field]) {
                throw new DomainException('eq_source_draft_metadata_drift');
            }
        }
    }

    private function assertProjection(array $row, ?Article $article, PromotionContext $context, bool $published): void
    {
        $revision = $article?->workingRevision;
        if (! $article || ! $revision || $article->trashed() || ! $article->isSourceArticle()
            || (string) $revision->authority_package_sha256 !== $context->packageSha256
            || (string) $revision->authority_asset_key !== $this->key($row)
            || (string) $revision->revision_status !== ($published ? ArticleTranslationRevision::STATUS_PUBLISHED : ArticleTranslationRevision::STATUS_APPROVED)
            || (bool) $article->is_public !== $published
            || ($published && (int) $article->published_revision_id !== (int) $revision->id)
            || (! $published && $article->published_revision_id !== null)) {
            throw new DomainException('eq_source_projection_invalid');
        }
        foreach ($row['snapshot'] as $field => $value) {
            if ((string) $revision->$field !== $value) {
                throw new DomainException('eq_source_revision_readback_drift');
            }
        }
        foreach (['title', 'excerpt', 'content_md'] as $field) {
            if ((string) $article->$field !== $row['snapshot'][$field]) {
                throw new DomainException('eq_source_public_readback_drift');
            }
        }
    }

    private function assertReceiptState(array $previous, array $state, PromotionContext $context): void
    {
        if (data_get($previous, 'receipt.workflow_run_id') !== $context->workflowRunId
            || data_get($previous, 'receipt.workflow_run_attempt') !== $context->workflowRunAttempt
            || data_get($previous, 'receipt.executor_release_sha256') !== $context->executorReleaseSha256) {
            throw new DomainException('eq_source_previous_workflow_mismatch');
        }
        if (! hash_equals($this->hash($state), (string) data_get($previous, 'receipt.target_state_sha256', ''))) {
            throw new DomainException('eq_source_prestate_drift');
        }
    }

    private function key(array $row): string
    {
        return '0:zh-CN:'.$row['identity']['slug'];
    }

    private function targets(array $rows): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_column($rows, 'identity'));
    }

    private function hash(array $value): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($value));
    }

    private function result(PromotionContext $context, int $written, int $published, ?string $reference, string $stateHash): array
    {
        return PromotionAdapterResultFactory::make($context, $written, 3, $published, $reference, [
            'indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0,
            'search_mutation_count' => 0, 'deploy_mutation_count' => 0,
        ]) + ['target_state_sha256' => $stateHash];
    }
}
