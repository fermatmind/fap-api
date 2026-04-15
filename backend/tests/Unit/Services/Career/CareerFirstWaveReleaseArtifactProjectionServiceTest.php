<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchManifestService;
use App\Domain\Career\Publish\CareerFirstWaveReleaseArtifactProjectionService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveReleaseArtifactProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_a_narrowed_launch_manifest_artifact_with_only_export_safe_fields(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $artifacts = app(CareerFirstWaveReleaseArtifactProjectionService::class)->build();
        $launchManifest = $artifacts['career-launch-manifest.json']->toArray();
        $members = collect($launchManifest['members'])->keyBy('canonical_slug');
        $registeredNurses = $members->get('registered-nurses');
        $internalMemberSlugs = collect(app(CareerFirstWaveLaunchManifestService::class)->build()->toArray()['members'])
            ->pluck('canonical_slug')
            ->values()
            ->all();

        $this->assertSame('career_launch_manifest', $launchManifest['artifact_kind']);
        $this->assertSame('career.launch_manifest.export.v1', $launchManifest['artifact_version']);
        $this->assertSame('career_first_wave_10', $launchManifest['scope']);
        $this->assertSame([
            'total' => 10,
            'stable' => 6,
            'candidate' => 0,
            'hold' => 0,
            'blocked' => 4,
        ], $launchManifest['counts']);
        $this->assertContains('registered-nurses', $launchManifest['groups']['stable']);
        $this->assertSame($internalMemberSlugs, $members->keys()->values()->all());

        $this->assertSame([
            'canonical_slug',
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

        $this->assertSame(true, data_get($registeredNurses, 'supporting_routes.family_hub'));
        $this->assertSame(1, data_get($registeredNurses, 'supporting_routes.next_step_links_count'));
        $this->assertArrayNotHasKey('member_kind', $registeredNurses);
        $this->assertArrayNotHasKey('blockers', $registeredNurses);
        $this->assertArrayNotHasKey('evidence_refs', $registeredNurses);
        $this->assertArrayNotHasKey('reviewed_at', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('next_review_due_at', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('review_freshness_basis', $registeredNurses['trust_freshness']);
        $this->assertArrayNotHasKey('demand_signal', $registeredNurses);
        $this->assertArrayNotHasKey('novelty_score', $registeredNurses);
        $this->assertArrayNotHasKey('canonical_conflict', $registeredNurses);
    }

    public function test_it_projects_a_smoke_matrix_artifact_with_backend_owned_authority_presence_only(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $artifacts = app(CareerFirstWaveReleaseArtifactProjectionService::class)->build();
        $smokeMatrix = $artifacts['career-smoke-matrix.json']->toArray();
        $members = collect($smokeMatrix['members'])->keyBy('canonical_slug');
        $registeredNurses = $members->get('registered-nurses');

        $this->assertSame('career_smoke_matrix', $smokeMatrix['artifact_kind']);
        $this->assertSame('career.smoke_matrix.export.v1', $smokeMatrix['artifact_version']);
        $this->assertSame('career_first_wave_10', $smokeMatrix['scope']);
        $this->assertSame([
            'canonical_slug',
            'smoke_matrix',
        ], array_keys($registeredNurses));
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

        $this->assertArrayNotHasKey('frontend_smoke_passed', $registeredNurses['smoke_matrix']);
        $this->assertArrayNotHasKey('jsonld_emitted', $registeredNurses['smoke_matrix']);
        $this->assertArrayNotHasKey('attribution_fired', $registeredNurses['smoke_matrix']);
    }

    public function test_it_keeps_b54_as_the_source_of_truth_and_does_not_mutate_internal_members(): void
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

        $projection = app(CareerFirstWaveReleaseArtifactProjectionService::class)->build();
        $launchManifest = $projection['career-launch-manifest.json']->toArray();
        $candidateRow = collect($launchManifest['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertSame([
            'total' => 10,
            'stable' => 5,
            'candidate' => 1,
            'hold' => 0,
            'blocked' => 4,
        ], $launchManifest['counts']);
        $this->assertSame(['data-scientists'], $launchManifest['groups']['candidate']);
        $this->assertSame('review_due', data_get($candidateRow, 'trust_freshness.review_staleness_state'));

        $internalManifest = app(CareerFirstWaveLaunchManifestService::class)->build()->toArray();
        $internalCandidate = collect($internalManifest['members'])->keyBy('canonical_slug')->get('data-scientists');

        $this->assertArrayHasKey('blockers', $internalCandidate);
        $this->assertArrayHasKey('evidence_refs', $internalCandidate);
        $this->assertContains('not_publish_ready', $internalCandidate['blockers']);
        $this->assertArrayNotHasKey('promotion_candidate', $launchManifest['groups']);
    }

    public function test_it_does_not_invent_trust_freshness_summary_when_source_evidence_is_missing(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $internalManifest = app(CareerFirstWaveLaunchManifestService::class)->build()->toArray();
        $member = collect($internalManifest['members'])->firstWhere('canonical_slug', 'registered-nurses');

        $this->assertIsArray($member);
        $this->assertArrayHasKey('trust_freshness', $member);

        unset($member['trust_freshness']);

        $manifestWithoutFreshness = $internalManifest;
        $manifestWithoutFreshness['members'] = array_map(
            static fn (array $row): array => ($row['canonical_slug'] ?? null) === 'registered-nurses' ? $member : $row,
            $manifestWithoutFreshness['members'],
        );

        $service = app(CareerFirstWaveReleaseArtifactProjectionService::class);
        $reflection = new \ReflectionClass(CareerFirstWaveReleaseArtifactProjectionService::class);
        $method = $reflection->getMethod('buildLaunchManifestArtifact');
        $method->setAccessible(true);

        $artifact = $method->invoke($service, $manifestWithoutFreshness);
        $row = collect($artifact->toArray()['members'])
            ->firstWhere('canonical_slug', 'registered-nurses');

        $this->assertArrayHasKey('trust_freshness', $row);
        $this->assertNull($row['trust_freshness']);
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
