<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveDiscoverabilityManifestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_first_wave_discoverability_manifest_for_supported_route_kinds_only(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/first-wave/discoverability-manifest');

        $response->assertOk()
            ->assertJsonPath('manifest_kind', 'career_first_wave_discoverability_manifest')
            ->assertJsonPath('manifest_version', 'career.discoverability.first_wave.v1')
            ->assertJsonPath('scope', 'career_first_wave_10')
            ->assertJsonStructure([
                'manifest_kind',
                'manifest_version',
                'scope',
                'routes' => [[
                    'route_kind',
                    'canonical_path',
                    'discoverability_state',
                    'reason_codes',
                ]],
            ])
            ->assertJsonMissingPath('recommended_action');

        $routes = collect($response->json('routes'));

        $this->assertSame(
            ['career_family_hub', 'career_job_detail'],
            $routes->pluck('route_kind')->unique()->sort()->values()->all()
        );
        $this->assertFalse($routes->contains(static fn (array $row): bool => str_starts_with((string) ($row['canonical_path'] ?? ''), '/career/recommendations')));
        $this->assertFalse($routes->contains(static fn (array $row): bool => (string) ($row['canonical_path'] ?? '') === '/career/jobs'));
        $this->assertContains(
            'discoverable',
            $routes->pluck('discoverability_state')->unique()->values()->all()
        );
        $this->assertContains(
            'excluded',
            $routes->pluck('discoverability_state')->unique()->values()->all()
        );

        $jobRoutes = $routes->where('route_kind', 'career_job_detail')->keyBy('canonical_slug');
        $familyRoutes = $routes->where('route_kind', 'career_family_hub')->keyBy('canonical_slug');

        $this->assertSame('discoverable', $jobRoutes['registered-nurses']['discoverability_state']);
        $this->assertSame('stable', $jobRoutes['registered-nurses']['launch_tier']);
        $this->assertSame(['stable_launch_tier'], $jobRoutes['registered-nurses']['reason_codes']);

        $this->assertSame('excluded', $jobRoutes['software-developers']['discoverability_state']);
        $this->assertContains('excluded_blocked_governance', $jobRoutes['software-developers']['reason_codes']);
        $this->assertSame('discoverable', $familyRoutes['computer-and-information-technology']['discoverability_state']);
        $this->assertSame(1, $familyRoutes['computer-and-information-technology']['visible_children_count']);
        $this->assertSame(['visible_children_present'], $familyRoutes['computer-and-information-technology']['reason_codes']);
    }

    public function test_it_keeps_candidate_jobs_and_zero_visible_families_explicitly_excluded(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $candidate->update([
            'crosswalk_mode' => 'direct_match',
        ]);

        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'blocked-tech-family-api',
            'title_en' => 'Blocked Tech Family API',
            'title_zh' => '受限技术家族 API',
        ]);

        $familyExcludedOccupation = Occupation::query()->where('canonical_slug', 'registered-nurses')->firstOrFail();
        $familyExcludedOccupation->update([
            'family_id' => $family->id,
            'crosswalk_mode' => 'family_proxy',
        ]);

        $routes = collect($this->getJson('/api/v0.5/career/first-wave/discoverability-manifest')->json('routes'));
        $jobRoutes = $routes->where('route_kind', 'career_job_detail')->keyBy('canonical_slug');
        $familyRoutes = $routes->where('route_kind', 'career_family_hub')->keyBy('canonical_slug');

        $this->assertSame('candidate', $jobRoutes['data-scientists']['launch_tier']);
        $this->assertSame('excluded', $jobRoutes['data-scientists']['discoverability_state']);
        $this->assertSame(['excluded_non_stable_tier'], $jobRoutes['data-scientists']['reason_codes']);

        $this->assertSame('excluded', $familyRoutes['blocked-tech-family-api']['discoverability_state']);
        $this->assertSame(0, $familyRoutes['blocked-tech-family-api']['visible_children_count']);
        $this->assertSame(['excluded_zero_visible_children'], $familyRoutes['blocked-tech-family-api']['reason_codes']);
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
