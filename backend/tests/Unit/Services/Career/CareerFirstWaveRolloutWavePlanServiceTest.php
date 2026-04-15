<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchManifestService;
use App\Domain\Career\Publish\CareerFirstWaveRolloutWavePlanService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveRolloutWavePlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_internal_rollout_wave_plan_with_job_detail_members_only(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $plan = app(CareerFirstWaveRolloutWavePlanService::class)->build()->toArray();
        $members = collect($plan['members'])->keyBy('canonical_slug');
        $registeredNurses = $members->get('registered-nurses');

        $this->assertSame('career_first_wave_rollout_wave_plan', $plan['plan_kind']);
        $this->assertSame('career.rollout_wave_plan.first_wave.v1', $plan['plan_version']);
        $this->assertSame('career_first_wave_10', $plan['scope']);
        $this->assertSame(['stable', 'candidate', 'hold', 'blocked', 'manual_review_needed'], array_keys($plan['counts']));
        $this->assertSame(['stable', 'candidate', 'hold', 'blocked', 'manual_review_needed'], array_keys($plan['cohorts']));
        $this->assertSame(10, count($plan['members']));

        $this->assertIsArray($registeredNurses);
        $this->assertSame([
            'member_kind',
            'canonical_slug',
            'rollout_cohort',
            'launch_tier',
            'readiness_status',
            'lifecycle_state',
            'public_index_state',
            'supporting_routes',
            'trust_freshness',
            'defer_reasons',
        ], array_keys($registeredNurses));
        $this->assertSame('career_job_detail', $registeredNurses['member_kind']);
        $this->assertSame([
            'family_hub',
            'next_step_links_count',
        ], array_keys($registeredNurses['supporting_routes']));
        $this->assertSame([
            'review_due_known',
            'review_staleness_state',
        ], array_keys($registeredNurses['trust_freshness']));

        $this->assertCount(0, array_filter(
            $plan['members'],
            static fn (array $member): bool => ($member['member_kind'] ?? null) === 'career_family_hub'
        ));

        $this->assertArrayNotHasKey('evidence_refs', $registeredNurses);
        $this->assertArrayNotHasKey('blockers', $registeredNurses);
        $this->assertArrayNotHasKey('reviewed_at', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('next_review_due_at', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('demand_signal', $registeredNurses);
        $this->assertArrayNotHasKey('novelty_score', $registeredNurses);
        $this->assertArrayNotHasKey('canonical_conflict', $registeredNurses);
    }

    public function test_it_keeps_manual_review_needed_as_advisory_without_mutating_primary_cohorts(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $candidate->update([
            'crosswalk_mode' => 'direct_match',
        ]);
        $candidate->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewed_at' => now()->subDay(),
            'next_review_due_at' => now()->subMinute(),
        ]);

        $manifest = app(CareerFirstWaveLaunchManifestService::class)->build()->toArray();
        $plan = app(CareerFirstWaveRolloutWavePlanService::class)->build()->toArray();
        $members = collect($plan['members'])->keyBy('canonical_slug');
        $candidateRow = $members->get('data-scientists');

        $this->assertSame($manifest['counts']['stable'], $plan['counts']['stable']);
        $this->assertSame($manifest['counts']['candidate'], $plan['counts']['candidate']);
        $this->assertSame($manifest['counts']['hold'], $plan['counts']['hold']);
        $this->assertSame($manifest['counts']['blocked'], $plan['counts']['blocked']);
        $this->assertSame($manifest['groups']['stable'], $plan['cohorts']['stable']);
        $this->assertSame($manifest['groups']['candidate'], $plan['cohorts']['candidate']);
        $this->assertSame($manifest['groups']['hold'], $plan['cohorts']['hold']);
        $this->assertSame($manifest['groups']['blocked'], $plan['cohorts']['blocked']);

        $this->assertIsArray($candidateRow);
        $this->assertSame('candidate', $candidateRow['rollout_cohort']);
        $this->assertSame('review_due', data_get($candidateRow, 'trust_freshness.review_staleness_state'));
        $this->assertContains('data-scientists', $plan['cohorts']['manual_review_needed']);
        $this->assertGreaterThan(0, $plan['counts']['manual_review_needed']);
        $this->assertArrayNotHasKey('promotion_candidate_ready', $plan['cohorts']);
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
