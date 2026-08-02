<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\MbtiResultPromotionAuthority;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;

final class MbtiResultPromotionAdapter implements ExactPackagePromotionAdapter
{
    public function __construct(
        private readonly MbtiResultPromotionAuthority $authority,
        private readonly PromotionRollbackSnapshotService $snapshots,
    ) {}

    public function id(): string
    {
        return 'w1_mbti_results_v2';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W1' && $subscope === 'mbti-results';
    }

    public function preflight(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);

        return PromotionAdapterResultFactory::make($context, 0, count($package['targets']), 0, null, $this->zeroBoundaryMutations());
    }

    public function draftImport(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $rollbackReference = $this->captureSnapshot($context, $package, 'before_draft_import');
        $result = $this->authority->importDraft($context);

        return PromotionAdapterResultFactory::make(
            $context,
            $result['created'] ? $result['readback_count'] : 0,
            $result['readback_count'],
            0,
            $rollbackReference,
            $this->zeroBoundaryMutations(),
            [
                'created_count' => $result['created'] ? $result['readback_count'] : 0,
                'updated_count' => 0,
                'unchanged_count' => $result['created'] ? 0 : $result['readback_count'],
            ],
        );
    }

    public function publish(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $rollbackReference = $this->captureSnapshot($context, $package, 'before_publication');
        $result = $this->authority->publish($context);

        return PromotionAdapterResultFactory::make(
            $context,
            $result['changed'] ? $result['readback_count'] : 0,
            $result['readback_count'],
            $result['published_count'],
            $rollbackReference,
            $this->zeroBoundaryMutations(),
            [
                'created_count' => 0,
                'updated_count' => $result['changed'] ? $result['readback_count'] : 0,
                'unchanged_count' => $result['changed'] ? 0 : $result['readback_count'],
            ],
        );
    }

    public function liveQa(PromotionContext $context): array
    {
        $result = $this->authority->liveQa($context);

        return PromotionAdapterResultFactory::make(
            $context,
            0,
            $result['readback_count'],
            $result['published_count'],
            null,
            $this->zeroBoundaryMutations(),
            ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => $result['readback_count']],
        );
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $package = $this->authority->inspect($context);
        $snapshot = $this->snapshots->resolve(
            $context,
            $this->targets($package),
            'MBTI.global.en.default',
            'before_publication',
            $rollbackReference,
        );
        $previousReleaseId = data_get($snapshot->meta_json, 'activation_before_release_id');
        if ($previousReleaseId !== null && (! is_string($previousReleaseId) || $previousReleaseId === '')) {
            throw new DomainException('mbti_result_rollback_snapshot_invalid');
        }
        $this->authority->restoreActivation($previousReleaseId);
    }

    /** @param array<string,mixed> $package */
    private function captureSnapshot(PromotionContext $context, array $package, string $phase): string
    {
        $targets = $this->targets($package);
        $activationBeforeReleaseId = $this->authority->activationReleaseId();
        $rows = array_map(
            static fn (array $target): array => [
                'locale' => $target['locale'],
                'pack_id' => $target['pack_id'],
                'row_id' => $target['row_id'],
                'activation_before_release_id' => $activationBeforeReleaseId,
            ],
            $targets->identities(),
        );

        return $this->snapshots->capture(
            $context,
            $targets,
            'MBTI.global.en.default',
            $phase,
            $rows,
            $targets->identities(),
            [
                'activation_before_release_id' => $activationBeforeReleaseId,
                'target_release_id' => $package['release_id'],
                'authority_hash' => $package['authority_hash'],
            ],
        );
    }

    /** @param array<string,mixed> $package */
    private function targets(array $package): PromotionTargetSet
    {
        $targets = $package['targets'] ?? null;
        if (! is_array($targets)) {
            throw new DomainException('mbti_result_targets_invalid');
        }

        return PromotionTargetSet::fromIdentities($targets);
    }

    /** @return array<string,int> */
    private function zeroBoundaryMutations(): array
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
