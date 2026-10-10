<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Http\Controllers\API\V0_5\Cms\ArticleController;
use App\Http\Controllers\API\V0_5\Cms\CareerGuideController;
use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\EqExistingPublicPagePackage;
use App\Services\ContentPromotion\EqExistingPublicPageState;
use App\Services\ContentPromotion\EqExistingPublicPageWriter;
use App\Services\ContentPromotion\EqPublicRegistryTextPatch;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionPhaseIdentity;
use App\Services\ContentPromotion\PromotionReceiptStore;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRegistryWriter;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** @review-surface article */
final class EqExistingPublicPagePromotionAdapter implements ExactPackagePromotionAdapter
{
    public const SUBSCOPE = 'EQ-EXISTING-PUBLIC-PAGES';

    private const PACK = 'eq-existing-public-pages';

    public function __construct(
        private readonly EqExistingPublicPagePackage $package,
        private readonly EqExistingPublicPageState $states,
        private readonly EqExistingPublicPageWriter $writer,
        private readonly PromotionReceiptStore $receipts,
        private readonly PromotionRollbackSnapshotService $snapshots,
        private readonly SeoDiscoverabilityCacheInvalidator $cache,
        private readonly ScaleRegistryWriter $scaleCache,
        private readonly ScaleRegistry $scaleReader,
        private readonly EqPublicRegistryTextPatch $patch,
        private readonly ArticleController $articles,
        private readonly CareerGuideController $guides,
    ) {}

    public function id(): string
    {
        return 'eq_existing_public_pages_v1';
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
        $this->rows($context);

        return $this->result($context, 0, 0, null, $this->states->read($context->packageSha256));
    }

    public function draftImport(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'content_promotion_preflight_receipt');
        [$reference, $state] = DB::transaction(function () use ($context, $rows, $previous): array {
            $state = $this->states->read($context->packageSha256, true);
            $this->assertPreviousState($previous, $state);
            $existing = $this->snapshot($context, $rows, 'before_draft_import');
            if ($existing !== null) {
                if (data_get($existing->meta_json, 'before_state_sha256') !== $this->states->hash($state)) {
                    throw new DomainException('eq_existing_draft_state_drift');
                }

                return ['content-release-snapshot:'.$existing->id, $state];
            }

            return [$this->capture($context, $rows, 'before_draft_import', $state, $state), $state];
        }, 3);

