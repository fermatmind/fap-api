<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use Tests\TestCase;

final class RiasecResultPageSelectorQaPolicyTest extends TestCase
{
    public function test_selector_qa_policy_is_staging_only_and_fail_closed(): void
    {
        $base = dirname(__DIR__, 4).'/content_assets/riasec/result_page_v2/selector_qa_policy/v0_1';

        foreach ([
            'riasec_result_page_v2_selector_qa_policy_v0_1_manifest.json',
            'riasec_result_page_v2_selector_qa_policy_v0_1_selection_policy.json',
            'riasec_result_page_v2_selector_qa_policy_v0_1_conflict_resolution.json',
            'riasec_result_page_v2_selector_qa_policy_v0_1_golden_cases.json',
            'riasec_result_page_v2_selector_qa_policy_v0_1_repair_report.json',
        ] as $filename) {
            $payload = $this->readJson($base.'/'.$filename);

            $this->assertSame('staging_only', $payload['runtime_use'] ?? null, $filename);
            $this->assertFalse((bool) ($payload['production_use_allowed'] ?? true), $filename);
            $this->assertFalse((bool) ($payload['ready_for_runtime'] ?? true), $filename);
            $this->assertFalse((bool) ($payload['ready_for_production'] ?? true), $filename);
        }

        $selectionPolicy = $this->readJson($base.'/riasec_result_page_v2_selector_qa_policy_v0_1_selection_policy.json');
        $this->assertContains('selector_ready_assets_missing', $selectionPolicy['coverage_warnings'] ?? []);
        $this->assertSame('omit_result_page_v2_modules', data_get($selectionPolicy, 'scope_overrides.route_miss.fallback_policy'));
        $this->assertSame('^module_[0-9]{2}_[a-z0-9_]+$', data_get($selectionPolicy, 'slot_module_naming_policy.module_key_pattern'));

        $conflictResolution = $this->readJson($base.'/riasec_result_page_v2_selector_qa_policy_v0_1_conflict_resolution.json');
        $this->assertContains('raw_score', $conflictResolution['public_payload_forbidden_fields'] ?? []);
        $this->assertContains('职业推荐', $conflictResolution['banned_terms'] ?? []);
        $this->assertSame('omit_share_block', data_get($conflictResolution, 'fallback_rules.share_safety_violation'));

        $goldenCases = $this->readJson($base.'/riasec_result_page_v2_selector_qa_policy_v0_1_golden_cases.json');
        $this->assertFalse((bool) ($goldenCases['golden_cases_ready'] ?? true));
        $this->assertSame(0, (int) ($goldenCases['golden_case_count'] ?? -1));
        $this->assertGreaterThanOrEqual(7, count($goldenCases['required_groups'] ?? []));
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
