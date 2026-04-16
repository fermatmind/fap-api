<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Operations\CareerCrosswalkBacklogConvergenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerCrosswalkBacklogConvergenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_internal_convergence_snapshot_with_unresolved_mode_split_and_patch_time_aging_basis(): void
    {
        $snapshot = app(CareerCrosswalkBacklogConvergenceService::class)->build()->toArray();

        $this->assertSame('career_crosswalk_backlog_convergence', $snapshot['authority_kind'] ?? null);
        $this->assertSame('career.crosswalk_convergence.v1', $snapshot['authority_version'] ?? null);
        $this->assertSame('career_all_342', $snapshot['scope'] ?? null);

        $trackingCounts = (array) ($snapshot['tracking_counts'] ?? []);
        $trackedTotal = (int) ($trackingCounts['tracked_total_occupations'] ?? 0);
        $expectedTotal = (int) ($trackingCounts['expected_total_occupations'] ?? 0);
        $this->assertGreaterThanOrEqual(0, $trackedTotal);
        $this->assertGreaterThanOrEqual(0, $expectedTotal);
        if ($expectedTotal > 0) {
            $this->assertLessThanOrEqual($trackedTotal, $expectedTotal);
        }

        $counts = (array) ($snapshot['counts'] ?? []);
        $this->assertEqualsCanonicalizing([
            'unresolved_local_heavy_interpretation',
            'unresolved_family_proxy',
            'unresolved_unmapped',
            'unresolved_functional_equivalent',
            'resolved_by_approved_patch',
            'resolved_by_override',
            'family_handoff',
            'review_needed',
            'blocked',
            'still_unresolved',
        ], array_keys($counts));

        $aging = (array) ($snapshot['aging'] ?? []);
        $this->assertSame('latest_unresolved_patch_created_at', $aging['metric_basis'] ?? null);
        $this->assertArrayNotHasKey('queue_opened_at', $aging);

        $coverage = (array) ($snapshot['patch_coverage'] ?? []);
        $this->assertArrayHasKey('approved_count', $coverage);
        $this->assertArrayHasKey('rejected_count', $coverage);
        $this->assertArrayHasKey('superseded_count', $coverage);
        $this->assertArrayHasKey('unresolved_without_approved_patch', $coverage);

        $members = (array) ($snapshot['members'] ?? []);
        $allowedStates = [
            'still_unresolved' => true,
            'resolved_by_approved_patch' => true,
            'family_handoff' => true,
            'review_needed' => true,
            'blocked' => true,
        ];

        foreach ($members as $member) {
            $state = (string) ($member['convergence_state'] ?? '');
            $this->assertArrayHasKey($state, $allowedStates);
        }

        $blockedFamilyMembers = array_filter($members, static function (array $member): bool {
            if (($member['convergence_state'] ?? null) !== 'blocked') {
                return false;
            }

            return ($member['resolved_target_kind'] ?? null) === 'family';
        });
        $this->assertCount(0, $blockedFamilyMembers);
    }

    public function test_it_keeps_family_proxy_unresolved_until_approved_override_and_separates_family_handoff(): void
    {
        $service = app(CareerCrosswalkBacklogConvergenceService::class);
        $resolver = new \ReflectionMethod($service, 'resolveConvergenceState');
        $resolver->setAccessible(true);

        $stillUnresolvedFamilyProxy = $resolver->invokeArgs($service, [
            [
                'release_cohort' => 'family_handoff',
                'blocker_reasons' => [],
            ],
            [
                'candidate_target_kind' => 'family',
            ],
            [
                'override_applied' => false,
                'resolved_target_kind' => 'occupation',
            ],
            false,
        ]);
        $this->assertSame('still_unresolved', $stillUnresolvedFamilyProxy);

        $familyHandoff = $resolver->invokeArgs($service, [
            [
                'release_cohort' => 'family_handoff',
                'blocker_reasons' => [],
            ],
            [
                'candidate_target_kind' => 'family',
            ],
            [
                'override_applied' => true,
                'resolved_target_kind' => 'family',
            ],
            true,
        ]);
        $this->assertSame('family_handoff', $familyHandoff);

        $resolvedByPatch = $resolver->invokeArgs($service, [
            [
                'release_cohort' => 'review_needed',
                'blocker_reasons' => [],
            ],
            [
                'candidate_target_kind' => 'occupation',
            ],
            [
                'override_applied' => true,
                'resolved_target_kind' => 'occupation',
            ],
            true,
        ]);
        $this->assertSame('resolved_by_approved_patch', $resolvedByPatch);
    }
}
