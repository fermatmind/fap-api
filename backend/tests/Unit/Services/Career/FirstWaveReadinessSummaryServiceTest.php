<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;
use App\Models\Occupation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FirstWaveReadinessSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_validator_truth_into_a_stable_readiness_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $summary = app(FirstWaveReadinessSummaryService::class)->build()->toArray();
        $occupations = collect($summary['occupations'])->keyBy('canonical_slug');

        $this->assertSame('career_first_wave_readiness', $summary['summary_kind']);
        $this->assertSame(FirstWaveReadinessSummaryService::SUMMARY_VERSION, $summary['summary_version']);
        $this->assertSame(10, $summary['counts']['total']);
        $this->assertSame(6, $summary['counts']['publish_ready']);
        $this->assertSame(2, $summary['counts']['blocked_override_eligible']);
        $this->assertSame(2, $summary['counts']['blocked_not_safely_remediable']);
        $this->assertSame(4, $summary['counts']['blocked_total']);
        $this->assertSame(0, $summary['counts']['partial_raw']);

        $software = $occupations->get('software-developers');
        $financial = $occupations->get('financial-analysts');
        $marketing = $occupations->get('marketing-managers');
        $elementary = $occupations->get('elementary-school-teachers-except-special-education');
        $dataScientists = $occupations->get('data-scientists');

        $this->assertIsArray($software);
        $this->assertSame('blocked_override_eligible', $software['status']);
        $this->assertFalse($software['authority_override_supplied']);
        $this->assertContains('missing_crosswalk_source_code', $software['reason_codes']);
        $this->assertContains('authority_override_not_supplied', $software['reason_codes']);
        $this->assertSame('Software Developers', $software['canonical_title_en']);

        $this->assertIsArray($financial);
        $this->assertSame('blocked_override_eligible', $financial['status']);
        $this->assertFalse($financial['authority_override_supplied']);

        $this->assertIsArray($marketing);
        $this->assertSame('blocked_not_safely_remediable', $marketing['status']);
        $this->assertContains('source_row_missing', $marketing['reason_codes']);

        $this->assertIsArray($elementary);
        $this->assertSame('blocked_not_safely_remediable', $elementary['status']);
        $this->assertContains('source_row_missing', $elementary['reason_codes']);

        $this->assertIsArray($dataScientists);
        $this->assertSame('publish_ready', $dataScientists['status']);
        $this->assertSame(['publish_ready'], $dataScientists['reason_codes']);
        $this->assertSame('approved', $dataScientists['reviewer_status']);
        $this->assertSame('indexable', $dataScientists['index_state']);
        $this->assertTrue($dataScientists['index_eligible']);

        $occupation = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $latestIndexState = $occupation->indexStates()->orderByDesc('changed_at')->orderByDesc('updated_at')->firstOrFail();
        $this->assertSame('indexed', $latestIndexState->index_state);
        $this->assertTrue($latestIndexState->index_eligible);
    }

    public function test_it_keeps_partial_only_as_a_raw_summary_count_when_present(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $summary = app(FirstWaveReadinessSummaryService::class)->build()->toArray();

        $this->assertArrayHasKey('partial_raw', $summary['counts']);
        $this->assertSame(0, $summary['counts']['partial_raw']);
        $this->assertCount(0, array_filter(
            $summary['occupations'],
            static fn (array $occupation): bool => $occupation['status'] === 'partial_raw'
        ));
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
