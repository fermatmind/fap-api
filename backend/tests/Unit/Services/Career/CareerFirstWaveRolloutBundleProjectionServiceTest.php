<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutBundleProjectionService;
use App\Domain\Career\Publish\CareerFirstWaveRolloutWavePlanArtifactProjectionService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveRolloutBundleProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_rollout_bundle_and_primary_cohort_lists_from_single_truth_source(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $projected = app(CareerFirstWaveRolloutBundleProjectionService::class)->build();

        $this->assertSame([
            'career-rollout-bundle.json',
            'career-stable-whitelist.json',
            'career-candidate-whitelist.json',
            'career-hold-list.json',
            'career-blocked-list.json',
        ], array_keys($projected));

        $bundle = $projected['career-rollout-bundle.json']->toArray();
        $stableList = $projected['career-stable-whitelist.json']->toArray();
        $candidateList = $projected['career-candidate-whitelist.json']->toArray();
        $holdList = $projected['career-hold-list.json']->toArray();
        $blockedList = $projected['career-blocked-list.json']->toArray();

        $b58Artifact = app(CareerFirstWaveRolloutWavePlanArtifactProjectionService::class)->build()->toArray();
        $bundleMembers = collect($bundle['members'])->keyBy('canonical_slug');
        $registeredNurses = $bundleMembers->get('registered-nurses');

        $this->assertSame('career_rollout_bundle', $bundle['artifact_kind']);
        $this->assertSame('career.rollout_bundle.export.v1', $bundle['artifact_version']);
        $this->assertSame('career_first_wave_10', $bundle['scope']);
        $this->assertSame(['stable', 'candidate', 'hold', 'blocked', 'manual_review_needed'], array_keys($bundle['counts']));
        $this->assertSame(['stable', 'candidate', 'hold', 'blocked'], array_keys($bundle['cohorts']));
        $this->assertSame(['manual_review_needed'], array_keys($bundle['advisory']));
        $this->assertSame($b58Artifact['counts'], $bundle['counts']);
        $this->assertSame($b58Artifact['cohorts'], $bundle['cohorts']);
        $this->assertSame($b58Artifact['advisory'], $bundle['advisory']);

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

        $this->assertSame('career_rollout_cohort_list', $stableList['artifact_kind']);
        $this->assertSame('career.rollout_cohort_list.export.v1', $stableList['artifact_version']);
        $this->assertSame('career_first_wave_10', $stableList['scope']);
        $this->assertSame('stable', $stableList['cohort']);
        $this->assertSame($bundle['cohorts']['stable'], $stableList['members']);

        $this->assertSame('candidate', $candidateList['cohort']);
        $this->assertSame($bundle['cohorts']['candidate'], $candidateList['members']);
        $this->assertSame('hold', $holdList['cohort']);
        $this->assertSame($bundle['cohorts']['hold'], $holdList['members']);
        $this->assertSame('blocked', $blockedList['cohort']);
        $this->assertSame($bundle['cohorts']['blocked'], $blockedList['members']);
    }

    public function test_it_keeps_manual_review_needed_advisory_only_without_primary_list_projection(): void
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

        $projected = app(CareerFirstWaveRolloutBundleProjectionService::class)->build();
        $bundle = $projected['career-rollout-bundle.json']->toArray();

        $this->assertArrayNotHasKey('manual_review_needed', $bundle['cohorts']);
        $this->assertContains('data-scientists', $bundle['advisory']['manual_review_needed']);
        $this->assertGreaterThan(0, $bundle['counts']['manual_review_needed']);
        $this->assertArrayNotHasKey('career-manual-review-needed.json', $projected);
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
