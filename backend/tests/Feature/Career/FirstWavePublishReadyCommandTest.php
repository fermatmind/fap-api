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
        $this->assertSame('blocked_override_eligible', $software['blocked_governance_status']);
        $this->assertTrue($software['override_eligible']);
        $this->assertFalse($software['authority_override_supplied']);
        $this->assertSame('authority_override_possible', $software['remediation_class']);

        $this->assertIsArray($financial);
        $this->assertSame('blocked', $financial['status']);
        $this->assertSame('blocked_override_eligible', $financial['blocked_governance_status']);
        $this->assertTrue($financial['override_eligible']);
        $this->assertFalse($financial['authority_override_supplied']);

        $this->assertIsArray($marketing);
        $this->assertSame('blocked', $marketing['status']);
        $this->assertSame('blocked_not_safely_remediable', $marketing['blocked_governance_status']);
        $this->assertFalse($marketing['override_eligible']);

        $this->assertIsArray($elementary);
        $this->assertSame('blocked', $elementary['status']);
        $this->assertSame('blocked_not_safely_remediable', $elementary['blocked_governance_status']);
        $this->assertFalse($elementary['override_eligible']);
    }

    public function test_it_can_resolve_missing_crosswalk_source_code_only_when_explicit_overrides_are_supplied(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_override_subset.csv'),
            '--authority-overrides' => base_path('tests/Fixtures/Career/authority_wave/first_wave_authority_overrides_fixture.json'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);

        $software = collect($report['occupations'])->firstWhere('canonical_slug', 'software-developers');
        $financial = collect($report['occupations'])->firstWhere('canonical_slug', 'financial-analysts');
        $marketing = collect($report['occupations'])->firstWhere('canonical_slug', 'marketing-managers');
        $elementary = collect($report['occupations'])->firstWhere('canonical_slug', 'elementary-school-teachers-except-special-education');

        $this->assertIsArray($software);
        $this->assertSame('publish_ready', $software['status']);
        $this->assertTrue($software['authority_override_supplied']);
        $this->assertNull($software['blocked_governance_status']);
        $this->assertNull($software['blocker_type']);
        $this->assertSame([], $software['notes']);

        $this->assertIsArray($financial);
        $this->assertSame('publish_ready', $financial['status']);
        $this->assertTrue($financial['authority_override_supplied']);
        $this->assertNull($financial['blocked_governance_status']);
        $this->assertNull($financial['blocker_type']);
        $this->assertSame([], $financial['notes']);

        $this->assertIsArray($marketing);
        $this->assertSame('blocked', $marketing['status']);
        $this->assertSame('blocked_not_safely_remediable', $marketing['blocked_governance_status']);

        $this->assertIsArray($elementary);
        $this->assertSame('blocked', $elementary['status']);
        $this->assertSame('blocked_not_safely_remediable', $elementary['blocked_governance_status']);
    }

    public function test_it_rebuilds_from_committed_truth_instead_of_reusing_stale_first_wave_state(): void
    {
        CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'software-developers',
            'crosswalk_mode' => 'exact',
        ]);

        $firstExitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_override_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);
        $firstReport = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $secondExitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_override_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);
        $secondReport = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $firstExitCode);
        $this->assertSame(0, $secondExitCode);

        $firstSoftware = collect($firstReport['occupations'])->firstWhere('canonical_slug', 'software-developers');
        $firstFinancial = collect($firstReport['occupations'])->firstWhere('canonical_slug', 'financial-analysts');
        $firstMarketing = collect($firstReport['occupations'])->firstWhere('canonical_slug', 'marketing-managers');
        $firstElementary = collect($firstReport['occupations'])->firstWhere('canonical_slug', 'elementary-school-teachers-except-special-education');

        $this->assertIsArray($firstSoftware);
        $this->assertSame('blocked', $firstSoftware['status']);
        $this->assertSame('blocked_override_eligible', $firstSoftware['blocked_governance_status']);
        $this->assertFalse($firstSoftware['authority_override_supplied']);

        $this->assertIsArray($firstFinancial);
        $this->assertSame('blocked', $firstFinancial['status']);
        $this->assertSame('blocked_override_eligible', $firstFinancial['blocked_governance_status']);
        $this->assertFalse($firstFinancial['authority_override_supplied']);

        $this->assertIsArray($firstMarketing);
        $this->assertSame('blocked', $firstMarketing['status']);
        $this->assertSame('blocked_not_safely_remediable', $firstMarketing['blocked_governance_status']);

        $this->assertIsArray($firstElementary);
        $this->assertSame('blocked', $firstElementary['status']);
        $this->assertSame('blocked_not_safely_remediable', $firstElementary['blocked_governance_status']);

        $this->assertSame(
            [
                'counts' => $firstReport['counts'],
                'occupations' => collect($firstReport['occupations'])
                    ->map(static fn (array $occupation): array => [
                        'canonical_slug' => $occupation['canonical_slug'],
                        'status' => $occupation['status'],
                        'missing_requirements' => $occupation['missing_requirements'],
                        'blocked_governance_status' => $occupation['blocked_governance_status'],
                        'authority_override_supplied' => $occupation['authority_override_supplied'],
                    ])
                    ->all(),
            ],
            [
                'counts' => $secondReport['counts'],
                'occupations' => collect($secondReport['occupations'])
                    ->map(static fn (array $occupation): array => [
                        'canonical_slug' => $occupation['canonical_slug'],
                        'status' => $occupation['status'],
                        'missing_requirements' => $occupation['missing_requirements'],
                        'blocked_governance_status' => $occupation['blocked_governance_status'],
                        'authority_override_supplied' => $occupation['authority_override_supplied'],
                    ])
                    ->all(),
            ]
        );
    }
}
