<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

require_once __DIR__.'/Concerns/AssertsExactPackagePromotionConformance.php';

use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionPhaseIdentity;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\ContentPromotion\Concerns\AssertsExactPackagePromotionConformance;

final class PromotionConformancePrimitivesTest extends TestCase
{
    use AssertsExactPackagePromotionConformance;
    use RefreshDatabase;

    public function test_target_sets_are_canonical_and_reject_duplicate_identities(): void
    {
        $targets = PromotionTargetSet::fromIdentities([
            ['slug' => 'b', 'locale' => 'en'],
            ['locale' => 'en', 'slug' => 'a'],
        ]);

        self::assertSame([
            ['locale' => 'en', 'slug' => 'a'],
            ['locale' => 'en', 'slug' => 'b'],
        ], $targets->identities());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $targets->fingerprint());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('promotion_target_identity_duplicate');
        PromotionTargetSet::fromIdentities([
            ['locale' => 'en', 'slug' => 'a'],
            ['slug' => 'a', 'locale' => 'en'],
        ]);
    }

    public function test_phase_identity_binds_phase_and_exact_target_set(): void
    {
        $context = $this->context();
        $first = PromotionTargetSet::fromIdentities([['locale' => 'en', 'slug' => 'a']]);
        $second = PromotionTargetSet::fromIdentities([['locale' => 'en', 'slug' => 'b']]);

        self::assertNotSame(
            PromotionPhaseIdentity::idempotencyKey($context, 'before_draft_import', $first),
            PromotionPhaseIdentity::idempotencyKey($context, 'before_publication', $first),
        );
        self::assertNotSame(
            PromotionPhaseIdentity::idempotencyKey($context, 'before_draft_import', $first),
            PromotionPhaseIdentity::idempotencyKey($context, 'before_draft_import', $second),
        );
    }

    public function test_snapshot_resolution_is_bound_to_the_exact_lane_package_and_target_set(): void
    {
        $context = $this->context();
        $targets = PromotionTargetSet::fromIdentities([['locale' => 'en', 'slug' => 'a']]);
        $snapshots = app(PromotionRollbackSnapshotService::class);
        $reference = $snapshots->capture(
            $context,
            $targets,
            'test-pack',
            'before_draft_import',
            [['id' => 7, 'locale' => 'en', 'slug' => 'a']],
            ['rows' => [['id' => 99]], 'phase' => 'metadata_phase'],
        );

        $snapshot = $snapshots->resolve($context, $targets, 'test-pack', 'before_draft_import', $reference);
        self::assertSame('test-pack', $snapshot->pack_id);
        self::assertSame([['id' => 7, 'locale' => 'en', 'slug' => 'a']], data_get($snapshot->meta_json, 'rows'));
        self::assertSame('before_draft_import', data_get($snapshot->meta_json, 'phase'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('rollback_snapshot_mismatch');
        $snapshots->resolve($this->context(lane: 'W2'), $targets, 'test-pack', 'before_draft_import', $reference);
    }

    public function test_result_factory_and_shared_harness_require_exact_readback_and_no_boundary_mutation(): void
    {
        $context = $this->context();
        $result = PromotionAdapterResultFactory::make($context, 1, 1, 0, 'content-release-snapshot:1');

        $this->assertExactPhaseResult($result, $context, 'draft-import');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('promotion_readback_count_mismatch');
        PromotionAdapterResultFactory::make($context, 0, 0, 0, null);
    }

    public function test_snapshot_resolution_rejects_a_phase_mismatch(): void
    {
        $context = $this->context();
        $targets = PromotionTargetSet::fromIdentities([['locale' => 'en', 'slug' => 'a']]);
        $snapshots = app(PromotionRollbackSnapshotService::class);
        $reference = $snapshots->capture($context, $targets, 'test-pack', 'before_draft_import', []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('rollback_snapshot_mismatch');
        $snapshots->resolve($context, $targets, 'test-pack', 'before_publication', $reference);
    }

    private function context(string $lane = 'W1'): PromotionContext
    {
        return new PromotionContext(
            packageDirectory: base_path('content_assets/en-content-parity'),
            packageSha256: str_repeat('a', 64),
            lane: $lane,
            subscope: 'test',
            sourceCommit: str_repeat('b', 40),
            executorReleaseSha256: str_repeat('c', 64),
            releasePolicySha256: str_repeat('d', 64),
            workflowRunId: '1',
            workflowRunAttempt: 1,
            workflowSignature: str_repeat('f', 64),
            expectedRowCount: 1,
            idempotencyKey: str_repeat('e', 64),
        );
    }
}
