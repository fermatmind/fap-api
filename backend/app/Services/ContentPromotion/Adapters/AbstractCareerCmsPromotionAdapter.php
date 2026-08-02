<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\CareerCmsPromotionAuthority;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PromotionPhaseIdentity;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class AbstractCareerCmsPromotionAdapter implements ExactPackagePromotionAdapter
{
    abstract protected function lane(): string;

    abstract protected function subscope(): string;

    abstract protected function revisionStore(): string;

    public function __construct(
        private readonly CareerCmsPromotionAuthority $authority,
        private readonly PromotionRollbackSnapshotService $snapshots,
    ) {}

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === $this->lane() && $subscope === $this->subscope();
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
        $snapshot = $this->snapshots->resolve($context, $this->targets($package), $this->revisionStore(), 'before_publication', $rollbackReference);
        DB::transaction(function () use ($package, $snapshot, $context): void {
            $targets = [];
            foreach ($package['targets'] as $target) {
                $targets[$target['asset_key']] = $target;
            }
            foreach ((array) data_get($snapshot->meta_json, 'rows', []) as $row) {
                if (! is_array($row) || (string) ($row['package_sha256'] ?? '') !== $context->packageSha256 || ! isset($targets[(string) ($row['asset_key'] ?? '')])) {
                    throw new DomainException('career_promotion_rollback_row_invalid');
                }
                $target = $targets[(string) $row['asset_key']];
                $model = $this->lockedModel((string) $package['kind'], (int) ($row['model_id'] ?? 0));
                $before = (array) ($row['before'] ?? []);
                $expected = (array) ($row['expected_public_projection'] ?? []);
                $current = $this->authority->state((string) $package['kind'], $model);
                if (hash_equals(PromotionContextFactory::canonicalJson($before), PromotionContextFactory::canonicalJson($current))) {
                    continue;
                }
                if (! hash_equals(PromotionContextFactory::canonicalJson($expected), PromotionContextFactory::canonicalJson($current))) {
                    throw new DomainException('career_promotion_rollback_public_projection_drift');
                }
                if (! hash_equals(PromotionContextFactory::canonicalJson((array) $target['snapshot']), PromotionContextFactory::canonicalJson((array) data_get($expected, 'content', [])))) {
                    throw new DomainException('career_promotion_rollback_target_drift');
                }
                $restore = array_merge((array) data_get($before, 'content', []), (array) data_get($before, 'public', []));
                $model->forceFill($restore)->saveQuietly();
            }
        }, 3);
    }

    /** @param array{kind:string,targets:list<array<string,mixed>>} $package */
    private function capture(PromotionContext $context, array $package, string $phase): string
    {
        $targets = $this->targets($package);
        if ($phase === 'before_publication') {
            $phaseKey = PromotionPhaseIdentity::idempotencyKey($context, $phase, $targets);
            $existing = ContentReleaseSnapshot::query()->where('pack_id', $this->revisionStore())
                ->where('reason', 'content_promotion_before_publication')->orderBy('id')->get()
                ->first(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'phase_idempotency_key') === $phaseKey
                    && data_get($snapshot->meta_json, 'target_fingerprint') === $targets->fingerprint());
            if ($existing instanceof ContentReleaseSnapshot) {
                return 'content-release-snapshot:'.$existing->id;
            }
        }
        $rows = [];
        foreach ($package['targets'] as $target) {
            $model = $target['model'];
            if (! $model instanceof Model) {
                throw new DomainException('career_promotion_target_missing');
            }
            $before = $this->authority->state($package['kind'], $model);
            $rows[] = [
                'model_id' => $model->id,
                'asset_key' => $target['asset_key'],
                'package_sha256' => $context->packageSha256,
                'before' => $before,
                'expected_public_projection' => ['content' => $target['snapshot'], 'public' => $before['public']],
            ];
        }

        return $this->snapshots->capture($context, $targets, $this->revisionStore(), $phase, $rows, $targets->identities(), ['career_kind' => $package['kind']]);
    }

    /** @param array{targets:list<array<string,mixed>>} $package */
    private function targets(array $package): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_map(static fn (array $target): array => $target['identity'], $package['targets']));
    }

    private function lockedModel(string $kind, int $id): Model
    {
        $class = $kind === 'guide' ? \App\Models\CareerGuide::class : \App\Models\CareerJob::class;
        $model = $class::query()->withoutGlobalScopes()->lockForUpdate()->find($id);
        if (! $model instanceof Model) {
            throw new DomainException('career_promotion_rollback_target_missing');
        }

        return $model;
    }

    /** @return array<string,int> */
    private function zero(): array
    {
        return ['indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0];
    }
}
