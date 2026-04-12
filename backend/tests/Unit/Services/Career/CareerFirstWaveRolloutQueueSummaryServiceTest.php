<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutQueueSummaryService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveRolloutQueueSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_empty_rollout_queue_from_stable_first_wave_truth(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $summary = app(CareerFirstWaveRolloutQueueSummaryService::class)->build()->toArray();

        $this->assertSame('career_first_wave_rollout_queue', $summary['summary_kind']);
        $this->assertSame(CareerFirstWaveRolloutQueueSummaryService::SUMMARY_VERSION, $summary['summary_version']);
        $this->assertSame(CareerFirstWaveRolloutQueueSummaryService::SCOPE, $summary['scope']);
        $this->assertSame(0, $summary['counts']['total']);
        $this->assertSame(0, $summary['counts']['promotion_candidate_review']);
        $this->assertSame(0, $summary['counts']['demotion_review']);
        $this->assertSame([], $summary['queue_items']);
    }

    public function test_it_projects_candidate_and_demoted_rows_into_review_buckets_with_curated_reason_codes(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $candidate = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $demoted = Occupation::query()->where('canonical_slug', 'management-analysts')->firstOrFail();
        $candidate->indexStates()->orderByDesc('changed_at')->orderByDesc('updated_at')->firstOrFail()->update([
            'index_state' => 'promotion_candidate',
            'index_eligible' => false,
            'reason_codes' => ['career_index_lifecycle_promotion_candidate', 'debug_candidate_tag'],
        ]);

        $demoted->trustManifests()->orderByDesc('reviewed_at')->orderByDesc('created_at')->firstOrFail()->update([
            'reviewer_status' => 'changes_required',
        ]);
        $demoted->indexStates()->orderByDesc('changed_at')->orderByDesc('updated_at')->firstOrFail()->update([
            'index_state' => 'demoted',
            'index_eligible' => false,
            'reason_codes' => ['career_index_lifecycle_demoted', 'career_index_lifecycle_regressed'],
        ]);

        $summary = app(CareerFirstWaveRolloutQueueSummaryService::class)->build()->toArray();
        $queueItems = collect($summary['queue_items'])->keyBy('canonical_slug');

        $this->assertSame(2, $summary['counts']['total']);
        $this->assertSame(1, $summary['counts']['promotion_candidate_review']);
        $this->assertSame(1, $summary['counts']['demotion_review']);
        $this->assertSame(['data-scientists', 'management-analysts'], array_column($summary['queue_items'], 'canonical_slug'));

        $candidateRow = $queueItems->get('data-scientists');
        $demotedRow = $queueItems->get('management-analysts');

        $this->assertIsArray($candidateRow);
        $this->assertSame('promotion_candidate_review', $candidateRow['queue_state']);
        $this->assertSame('promotion_candidate', $candidateRow['lifecycle_state']);
        $this->assertSame('partial_raw', $candidateRow['readiness_status']);
        $this->assertNull($candidateRow['blocked_governance_status']);
        $this->assertSame(
            ['promotion_candidate', 'publish_gate_candidate', 'not_index_eligible', 'trust_limited'],
            $candidateRow['reason_codes']
        );

        $this->assertIsArray($demotedRow);
        $this->assertSame('demotion_review', $demotedRow['queue_state']);
        $this->assertSame('demoted', $demotedRow['lifecycle_state']);
        $this->assertContains('demoted_lifecycle', $demotedRow['reason_codes']);
        $this->assertContains('demoted_review_regression', $demotedRow['reason_codes']);
        $this->assertContains('demoted_trust_regression', $demotedRow['reason_codes']);

        $this->assertNotContains('software-developers', array_column($summary['queue_items'], 'canonical_slug'));
        $this->assertNotContains('career_index_lifecycle_promotion_candidate', $candidateRow['reason_codes']);
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
