<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveRolloutQueueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_an_empty_first_wave_rollout_queue_when_no_rows_need_review(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/first-wave/rollout-queue');

        $response->assertOk()
            ->assertJsonPath('summary_kind', 'career_first_wave_rollout_queue')
            ->assertJsonPath('summary_version', 'career.rollout.first_wave.v1')
            ->assertJsonPath('scope', 'career_first_wave_10')
            ->assertJsonPath('counts.total', 0)
            ->assertJsonPath('counts.promotion_candidate_review', 0)
            ->assertJsonPath('counts.demotion_review', 0)
            ->assertJsonPath('queue_items', [])
            ->assertJsonMissingPath('recommended_action');
    }

    public function test_it_exposes_queue_worthy_candidate_and_demoted_rows_without_recommended_actions(): void
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

        $response = $this->getJson('/api/v0.5/career/first-wave/rollout-queue');

        $response->assertOk()
            ->assertJsonPath('counts.total', 2)
            ->assertJsonPath('counts.promotion_candidate_review', 1)
            ->assertJsonPath('counts.demotion_review', 1)
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'scope',
                'counts' => [
                    'total',
                    'promotion_candidate_review',
                    'demotion_review',
                ],
                'queue_items' => [[
                    'occupation_uuid',
                    'canonical_slug',
                    'canonical_title_en',
                    'lifecycle_state',
                    'readiness_status',
                    'public_index_state',
                    'index_eligible',
                    'reviewer_status',
                    'blocked_governance_status',
                    'queue_state',
                    'reason_codes',
                ]],
            ])
            ->assertJsonMissingPath('queue_items.0.recommended_action');

        $queueItems = collect($response->json('queue_items'))->keyBy('canonical_slug');
        $candidateRow = $queueItems['data-scientists'];
        $demotedRow = $queueItems['management-analysts'];

        $this->assertSame('promotion_candidate_review', $candidateRow['queue_state']);
        $this->assertSame(
            ['promotion_candidate', 'publish_gate_candidate', 'not_index_eligible', 'trust_limited'],
            $candidateRow['reason_codes']
        );

        $this->assertSame('demotion_review', $demotedRow['queue_state']);
        $this->assertContains('demoted_lifecycle', $demotedRow['reason_codes']);
        $this->assertContains('demoted_review_regression', $demotedRow['reason_codes']);
        $this->assertContains('demoted_trust_regression', $demotedRow['reason_codes']);
        $this->assertNotContains('career_index_lifecycle_demoted', $demotedRow['reason_codes']);
        $this->assertNotContains('career_index_lifecycle_regressed', $demotedRow['reason_codes']);
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
