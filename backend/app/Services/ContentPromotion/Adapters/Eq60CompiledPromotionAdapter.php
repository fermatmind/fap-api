<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\Eq60CompiledPromotionAuthority;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionPhaseIdentity;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;

final class Eq60CompiledPromotionAdapter implements ExactPackagePromotionAdapter
{
    public function __construct(private readonly Eq60CompiledPromotionAuthority $authority, private readonly PromotionRollbackSnapshotService $snapshots) {}

    public function id(): string
    {
        return 'w7_eq60_compiled_result_content_release_v2';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W7' && $subscope === 'eq';
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

        return PromotionAdapterResultFactory::make($context, $result['created_count'], $result['readback_count'], 0, $reference, $this->zero(), ['created_count' => $result['created_count'], 'updated_count' => 0, 'unchanged_count' => $result['unchanged_count']]);
    }

    public function publish(PromotionContext $context): array
    {
        $package = $this->authority->inspect($context);
        $reference = $this->capture($context, $package, 'before_publication');
        $snapshot = $this->snapshots->resolve($context, $this->targets($package), 'eq60-compiled-result-content-release', 'before_publication', $reference);
        $result = $this->authority->publish($context, $this->previousReleaseId($snapshot, $context));

        return PromotionAdapterResultFactory::make($context, $result['changed_count'], $result['readback_count'], $result['readback_count'], $reference, $this->zero(), ['created_count' => 0, 'updated_count' => $result['changed_count'], 'unchanged_count' => $result['unchanged_count']]);
    }

    public function liveQa(PromotionContext $context): array
    {
        $result = $this->authority->liveQa($context);

        return PromotionAdapterResultFactory::make($context, 0, $result['readback_count'], $result['readback_count'], null, $this->zero(), ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => $result['readback_count']]);
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $package = $this->authority->inspect($context);
        $snapshot = $this->snapshots->resolve($context, $this->targets($package), 'eq60-compiled-result-content-release', 'before_publication', $rollbackReference);
        $this->authority->rollback($context, $this->previousReleaseId($snapshot, $context));
    }

    /** @param array{targets:list<array<string,mixed>>} $package */
    private function capture(PromotionContext $context, array $package, string $phase): string
    {
        $targets = $this->targets($package);
        if ($phase === 'before_publication') {
            $phaseKey = PromotionPhaseIdentity::idempotencyKey($context, $phase, $targets);
            $existing = ContentReleaseSnapshot::query()->where('pack_id', 'eq60-compiled-result-content-release')->where('reason', 'content_promotion_before_publication')->orderBy('id')->get()->first(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'phase_idempotency_key') === $phaseKey && data_get($snapshot->meta_json, 'target_fingerprint') === $targets->fingerprint());
            if ($existing instanceof ContentReleaseSnapshot) {
                return 'content-release-snapshot:'.$existing->id;
            }
        }
        $previous = $this->authority->activeReleaseId($context->packageSha256);
        $rows = array_map(static fn (array $target): array => ['asset_key' => $target['asset_key'], 'package_sha256' => $context->packageSha256, 'previous_release_id' => $previous], $package['targets']);

        return $this->snapshots->capture($context, $targets, 'eq60-compiled-result-content-release', $phase, $rows, $targets->identities(), ['previous_release_id' => $previous]);
    }

    /** @param array{targets:list<array<string,mixed>>} $package */
    private function targets(array $package): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_map(static fn (array $target): array => $target['identity'], $package['targets']));
    }

    private function previousReleaseId(ContentReleaseSnapshot $snapshot, PromotionContext $context): ?string
    {
        $previous = data_get($snapshot->meta_json, 'previous_release_id');
        foreach ((array) data_get($snapshot->meta_json, 'rows', []) as $row) {
            if (! is_array($row) || (string) ($row['package_sha256'] ?? '') !== $context->packageSha256 || ($row['previous_release_id'] ?? null) !== $previous) {
                throw new DomainException('eq60_promotion_rollback_snapshot_invalid');
            }
        }

        return is_string($previous) && $previous !== '' ? $previous : null;
    }

    /** @return array<string,int> */
    private function zero(): array
    {
        return ['indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0];
    }
}
