<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2SelectorQaPolicyRepairTest extends TestCase
{
    private const POLICY_DIR = 'content_assets/big5/result_page_v2/selector_qa_policy/v0_1';

    public function test_repair_report_locks_staging_only_policy_repairs(): void
    {
        $report = $this->decodePolicyJson('big5_result_page_v2_selector_qa_policy_v0_1_repair_report.json');

        $this->assertSame('BIG5-RESULT-SELECTOR-QA-REPAIR-01', $report['repair_id'] ?? null);
        $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
        $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_pilot'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_production'] ?? true));
        $this->assertFalse((bool) data_get($report, 'repair_summary.selector_asset_mutation', true));
        $this->assertFalse((bool) data_get($report, 'repair_summary.runtime_wrapper_change', true));
        $this->assertFalse((bool) data_get($report, 'repair_summary.cms_write', true));
        $this->assertFalse((bool) data_get($report, 'repair_summary.production_gate_change', true));
    }

    public function test_repair_report_matches_golden_case_groups_and_o59_case(): void
    {
        $report = $this->decodePolicyJson('big5_result_page_v2_selector_qa_policy_v0_1_repair_report.json');
        $manifest = $this->decodePolicyJson('big5_result_page_v2_selector_qa_policy_v0_1_manifest.json');
        $goldenCases = $this->decodePolicyJson('big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json');

        $expectedGroups = [
            'clear_signature' => 6,
            'high_tension_or_mixed' => 7,
            'balanced_or_diffuse' => 3,
            'facet_reframe' => 5,
            'safety_downgrade' => 5,
            'scenario_application' => 4,
            'canonical_o59_preview' => 1,
        ];

        $this->assertCount(31, $goldenCases);
        $this->assertSame($expectedGroups, $manifest['golden_case_groups'] ?? null);
        $this->assertSame($expectedGroups, $report['golden_case_groups'] ?? null);

        $o59Cases = array_values(array_filter(
            $goldenCases,
            static fn (array $case): bool => ($case['case_key'] ?? null) === 'golden_case_31_o59_canonical_preview',
        ));

        $this->assertCount(1, $o59Cases);
        $this->assertSame('canonical_o59_preview', $o59Cases[0]['golden_group'] ?? null);
        $this->assertSame('O3_C2_E2_A3_N4', data_get($report, 'o59_canonical_case.combination_key'));
        $this->assertSame('sensitive_independent_thinker', data_get($report, 'o59_canonical_case.expected_profile_family'));
    }

    public function test_slot_module_alias_and_banned_terms_are_explicit_policy(): void
    {
        $selectionPolicy = $this->decodePolicyJson('big5_result_page_v2_selector_qa_policy_v0_1_selection_policy.json');
        $conflictPolicy = $this->decodePolicyJson('big5_result_page_v2_selector_qa_policy_v0_1_conflict_resolution.json');
        $report = $this->decodePolicyJson('big5_result_page_v2_selector_qa_policy_v0_1_repair_report.json');

        $alias = data_get($selectionPolicy, 'global_rules.slot_key_prefix_aliases.module_02_quick');
        $this->assertSame('module_02_quick_understanding', $alias['module_key'] ?? null);
        $this->assertSame('legacy_slot_prefix_allowed', $alias['status'] ?? null);
        $this->assertTrue((bool) ($alias['must_not_be_used_as_module_key'] ?? false));
        $this->assertSame($alias['module_key'] ?? null, data_get($report, 'slot_key_prefix_aliases.module_02_quick.module_key'));

        $bannedTerms = data_get($conflictPolicy, 'rendered_text_banned_scan.must_not_render');
        $this->assertIsArray($bannedTerms);

        foreach ([
            'official_32_type',
            '32_type',
            'personality_type_assignment',
            'clinical_diagnosis',
            'therapy_claim',
            'treatment_claim',
            'employment_screening',
            'success_prediction',
            'ability_measurement',
            '官方32型',
            '人格类型判定',
            '临床诊断',
            '治疗建议',
            '招聘筛选',
            '成功预测',
            '能力测量',
        ] as $term) {
            $this->assertContains($term, $bannedTerms, $term);
            $this->assertContains($term, $report['rendered_banned_terms_added'] ?? [], $term);
        }
    }

    public function test_sha256sums_match_policy_files(): void
    {
        $sha256Sums = file(base_path(self::POLICY_DIR.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($sha256Sums);

        foreach ($sha256Sums as $line) {
            [$expectedSha256, $fileName] = explode('  ', $line, 2);
            $this->assertSame($expectedSha256, hash_file('sha256', base_path(self::POLICY_DIR.'/'.$fileName)), $fileName);
        }
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodePolicyJson(string $fileName): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(self::POLICY_DIR.'/'.$fileName)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
