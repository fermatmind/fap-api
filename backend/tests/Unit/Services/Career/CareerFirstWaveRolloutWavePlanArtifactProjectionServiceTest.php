<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutWavePlanArtifactProjectionService;
use App\Domain\Career\Publish\CareerFirstWaveRolloutWavePlanService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveRolloutWavePlanArtifactProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_an_export_safe_rollout_wave_plan_artifact(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $artifact = app(CareerFirstWaveRolloutWavePlanArtifactProjectionService::class)->build()->toArray();
        $members = collect($artifact['members'])->keyBy('canonical_slug');
        $registeredNurses = $members->get('registered-nurses');
        $internalPlan = app(CareerFirstWaveRolloutWavePlanService::class)->build()->toArray();
        $internalMemberSlugs = collect($internalPlan['members'])->pluck('canonical_slug')->values()->all();

        $this->assertSame('career_rollout_wave_plan', $artifact['artifact_kind']);
        $this->assertSame('career.rollout_wave_plan.export.v1', $artifact['artifact_version']);
        $this->assertSame('career_first_wave_10', $artifact['scope']);
        $this->assertSame(['stable', 'candidate', 'hold', 'blocked', 'manual_review_needed'], array_keys($artifact['counts']));
        $this->assertSame(['stable', 'candidate', 'hold', 'blocked'], array_keys($artifact['cohorts']));
        $this->assertSame(['manual_review_needed'], array_keys($artifact['advisory']));
        $this->assertSame($internalMemberSlugs, $members->keys()->values()->all());

        $this->assertIsArray($registeredNurses);
        $this->assertSame([
            'canonical_slug',
            'rollout_cohort',
            'launch_tier',
            'readiness_status',
            'lifecycle_state',
            'public_index_state',
            'supporting_routes',
            'trust_freshness',
        ], array_keys($registeredNurses));
        $this->assertSame([
            'family_hub',
            'next_step_links_count',
        ], array_keys($registeredNurses['supporting_routes']));
        $this->assertSame([
            'review_due_known',
            'review_staleness_state',
        ], array_keys($registeredNurses['trust_freshness']));

        $this->assertArrayNotHasKey('member_kind', $registeredNurses);
        $this->assertArrayNotHasKey('defer_reasons', $registeredNurses);
        $this->assertArrayNotHasKey('blockers', $registeredNurses);
        $this->assertArrayNotHasKey('evidence_refs', $registeredNurses);
        $this->assertArrayNotHasKey('reviewed_at', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('next_review_due_at', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('review_freshness_basis', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('demand_signal', $registeredNurses);
        $this->assertArrayNotHasKey('novelty_score', $registeredNurses);
        $this->assertArrayNotHasKey('canonical_conflict', $registeredNurses);
    }

    public function test_it_keeps_manual_review_needed_in_advisory_without_mutating_primary_cohorts(): void
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

        $internalPlan = app(CareerFirstWaveRolloutWavePlanService::class)->build()->toArray();
        $artifact = app(CareerFirstWaveRolloutWavePlanArtifactProjectionService::class)->build()->toArray();
        $candidateRow = collect($artifact['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertSame($internalPlan['counts']['stable'], $artifact['counts']['stable']);
        $this->assertSame($internalPlan['counts']['candidate'], $artifact['counts']['candidate']);
        $this->assertSame($internalPlan['counts']['hold'], $artifact['counts']['hold']);
        $this->assertSame($internalPlan['counts']['blocked'], $artifact['counts']['blocked']);
        $this->assertSame($internalPlan['cohorts']['stable'], $artifact['cohorts']['stable']);
        $this->assertSame($internalPlan['cohorts']['candidate'], $artifact['cohorts']['candidate']);
        $this->assertSame($internalPlan['cohorts']['hold'], $artifact['cohorts']['hold']);
        $this->assertSame($internalPlan['cohorts']['blocked'], $artifact['cohorts']['blocked']);
        $this->assertSame($internalPlan['cohorts']['manual_review_needed'], $artifact['advisory']['manual_review_needed']);

        $this->assertIsArray($candidateRow);
        $this->assertSame('candidate', $candidateRow['rollout_cohort']);
        $this->assertSame('review_due', data_get($candidateRow, 'trust_freshness.review_staleness_state'));
        $this->assertContains('data-scientists', $artifact['advisory']['manual_review_needed']);
        $this->assertArrayNotHasKey('manual_review_needed', $artifact['cohorts']);
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
