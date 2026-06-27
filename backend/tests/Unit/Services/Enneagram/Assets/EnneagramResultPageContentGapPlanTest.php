<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use Tests\TestCase;

final class EnneagramResultPageContentGapPlanTest extends TestCase
{
    public function test_content_gap_plan_maps_rendered_inventory_to_backend_asset_streams_without_runtime_permissions(): void
    {
        $root = base_path('content_assets/enneagram/result_page/content_gap_plan/v0_1');
        $plan = $this->readJson($root.'/content_asset_gap_plan_v0_1.json');

        $this->assertSame('fap.enneagram.result_page.content_gap_plan.v0.1', $plan['schema_version'] ?? null);
        $this->assertSame('planning_workspace_only', $plan['status'] ?? null);
        $this->assertSame('not_runtime', $plan['runtime_use'] ?? null);
        $this->assertFalse((bool) ($plan['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($plan['candidate_export_happened'] ?? true));
        $this->assertFalse((bool) ($plan['inactive_import_happened'] ?? true));
        $this->assertFalse((bool) ($plan['activation_happened'] ?? true));
        $this->assertFalse((bool) ($plan['cms_write_performed'] ?? true));
        $this->assertFalse((bool) ($plan['frontend_fallback_allowed'] ?? true));

        $this->assertSame(
            'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0',
            data_get($plan, 'source_refs.candidate_contract.baseline_candidate_manifest_sha256')
        );
        $this->assertSame(
            'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f',
            data_get($plan, 'source_refs.candidate_contract.runtime_registry_manifest_sha256')
        );
        $this->assertSame(630, (int) data_get($plan, 'source_refs.candidate_contract.expected_candidate_payload_count'));
        $this->assertSame(['1R-I', '1R-J'], data_get($plan, 'source_refs.candidate_contract.out_of_launch_scope'));
        $this->assertSame('41039084935ea7b7fadba93be62771d2a50d10b6', data_get($plan, 'source_refs.rendered_inventory.merge_commit'));

        $moduleIds = array_map(
            static fn (array $module): string => (string) ($module['module_id'] ?? ''),
            (array) ($plan['module_gap_plan'] ?? [])
        );

        foreach ([
            'result_overview_hero',
            'top3_candidate_reading',
            'primary_type_deep_dive',
            'all9_profile_score_band',
            'confidence_dominance_public_copy',
            'close_call_pair_differentiation',
            'work_reality',
            'growth_spectrum',
            'relationship_conflict',
            'method_observation_next',
            'public_safe_share_pdf_history_compare',
        ] as $expectedModule) {
            $this->assertContains($expectedModule, $moduleIds);
        }

        $gapIds = array_map(
            static fn (array $gap): string => (string) ($gap['gap_id'] ?? ''),
            (array) ($plan['observed_rendered_gaps'] ?? [])
        );

        foreach ([
            'rendered_close_call_scaffold_copy',
            'rendered_raw_score_like_numbers',
            'rendered_internal_metric_copy',
            'zh_cn_type_label_depth',
            'certainty_copy_boundary',
            'share_entry_gap',
            'growth_module_repeated_copy_risk',
        ] as $expectedGap) {
            $this->assertContains($expectedGap, $gapIds);
        }

        $benchmarkUrls = array_map(
            static fn (array $ref): string => (string) ($ref['url'] ?? ''),
            (array) data_get($plan, 'source_refs.benchmark_refs', [])
        );
        $this->assertContains('https://www.123test.com/enneagram-test/', $benchmarkUrls);
        $this->assertContains('https://www.truity.com/test/enneagram-personality-test', $benchmarkUrls);

        foreach ((array) ($plan['negative_guarantees'] ?? []) as $guarantee) {
            $this->assertFalse((bool) $guarantee);
        }
    }

    public function test_agent_generation_contract_blocks_private_fields_and_forbidden_claim_families(): void
    {
        $root = base_path('content_assets/enneagram/result_page/content_gap_plan/v0_1');
        $contract = $this->readJson($root.'/agent_generation_contract_v0_1.json');

        $this->assertSame('fap.enneagram.result_page.agent_generation_contract.v0.1', $contract['schema_version'] ?? null);
        $this->assertFalse((bool) ($contract['agent_may_generate_bulk_content'] ?? true));
        $this->assertFalse((bool) ($contract['agent_may_export_candidate'] ?? true));
        $this->assertFalse((bool) ($contract['agent_may_import_candidate'] ?? true));
        $this->assertFalse((bool) ($contract['agent_may_activate_runtime'] ?? true));
        $this->assertFalse((bool) ($contract['agent_may_write_production'] ?? true));
        $this->assertFalse((bool) ($contract['agent_may_write_cms'] ?? true));
        $this->assertFalse((bool) ($contract['agent_may_change_frontend'] ?? true));

        foreach ([
            'final_typing_you_are_this_type_claim',
            'fixed_type_certainty_claim',
            'diagnosis_therapy_treatment_claim',
            'hiring_screen_or_employment_suitability_claim',
            'success_salary_performance_prediction_claim',
            'e105_fc144_score_comparison',
            'fc144_more_accurate_or_replacement_result_claim',
            'raw_score_vector_public_leak',
            'attempt_id_public_leak',
            'private_report_payload_publication',
        ] as $blockedFamily) {
            $this->assertContains($blockedFamily, (array) ($contract['forbidden_claim_families'] ?? []));
        }

        foreach ([
            'attempt_id',
            'raw_score',
            'score_vector',
            'dominance_gap',
            'profile_entropy',
            'selector_trace',
            'candidate_manifest_sha256',
            'runtime_registry_sha256',
            'private_report_payload',
        ] as $blockedField) {
            $this->assertContains($blockedField, (array) ($contract['public_surface_forbidden_fields'] ?? []));
        }

        foreach ((array) ($contract['negative_guarantees'] ?? []) as $guarantee) {
            $this->assertFalse((bool) $guarantee);
        }
    }

    public function test_workspace_is_editable_but_not_runtime_content(): void
    {
        $root = base_path('content_assets/enneagram/result_page/content_gap_plan/v0_1');
        $workspace = (string) file_get_contents($root.'/content_asset_pack_workspace_v0_1.md');

        foreach ([
            'Module 1: Result Overview Hero',
            'Module 6: Close-Call Pair Differentiation',
            'Module 11: Public-Safe Share, PDF, History, Compare',
            'This file is not runtime content.',
            'Benchmark 123test and Truity for section shape only.',
            'Do not expose raw scores',
            'Do not write final type verdicts',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $workspace);
        }

        foreach ([
            '/Users/rainie/',
            '/private/tmp/',
            'you are this type',
            'FC144 is more accurate',
            'salary prediction',
            'hiring suitability',
            'production_activation_happened\": true',
        ] as $forbiddenText) {
            $this->assertStringNotContainsString($forbiddenText, $workspace);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $path.' must decode to an array');

        return $decoded;
    }
}
