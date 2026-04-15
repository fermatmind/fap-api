<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchReadinessAuditService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveLaunchReadinessAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_internal_first_wave_launch_readiness_audit_for_job_detail_members_only(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $audit = app(CareerFirstWaveLaunchReadinessAuditService::class)->build()->toArray();
        $members = collect($audit['members'])->keyBy('canonical_slug');

        $this->assertSame('career_first_wave_launch_readiness_audit', $audit['summary_kind']);
        $this->assertSame(CareerFirstWaveLaunchReadinessAuditService::SUMMARY_VERSION, $audit['summary_version']);
        $this->assertSame('career_first_wave_10', $audit['scope']);
        $this->assertSame(10, $audit['counts']['total']);
        $this->assertSame(6, $audit['counts']['launch_ready']);
        $this->assertSame(0, $audit['counts']['candidate_review']);
        $this->assertSame(0, $audit['counts']['hold']);
        $this->assertSame(4, $audit['counts']['blocked']);

        $registeredNurses = $members->get('registered-nurses');
        $softwareDevelopers = $members->get('software-developers');

        $this->assertIsArray($registeredNurses);
        $this->assertSame('career_job_detail', $registeredNurses['member_kind']);
        $this->assertSame('stable', $registeredNurses['launch_tier']);
        $this->assertSame('publish_ready', $registeredNurses['readiness_status']);
        $this->assertSame('indexed', $registeredNurses['lifecycle_state']);
        $this->assertSame('indexable', $registeredNurses['public_index_state']);
        $this->assertTrue($registeredNurses['index_eligible']);
        $this->assertSame('approved', $registeredNurses['reviewer_status']);
        $this->assertSame('exact', $registeredNurses['crosswalk_mode']);
        $this->assertTrue($registeredNurses['allow_strong_claim']);
        $this->assertSame(90, $registeredNurses['confidence_score']);
        $this->assertSame(1, $registeredNurses['next_step_links_count']);
        $this->assertTrue($registeredNurses['family_hub_supporting_route']);
        $this->assertSame([], $registeredNurses['blockers']);
        $this->assertSame('career_first_wave_launch_tier', data_get($registeredNurses, 'evidence_refs.launch_tier.summary_kind'));
        $this->assertSame('registered-nurses', data_get($registeredNurses, 'evidence_refs.next_step_links.canonical_slug'));

        $this->assertIsArray($softwareDevelopers);
        $this->assertSame('hold', $softwareDevelopers['launch_tier']);
        $this->assertSame('blocked_override_eligible', $softwareDevelopers['readiness_status']);
        $this->assertSame('blocked_override_eligible', $softwareDevelopers['blocked_governance_status']);
        $this->assertContains('blocked_governance', $softwareDevelopers['blockers']);
        $this->assertContains('not_index_eligible', $softwareDevelopers['blockers']);
        $this->assertContains('strong_claim_disallowed', $softwareDevelopers['blockers']);
        $this->assertContains('not_publish_ready', $softwareDevelopers['blockers']);

        $this->assertCount(0, array_filter(
            $audit['members'],
            static fn (array $member): bool => ($member['member_kind'] ?? null) === 'career_family_hub'
        ));
        $this->assertArrayNotHasKey('demand_signal', $registeredNurses);
        $this->assertArrayNotHasKey('novelty_score', $registeredNurses);
        $this->assertArrayNotHasKey('canonical_conflict', $registeredNurses);
        $this->assertArrayNotHasKey('trust_freshness_state', $registeredNurses);
    }

    public function test_it_projects_candidate_review_without_claiming_family_hubs_as_members(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $candidate->update([
            'crosswalk_mode' => 'direct_match',
        ]);

        $audit = app(CareerFirstWaveLaunchReadinessAuditService::class)->build()->toArray();
        $members = collect($audit['members'])->keyBy('canonical_slug');
        $candidateRow = $members->get('data-scientists');

        $this->assertSame(5, $audit['counts']['launch_ready']);
        $this->assertSame(1, $audit['counts']['candidate_review']);
        $this->assertSame(0, $audit['counts']['hold']);
        $this->assertSame(4, $audit['counts']['blocked']);

        $this->assertIsArray($candidateRow);
        $this->assertSame('candidate', $candidateRow['launch_tier']);
        $this->assertSame('partial_raw', $candidateRow['readiness_status']);
        $this->assertContains('not_publish_ready', $candidateRow['blockers']);
    }

    public function test_it_can_hold_non_blocked_members_when_current_truth_stays_non_releasable(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $subject = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $subject->update([
            'crosswalk_mode' => 'exact',
        ]);
        $subject->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewer_status' => 'approved',
            'quality' => [
                'confidence_score' => 55,
            ],
        ]);

        $audit = app(CareerFirstWaveLaunchReadinessAuditService::class)->build()->toArray();
        $members = collect($audit['members'])->keyBy('canonical_slug');
        $row = $members->get('data-scientists');

        $this->assertSame(5, $audit['counts']['launch_ready']);
        $this->assertSame(0, $audit['counts']['candidate_review']);
        $this->assertSame(1, $audit['counts']['hold']);
        $this->assertSame(4, $audit['counts']['blocked']);

        $this->assertIsArray($row);
        $this->assertSame('hold', $row['launch_tier']);
        $this->assertSame('partial_raw', $row['readiness_status']);
        $this->assertContains('low_confidence', $row['blockers']);
        $this->assertFalse(in_array('blocked_governance', $row['blockers'], true));
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
