<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchTierSummaryService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveLaunchTierSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_machine_coded_first_wave_launch_tier_summary_from_current_truth(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $summary = app(CareerFirstWaveLaunchTierSummaryService::class)->build()->toArray();
        $occupations = collect($summary['occupations'])->keyBy('canonical_slug');

        $this->assertSame('career_first_wave_launch_tier', $summary['summary_kind']);
        $this->assertSame(CareerFirstWaveLaunchTierSummaryService::SUMMARY_VERSION, $summary['summary_version']);
        $this->assertSame(CareerFirstWaveLaunchTierSummaryService::SCOPE, $summary['scope']);
        $this->assertSame(10, $summary['counts']['total']);
        $this->assertSame(6, $summary['counts']['stable']);
        $this->assertSame(0, $summary['counts']['candidate']);
        $this->assertSame(4, $summary['counts']['hold']);
        $this->assertSame('software-developers', $summary['occupations'][0]['canonical_slug']);
        $this->assertSame('management-analysts', $summary['occupations'][9]['canonical_slug']);

        $registeredNurses = $occupations->get('registered-nurses');
        $softwareDevelopers = $occupations->get('software-developers');

        $this->assertIsArray($registeredNurses);
        $this->assertSame('stable', $registeredNurses['launch_tier']);
        $this->assertSame('publish_ready', $registeredNurses['readiness_status']);
        $this->assertSame('indexed', $registeredNurses['lifecycle_state']);
        $this->assertSame('indexable', $registeredNurses['public_index_state']);
        $this->assertTrue($registeredNurses['index_eligible']);
        $this->assertSame('approved', $registeredNurses['reviewer_status']);
        $this->assertContains('stable_launch_ready', $registeredNurses['reason_codes']);

        $this->assertIsArray($softwareDevelopers);
        $this->assertSame('hold', $softwareDevelopers['launch_tier']);
        $this->assertSame('blocked_override_eligible', $softwareDevelopers['blocked_governance_status']);
        $this->assertContains('hold_blocked_governance', $softwareDevelopers['reason_codes']);
        $this->assertContains('hold_not_index_eligible', $softwareDevelopers['reason_codes']);
    }

    public function test_it_can_classify_candidate_without_conflating_lifecycle_state(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $candidate->update([
            'crosswalk_mode' => 'direct_match',
        ]);

        $summary = app(CareerFirstWaveLaunchTierSummaryService::class)->build()->toArray();
        $occupations = collect($summary['occupations'])->keyBy('canonical_slug');
        $candidateRow = $occupations->get('data-scientists');

        $this->assertSame(5, $summary['counts']['stable']);
        $this->assertSame(1, $summary['counts']['candidate']);
        $this->assertSame(4, $summary['counts']['hold']);

        $this->assertIsArray($candidateRow);
        $this->assertSame('candidate', $candidateRow['launch_tier']);
        $this->assertSame('indexed', $candidateRow['lifecycle_state']);
        $this->assertSame('direct_match', $candidateRow['crosswalk_mode']);
        $this->assertSame(['candidate_review_required'], $candidateRow['reason_codes']);
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
