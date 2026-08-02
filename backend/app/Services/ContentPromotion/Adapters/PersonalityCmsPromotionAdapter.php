<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\PersonalityCmsPromotionAuthority;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PersonalityCmsPromotionAdapter implements ExactPackagePromotionAdapter
{
    public function __construct(
        private readonly string $lane,
        private readonly string $subscope,
        private readonly PersonalityCmsPromotionAuthority $authority,
        private readonly PromotionRollbackSnapshotService $snapshots,
    ) {}

    public function id(): string
    {
        return strtolower($this->lane).'_'.str_replace('-', '_', $this->subscope).'_personality_cms_v2';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === $this->lane && $subscope === $this->subscope;
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
        $targets = $this->targets($package);
        $snapshot = $this->snapshots->resolve($context, $targets, 'personality-public-'.$this->subscope, 'before_publication', $rollbackReference);
        DB::transaction(function () use ($snapshot, $context): void {
            foreach ((array) data_get($snapshot->meta_json, 'rows', []) as $row) {
                if (! is_array($row)) {
                    throw new DomainException('personality_promotion_rollback_row_invalid');
                }
                $assetId = (int) ($row['asset_id'] ?? 0);
                $revisionId = (int) ($row['published_revision_id_before'] ?? 0);
                $workingRevisionId = (int) ($row['working_revision_id_before'] ?? 0);
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->lockForUpdate()->find($assetId);
                if (! $asset instanceof PersonalityPublicContentAsset || (string) $row['package_sha256'] !== $context->packageSha256) {
                    throw new DomainException('personality_promotion_rollback_target_invalid');
                }
                $before = is_array($row['before'] ?? null) ? $row['before'] : [];
                $promotedRevision = PersonalityPublicContentAssetRevision::query()
                    ->lockForUpdate()
                    ->where('authority_package_sha256', $context->packageSha256)
                    ->where('authority_asset_key', (string) ($row['asset_key'] ?? ''))
                    ->first();
                if (! $promotedRevision instanceof PersonalityPublicContentAssetRevision
                    || ($workingRevisionId > 0 && (int) $promotedRevision->id !== $workingRevisionId)) {
                    throw new DomainException('personality_promotion_rollback_revision_invalid');
                }
                if ((int) $asset->published_revision_id !== (int) $promotedRevision->id
                    || $asset->working_revision_id !== null
                    || ! is_array($promotedRevision->snapshot_json)
                    || ! hash_equals(
                        \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($promotedRevision->snapshot_json),
                        \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($this->snapshot($asset)),
                    )) {
                    throw new DomainException('personality_promotion_rollback_concurrent_publication');
                }
                $asset->forceFill([
                    ...$before,
                    'published_revision_id' => $revisionId > 0 ? $revisionId : null,
                    'working_revision_id' => $workingRevisionId > 0 ? $workingRevisionId : null,
                ])->saveQuietly();
                $promotedRevision->forceFill([
                    'workflow_state' => (string) ($row['working_revision_workflow_state_before'] ?? PersonalityPublicContentAssetRevision::STATE_DRAFT),
                ])->save();
            }
        });
        $this->authority->invalidateTargets($package['targets']);
    }

    /** @param array{framework:string,targets:list<array<string,mixed>>,package_sha256:string} $package */
    private function capture(PromotionContext $context, array $package, string $phase): string
    {
        $targets = $this->targets($package);
        $rows = [];
        foreach ($package['targets'] as $target) {
            /** @var PersonalityPublicContentAsset $asset */
            $asset = $target['asset'];
            $before = [];
            foreach (['title', 'summary', 'content_sections_json', 'seo_json', 'faq_json', 'method_boundary_json', 'evidence_notes_json', 'authority_json', 'internal_links_json', 'source_package', 'source_hash'] as $field) {
                $before[$field] = $asset->getAttribute($field);
            }
            $workingRevision = $asset->working_revision_id === null
                ? null
                : PersonalityPublicContentAssetRevision::query()->find((int) $asset->working_revision_id);
            $rows[] = [
                'asset_id' => $asset->id,
                'asset_key' => $target['asset_key'],
                'published_revision_id_before' => $asset->published_revision_id,
                'working_revision_id_before' => $asset->working_revision_id,
                'working_revision_workflow_state_before' => $workingRevision?->workflow_state,
                'package_sha256' => $context->packageSha256,
                'before' => $before,
            ];
        }

        return $this->snapshots->capture($context, $targets, 'personality-public-'.$this->subscope, $phase, $rows, $targets->identities(), ['framework' => $package['framework']]);
    }

    /** @param array{framework:string,targets:list<array<string,mixed>>,package_sha256:string} $package */
    private function targets(array $package): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_map(static fn (array $target): array => ['framework' => $target['identity']['framework'], 'entity_type' => $target['identity']['entity_type'], 'entity_key' => $target['identity']['entity_key'], 'locale' => $target['identity']['locale']], $package['targets']));
    }

    /** @return array<string,int> */
    private function zero(): array
    {
        return ['indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0, 'search_mutation_count' => 0, 'deploy_mutation_count' => 0];
    }

    /** @return array<string,mixed> */
    private function snapshot(PersonalityPublicContentAsset $asset): array
    {
        $snapshot = [];
        foreach (['title', 'summary', 'content_sections_json', 'seo_json', 'faq_json', 'method_boundary_json', 'evidence_notes_json', 'authority_json', 'internal_links_json', 'source_package', 'source_hash'] as $field) {
            $snapshot[$field] = $asset->getAttribute($field);
        }

        return $snapshot;
    }
}
