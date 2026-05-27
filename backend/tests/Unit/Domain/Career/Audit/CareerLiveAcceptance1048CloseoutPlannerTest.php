<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\Career80TotalLiveAcceptancePlanner;
use App\Domain\Career\Audit\CareerProgressiveCohortCloseoutPlanner;
use Tests\TestCase;

final class CareerLiveAcceptance1048CloseoutPlannerTest extends TestCase
{
    public function test_detail_ready_1048_live_acceptance_and_closeout_use_product_visible_counts(): void
    {
        $liveAcceptance = (new Career80TotalLiveAcceptancePlanner)->plan(
            targetDelta: $this->targetDelta(),
            liveAcceptance: $this->acceptedLiveAcceptance(),
            targetPublicTotal: 1048,
            target: 'detail_ready_1048',
        )->toArray();

        $this->assertSame('pass', $liveAcceptance['status']);
        $this->assertSame('DETAIL_READY_1048_LIVE_ACCEPTANCE_COMPLETE', $liveAcceptance['next_required_action']);

        $closeout = (new CareerProgressiveCohortCloseoutPlanner)->closeout(
            liveAcceptance: $liveAcceptance,
            totalSlugsPath: '/tmp/career_detail_ready_1048_total_slugs.txt',
        )->toArray();

        $this->assertSame('complete', $closeout['status']);
        $this->assertSame('detail_ready_1048', $closeout['target']);
        $this->assertSame('CAREER_DETAIL_READY_1048_CLOSEOUT_COMPLETE', $closeout['next_required_action']);
        $this->assertTrue(data_get($closeout, 'acceptance_summary.full_visible_publication_gate.product_claim.visible_detail_claim_allowed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function targetDelta(): array
    {
        $baseline = $this->slugs('current', 30);
        $delta = $this->slugs('delta', 1018);

        return [
            'schema_version' => 'career_progressive_cohort_delta_plan.v1',
            'status' => 'pass',
            'target' => 'detail_ready_1048',
            'read_only' => true,
            'writes_database' => false,
            'current_public_total' => 30,
            'target_public_total' => 1048,
            'delta_slug_count' => 1018,
            'current_public_slugs' => $baseline,
            'delta_promotion_slugs' => $delta,
            'recommended_rollout_delta_slugs' => $delta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptedLiveAcceptance(): array
    {
        return [
            'status' => 'pass',
            'accepted' => true,
            'expected_rows' => 2096,
            'target_public_total' => 1048,
            'read_only' => true,
            'writes_database' => false,
            'product_surface' => [
                'directory_member_count' => 1048,
                'career_jobs_item_count' => 1048,
                'detail_ready_count' => 1048,
                'public_detail_indexable_count' => 1048,
                'canonical_public_slug_count' => 1048,
            ],
            'found_published' => 2096,
            'release_gate' => [
                'pass_count' => 2096,
                'blocked_count' => 0,
            ],
            'failures' => [],
            'sidecars' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%04d', $prefix, $i);
        }

        return $slugs;
    }
}
