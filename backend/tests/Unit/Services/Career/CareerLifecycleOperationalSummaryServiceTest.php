<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerLifecycleOperationalSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerLifecycleOperationalSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_full_342_lifecycle_operational_summary_without_overwriting_lineage_truth(): void
    {
        $summary = app(CareerLifecycleOperationalSummaryService::class)->build()->toArray();

        $this->assertSame('career_lifecycle_operational_summary', $summary['summary_kind'] ?? null);
        $this->assertSame('career.lifecycle.operational.v1', $summary['summary_version'] ?? null);
        $this->assertSame('career_all_342', $summary['scope'] ?? null);

        $counts = (array) ($summary['counts'] ?? []);
        $this->assertEqualsCanonicalizing([
            'total',
            'with_feedback',
            'without_feedback',
            'with_multiple_snapshots',
            'timeline_active',
            'delta_available',
            'conversion_ready',
        ], array_keys($counts));

        $members = (array) ($summary['members'] ?? []);
        $this->assertCount(342, $members);
        $this->assertSame(342, (int) ($counts['total'] ?? 0));
        $this->assertSame(
            (int) ($counts['total'] ?? 0),
            (int) ($counts['with_feedback'] ?? 0) + (int) ($counts['without_feedback'] ?? 0)
        );

        foreach ($members as $member) {
            $this->assertSame('career_tracked_occupation', $member['member_kind'] ?? null);
            $this->assertContains($member['lifecycle_state'] ?? null, ['baseline_only', 'feedback_active', 'timeline_active']);
            $this->assertContains($member['closure_state'] ?? null, ['baseline_only', 'feedback_active', 'timeline_active', 'conversion_ready']);
        }
    }

    public function test_it_exposes_baseline_member_shape_for_unknown_slug(): void
    {
        $member = app(CareerLifecycleOperationalSummaryService::class)->buildForSlug('non-existent-slug');

        $this->assertSame('career_tracked_occupation', $member['member_kind'] ?? null);
        $this->assertSame('non-existent-slug', $member['canonical_slug'] ?? null);
        $this->assertSame(0, $member['timeline_entry_count'] ?? null);
        $this->assertFalse((bool) ($member['delta_available'] ?? true));
        $this->assertSame('baseline_only', $member['lifecycle_state'] ?? null);
        $this->assertSame('baseline_only', $member['closure_state'] ?? null);
    }
}
