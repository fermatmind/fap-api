<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\EnneagramPrivateResultPromotionAuthority;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;

final class EnneagramPrivateResultPromotionAdapter implements ExactPackagePromotionAdapter
{
    public function __construct(
        private readonly EnneagramPrivateResultPromotionAuthority $authority,
        private readonly PromotionRollbackSnapshotService $snapshots,
    ) {}

    public function id(): string
    {
        return 'w5_enneagram_private_results_v2';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W5' && $subscope === 'enneagram-results';
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

        return PromotionAdapterResultFactory::make($context, $result['created'] ? $result['readback_count'] : 0, $result['readback_count'], 0, $reference, $this->zero(), [
            'created_count' => $result['created'] ? $result['readback_count'] : 0,
            'updated_count' => 0,
            'unchanged_count' => $result['created'] ? 0 : $result['readback_count'],
        ]);
    }

    public function publish(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $reference = $this->capture($context, $package, 'before_publication');
        $result = $this->authority->publish($context);

        return PromotionAdapterResultFactory::make($context, $result['changed'] ? $result['readback_count'] : 0, $result['readback_count'], $result['published_count'], $reference, $this->zero(), [
            'created_count' => 0,
            'updated_count' => $result['changed'] ? $result['readback_count'] : 0,
            'unchanged_count' => $result['changed'] ? 0 : $result['readback_count'],
        ]);
    }

    public function liveQa(PromotionContext $context): array
    {
        $result = $this->authority->liveQa($context);

        return PromotionAdapterResultFactory::make($context, 0, $result['readback_count'], $result['published_count'], null, $this->zero(), ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => $result['readback_count']]);
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $package = $this->authority->inspect($context);
        $snapshot = $this->snapshots->resolve($context, $this->targets($package), 'ENNEAGRAM', 'before_publication', $rollbackReference);
        if (data_get($snapshot->meta_json, 'target_release_id') !== $package['release_id']) {
            throw new DomainException('enneagram_private_result_rollback_snapshot_invalid');
        }
        $this->authority->rollback($context);
    }

    /** @param array<string,mixed> $package */
    private function capture(PromotionContext $context, array $package, string $phase): string
    {
        $targets = $this->targets($package);
        $activeBefore = $this->authority->activeReleaseId();
        $rows = array_map(static fn (array $identity): array => $identity + ['activation_before_release_id' => $activeBefore], $targets->identities());

        return $this->snapshots->capture($context, $targets, 'ENNEAGRAM', $phase, $rows, $targets->identities(), [
            'activation_before_release_id' => $activeBefore,
            'target_release_id' => $package['release_id'],
            'candidate_manifest_sha256' => $package['candidate_manifest_sha256'],
        ]);
    }

    /** @param array<string,mixed> $package */
    private function targets(array $package): PromotionTargetSet
    {
        $targets = $package['targets'] ?? null;
        if (! is_array($targets)) {
            throw new DomainException('enneagram_private_result_targets_invalid');
        }

        return PromotionTargetSet::fromIdentities($targets);
    }

    /** @return array<string,int> */
    private function zero(): array
    {
        return ['indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0];
    }
}
