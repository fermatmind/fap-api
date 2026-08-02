<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\ContentPromotion\ArticleCmsPromotionAuthority;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ArticleCmsPromotionAdapter implements ExactPackagePromotionAdapter
{
    public function __construct(
        private readonly ArticleCmsPromotionAuthority $authority,
        private readonly PromotionRollbackSnapshotService $snapshots,
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
        return $lane === 'W3' && $subscope === 'articles';
    }

    public function preflight(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);

        return PromotionAdapterResultFactory::make($context, 0, count($package['targets']), 0, null, $this->zero());
    }

    public function draftImport(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $reference = $this->capture($context, $package, 'before_draft_import');
        $result = $this->authority->importDraft($context);

        return PromotionAdapterResultFactory::make($context, $result['created_count'], $result['readback_count'], 0, $reference, $this->zero(), [
            'created_count' => $result['created_count'], 'updated_count' => 0, 'unchanged_count' => $result['unchanged_count'],
        ]);
    }

    public function publish(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $reference = $this->capture($context, $package, 'before_publication');
        $result = $this->authority->publish($context);

        return PromotionAdapterResultFactory::make($context, $result['changed_count'], $result['readback_count'], $result['readback_count'], $reference, $this->zero(), [
            'created_count' => 0, 'updated_count' => $result['changed_count'], 'unchanged_count' => $result['unchanged_count'],
        ]);
    }

    public function liveQa(PromotionContext $context): array
    {
        $result = $this->authority->liveQa($context);

        return PromotionAdapterResultFactory::make($context, 0, $result['readback_count'], $result['readback_count'], null, $this->zero(), [
            'created_count' => 0, 'updated_count' => 0, 'unchanged_count' => $result['readback_count'],
        ]);
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $package = $this->authority->inspect($context);
        $snapshot = $this->snapshots->resolve($context, $this->targets($package), 'article-cms', 'before_publication', $rollbackReference);
        DB::transaction(function () use ($snapshot, $context): void {
            foreach ((array) data_get($snapshot->meta_json, 'rows', []) as $row) {
                if (! is_array($row) || (string) ($row['package_sha256'] ?? '') !== $context->packageSha256) {
                    throw new DomainException('article_promotion_rollback_row_invalid');
                }
                $article = Article::query()->withoutGlobalScopes()->lockForUpdate()->find((int) ($row['article_id'] ?? 0));
                $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->lockForUpdate()
                    ->where('authority_package_sha256', $context->packageSha256)
                    ->where('authority_asset_key', (string) ($row['asset_key'] ?? ''))->first();
                if (! $article instanceof Article || ! $revision instanceof ArticleTranslationRevision
                    || (int) $revision->article_id !== (int) $article->id
                    || (int) $article->published_revision_id !== (int) $revision->id
                    || $article->working_revision_id !== null) {
                    throw new DomainException('article_promotion_rollback_concurrent_publication');
                }
                foreach ((array) ($row['revision_statuses_before'] ?? []) as $revisionId => $status) {
                    ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->whereKey((int) $revisionId)
                        ->update(['revision_status' => (string) $status]);
                }
                $article->forceFill((array) ($row['article_before'] ?? []))->saveQuietly();
                $revision->forceFill([
                    'revision_status' => (string) ($row['package_revision_status_before'] ?? ArticleTranslationRevision::STATUS_APPROVED),
                    'published_at' => $row['package_revision_published_at_before'] ?? null,
                ])->saveQuietly();
            }
        }, 3);
    }

    /** @param array{targets:list<array<string,mixed>>} $package */
    private function capture(PromotionContext $context, array $package, string $phase): string
    {
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
            $rows[] = [
                'article_id' => $article->id, 'asset_key' => $target['asset_key'], 'package_sha256' => $context->packageSha256,
                'article_before' => $this->articleState($article), 'revision_statuses_before' => $statuses,
                'package_revision_status_before' => $packageRevision?->revision_status ?? ArticleTranslationRevision::STATUS_APPROVED,
                'package_revision_published_at_before' => $packageRevision?->published_at?->toISOString(),
            ];
        }

        return $this->snapshots->capture($context, $this->targets($package), 'article-cms', $phase, $rows, $this->targets($package)->identities());
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
        foreach (['title', 'excerpt', 'content_md', 'working_revision_id', 'published_revision_id', 'status', 'is_public', 'published_at'] as $field) {
            $state[$field] = $article->getAttribute($field);
        }

        return $state;
    }

    /** @return array<string,int> */
    private function zero(): array
    {
        return ['indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0];
    }
}
