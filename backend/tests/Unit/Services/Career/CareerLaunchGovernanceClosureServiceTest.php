<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerLaunchGovernanceClosureServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_unified_full_342_governance_closure_summary_and_member_states(): void
    {
        $closure = app(CareerLaunchGovernanceClosureService::class)->build()->toArray();

        $this->assertSame('career_launch_governance_closure', $closure['governance_kind'] ?? null);
        $this->assertSame('career.governance.v1', $closure['governance_version'] ?? null);
        $this->assertSame('career_all_342', $closure['scope'] ?? null);

        $trackingCounts = (array) data_get($closure, 'counts.tracking_counts', []);
        $summary = (array) data_get($closure, 'counts.summary', []);
        $members = (array) ($closure['members'] ?? []);
        $publicStatement = (array) ($closure['public_statement'] ?? []);

        $this->assertSame(342, (int) ($trackingCounts['tracked_total_occupations'] ?? 0));
        $this->assertCount(342, $members);

        $this->assertEqualsCanonicalizing([
            'mature_public_launch_count',
            'public_but_conservative_count',
            'strong_index_ready_count',
            'strong_operations_ready_count',
            'not_yet_ready_count',
        ], array_keys($summary));

        $trackedTotal = (int) ($trackingCounts['tracked_total_occupations'] ?? 0);
        $this->assertSame(
            $trackedTotal,
            (int) ($summary['mature_public_launch_count'] ?? 0)
            + (int) ($summary['public_but_conservative_count'] ?? 0)
            + (int) ($summary['not_yet_ready_count'] ?? 0),
        );
        $this->assertGreaterThanOrEqual((int) ($summary['mature_public_launch_count'] ?? 0), $trackedTotal);
        $this->assertGreaterThanOrEqual((int) ($summary['public_but_conservative_count'] ?? 0), $trackedTotal);
        $this->assertGreaterThanOrEqual((int) ($summary['strong_index_ready_count'] ?? 0), $trackedTotal);
        $this->assertGreaterThanOrEqual((int) ($summary['strong_operations_ready_count'] ?? 0), $trackedTotal);
        $this->assertGreaterThanOrEqual((int) ($summary['not_yet_ready_count'] ?? 0), $trackedTotal);

        $this->assertArrayHasKey('can_claim_mature_public_launch', $publicStatement);
        $this->assertArrayHasKey('can_claim_strong_index_ready', $publicStatement);
        $this->assertArrayHasKey('can_claim_strong_operations_ready', $publicStatement);
        $this->assertArrayHasKey('allowed_external_statement', $publicStatement);

        $allowedGovernance = [
            'mature_public_launch' => true,
            'public_but_conservative' => true,
            'not_yet_mature' => true,
        ];
        $allowedOperations = [
            'strong_operations_ready' => true,
            'not_strong_operations_ready' => true,
        ];

        foreach ($members as $member) {
            $this->assertArrayHasKey((string) ($member['governance_state'] ?? ''), $allowedGovernance);
            $this->assertArrayHasKey((string) ($member['operations_state'] ?? ''), $allowedOperations);
            $this->assertArrayHasKey('release_state', $member);
            $this->assertArrayHasKey('strong_index_state', $member);
            $this->assertArrayHasKey('blocking_reasons', $member);
            $this->assertArrayHasKey('evidence_refs', $member);
            $this->assertArrayNotHasKey('demand_signal', $member);
            $this->assertArrayNotHasKey('novelty_score', $member);
            $this->assertArrayNotHasKey('canonical_conflict', $member);
        }
    }
}