        // The journal binds a candidate; it never replaces an operator's draft.
        return $this->result($context, 0, 0, $reference, $state);
    }

    public function publish(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'cms_draft_import_receipt');
        $draft = $this->resolve($context, $rows, 'before_draft_import', (string) data_get($previous, 'receipt.rollback_reference'));
        $published = DB::transaction(function () use ($context, $rows, $previous, $draft): array {
            $before = $this->states->read($context->packageSha256, true);
            $existing = $this->snapshot($context, $rows, 'before_publication');
            if ($existing !== null) {
                if (data_get($existing->meta_json, 'after_state_sha256') !== $this->states->hash($before)) {
                    throw new DomainException('eq_existing_publication_replay_drift');
                }
                $this->writer->assertPublished($rows, $before);

                return ['reference' => 'content-release-snapshot:'.$existing->id, 'state' => $before, 'written_count' => 0];
            }
            $this->assertPreviousState($previous, $before);
            if (data_get($draft->meta_json, 'before_state_sha256') !== $this->states->hash($before)) {
                throw new DomainException('eq_existing_draft_state_drift');
            }
            $result = $this->writer->publish($context, $before);
            // Both states and native writes commit together. The immutable
            // snapshot is created once all generated revision IDs are known.
            $reference = $this->capture($context, $rows, 'before_publication', $before, $result['state']);

            return ['reference' => $reference, ...$result];
        }, 3);
        try {
            $this->invalidate();
        } catch (Throwable $error) {
            if ($published['written_count'] > 0) {
                $this->rollback($context, $published['reference']);
            }
            throw new DomainException('eq_existing_cache_closeout_failed', previous: $error);
        }

        return $this->result($context, $published['written_count'], 6, $published['reference'], $published['state']);
    }

    public function liveQa(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'cms_publication_receipt');
        $snapshot = $this->resolve($context, $rows, 'before_publication', (string) data_get($previous, 'receipt.rollback_reference'));
        $state = $this->states->read($context->packageSha256);
        if (data_get($snapshot->meta_json, 'after_state_sha256') !== $this->states->hash($state)) {
            throw new DomainException('eq_existing_live_state_drift');
        }
        $this->writer->assertPublished($rows, $state);
        foreach ($rows as $row) {
            if ($row['page_id'] === 'EQ-01') {
                foreach ([$this->scaleReader->getByCode('EQ_60', 0), $this->scaleReader->lookupBySlug($row['identity']['slug'], 0, false)] as $public) {
                    if (! is_array($public) || ($public['primary_slug'] ?? null) !== $row['identity']['slug']
                        || ! is_array($public['content_i18n_json'] ?? null)
                        || $this->states->hash($this->patch->apply($public['content_i18n_json'], $row)) !== $this->states->hash($public['content_i18n_json'])) {
                        throw new DomainException('eq_existing_scale_public_readback');
                    }
                }

                continue;
            }
            $isArticle = $row['page_id'] === 'EQ-02';
            $prefix = $isArticle ? 'articles/' : 'career-guides/';
            $request = Request::create('/api/v0.5/'.$prefix.$row['identity']['slug'], 'GET', ['locale' => $row['identity']['locale'], 'org_id' => 0]);
            $response = $isArticle ? $this->articles->show($request, $row['identity']['slug']) : $this->guides->show($request, $row['identity']['slug']);
            $payload = $response->getData(true);
            $body = $payload[$isArticle ? 'article' : 'guide'] ?? [];
            if ($response->getStatusCode() !== 200 || ($payload['ok'] ?? null) !== true || ($body['locale'] ?? null) !== $row['identity']['locale']) {
                throw new DomainException('eq_existing_public_api_identity');
            }
            foreach ($row['snapshot'] as $field => $expected) {
                $actual = str_starts_with($field, 'seo_') ? data_get($payload, 'seo_surface_v1.'.($field === 'seo_title' ? 'title' : 'description')) : ($body[! $isArticle && $field === 'content_md' ? 'body_md' : $field] ?? null);
                if ($actual !== $expected) {
                    throw new DomainException('eq_existing_public_api_payload');
                }
            }
        }

        return $this->result($context, 0, 6, null, $state);
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $rows = $this->rows($context);
        $snapshot = $this->resolve($context, $rows, 'before_publication', $rollbackReference);
        DB::transaction(fn () => $this->writer->restore($context, data_get($snapshot->meta_json, 'before_state'), data_get($snapshot->meta_json, 'after_state')), 3);
        $this->invalidate();
    }

    public function recoverFailedPublication(PromotionContext $context): bool
    {
        $snapshot = $this->snapshot($context, $this->rows($context), 'before_publication');
        if ($snapshot === null) {
            return false;
        }
        $this->rollback($context, 'content-release-snapshot:'.$snapshot->id);

        return true;
    }

    private function rows(PromotionContext $context): array
    {
        if (! $this->supports($context->lane, $context->subscope) || $context->expectedRowCount !== 6
            || realpath($context->packageDirectory) !== realpath(base_path(EqExistingPublicPagePackage::PACKAGE))) {
            throw new DomainException('eq_existing_context_invalid');
        }

        return $this->package->read(base_path(), $context->packageSha256)['candidates'];
    }

    private function previous(PromotionContext $context, string $kind): array
    {
        $previous = $this->receipts->readPrevious($kind, $context);
        $receipt = $previous['receipt'];
        $digest = $receipt['receipt_content_sha256'] ?? '';
        unset($receipt['receipt_content_sha256']);
        if ($digest !== $this->states->hash($receipt) || ($receipt['workflow_run_id'] ?? null) !== $context->workflowRunId
            || ($receipt['workflow_run_attempt'] ?? null) !== $context->workflowRunAttempt
            || ($receipt['executor_release_sha256'] ?? null) !== $context->executorReleaseSha256) {
            throw new DomainException('eq_existing_previous_execution_mismatch');
        }

        return $previous;
    }

    private function assertPreviousState(array $previous, array $state): void
    {
        if (data_get($previous, 'receipt.target_state_sha256') !== $this->states->hash($state)) {
            throw new DomainException('eq_existing_previous_state_drift');
        }
    }

    private function targets(array $rows): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_column($rows, 'identity'));
    }

    private function capture(PromotionContext $context, array $rows, string $phase, array $before, array $after): string
    {
        return $this->snapshots->capture($context, $this->targets($rows), self::PACK, $phase, $rows, array_column($rows, 'identity'), [
            'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
            'executor_release_sha256' => $context->executorReleaseSha256, 'before_state' => $before, 'after_state' => $after,
            'before_state_sha256' => $this->states->hash($before), 'after_state_sha256' => $this->states->hash($after),
        ]);
    }

    private function resolve(PromotionContext $context, array $rows, string $phase, string $reference): ContentReleaseSnapshot
    {
        $snapshot = $this->snapshots->resolve($context, $this->targets($rows), self::PACK, $phase, $reference);
        if (data_get($snapshot->meta_json, 'workflow_run_id') !== $context->workflowRunId
            || data_get($snapshot->meta_json, 'workflow_run_attempt') !== $context->workflowRunAttempt
            || data_get($snapshot->meta_json, 'executor_release_sha256') !== $context->executorReleaseSha256
            || $this->states->hash((array) data_get($snapshot->meta_json, 'rows')) !== $this->states->hash($rows)
            || data_get($snapshot->meta_json, 'before_state_sha256') !== $this->states->hash((array) data_get($snapshot->meta_json, 'before_state'))
            || data_get($snapshot->meta_json, 'after_state_sha256') !== $this->states->hash((array) data_get($snapshot->meta_json, 'after_state'))) {
            throw new DomainException('eq_existing_snapshot_execution_mismatch');
        }

        return $snapshot;
    }

    private function snapshot(PromotionContext $context, array $rows, string $phase): ?ContentReleaseSnapshot
    {
        $key = PromotionPhaseIdentity::idempotencyKey($context, $phase, $this->targets($rows));
        $snapshot = ContentReleaseSnapshot::query()->where('pack_id', self::PACK)->where('reason', 'content_promotion_'.$phase)->orderBy('id')->get()
            ->first(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'phase_idempotency_key') === $key);

        return $snapshot === null ? null : $this->resolve($context, $rows, $phase, 'content-release-snapshot:'.$snapshot->id);
    }

    private function invalidate(): void
    {
        $this->scaleCache->invalidatePublicContentProjection(0, 'EQ_60', ['eq-test-emotional-intelligence-assessment']);
        $this->cache->flushArticleDiscoverabilityCaches(false);
    }

    private function result(PromotionContext $context, int $written, int $published, ?string $reference, array $state): array
    {
        return PromotionAdapterResultFactory::make($context, $written, 6, $published, $reference, [
            'indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0,
        ]) + ['target_state_sha256' => $this->states->hash($state)];
    }
}
