<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_machine_readable_first_wave_lifecycle_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/first-wave/lifecycle');

        $response->assertOk()
            ->assertJsonPath('summary_kind', 'career_first_wave_lifecycle')
            ->assertJsonPath('summary_version', 'career.lifecycle.first_wave.v1')
            ->assertJsonPath('scope', 'career_first_wave_10')
            ->assertJsonPath('counts.total', 10)
            ->assertJsonPath('counts.noindex', 4)
            ->assertJsonPath('counts.promotion_candidate', 0)
            ->assertJsonPath('counts.indexed', 6)
            ->assertJsonPath('counts.demoted', 0)
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'scope',
                'counts' => [
                    'total',
                    'noindex',
                    'promotion_candidate',
                    'indexed',
                    'demoted',
                ],
                'occupations' => [[
                    'occupation_uuid',
                    'canonical_slug',
                    'canonical_title_en',
                    'public_index_state',
                    'index_eligible',
                ]],
            ])
            ->assertJsonMissingPath('occupations.0.lifecycle_state')
            ->assertJsonMissingPath('occupations.0.reviewer_status')
            ->assertJsonMissingPath('occupations.0.reason_codes');

        $occupations = collect($response->json('occupations'))->keyBy('canonical_slug');

        $this->assertSame('indexable', $occupations['registered-nurses']['public_index_state']);
        $this->assertSame('noindex', $occupations['software-developers']['public_index_state']);
    }

    public function test_it_exposes_curated_candidate_and_demoted_states_without_raw_internal_tags(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $demoted = Occupation::query()->where('canonical_slug', 'management-analysts')->firstOrFail();

        $candidate->indexStates()->orderByDesc('changed_at')->orderByDesc('updated_at')->firstOrFail()->update([
            'index_state' => 'promotion_candidate',
            'index_eligible' => false,
            'reason_codes' => ['career_index_lifecycle_promotion_candidate'],
        ]);

        $demoted->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewer_status' => 'changes_required',
        ]);
        $demoted->indexStates()->orderByDesc('changed_at')->orderByDesc('updated_at')->firstOrFail()->update([
            'index_state' => 'demoted',
            'index_eligible' => false,
            'reason_codes' => ['career_index_lifecycle_demoted', 'career_index_lifecycle_regressed'],
        ]);

        $response = $this->getJson('/api/v0.5/career/first-wave/lifecycle');

        $response->assertOk()
            ->assertJsonPath('counts.promotion_candidate', 1)
            ->assertJsonPath('counts.demoted', 1);

        $occupations = collect($response->json('occupations'))->keyBy('canonical_slug');
        $candidateRow = $occupations['data-scientists'];
        $demotedRow = $occupations['management-analysts'];

        $this->assertArrayNotHasKey('lifecycle_state', $candidateRow);
        $this->assertArrayNotHasKey('reason_codes', $candidateRow);

        $this->assertArrayNotHasKey('lifecycle_state', $demotedRow);
        $this->assertArrayNotHasKey('reason_codes', $demotedRow);
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
