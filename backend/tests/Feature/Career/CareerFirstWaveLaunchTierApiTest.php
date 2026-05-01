<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveLaunchTierApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_machine_readable_first_wave_launch_tier_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/first-wave/launch-tier');

        $response->assertOk()
            ->assertJsonPath('summary_kind', 'career_first_wave_launch_tier')
            ->assertJsonPath('summary_version', 'career.launch_tier.first_wave.v1')
            ->assertJsonPath('scope', 'career_first_wave_10')
            ->assertJsonPath('counts.total', 10)
            ->assertJsonPath('counts.stable', 6)
            ->assertJsonPath('counts.candidate', 0)
            ->assertJsonPath('counts.hold', 4)
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'scope',
                'counts' => [
                    'total',
                    'stable',
                    'candidate',
                    'hold',
                ],
                'occupations' => [[
                    'occupation_uuid',
                    'canonical_slug',
                    'canonical_title_en',
                    'launch_tier',
                    'public_index_state',
                    'index_eligible',
                ]],
            ])
            ->assertJsonMissingPath('recommended_action')
            ->assertJsonMissingPath('occupations.0.reviewer_status')
            ->assertJsonMissingPath('occupations.0.crosswalk_mode')
            ->assertJsonMissingPath('occupations.0.blocked_governance_status')
            ->assertJsonMissingPath('occupations.0.confidence_score');

        $occupations = collect($response->json('occupations'))->keyBy('canonical_slug');

        $this->assertSame('stable', $occupations['registered-nurses']['launch_tier']);
        $this->assertSame('hold', $occupations['software-developers']['launch_tier']);
    }

    public function test_it_exposes_candidate_rows_without_turning_them_into_rollout_queue_actions(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $candidate->update([
            'crosswalk_mode' => 'direct_match',
        ]);

        $response = $this->getJson('/api/v0.5/career/first-wave/launch-tier');

        $response->assertOk()
            ->assertJsonPath('counts.stable', 5)
            ->assertJsonPath('counts.candidate', 1)
            ->assertJsonPath('counts.hold', 4)
            ->assertJsonMissingPath('occupations.0.recommended_action');

        $occupations = collect($response->json('occupations'))->keyBy('canonical_slug');
        $candidateRow = $occupations['data-scientists'];

        $this->assertSame('candidate', $candidateRow['launch_tier']);
        $this->assertArrayNotHasKey('lifecycle_state', $candidateRow);
        $this->assertArrayNotHasKey('crosswalk_mode', $candidateRow);
        $this->assertArrayNotHasKey('reason_codes', $candidateRow);
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
