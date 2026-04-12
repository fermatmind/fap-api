<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveDiscoverabilityManifestService;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveDiscoverabilityManifestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_mixed_route_discoverability_manifest_from_current_backend_truth(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $manifest = app(CareerFirstWaveDiscoverabilityManifestService::class)->build()->toArray();
        $routes = collect($manifest['routes']);
        $jobRoutes = $routes->where('route_kind', 'career_job_detail')->keyBy('canonical_slug');
        $familyRoutes = $routes->where('route_kind', 'career_family_hub')->keyBy('canonical_slug');

        $this->assertSame('career_first_wave_discoverability_manifest', $manifest['manifest_kind']);
        $this->assertSame(CareerFirstWaveDiscoverabilityManifestService::MANIFEST_VERSION, $manifest['manifest_version']);
        $this->assertSame(CareerFirstWaveDiscoverabilityManifestService::SCOPE, $manifest['scope']);
        $this->assertNotEmpty($manifest['routes']);
        $this->assertContainsOnly('array', $manifest['routes']);
        $this->assertSame(
            ['career_family_hub', 'career_job_detail'],
            $routes->pluck('route_kind')->unique()->sort()->values()->all()
        );
        $this->assertFalse($routes->contains(static fn (array $row): bool => str_starts_with((string) ($row['canonical_path'] ?? ''), '/career/recommendations')));
        $this->assertFalse($routes->contains(static fn (array $row): bool => (string) ($row['canonical_path'] ?? '') === '/career/jobs'));

        $registeredNurses = $jobRoutes->get('registered-nurses');
        $softwareDevelopers = $jobRoutes->get('software-developers');
        $technologyFamily = $familyRoutes->get('computer-and-information-technology');

        $this->assertIsArray($registeredNurses);
        $this->assertSame('/career/jobs/registered-nurses', $registeredNurses['canonical_path']);
        $this->assertSame('discoverable', $registeredNurses['discoverability_state']);
        $this->assertSame('stable', $registeredNurses['launch_tier']);
        $this->assertSame(['stable_launch_tier'], $registeredNurses['reason_codes']);

        $this->assertIsArray($softwareDevelopers);
        $this->assertSame('excluded', $softwareDevelopers['discoverability_state']);
        $this->assertSame('hold', $softwareDevelopers['launch_tier']);
        $this->assertContains('excluded_blocked_governance', $softwareDevelopers['reason_codes']);
        $this->assertContains('excluded_not_index_eligible', $softwareDevelopers['reason_codes']);

        $this->assertIsArray($technologyFamily);
        $this->assertSame('/career/family/computer-and-information-technology', $technologyFamily['canonical_path']);
        $this->assertSame('discoverable', $technologyFamily['discoverability_state']);
        $this->assertSame(1, $technologyFamily['visible_children_count']);
        $this->assertSame(['visible_children_present'], $technologyFamily['reason_codes']);
    }

    public function test_it_excludes_non_stable_jobs_and_zero_visible_families_with_explicit_states(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $candidate->update([
            'crosswalk_mode' => 'direct_match',
        ]);

        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'blocked-tech-family',
            'title_en' => 'Blocked Tech Family',
            'title_zh' => '受限技术家族',
        ]);

        $familyExcludedOccupation = Occupation::query()->where('canonical_slug', 'registered-nurses')->firstOrFail();
        $familyExcludedOccupation->update([
            'family_id' => $family->id,
            'crosswalk_mode' => 'family_proxy',
        ]);

        $manifest = app(CareerFirstWaveDiscoverabilityManifestService::class)->build()->toArray();
        $routes = collect($manifest['routes']);
        $jobRoutes = $routes->where('route_kind', 'career_job_detail')->keyBy('canonical_slug');
        $familyRoutes = $routes->where('route_kind', 'career_family_hub')->keyBy('canonical_slug');

        $candidateRow = $jobRoutes->get('data-scientists');
        $excludedFamily = $familyRoutes->get('blocked-tech-family');

        $this->assertIsArray($candidateRow);
        $this->assertSame('candidate', $candidateRow['launch_tier']);
        $this->assertSame('excluded', $candidateRow['discoverability_state']);
        $this->assertSame(['excluded_non_stable_tier'], $candidateRow['reason_codes']);

        $this->assertIsArray($excludedFamily);
        $this->assertSame('excluded', $excludedFamily['discoverability_state']);
        $this->assertSame(0, $excludedFamily['visible_children_count']);
        $this->assertSame(['excluded_zero_visible_children'], $excludedFamily['reason_codes']);
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
