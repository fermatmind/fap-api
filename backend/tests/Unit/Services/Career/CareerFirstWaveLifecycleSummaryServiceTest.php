<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveLifecycleSummaryService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveLifecycleSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_stable_first_wave_lifecycle_summary_from_persisted_truth(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $summary = app(CareerFirstWaveLifecycleSummaryService::class)->build()->toArray();
        $occupations = collect($summary['occupations'])->keyBy('canonical_slug');

        $this->assertSame('career_first_wave_lifecycle', $summary['summary_kind']);
        $this->assertSame(CareerFirstWaveLifecycleSummaryService::SUMMARY_VERSION, $summary['summary_version']);
        $this->assertSame(CareerFirstWaveLifecycleSummaryService::SCOPE, $summary['scope']);
        $this->assertSame(10, $summary['counts']['total']);
        $this->assertSame(4, $summary['counts']['noindex']);
        $this->assertSame(0, $summary['counts']['promotion_candidate']);
        $this->assertSame(6, $summary['counts']['indexed']);
        $this->assertSame(0, $summary['counts']['demoted']);
        $this->assertSame('software-developers', $summary['occupations'][0]['canonical_slug']);
        $this->assertSame('management-analysts', $summary['occupations'][9]['canonical_slug']);

        $dataScientists = $occupations->get('data-scientists');
        $software = $occupations->get('software-developers');

        $this->assertIsArray($dataScientists);
        $this->assertSame('indexed', $dataScientists['lifecycle_state']);
        $this->assertSame('indexable', $dataScientists['public_index_state']);
        $this->assertTrue($dataScientists['index_eligible']);
        $this->assertSame('approved', $dataScientists['reviewer_status']);
        $this->assertSame(['indexed_ready'], $dataScientists['reason_codes']);

        $this->assertIsArray($software);
        $this->assertSame('noindex', $software['lifecycle_state']);
        $this->assertSame('noindex', $software['public_index_state']);
        $this->assertFalse($software['index_eligible']);
        $this->assertNull($software['reviewer_status']);
        $this->assertContains('publish_gate_hold', $software['reason_codes']);
        $this->assertContains('not_index_eligible', $software['reason_codes']);
    }

    public function test_it_curates_candidate_and_demoted_reason_codes_without_leaking_internal_compiler_tags(): void
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

        $summary = app(CareerFirstWaveLifecycleSummaryService::class)->build()->toArray();
        $occupations = collect($summary['occupations'])->keyBy('canonical_slug');

        $this->assertSame(4, $summary['counts']['indexed']);
        $this->assertSame(1, $summary['counts']['promotion_candidate']);
        $this->assertSame(1, $summary['counts']['demoted']);

        $candidateRow = $occupations->get('data-scientists');
        $demotedRow = $occupations->get('management-analysts');

        $this->assertIsArray($candidateRow);
        $this->assertSame('promotion_candidate', $candidateRow['lifecycle_state']);
        $this->assertSame('trust_limited', $candidateRow['public_index_state']);
        $this->assertSame(['publish_gate_candidate', 'not_index_eligible', 'trust_limited'], $candidateRow['reason_codes']);

        $this->assertIsArray($demotedRow);
        $this->assertSame('demoted', $demotedRow['lifecycle_state']);
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
