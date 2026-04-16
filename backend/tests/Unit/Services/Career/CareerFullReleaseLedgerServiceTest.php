<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerFullReleaseLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_internal_full_342_release_ledger_with_expected_scope_and_count_boundaries(): void
    {
        $ledger = app(CareerFullReleaseLedgerService::class)->build()->toArray();

        $this->assertSame('career_full_release_ledger', $ledger['ledger_kind'] ?? null);
        $this->assertSame('career.release_ledger.full_342.v1', $ledger['ledger_version'] ?? null);
        $this->assertSame('career_all_342', $ledger['scope'] ?? null);

        $this->assertSame(342, (int) data_get($ledger, 'counts.tracking_counts.expected_total_occupations'));
        $this->assertSame(342, (int) data_get($ledger, 'counts.tracking_counts.tracked_total_occupations'));
        $this->assertSame(0, (int) data_get($ledger, 'counts.tracking_counts.missing_occupations'));
        $this->assertTrue((bool) data_get($ledger, 'counts.tracking_counts.tracking_complete'));
        $this->assertIsBool(data_get($ledger, 'counts.tracking_counts.first_wave_audit_available'));

        $this->assertCount(342, (array) ($ledger['members'] ?? []));
        $this->assertArrayHasKey('public_detail_indexable', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('public_detail_conservative', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('explorer_only', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('family_handoff', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('review_needed', data_get($ledger, 'counts.release_counts', []));
        $this->assertArrayHasKey('blocked', data_get($ledger, 'counts.release_counts', []));

        $this->assertNotSame(
            (int) data_get($ledger, 'counts.tracking_counts.tracked_total_occupations'),
            (int) data_get($ledger, 'counts.release_counts.public_detail_indexable', 0)
            + (int) data_get($ledger, 'counts.release_counts.public_detail_conservative', 0)
        );

        $this->assertSame(
            (int) data_get($ledger, 'counts.tracking_counts.tracked_total_occupations'),
            array_sum((array) data_get($ledger, 'counts.release_counts', []))
        );
    }

    public function test_it_keeps_family_handoff_separate_from_blocked_and_forbids_weak_truth_fields(): void
    {
        $ledger = app(CareerFullReleaseLedgerService::class)->build()->toArray();
        $members = collect((array) ($ledger['members'] ?? []));

        $familyMembers = $members->where('release_cohort', 'family_handoff')->values();
        $this->assertNotEmpty($familyMembers);

        foreach ($familyMembers as $member) {
            $this->assertNotSame('blocked', $member['release_cohort'] ?? null);
        }

        $sample = (array) $members->first();
        $this->assertArrayNotHasKey('demand_signal', $sample);
        $this->assertArrayNotHasKey('novelty_score', $sample);
        $this->assertArrayNotHasKey('canonical_conflict', $sample);
    }
}
