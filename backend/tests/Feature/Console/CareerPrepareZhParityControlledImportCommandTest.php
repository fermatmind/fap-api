<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerPrepareZhParityControlledImportCommandTest extends TestCase
{
    #[Test]
    public function it_prepares_read_only_import_and_cache_readiness_from_live_report_manifest(): void
    {
        $sourceReport = $this->writeSourceReport($this->sourceReport([
            'aerospace-engineers',
            'agricultural-and-food-scientists',
            'agricultural-workers',
        ]));
        $contractRepairReport = $this->writeSourceReport($this->contractRepairReport(
            ['aerospace-engineers', 'agricultural-and-food-scientists'],
            ['agricultural-workers'],
        ));
        $output = sys_get_temp_dir().'/career-zh-parity-controlled-import-readiness-test.json';
        @unlink($output);

        $exitCode = Artisan::call('career:prepare-zh-parity-controlled-import', [
            '--source-report' => $sourceReport,
            '--contract-repair-report' => $contractRepairReport,
            '--chunk-size' => '2',
            '--output' => $output,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $written = json_decode((string) File::get($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['cache_mutation']);
        $this->assertFalse($report['content_mutation']);
        $this->assertSame('ready_for_auto_repairable_runtime_shell_dry_run', $report['readiness_decision']);
        $this->assertSame(3, $report['controlled_import_input_manifest']['source_candidate_count']);
        $this->assertSame(2, $report['controlled_import_input_manifest']['candidate_count']);
        $this->assertSame(['agricultural-workers'], $report['operator_review_hold']['manual_review_required_slugs']);
        $this->assertSame([
            'aerospace-engineers',
            'agricultural-and-food-scientists',
        ], $report['controlled_import_input_manifest']['candidate_slugs']);
        $this->assertSame(1, $report['execution_plan']['chunk_count']);
        $this->assertSame(2, $report['execution_plan']['chunks'][0]['candidate_count']);
        $this->assertStringContainsString(
            'career:align-career-authority-batch --file=<reviewed_workbook.xlsx> --slugs=aerospace-engineers,agricultural-and-food-scientists --dry-run --json',
            $report['execution_plan']['chunks'][0]['authority_crosswalk_dry_run_command'],
        );
        $this->assertStringContainsString(
            'career:align-career-authority-batch --file=<reviewed_workbook.xlsx> --slugs=aerospace-engineers,agricultural-and-food-scientists --force --json',
            $report['execution_plan']['chunks'][0]['authority_crosswalk_force_command_requires_explicit_approval'],
        );
        $this->assertStringContainsString('--dry-run --json', $report['execution_plan']['chunks'][0]['dry_run_command']);
        $this->assertStringContainsString('--force --json', $report['execution_plan']['chunks'][0]['force_command_requires_explicit_approval']);
        $this->assertStringContainsString(
            'career:warm-public-authority-cache --job-detail-slugs=aerospace-engineers,agricultural-and-food-scientists',
            $report['execution_plan']['chunks'][0]['post_import_cache_refresh_command_requires_explicit_approval'],
        );
        $this->assertSame($report['controlled_import_input_manifest'], $written['controlled_import_input_manifest']);
    }

    #[Test]
    public function it_rejects_source_reports_that_change_index_strategy_or_have_manifest_count_drift(): void
    {
        $report = $this->sourceReport(['aerospace-engineers', 'agricultural-workers']);
        $report['index_strategy_changed'] = true;
        $report['controlled_import_manifest']['candidate_count'] = 99;
        $sourceReport = $this->writeSourceReport($report);
        $contractRepairReport = $this->writeSourceReport($this->contractRepairReport(
            ['aerospace-engineers', 'agricultural-workers'],
            [],
        ));

        $exitCode = Artisan::call('career:prepare-zh-parity-controlled-import', [
            '--source-report' => $sourceReport,
            '--contract-repair-report' => $contractRepairReport,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $output['decision']);
        $this->assertContains(
            'controlled_import_manifest.candidate_count must match candidate_slugs.',
            $output['errors'],
        );
        $this->assertContains(
            'Source report must not change index strategy.',
            $output['errors'],
        );
    }

    #[Test]
    public function it_rejects_contract_repair_reports_that_do_not_match_the_source_candidates(): void
    {
        $sourceReport = $this->writeSourceReport($this->sourceReport([
            'aerospace-engineers',
            'agricultural-workers',
        ]));
        $repairReport = $this->contractRepairReport(['aerospace-engineers'], []);
        $repairReport['auto_repairable_rows'] = 1;
        $contractRepairReport = $this->writeSourceReport($repairReport);

        $exitCode = Artisan::call('career:prepare-zh-parity-controlled-import', [
            '--source-report' => $sourceReport,
            '--contract-repair-report' => $contractRepairReport,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $output['decision']);
        $this->assertContains(
            'Contract repair report selected_rows must match source candidate count.',
            $output['errors'],
        );
        $this->assertContains(
            'Contract repair report auto_repairable_rows must match eligible candidates.',
            $output['errors'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeSourceReport(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'career-zh-live-report-');
        $this->assertIsString($path);
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @param  list<string>  $candidateSlugs
     * @return array<string, mixed>
     */
    private function sourceReport(array $candidateSlugs): array
    {
        return [
            'validator_version' => 'career_zh_display_parity_audit_v0.3',
            'decision' => 'pass',
            'read_only' => true,
            'writes_database' => false,
            'sitemap_changed' => false,
            'llms_changed' => false,
            'index_strategy_changed' => false,
            'summary' => [
                'total_slugs' => 1046,
            ],
            'live_gate' => [
                'decision' => 'blocked',
            ],
            'production_live_assessment' => [
                'runtime_shell_count' => 237,
                'missing_modules_by_slug_count' => 672,
                'root_cause_counts' => [
                    'runtime_shell_missing_or_unpublished_zh_display_asset' => 237,
                ],
                'cache_stale_counts' => [
                    'not_proven' => 237,
                ],
                'cms_asset_exists_counts' => [
                    'inferred_absent_or_not_published_from_public_payload' => 237,
                ],
            ],
            'controlled_import_manifest' => [
                'schema_version' => 'career_zh_parity_controlled_import_manifest.v0.1',
                'source_scope' => 'post_deploy_production_public_api_read_only',
                'target_command' => 'career:import-selected-display-assets',
                'target_locale' => 'zh-CN',
                'candidate_count' => count($candidateSlugs),
                'candidate_slugs' => $candidateSlugs,
                'rows' => array_map(static fn (string $slug): array => [
                    'slug' => $slug,
                    'root_cause' => 'runtime_shell_missing_or_unpublished_zh_display_asset',
                ], $candidateSlugs),
                'requires_reviewed_workbook' => true,
                'requires_explicit_production_write_approval' => true,
                'requires_cache_forget_warm_after_import' => true,
                'must_not_change_sitemap_llms_or_index_strategy' => true,
            ],
        ];
    }

    /**
     * @param  list<string>  $autoRepairableSlugs
     * @param  list<string>  $manualReviewSlugs
     * @return array<string, mixed>
     */
    private function contractRepairReport(array $autoRepairableSlugs, array $manualReviewSlugs): array
    {
        return [
            'command' => 'career:repair-display-workbook-contract',
            'repairer_version' => 'career_display_workbook_contract_repairer_v0.2',
            'execute' => false,
            'writes_database' => false,
            'cms_mutation' => false,
            'selected_rows' => count($autoRepairableSlugs) + count($manualReviewSlugs),
            'auto_repairable_rows' => count($autoRepairableSlugs),
            'manual_review_required_count' => count($manualReviewSlugs),
            'manual_review_required_rows' => array_map(static fn (string $slug, int $index): array => [
                'row_number' => $index + 10,
                'slug' => $slug,
                'reasons' => [
                    'CN_Title lacks reviewed Chinese text',
                    'CN_H1 lacks reviewed Chinese text',
                ],
            ], $manualReviewSlugs, array_keys($manualReviewSlugs)),
            'decision' => 'dry_run_changes_available',
        ];
    }
}
