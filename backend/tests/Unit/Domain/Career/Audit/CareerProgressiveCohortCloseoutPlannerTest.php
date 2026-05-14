<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerProgressiveCohortCloseoutPlanner;
use Tests\TestCase;

final class CareerProgressiveCohortCloseoutPlannerTest extends TestCase
{
    public function test_closes_out_80_cohort(): void
    {
        $result = (new CareerProgressiveCohortCloseoutPlanner)->closeout(
            liveAcceptance: $this->liveAcceptance(target: 80, baseline: 29, delta: 51),
            totalSlugsPath: '/tmp/career_80_total_slugs.txt',
        )->toArray();

        $this->assertSame('career_progressive_cohort_closeout.v1', $result['schema_version']);
        $this->assertSame('complete', $result['status']);
        $this->assertTrue($result['accepted']);
        $this->assertSame(80, $result['target_public_total']);
        $this->assertSame(29, $result['baseline_count']);
        $this->assertSame(51, $result['delta_count']);
        $this->assertSame(160, $result['expected_locale_rows']);
        $this->assertSame('300_READINESS_1', $result['next_required_action']);
        $this->assertFalse($result['writes_database']);
    }

    public function test_closes_out_300_cohort(): void
    {
        $result = (new CareerProgressiveCohortCloseoutPlanner)->closeout(
            liveAcceptance: $this->liveAcceptance(target: 300, baseline: 80, delta: 220),
            totalSlugsPath: '/tmp/career_300_total_slugs.txt',
        )->toArray();

        $this->assertSame('career_300_total', $result['target']);
        $this->assertSame(300, $result['total_slug_count']);
        $this->assertSame(600, $result['expected_locale_rows']);
        $this->assertSame('800_READINESS_1', $result['next_required_action']);
    }

    public function test_closes_out_800_cohort(): void
    {
        $result = (new CareerProgressiveCohortCloseoutPlanner)->closeout(
            liveAcceptance: $this->liveAcceptance(target: 800, baseline: 300, delta: 500),
            totalSlugsPath: '/tmp/career_800_total_slugs.txt',
        )->toArray();

        $this->assertSame(800, $result['target_public_total']);
        $this->assertSame(1600, $result['expected_locale_rows']);
        $this->assertSame('2786_READINESS_1', $result['next_required_action']);
    }

    public function test_closes_out_2786_cohort(): void
    {
        $result = (new CareerProgressiveCohortCloseoutPlanner)->closeout(
            liveAcceptance: $this->liveAcceptance(target: 2786, baseline: 800, delta: 1986),
            totalSlugsPath: '/tmp/career_2786_total_slugs.txt',
        )->toArray();

        $this->assertSame(2786, $result['target_public_total']);
        $this->assertSame(5572, $result['expected_locale_rows']);
        $this->assertSame('CAREER_2786_FINAL_CLOSEOUT_COMPLETE', $result['next_required_action']);
    }

    public function test_refuses_closeout_when_acceptance_failed(): void
    {
        $artifact = $this->liveAcceptance(target: 300, baseline: 80, delta: 220);
        $artifact['accepted'] = false;
        $artifact['status'] = 'blocked';

        $result = (new CareerProgressiveCohortCloseoutPlanner)->closeout(
            liveAcceptance: $artifact,
            totalSlugsPath: '/tmp/career_300_total_slugs.txt',
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['accepted']);
        $this->assertContains('live_acceptance_not_accepted', array_column($result['blockers'], 'reason'));
    }

    public function test_refuses_closeout_without_total_slug_artifact_path(): void
    {
        $result = (new CareerProgressiveCohortCloseoutPlanner)->closeout(
            liveAcceptance: $this->liveAcceptance(target: 300, baseline: 80, delta: 220),
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('total_slugs_path_missing', array_column($result['blockers'], 'reason'));
    }

    /**
     * @return array<string, mixed>
     */
    private function liveAcceptance(int $target, int $baseline, int $delta): array
    {
        return [
            'schema_version' => 'career_80_total_live_acceptance.v1',
            'status' => 'pass',
            'accepted' => true,
            'read_only' => true,
            'writes_database' => false,
            'target_public_total' => $target,
            'baseline_count' => $baseline,
            'delta_count' => $delta,
            'total_slug_count' => $target,
            'expected_locale_rows' => $target * 2,
            'locales' => ['en', 'zh'],
            'projection_truth' => [
                'found_published' => $target * 2,
            ],
            'release_gate' => [
                'pass_count' => $target * 2,
                'blocked_count' => 0,
            ],
            'surface_equality' => 'pass',
            'mismatch_count' => 0,
            'unexpected_exposure' => 0,
            'failures' => [],
            'sidecars' => [],
        ];
    }
}
