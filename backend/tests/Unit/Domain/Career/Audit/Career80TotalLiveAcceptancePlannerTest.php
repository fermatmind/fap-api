<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\Career80TotalLiveAcceptancePlanner;
use Tests\TestCase;

final class Career80TotalLiveAcceptancePlannerTest extends TestCase
{
    public function test_plans_29_baseline_plus_51_delta_accounting(): void
    {
        $result = (new Career80TotalLiveAcceptancePlanner)->plan($this->targetDelta())->toArray();

        $this->assertSame('career_80_total_live_acceptance.v1', $result['schema_version']);
        $this->assertSame('planned', $result['status']);
        $this->assertFalse($result['accepted']);
        $this->assertSame(80, $result['target_public_total']);
        $this->assertSame(29, $result['baseline_count']);
        $this->assertSame(51, $result['delta_count']);
        $this->assertSame(80, $result['total_slug_count']);
        $this->assertSame(160, $result['expected_locale_rows']);
        $this->assertFalse($result['writes_database']);
        $this->assertFalse($result['apply_allowed']);
        $this->assertFalse($result['live_crawl_executed']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_passes_when_accepted_live_acceptance_artifact_is_supplied(): void
    {
        $result = (new Career80TotalLiveAcceptancePlanner)->plan(
            targetDelta: $this->targetDelta(),
            deltaManifest: $this->deltaManifest(),
            liveAcceptance: $this->liveAcceptance(accepted: true),
        )->toArray();

        $this->assertSame('pass', $result['status']);
        $this->assertTrue($result['accepted']);
        $this->assertSame('80_TOTAL_LIVE_ACCEPTANCE_COMPLETE', $result['next_required_action']);
    }

    public function test_blocks_when_target_total_does_not_match_combined_slugs(): void
    {
        $result = (new Career80TotalLiveAcceptancePlanner)->plan(
            $this->targetDelta(delta: ['delta-001']),
            targetPublicTotal: 80,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('target_public_total_mismatch', array_column($result['blockers'], 'reason'));
    }

    public function test_blocks_when_delta_manifest_slug_set_differs(): void
    {
        $manifest = $this->deltaManifest();
        $manifest['slugs'] = $this->slugs('other-delta', 51);

        $result = (new Career80TotalLiveAcceptancePlanner)->plan(
            targetDelta: $this->targetDelta(),
            deltaManifest: $manifest,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('delta_manifest_slug_mismatch', array_column($result['blockers'], 'reason'));
    }

    public function test_blocks_when_live_acceptance_artifact_is_not_accepted(): void
    {
        $result = (new Career80TotalLiveAcceptancePlanner)->plan(
            targetDelta: $this->targetDelta(),
            liveAcceptance: $this->liveAcceptance(accepted: false),
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['accepted']);
        $this->assertContains('live_acceptance_not_accepted', array_column($result['blockers'], 'reason'));
    }

    public function test_blocks_when_live_acceptance_expected_rows_do_not_match(): void
    {
        $result = (new Career80TotalLiveAcceptancePlanner)->plan(
            targetDelta: $this->targetDelta(),
            liveAcceptance: $this->liveAcceptance(accepted: true, expectedRows: 102),
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('live_acceptance_expected_rows_mismatch', array_column($result['blockers'], 'reason'));
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%03d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @param  list<string>|null  $baseline
     * @param  list<string>|null  $delta
     * @return array<string, mixed>
     */
    private function targetDelta(?array $baseline = null, ?array $delta = null): array
    {
        $baseline ??= $this->slugs('baseline', 29);
        $delta ??= $this->slugs('delta', 51);

        return [
            'schema_version' => 'career_80_target_delta.v1',
            'status' => 'pass',
            'read_only' => true,
            'writes_database' => false,
            'target_public_total' => 80,
            'published_baseline_count' => count($baseline),
            'delta_promotion_count' => count($delta),
            'published_baseline_slugs' => $baseline,
            'delta_promotion_slugs' => $delta,
            'recommended_rollout_delta_slugs' => $delta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deltaManifest(): array
    {
        $delta = $this->slugs('delta', 51);

        return [
            'schema_version' => 'career_delta_rollout_manifest.v1',
            'status' => 'pass',
            'target_public_total' => 80,
            'published_baseline_count' => 29,
            'delta_slug_count' => 51,
            'expected_delta_locale_rows' => 102,
            'slugs' => $delta,
            'rollback_group' => $delta,
            'apply_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveAcceptance(bool $accepted, int $expectedRows = 160): array
    {
        return [
            'status' => $accepted ? 'pass' : 'fail',
            'accepted' => $accepted,
            'expected_rows' => $expectedRows,
            'read_only' => true,
            'writes_database' => false,
        ];
    }
}
