<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2SelectorPolicyTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/selector_qa_policy/v0_1';

    public function test_policy_pack_is_parseable_and_staging_only(): void
    {
        $manifest = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_manifest.json');
        $goldenCases = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json');
        $selectionPolicy = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_selection_policy.json');
        $conflictResolution = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_conflict_resolution.json');
        $slotResolution = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_slot_resolution_report.json');

        $this->assertSame('selector_qa_policy_advisory_pack', $manifest['package_role'] ?? null);
        $this->assertSame('advisory_only', $manifest['policy_use'] ?? null);
        $this->assertSame(31, $manifest['golden_case_count'] ?? null);
        $this->assertSame(30, $manifest['source_golden_case_count'] ?? null);
        $this->assertTrue((bool) data_get($manifest, 'normalization_summary.added_o59_canonical_case'));
        $this->assertTrue((bool) data_get($manifest, 'normalization_summary.normalized_facet_include_slot_case'));
        $this->assertSame(0, data_get($manifest, 'normalization_summary.slot_resolution_report_unresolved_reference_count'));

        foreach ([$manifest, $selectionPolicy, $conflictResolution] as $document) {
            $this->assertSame('staging_only', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }

        $this->assertSame('not_runtime', $slotResolution['runtime_use'] ?? null);
        $this->assertFalse((bool) ($slotResolution['production_use_allowed'] ?? true));
        $this->assertCount(31, $goldenCases);
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    public function test_golden_cases_include_groups_and_o59_canonical_preview_case(): void
    {
        $cases = $this->goldenCases();
        $casesByKey = [];

        foreach ($cases as $case) {
            $caseKey = (string) ($case['case_key'] ?? '');
            $casesByKey[$caseKey] = $case;

            $this->assertSame('selector_golden_case', $case['case_type'] ?? null);
            $this->assertNotSame('', (string) ($case['golden_group'] ?? ''));
            $this->assertSame('staging_only', $case['runtime_use'] ?? null);
            $this->assertFalse((bool) ($case['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($case['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($case['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($case['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($case['ready_for_production'] ?? true));
        }

        $this->assertArrayHasKey('golden_case_31_o59_canonical_preview', $casesByKey);
        $o59 = $casesByKey['golden_case_31_o59_canonical_preview'];

        $this->assertSame('canonical_o59_preview', $o59['golden_group'] ?? null);
        $this->assertSame([
            'O' => 59,
            'C' => 32,
            'E' => 20,
            'A' => 55,
            'N' => 68,
        ], data_get($o59, 'input_projection.domain_scores'));
        $this->assertSame('canonical_o59_c32_e20_a55_n68', data_get($o59, 'input_projection.source_profile_id'));
        $this->assertSame('sensitive_independent_thinker', data_get($o59, 'expected_selection.primary_signature_key'));
        $this->assertContains('module_01_hero.primary_signature.sensitive_independent_thinker', data_get($o59, 'expected_selection.include_slots'));
    }

    public function test_include_slots_and_registry_keys_resolve_against_selector_ready_assets(): void
    {
        $selectorAssets = $this->decodeJson('content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json');
        $slotKeys = array_fill_keys(array_map(static fn (array $asset): string => (string) $asset['slot_key'], $selectorAssets), true);
        $registryKeys = array_fill_keys(array_map(static fn (array $asset): string => (string) $asset['registry_key'], $selectorAssets), true);
        $computedUnresolved = [];

        foreach ($this->goldenCases() as $case) {
            foreach ((array) data_get($case, 'expected_selection.include_slots', []) as $slotKey) {
                $this->assertMatchesRegularExpression('/^module_\\d{2}_[a-z0-9_]+\\.[a-z0-9_]+\\.[A-Za-z0-9_.]+$/', $slotKey);
                $this->assertDoesNotMatchRegularExpression('/^module_05_facet_reframe\\.facet_card\\.[A-Z]\\d\\./', $slotKey);
                if (! isset($slotKeys[(string) $slotKey])) {
                    $computedUnresolved[] = [
                        'case_key' => (string) ($case['case_key'] ?? ''),
                        'reference_type' => 'include_slot',
                        'reference' => (string) $slotKey,
                    ];
                }
            }

            foreach ((array) data_get($case, 'expected_selection.include_registry_keys', []) as $registryKey) {
                if (! isset($registryKeys[(string) $registryKey])) {
                    $computedUnresolved[] = [
                        'case_key' => (string) ($case['case_key'] ?? ''),
                        'reference_type' => 'include_registry_key',
                        'reference' => (string) $registryKey,
                    ];
                }
            }
        }

        $report = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_slot_resolution_report.json');

        $this->assertSame(31, data_get($report, 'summary.golden_case_count'));
        $this->assertSame(325, data_get($report, 'summary.selector_asset_count'));
        $this->assertSame(10, data_get($report, 'summary.normalization_change_count'));
        $this->assertSame(0, data_get($report, 'summary.unresolved_reference_count'));
        $this->assertSame($computedUnresolved, $report['unresolved_references'] ?? null);
    }

    public function test_rendered_banned_terms_are_expanded_for_public_surfaces(): void
    {
        $selectionPolicy = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_selection_policy.json');
        $conflictResolution = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_conflict_resolution.json');
        $bannedTerms = (array) data_get($conflictResolution, 'rendered_text_banned_scan.must_not_render');
        $surfaces = (array) data_get($conflictResolution, 'rendered_text_banned_scan.surfaces');
        $metadataNeverPublic = (array) data_get($selectionPolicy, 'global_rules.metadata_never_public');

        foreach ([
            'internal_metadata',
            'selector_basis',
            'review_required',
            'source_reference',
            'frontend_fallback',
            '[object Object]',
            'deferred_to_future',
            'policy_not_shipped',
            'production_use_allowed',
            'ready_for_runtime',
            'ready_for_pilot',
            'ready_for_production',
            'medical_diagnosis',
            'hiring_screening',
        ] as $term) {
            $this->assertContains($term, $bannedTerms);
        }

        foreach ([
            'internal_metadata',
            'selector_basis',
            'review_required',
            'source_reference',
            'frontend_fallback',
            'production_use_allowed',
            'ready_for_runtime',
            'ready_for_pilot',
            'ready_for_production',
        ] as $term) {
            $this->assertContains($term, $metadataNeverPublic);
        }

        foreach (['result_page_desktop', 'result_page_mobile', 'pdf', 'share_card', 'history', 'compare'] as $surface) {
            $this->assertContains($surface, $surfaces);
        }

        foreach ($this->goldenCases() as $case) {
            foreach (['internal_metadata', 'selector_basis', 'frontend_fallback', '[object Object]'] as $term) {
                $this->assertContains($term, data_get($case, 'expected_selection.must_not_contain'));
            }
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function goldenCases(): array
    {
        $cases = $this->jsonFile('big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json');
        $this->assertIsList($cases);

        return $cases;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        return $this->decodeJson(self::BASE_PATH.'/'.$fileName);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJson(string $relativePath): array
    {
        $json = file_get_contents(base_path($relativePath));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
