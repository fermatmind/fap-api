<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FirstWaveReadinessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_machine_readable_first_wave_readiness_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/first-wave/readiness');

        $response->assertOk()
            ->assertJsonPath('summary_kind', 'career_first_wave_readiness')
            ->assertJsonPath('summary_version', 'career.release.first_wave_readiness.v1')
            ->assertJsonPath('counts.total', 10)
            ->assertJsonPath('counts.publish_ready', 6)
            ->assertJsonPath('counts.not_public', 4)
            ->assertJsonCount(6, 'occupations')
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'wave_name',
                'counts' => [
                    'total',
                    'publish_ready',
                    'not_public',
                ],
                'occupations' => [[
                    'occupation_uuid',
                    'canonical_slug',
                    'canonical_title_en',
                    'status',
                    'index_state',
                    'index_eligible',
                ]],
            ])
            ->assertJsonMissingPath('counts.blocked_override_eligible')
            ->assertJsonMissingPath('counts.blocked_not_safely_remediable')
            ->assertJsonMissingPath('counts.blocked_total')
            ->assertJsonMissingPath('counts.partial_raw')
            ->assertJsonMissingPath('occupations.0.blocker_type')
            ->assertJsonMissingPath('occupations.0.remediation_class')
            ->assertJsonMissingPath('occupations.0.authority_override_supplied')
            ->assertJsonMissingPath('occupations.0.review_required')
            ->assertJsonMissingPath('occupations.0.crosswalk_mode')
            ->assertJsonMissingPath('occupations.0.reviewer_status')
            ->assertJsonMissingPath('occupations.0.reason_codes');

        $occupations = collect($response->json('occupations'))->keyBy('canonical_slug');

        $this->assertFalse($occupations->has('software-developers'));
        $this->assertFalse($occupations->has('financial-analysts'));
        $this->assertFalse($occupations->has('marketing-managers'));
        $this->assertFalse($occupations->has('elementary-school-teachers-except-special-education'));
        $this->assertSame('publish_ready', $occupations['registered-nurses']['status']);
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
