<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\ReviewerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class FirstWavePublishReadyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_blocked_subjects_when_no_first_wave_rows_are_materialized(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"publish_ready": 0', $output);
        $this->assertStringContainsString('"blocked": 10', $output);
    }

    public function test_it_materializes_only_manifest_scoped_rows_and_reports_publish_ready_counts(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_publish_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"source_row_missing"', $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(10, count($report['occupations']));
        $this->assertSame(6, $report['counts']['blocked']);
        $this->assertSame(4, $report['counts']['publish_ready'] + $report['counts']['partial']);

        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'data-scientists']);
        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'project-management-specialists']);
        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'human-resources-specialists']);
        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'management-analysts']);
        $this->assertDatabaseMissing('occupations', ['canonical_slug' => 'civil-engineers']);

        $repeatExitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_publish_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);
        $repeatReport = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $repeatExitCode);
        $firstDataScientists = collect($report['occupations'])->firstWhere('canonical_slug', 'data-scientists');
        $repeatDataScientists = collect($repeatReport['occupations'])->firstWhere('canonical_slug', 'data-scientists');
        $this->assertIsArray($firstDataScientists);
        $this->assertIsArray($repeatDataScientists);
        $this->assertSame($firstDataScientists['alias_count'], $repeatDataScientists['alias_count']);
    }

    public function test_it_repairs_accountants_and_auditors_without_promoting_evidence_blocked_subjects(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'accountants-and-auditors',
            'crosswalk_mode' => 'exact',
        ]);

        $chain['trustManifest']->forceFill([
            'reviewer_status' => ReviewerStatus::PENDING,
            'reviewed_at' => null,
            'quality' => ['confidence' => 0.74, 'review_required' => true],
        ])->save();
        $chain['indexState']->forceFill([
            'index_state' => IndexStateValue::TRUST_LIMITED,
            'index_eligible' => false,
        ])->save();
        $chain['contextSnapshot']->forceFill([
            'context_payload' => ['materialization' => 'career_first_wave'],
        ])->save();
        $chain['childProjection']->forceFill([
            'projection_payload' => ['materialization' => 'career_first_wave'],
        ])->save();
        $chain['recommendationSnapshot']->forceFill([
            'compiled_at' => now()->subMinute(),
            'snapshot_payload' => ['claim_permissions' => ['allow_strong_claim' => false]],
        ])->save();

        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_repair_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);

        $accountants = collect($report['occupations'])->firstWhere('canonical_slug', 'accountants-and-auditors');
        $software = collect($report['occupations'])->firstWhere('canonical_slug', 'software-developers');
        $financial = collect($report['occupations'])->firstWhere('canonical_slug', 'financial-analysts');
        $marketing = collect($report['occupations'])->firstWhere('canonical_slug', 'marketing-managers');
        $elementary = collect($report['occupations'])->firstWhere('canonical_slug', 'elementary-school-teachers-except-special-education');

        $this->assertIsArray($accountants);
        $this->assertSame('publish_ready', $accountants['status']);
        $this->assertSame(5, $accountants['alias_count']);

        $this->assertIsArray($software);
        $this->assertSame('blocked', $software['status']);

        $this->assertIsArray($financial);
        $this->assertSame('blocked', $financial['status']);

        $this->assertIsArray($marketing);
        $this->assertSame('blocked', $marketing['status']);

        $this->assertIsArray($elementary);
        $this->assertSame('blocked', $elementary['status']);
    }
}
