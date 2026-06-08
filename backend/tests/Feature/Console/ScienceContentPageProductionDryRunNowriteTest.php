<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPage;
use App\Services\Cms\ScienceContentPageDraftDryRunService;
use App\Services\Cms\ScienceContentPageOperatorReviewReadinessService;
use App\Services\Cms\ScienceContentPagePreImportQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScienceContentPageProductionDryRunNowriteTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_PATH = 'docs/seo/import-packages/science-contentpage-gpt55-review-draft-2026-06-08';

    private const REPORT_PATH = 'docs/seo/generated/science-contentpage-production-dry-run-nowrite-01.v1.json';

    #[Test]
    public function production_nowrite_gate_report_keeps_runtime_and_import_disabled(): void
    {
        $report = $this->report();

        $this->assertSame('SCIENCE-CONTENTPAGE-PRODUCTION-DRY-RUN-NOWRITE-01', $report['task'] ?? null);
        $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
        $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_production_import'] ?? true));
        $this->assertFalse((bool) ($report['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($report['cms_mutation_allowed'] ?? true));
        $this->assertFalse((bool) ($report['database_writes_allowed'] ?? true));
        $this->assertFalse((bool) ($report['content_import_allowed'] ?? true));
        $this->assertFalse((bool) ($report['publish_allowed'] ?? true));
    }

    #[Test]
    public function science_package_dry_run_passes_without_content_page_writes(): void
    {
        $this->artisan('content-pages:science-draft-dry-run', [
            '--package' => $this->packagePath(),
        ])
            ->expectsOutputToContain('status=pass_no_write_dry_run')
            ->expectsOutputToContain('dry_run=true')
            ->expectsOutputToContain('would_write=false')
            ->expectsOutputToContain('pages_seen=6')
            ->expectsOutputToContain('pages_ready_for_non_public_draft_import=5')
            ->expectsOutputToContain('pages_reconciled_existing_authority=1')
            ->expectsOutputToContain('pages_blocked=0')
            ->assertExitCode(0);

        $this->assertSame(0, ContentPage::query()->count());
    }

    #[Test]
    public function generated_report_matches_current_dry_run_and_pre_import_qa_gates(): void
    {
        $report = $this->report();
        $dryRun = app(ScienceContentPageDraftDryRunService::class)->dryRun($this->packagePath());
        $operator = app(ScienceContentPageOperatorReviewReadinessService::class)->review($this->packagePath());
        $preImportQa = app(ScienceContentPagePreImportQaService::class)->check($this->packagePath());

        $this->assertSame($dryRun['status'], $report['local_no_write_dry_run']['status']);
        $this->assertTrue($report['local_no_write_dry_run']['dry_run']);
        $this->assertFalse($report['local_no_write_dry_run']['would_write']);
        $this->assertFalse($report['local_no_write_dry_run']['database_writes_allowed']);
        $this->assertSame($dryRun['pages_seen'], $report['local_no_write_dry_run']['pages_seen']);
        $this->assertSame($dryRun['pages_expected'], $report['local_no_write_dry_run']['pages_expected']);
        $this->assertSame($dryRun['pages_ready_for_non_public_draft_import'], $report['local_no_write_dry_run']['pages_ready_for_non_public_draft_import']);
        $this->assertSame($dryRun['pages_reconciled_existing_authority'], $report['local_no_write_dry_run']['pages_reconciled_existing_authority']);
        $this->assertSame($dryRun['pages_blocked'], $report['local_no_write_dry_run']['pages_blocked']);
        $this->assertSame($dryRun['issue_count'], $report['local_no_write_dry_run']['issue_count']);

        $this->assertSame($operator['decision'], $report['operator_review_gate']['decision']);
        $this->assertSame($operator['operator_review_ready_for_non_public_draft'], $report['operator_review_gate']['operator_review_ready_for_non_public_draft']);
        $this->assertSame($operator['operator_publish_decision_ready'], $report['operator_review_gate']['operator_publish_decision_ready']);
        $this->assertSame($operator['missing_first_class_publish_safety_fields'], $report['operator_review_gate']['missing_first_class_publish_safety_fields']);

        $this->assertSame($preImportQa['decision'], $report['pre_import_qa_gate']['decision']);
        $this->assertSame($preImportQa['non_public_draft_import_qa_passed'], $report['pre_import_qa_gate']['non_public_draft_import_qa_passed']);
        $this->assertSame($preImportQa['real_import_allowed'], $report['pre_import_qa_gate']['real_import_allowed']);
        $this->assertSame($preImportQa['publish_allowed'], $report['pre_import_qa_gate']['publish_allowed']);
        $this->assertSame($preImportQa['package_pre_import_qa_issue_count'], $report['pre_import_qa_gate']['package_pre_import_qa_issue_count']);
        $this->assertSame($preImportQa['blocking_reasons'], $report['pre_import_qa_gate']['blocking_reasons']);
    }

    #[Test]
    public function report_does_not_claim_production_execution_or_private_route_access(): void
    {
        $report = $this->report();

        $this->assertFalse((bool) data_get($report, 'production_no_write_execution.production_shell_accessed', true));
        $this->assertFalse((bool) data_get($report, 'production_no_write_execution.production_dry_run_executed', true));
        $this->assertFalse((bool) data_get($report, 'production_no_write_execution.production_database_mutated', true));
        $this->assertFalse((bool) data_get($report, 'production_no_write_execution.production_cms_import_performed', true));
        $this->assertFalse((bool) data_get($report, 'production_no_write_execution.production_publish_performed', true));
        $this->assertSame('Unknown', data_get($report, 'production_no_write_execution.production_backend_sha'));
        $this->assertSame([], data_get($report, 'route_mapping.private_routes_allowed'));
        $this->assertContains('no_private_url', $report['hard_no_go'] ?? []);
        $this->assertContains('no_database_write', $report['hard_no_go'] ?? []);
        $this->assertSame('GO_FOR_REAL_IMPORT_COMMAND_ENABLEMENT_ONLY', $report['final_decision'] ?? null);
    }

    private function packagePath(): string
    {
        return base_path(self::PACKAGE_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path(self::REPORT_PATH)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
