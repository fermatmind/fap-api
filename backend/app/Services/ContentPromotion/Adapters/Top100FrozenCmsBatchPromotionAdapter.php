<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use App\Services\ContentPromotion\Top100FrozenCmsBatchAuthority;
use App\Services\ContentPromotion\Top100FrozenPackage;
use DomainException;
use Throwable;

final class Top100FrozenCmsBatchPromotionAdapter implements ExactPackagePromotionAdapter
{
    private const PACK_ID = 'seo-top100-frozen';

    public function __construct(
        private readonly Top100FrozenCmsBatchAuthority $authority,
        private readonly PromotionRollbackSnapshotService $snapshots,
    ) {}

    public function id(): string
    {
        return 'top100_frozen_20260812_v1';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'TOP100' && $subscope === Top100FrozenPackage::SUBSCOPE;
    }

    public function preflight(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);

        return $this->result($context, 0, 30, 0, null, 0, 0, 30, $package);
    }

    /** @return array<string,mixed> */
    public function lockedPreflight(PromotionContext $context): array
    {
        return $this->result($context, 0, 30, 0, null, 0, 0, 30, $this->authority->inspect($context, true));
    }

    public function draftImport(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $reference = $this->capture($context, $package, 'before_draft_import');
        $result = $this->authority->importDraft($context);

        return $this->result($context, $result['created_count'], 30, 0, $reference, $result['created_count'], 0, $result['unchanged_count'], $package);
    }

    public function publish(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $reference = $this->draftSnapshotReference($context, $package);
        try {
            $result = $this->authority->publish($context);
        } catch (Throwable $throwable) {
            $this->rollback($context, $reference);

            throw new DomainException('top100_publish_failed_rollback_succeeded', previous: $throwable);
        }

        return $this->result($context, $result['changed_count'], 30, 30, $reference, 0, $result['changed_count'], $result['unchanged_count'], $package);
    }

    public function liveQa(PromotionContext $context): array
    {
        $result = $this->authority->liveQa($context);

        return [
            ...$this->result($context, 0, $result['readback_count'], $result['readback_count'], null, 0, 0, 30, $this->authority->inspect($context)),
            'public_api_readback_count' => $result['public_api_readback_count'],
            'live_html_readback_count' => $result['live_html_readback_count'],
        ];
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        if (preg_match('/\Acontent-release-snapshot:([1-9][0-9]*)\z/', $rollbackReference, $match) !== 1) {
            throw new DomainException('rollback_reference_invalid');
        }
        $candidate = ContentReleaseSnapshot::query()->find((int) $match[1]);
        $identities = $candidate instanceof ContentReleaseSnapshot ? (array) data_get($candidate->meta_json, 'target_identities', []) : [];
        $targets = PromotionTargetSet::fromIdentities($identities);
        $phase = (string) data_get($candidate?->meta_json, 'phase', '');
        if (! in_array($phase, ['before_draft_import', 'before_publication'], true)) {
            throw new DomainException('rollback_snapshot_phase_invalid');
        }
        $snapshot = $this->snapshots->resolve($context, $targets, self::PACK_ID, $phase, $rollbackReference);
        $this->authority->rollback($context, (array) data_get($snapshot->meta_json, 'rows', []));
    }

    private function draftSnapshotReference(PromotionContext $context, array $package): string
    {
        $targets = $this->targets($package);
        $snapshot = ContentReleaseSnapshot::query()
            ->where('pack_id', self::PACK_ID)
            ->where('reason', 'content_promotion_before_draft_import')
            ->orderByDesc('id')
            ->get()
            ->first(static fn (ContentReleaseSnapshot $candidate): bool => data_get($candidate->meta_json, 'package_sha256') === $context->packageSha256
                && data_get($candidate->meta_json, 'source_commit') === $context->sourceCommit
                && data_get($candidate->meta_json, 'idempotency_key') === $context->idempotencyKey
                && data_get($candidate->meta_json, 'target_fingerprint') === $targets->fingerprint());
        if (! $snapshot instanceof ContentReleaseSnapshot) {
            throw new DomainException('top100_before_draft_snapshot_missing');
        }
        $reference = 'content-release-snapshot:'.$snapshot->id;
        $this->snapshots->resolve($context, $targets, self::PACK_ID, 'before_draft_import', $reference);

        return $reference;
    }

    private function capture(PromotionContext $context, array $package, string $phase): string
    {
        $targets = $this->targets($package);
        $rows = array_map(fn (array $target): array => $this->authority->snapshotRow($context, $target), $package['targets']);

        return $this->snapshots->capture($context, $targets, self::PACK_ID, $phase, $rows, $targets->identities(), [
            'batch_id' => Top100FrozenPackage::BATCH_ID,
            'target_state_sha256' => $this->targetStateSha256($package),
            'approved_prestate_sha256' => $this->approvedPrestateSha256($package),
            'source_sha256' => Top100FrozenPackage::SOURCE_SHA256,
            'target_set_sha256' => $package['target_set_sha256'],
            'changed_count' => $package['changed_count'],
            'unchanged_count' => $package['unchanged_count'],
            'hold_write_count' => 0,
            'control_write_count' => 0,
            'deferred_out_of_target_link_source_count' => (int) $package['deferred_out_of_target_link_source_count'],
        ]);
    }

    private function targetStateSha256(array $package): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson([
            'schema_version' => 'fermatmind.seo_top100_target_state.v1',
            'target_set_sha256' => $package['target_set_sha256'],
            'targets' => array_map(static fn (array $target): array => [
                'priority' => (int) $target['priority'],
                'url_sha256' => hash('sha256', (string) $target['url']),
                'before_sha256' => (string) $target['before_sha256'],
                'desired_sha256' => (string) $target['desired_sha256'],
            ], $package['targets']),
        ]));
    }

    private function approvedPrestateSha256(array $package): string
    {
        $targets = array_map(static function (array $target): array {
            $current = $target['current'];
            unset($current['mutable']['working_revision_id'], $current['mutable']['published_revision_id']);
            unset($current['mutable']['revision_statuses']);
            if (is_array(data_get($current, 'mutable.article'))) {
                unset($current['mutable']['article']['working_revision_id'], $current['mutable']['article']['published_revision_id']);
            }

            return [
                'priority' => (int) $target['priority'],
                'url_sha256' => hash('sha256', (string) $target['url']),
                'current' => $current,
            ];
        }, $package['targets']);

        return hash('sha256', PromotionContextFactory::canonicalJson([
            'schema_version' => 'fermatmind.seo_top100_approved_prestate.v1',
            'target_set_sha256' => $package['target_set_sha256'],
            'targets' => $targets,
        ]));
    }

    private function targets(array $package): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_map(static fn (array $target): array => [
            'priority' => (int) $target['priority'],
            'url_sha256' => hash('sha256', (string) $target['url']),
        ], $package['targets']));
    }

    private function result(PromotionContext $context, int $written, int $readback, int $published, ?string $rollback, int $created, int $updated, int $unchanged, array $package): array
    {
        return [
            ...PromotionAdapterResultFactory::make($context, $written, $readback, $published, $rollback, $this->zero(), [
                'created_count' => $created,
                'updated_count' => $updated,
                'unchanged_count' => $unchanged,
            ]),
            'batch_id' => Top100FrozenPackage::BATCH_ID,
            'target_state_sha256' => $this->targetStateSha256($package),
            'approved_prestate_sha256' => $this->approvedPrestateSha256($package),
            'target_count' => 30,
            'planned_changed_count' => (int) $package['changed_count'],
            'planned_unchanged_count' => (int) $package['unchanged_count'],
            'unknown_count' => 0,
            'hold_write_count' => 0,
            'control_write_count' => 0,
            'deferred_out_of_target_link_source_count' => (int) $package['deferred_out_of_target_link_source_count'],
            'media_mutation_count' => 0,
            'canonical_mutation_count' => 0,
            'hreflang_mutation_count' => 0,
            'schema_type_mutation_count' => 0,
        ];
    }

    private function zero(): array
    {
        return [
            'indexability_mutation_count' => 0,
            'sitemap_mutation_count' => 0,
            'llms_mutation_count' => 0,
            'search_mutation_count' => 0,
            'deploy_mutation_count' => 0,
        ];
    }
}
