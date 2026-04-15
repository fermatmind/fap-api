<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchManifestService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveLaunchManifestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_internal_job_detail_only_launch_manifest_with_backend_owned_smoke_matrix(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $manifest = app(CareerFirstWaveLaunchManifestService::class)->build()->toArray();
        $members = collect($manifest['members'])->keyBy('canonical_slug');
        $registeredNurses = $members->get('registered-nurses');

        $this->assertSame('career_first_wave_launch_manifest', $manifest['manifest_kind']);
        $this->assertSame('career.launch_manifest.first_wave.v1', $manifest['manifest_version']);
        $this->assertSame('career_first_wave_10', $manifest['scope']);
        $this->assertSame([
            'total' => 10,
            'stable' => 6,
            'candidate' => 0,
            'hold' => 0,
            'blocked' => 4,
        ], $manifest['counts']);
        $this->assertCount(6, $manifest['groups']['stable']);
        $this->assertContains('registered-nurses', $manifest['groups']['stable']);

        $this->assertIsArray($registeredNurses);
        $this->assertSame('career_job_detail', $registeredNurses['member_kind']);
        $this->assertArrayHasKey('supporting_routes', $registeredNurses);
        $this->assertArrayHasKey('smoke_matrix', $registeredNurses);
        $this->assertArrayHasKey('trust_freshness', $registeredNurses);

        $this->assertSame([
            'job_detail_route_known',
            'discoverable_route_known',
            'seo_contract_present',
            'structured_data_authority_present',
            'trust_freshness_present',
            'family_support_route_present',
            'next_step_support_present',
        ], array_keys($registeredNurses['smoke_matrix']));
        $this->assertTrue($registeredNurses['smoke_matrix']['job_detail_route_known']);
        $this->assertTrue($registeredNurses['smoke_matrix']['discoverable_route_known']);
        $this->assertTrue($registeredNurses['smoke_matrix']['seo_contract_present']);
        $this->assertTrue($registeredNurses['smoke_matrix']['structured_data_authority_present']);
        $this->assertTrue($registeredNurses['smoke_matrix']['trust_freshness_present']);
        $this->assertTrue($registeredNurses['smoke_matrix']['family_support_route_present']);
        $this->assertTrue($registeredNurses['smoke_matrix']['next_step_support_present']);

        $this->assertSame(true, data_get($registeredNurses, 'supporting_routes.family_hub'));
        $this->assertSame(1, data_get($registeredNurses, 'supporting_routes.next_step_links_count'));

        $this->assertCount(0, array_filter(
            $manifest['members'],
            static fn (array $member): bool => ($member['member_kind'] ?? null) === 'career_family_hub'
        ));
        $this->assertArrayNotHasKey('demand_signal', $registeredNurses);
        $this->assertArrayNotHasKey('novelty_score', $registeredNurses);
        $this->assertArrayNotHasKey('canonical_conflict', $registeredNurses);
        $this->assertArrayNotHasKey('frontend_smoke_passed', $registeredNurses['smoke_matrix']);
        $this->assertArrayNotHasKey('jsonld_emitted', $registeredNurses['smoke_matrix']);
        $this->assertArrayNotHasKey('attribution_fired', $registeredNurses['smoke_matrix']);
    }

    public function test_it_preserves_existing_group_truth_without_promoting_lifecycle_or_freshness_to_primary_axes(): void
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
        $candidateRow = collect($manifest['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertSame([
            'total' => 10,
            'stable' => 5,
            'candidate' => 1,
            'hold' => 0,
            'blocked' => 4,
        ], $manifest['counts']);
        $this->assertSame(['data-scientists'], $manifest['groups']['candidate']);

        $this->assertIsArray($candidateRow);
        $this->assertSame('candidate', $candidateRow['launch_tier']);
        $this->assertSame('review_due', data_get($candidateRow, 'trust_freshness.review_staleness_state'));
        $this->assertContains('not_publish_ready', $candidateRow['blockers']);
        $this->assertFalse(in_array('review_due', $candidateRow['blockers'], true));
        $this->assertArrayNotHasKey('promotion_candidate', $manifest['groups']);
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
