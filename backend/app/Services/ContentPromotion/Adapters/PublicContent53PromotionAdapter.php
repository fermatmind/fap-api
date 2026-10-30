<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionPhaseIdentity;
use App\Services\ContentPromotion\PromotionReceiptStore;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use App\Services\ContentPromotion\PublicContent53Package;
use App\Services\ContentPromotion\PublicContent53State;
use App\Services\ContentPromotion\PublicContent53Writer;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** @review-surface article */
final class PublicContent53PromotionAdapter implements ExactPackagePromotionAdapter
{
    public const SUBSCOPE = 'PUBLIC-CONTENT-53';

    private const PACK = 'public-content-53';

    private string $activePackageSha = '';

    public function __construct(
        private readonly PublicContent53Package $package,
        private readonly PublicContent53State $states,
        private readonly PublicContent53Writer $writer,
        private readonly PromotionReceiptStore $receipts,
        private readonly PromotionRollbackSnapshotService $snapshots,
        private readonly SeoDiscoverabilityCacheInvalidator $cache,
    ) {}

    public function id(): string
    {
        return 'public_content_53_public_pages_v1';
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
        app(\App\Services\ContentPromotion\PublicContent53FrontendRevalidator::class)->assertConfigured();
        $this->states->assertBaseline($rows);

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
                    throw new DomainException('public_content_53_draft_state_drift');
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
                    throw new DomainException('public_content_53_publication_replay_drift');
                }
                $this->states->assertPublished($rows, $before);

                return ['reference' => 'content-release-snapshot:'.$existing->id, 'state' => $before, 'written_count' => 0];
            }
            $this->assertPreviousState($previous, $before);
            if (data_get($draft->meta_json, 'before_state_sha256') !== $this->states->hash($before)) {
                throw new DomainException('public_content_53_draft_state_drift');
            }
            $result = $this->writer->publish($context, $before);
            // Both states and native writes commit together. The immutable
            // snapshot is created once all generated revision IDs are known.
            $reference = $this->capture($context, $rows, 'before_publication', $before, $result['state']);

            return ['reference' => $reference, ...$result];
        }, 3);
        try {
            $this->invalidate();
            $this->states->assertPublished($rows, $published['state']);
        } catch (Throwable $error) {
            if ($published['written_count'] > 0) {
                $this->rollback($context, $published['reference']);
            }
            throw new DomainException('public_content_53_cache_closeout_failed', previous: $error);
        }

        return $this->result($context, $published['written_count'], $context->expectedRowCount, $published['reference'], $published['state'], $published['created_count'] ?? 0);
    }

    public function liveQa(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'cms_publication_receipt');
        $snapshot = $this->resolve($context, $rows, 'before_publication', (string) data_get($previous, 'receipt.rollback_reference'));
        $state = $this->states->read($context->packageSha256);
        if (data_get($snapshot->meta_json, 'after_state_sha256') !== $this->states->hash($state)) {
            throw new DomainException('public_content_53_live_state_drift');
        }
        $this->states->assertPublished($rows, $state);

        return $this->result($context, 0, $context->expectedRowCount, null, $state);
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
        if (! $this->supports($context->lane, $context->subscope)
            || realpath($context->packageDirectory) !== realpath(base_path(PublicContent53Package::PACKAGE))) {
            throw new DomainException('public_content_53_context_invalid');
        }

        $this->activePackageSha = $context->packageSha256;

        $rows = $this->package->read(base_path(), $context->packageSha256)['candidates'];
        if (count($rows) !== $context->expectedRowCount) {
            throw new DomainException('public_content_53_context_count_invalid');
        }

        return $rows;
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
            throw new DomainException('public_content_53_previous_execution_mismatch');
        }

        return $previous;
    }

    private function assertPreviousState(array $previous, array $state): void
    {
        if (data_get($previous, 'receipt.target_state_sha256') !== $this->states->hash($state)) {
            throw new DomainException('public_content_53_previous_state_drift');
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
            throw new DomainException('public_content_53_snapshot_execution_mismatch');
        }

        return $snapshot;
    }

    private function snapshot(PromotionContext $context, array $rows, string $phase): ?ContentReleaseSnapshot
    {
        $key = PromotionPhaseIdentity::idempotencyKey($context, $phase, $this->targets($rows));
        // Select this immutable phase before transferring large rollback bodies.
        $snapshot = ContentReleaseSnapshot::query()->where('pack_id', self::PACK)->where('reason', 'content_promotion_'.$phase)
            ->where('meta_json->phase_idempotency_key', $key)->orderBy('id')->first();

        return $snapshot === null ? null : $this->resolve($context, $rows, $phase, 'content-release-snapshot:'.$snapshot->id);
    }

    private function invalidate(): void
    {
        $rows = $this->package->read(base_path(), $this->activePackageSha)['candidates'];
        foreach ($rows as $row) {
            if ($row['kind'] === 'page') {
                \Illuminate\Support\Facades\Cache::forget('content_page:v1:0:'.$row['identity']['slug'].':'.$row['identity']['locale']);
            }
        }
        if (array_filter($rows, static fn (array $row): bool => ! in_array($row['kind'], ['page', 'guide', 'mbti-result'], true))) {
            $this->cache->flushArticleDiscoverabilityCaches(false);
        }
        app(\App\Services\ContentPromotion\PublicContent53FrontendRevalidator::class)->revalidate($rows);
    }

    private function result(PromotionContext $context, int $written, int $published, ?string $reference, array $state, int $created = 0): array
    {
        $result = PromotionAdapterResultFactory::make($context, $written, $context->expectedRowCount, $published, $reference, [
            'indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0,
        ]) + ['target_state_sha256' => $this->states->hash($state)];
        if ($published > 0 && $written > 0 && \App\Services\ContentPromotion\RiasecCodeSampleArticles::matchesContext($context)) {
            $result['created_count'] = $created;
            $result['updated_count'] = $written - $created;
            foreach (['indexability_mutation_count', 'sitemap_mutation_count', 'llms_mutation_count'] as $field) {
                $result[$field] = $written;
            }
        }

        return $result;
    }
}
