<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Expansion\CanonicalExpansionManifestService;
use App\Domain\Career\Expansion\CanonicalRolloutBatchStateMachine;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use PHPUnit\Framework\TestCase;

final class CanonicalRolloutBatchStateMachineTest extends TestCase
{
    public function test_it_plans_publish_transition_only_after_governance_pass(): void
    {
        $result = (new CanonicalRolloutBatchStateMachine)->transition(
            $this->manifest(),
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
            ['status' => 'pass'],
        );

        $this->assertSame('planned', $result['status']);
        $this->assertFalse($result['writes_database']);
        $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED, data_get($result, 'updated_manifest.projection_state'));
        $this->assertSame(CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED, data_get($result, 'updated_manifest.rollout_state'));
    }

    public function test_it_blocks_publish_without_governance_pass(): void
    {
        $result = (new CanonicalRolloutBatchStateMachine)->transition(
            $this->manifest(),
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
            ['status' => 'blocked'],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('publish_requires_governance_pass', data_get($result, 'failures.0.reason'));
    }

    public function test_it_plans_rollback_from_published_to_candidate(): void
    {
        $result = (new CanonicalRolloutBatchStateMachine)->transition(
            $this->manifest([
                'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
                'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            ]),
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
        );

        $this->assertSame('planned', $result['status']);
        $this->assertSame(CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE, data_get($result, 'updated_manifest.projection_state'));
    }

    public function test_it_preserves_quarantine_projection_state(): void
    {
        $result = (new CanonicalRolloutBatchStateMachine)->transition(
            $this->manifest([
                'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
                'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            ]),
            CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED,
        );

        $this->assertSame('planned', $result['status']);
        $this->assertSame(CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED, data_get($result, 'updated_manifest.rollout_state'));
        $this->assertSame(CareerRuntimePublishProjectionService::STATE_QUARANTINED, data_get($result, 'updated_manifest.projection_state'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function manifest(array $overrides = []): array
    {
        return array_merge([
            'batch_id' => 'batch-001',
            'batch_size' => 1,
            'slugs' => ['actors'],
            'locales' => ['en', 'zh'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            'release_gate_required' => true,
            'surface_equality_required' => true,
            'rollback_group' => ['actors'],
            'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
        ], $overrides);
    }
}
