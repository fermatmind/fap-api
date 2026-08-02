<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\ContentReleaseSnapshot;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use DomainException;

final class PromotionRollbackSnapshotService
{
    public function __construct(private readonly ContentReleaseSnapshotCatalogService $snapshots) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $metadata
     */
    public function capture(
        PromotionContext $context,
        PromotionTargetSet $targets,
        string $packId,
        string $phase,
        array $rows,
        array $metadata = [],
    ): string {
        $targets->assertExpectedCount($context->expectedRowCount);
        if (! preg_match('/\A[a-z][a-z0-9_]{2,63}\z/', $phase)) {
            throw new DomainException('promotion_snapshot_phase_invalid');
        }

        $snapshot = $this->snapshots->recordSnapshot([
            'pack_id' => $packId,
            'pack_version' => substr($context->packageSha256, 0, 16),
            'reason' => 'content_promotion_'.$phase,
            'created_by' => 'content-promotion-v2',
            'meta_json' => [
                'schema_version' => 'fermatmind.content_promotion_rollback_snapshot.v2',
                'lane' => $context->lane,
                'subscope' => $context->subscope,
                'package_sha256' => $context->packageSha256,
                'source_commit' => $context->sourceCommit,
                'idempotency_key' => $context->idempotencyKey,
                'phase' => $phase,
                'phase_idempotency_key' => PromotionPhaseIdentity::idempotencyKey($context, $phase, $targets),
                'target_fingerprint' => $targets->fingerprint(),
                'target_identities' => $targets->identities(),
                'rows' => $rows,
                ...$metadata,
            ],
        ]);

        return 'content-release-snapshot:'.$snapshot->id;
    }

    public function resolve(
        PromotionContext $context,
        PromotionTargetSet $targets,
        string $packId,
        string $rollbackReference,
    ): ContentReleaseSnapshot {
        if (preg_match('/\Acontent-release-snapshot:([1-9][0-9]*)\z/', $rollbackReference, $match) !== 1) {
            throw new DomainException('rollback_reference_invalid');
        }
        $snapshot = ContentReleaseSnapshot::query()->find((int) $match[1]);
        if (! $snapshot instanceof ContentReleaseSnapshot
            || (string) $snapshot->pack_id !== $packId
            || data_get($snapshot->meta_json, 'lane') !== $context->lane
            || data_get($snapshot->meta_json, 'subscope') !== $context->subscope
            || data_get($snapshot->meta_json, 'package_sha256') !== $context->packageSha256
            || data_get($snapshot->meta_json, 'source_commit') !== $context->sourceCommit
            || data_get($snapshot->meta_json, 'idempotency_key') !== $context->idempotencyKey
            || data_get($snapshot->meta_json, 'target_fingerprint') !== $targets->fingerprint()
            || data_get($snapshot->meta_json, 'target_identities') !== $targets->identities()) {
            throw new DomainException('rollback_snapshot_mismatch');
        }

        return $snapshot;
    }
}
