<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Dataset\CareerFullDatasetAuthorityBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFullDatasetAuthorityBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_full_342_dataset_authority_with_included_excluded_truth(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerFullDatasetAuthorityBuilder::class)->build()->toArray();

        $this->assertSame('career_full_dataset_authority', $authority['authority_kind'] ?? null);
        $this->assertSame('career.dataset_authority.full_342.v1', $authority['authority_version'] ?? null);
        $this->assertSame('career_all_342_occupations_dataset', $authority['dataset_key'] ?? null);
        $this->assertSame('career_all_342', $authority['dataset_scope'] ?? null);
        $this->assertSame('career_tracked_occupation', $authority['member_kind'] ?? null);
        $this->assertSame(342, (int) ($authority['member_count'] ?? 0));
        $this->assertSame(342, (int) data_get($authority, 'tracking_counts.tracked_total_occupations', 0));
        $this->assertTrue((bool) data_get($authority, 'tracking_counts.tracking_complete', false));

        $this->assertSame(342, (int) data_get($authority, 'summary.included_count', 0) + (int) data_get($authority, 'summary.excluded_count', 0));
        $this->assertIsArray(data_get($authority, 'summary.release_cohort_counts'));
        $this->assertIsArray(data_get($authority, 'summary.public_index_state_counts'));
        $this->assertIsArray(data_get($authority, 'summary.strong_index_decision_counts'));
        $this->assertIsArray(data_get($authority, 'facet_distributions.family'));
        $this->assertIsArray(data_get($authority, 'facet_distributions.publish_track'));

        $members = (array) ($authority['members'] ?? []);
        $this->assertCount(342, $members);

        $excludedMembers = array_values(array_filter($members, static fn (array $member): bool => ! (bool) ($member['included_in_public_dataset'] ?? false)));
        $this->assertNotEmpty($excludedMembers);
        foreach ($excludedMembers as $member) {
            $this->assertNotEmpty((array) ($member['exclusion_reasons'] ?? []));
        }
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
