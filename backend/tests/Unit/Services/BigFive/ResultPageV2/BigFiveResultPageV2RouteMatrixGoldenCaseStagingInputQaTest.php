<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use Tests\TestCase;

final class BigFiveResultPageV2RouteMatrixGoldenCaseStagingInputQaTest extends TestCase
{
    private const REPORT_PATH = 'content_assets/big5/result_page_v2/qa/route_matrix_golden_case_staging_input/v0_1/big5_route_matrix_golden_case_staging_input_qa_report_v0_1.json';

    private const SUMMARY_PATH = 'content_assets/big5/result_page_v2/qa/route_matrix_golden_case_staging_input/v0_1/big5_route_matrix_golden_case_staging_input_qa_summary_v0_1.json';

    public function test_report_matches_route_matrix_parser_and_o59_row(): void
    {
        $report = $this->decodeJson(self::REPORT_PATH);
        $summary = $this->decodeJson(self::SUMMARY_PATH);
        $parseResult = app(BigFiveV2RouteMatrixParser::class)->parse();

        $this->assertTrue($parseResult->isValid(), implode("\n", $parseResult->errors));
        $this->assertSame(3125, $parseResult->rowCount());
        $this->assertSame(3125, data_get($report, 'route_matrix.row_count'));
        $this->assertSame($parseResult->rowCountsByShard, data_get($report, 'route_matrix.row_counts_by_shard'));
        $this->assertSame(3125, $summary['route_matrix_row_count'] ?? null);
        $this->assertSame(5, $summary['route_matrix_shard_count'] ?? null);

        $o59 = $parseResult->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($o59);
        $this->assertSame($o59->combinationKey, data_get($report, 'route_matrix.o59_canonical_row.combination_key'));
        $this->assertSame($o59->profileKey, data_get($report, 'route_matrix.o59_canonical_row.nearest_canonical_profile_key'));
        $this->assertSame($o59->profileFamily, data_get($report, 'route_matrix.o59_canonical_row.profile_family'));
        $this->assertSame($o59->interpretationScope, data_get($report, 'route_matrix.o59_canonical_row.interpretation_scope'));
        $this->assertSame('sensitive_independent_thinker', $summary['o59_profile_key'] ?? null);
    }

    public function test_report_matches_selector_and_route_driven_golden_case_sources(): void
    {
        $report = $this->decodeJson(self::REPORT_PATH);
        $selectorCases = $this->decodeJson('content_assets/big5/result_page_v2/selector_qa_policy/v0_1/big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json');
        $routeCases = $this->decodeJson('content_assets/big5/result_page_v2/qa/route_driven_golden_cases/v0_1/big5_route_driven_golden_cases_v0_1.json');
        $routeSummary = $this->decodeJson('content_assets/big5/result_page_v2/qa/route_driven_golden_cases/v0_1/big5_route_driven_golden_cases_summary_v0_1.json');

        $this->assertCount(31, $selectorCases);
        $this->assertSame(31, data_get($report, 'golden_cases.selector_qa_golden_case_count'));
        $this->assertCount(16, $routeCases);
        $this->assertSame(16, data_get($report, 'golden_cases.route_driven_backend_case_count'));
        $this->assertSame(8, data_get($report, 'golden_cases.canonical_profile_family_case_count'));
        $this->assertSame(8, data_get($report, 'golden_cases.variant_case_count'));
        $this->assertSame($routeSummary['variant_groups'] ?? [], data_get($report, 'golden_cases.route_driven_variant_groups'));

        $o59SelectorCases = array_values(array_filter(
            $selectorCases,
            static fn (array $case): bool => ($case['case_key'] ?? null) === 'golden_case_31_o59_canonical_preview'
        ));
        $this->assertCount(1, $o59SelectorCases);

        $profileFamilies = array_values(array_unique(array_map(
            static fn (array $case): string => (string) $case['profile_family'],
            array_filter($routeCases, static fn (array $case): bool => ($case['golden_group'] ?? null) === 'canonical_profile_family')
        )));
        sort($profileFamilies);
        $this->assertSame(data_get($report, 'canonical_profiles.profile_keys'), $profileFamilies);
    }

    public function test_selector_reference_and_conflict_resolution_remain_fail_closed(): void
    {
        $report = $this->decodeJson(self::REPORT_PATH);
        $referenceReport = $this->decodeJson('content_assets/big5/result_page_v2/qa/selector_reference_consistency/v0_1/selector_reference_consistency_report_v0_1.json');
        $conflictPolicy = $this->decodeJson('content_assets/big5/result_page_v2/selector_qa_policy/v0_1/big5_result_page_v2_selector_qa_policy_v0_1_conflict_resolution.json');

        $this->assertSame('go_with_fail_closed_suppression', data_get($report, 'staging_selector_input_status.status'));
        $this->assertTrue((bool) data_get($report, 'ready_for_staging_selector_input'));
        $this->assertFalse((bool) data_get($report, 'ready_for_runtime', true));
        $this->assertFalse((bool) data_get($report, 'ready_for_production', true));
        $this->assertFalse((bool) data_get($report, 'production_use_allowed', true));

        $this->assertSame(data_get($referenceReport, 'summary.unresolved_reference_count'), data_get($report, 'selector_reference_resolution.unresolved_reference_count'));
        $this->assertSame(data_get($referenceReport, 'summary.unresolved_by_type'), data_get($report, 'selector_reference_resolution.unresolved_by_type'));
        $this->assertSame(0, data_get($report, 'selector_reference_resolution.route_matrix_to_imported_content_assets_unresolved_count'));
        $this->assertTrue((bool) data_get($report, 'selector_reference_resolution.fail_closed_required'));
        $this->assertFalse((bool) data_get($report, 'selector_reference_resolution.may_unsuppress_selector_refs', true));

        $this->assertSame($conflictPolicy['resolution_order'] ?? [], data_get($report, 'conflict_resolution.resolution_order'));
        $this->assertSame(count((array) ($conflictPolicy['mutual_exclusion_rules'] ?? [])), data_get($report, 'conflict_resolution.mutual_exclusion_rule_count'));
        $this->assertSame(count((array) ($conflictPolicy['semantic_conflict_guards'] ?? [])), data_get($report, 'conflict_resolution.semantic_conflict_guard_count'));
        $this->assertTrue((bool) data_get($report, 'conflict_resolution.rendered_text_banned_scan_present'));

        $this->assertFalse((bool) data_get($report, 'staging_input_contract.body_composition_allowed', true));
        $this->assertFalse((bool) data_get($report, 'staging_input_contract.cms_write_allowed', true));
        $this->assertFalse((bool) data_get($report, 'staging_input_contract.production_import_allowed', true));
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJson(string $relativePath): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($relativePath)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
