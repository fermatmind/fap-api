<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use Tests\TestCase;

final class BigFiveResultPageV2DeterministicSelectorTest extends TestCase
{
    private const GOLDEN_CASES_PATH = 'content_assets/big5/result_page_v2/selector_qa_policy/v0_1/big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json';

    private const SELECTOR_ASSETS_PATH = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json';

    private const REFERENCE_REPORT_PATH = 'content_assets/big5/result_page_v2/qa/selector_reference_consistency/v0_1/selector_reference_consistency_report_v0_1.json';

    public function test_o59_golden_case_selects_expected_available_asset_refs(): void
    {
        $result = $this->selector()->select($this->o59Input());
        $selectedSlots = array_map(
            static fn (BigFiveV2SelectedAssetRef $ref): string => $ref->slotKey,
            $result->selectedAssetRefs,
        );
        $expectedAvailableSlots = array_values(array_diff($this->o59ExpectedSlots(), $this->o59ExpectedUnresolvedSlots()));

        $this->assertSame($expectedAvailableSlots, $selectedSlots);
        $this->assertCount(6, $result->selectedAssetRefs);
        $this->assertSame('staging_only', $result->safetyDecisions['runtime_use']);
        $this->assertFalse($result->safetyDecisions['production_use_allowed']);
        $this->assertFalse($result->safetyDecisions['ready_for_pilot']);
        $this->assertFalse($result->safetyDecisions['ready_for_runtime']);
        $this->assertFalse($result->safetyDecisions['ready_for_production']);
        $this->assertFalse($result->safetyDecisions['consumer_side_body_fallback_allowed']);
        $this->assertFalse($result->safetyDecisions['body_composition_allowed']);
    }

    public function test_selected_refs_resolve_through_selector_ready_assets_and_do_not_include_unresolved_refs(): void
    {
        $result = $this->selector()->select($this->o59Input());
        $assetKeys = $this->selectorAssetKeys();
        $unresolvedAssetKeys = $this->unresolvedAssetKeys();

        foreach ($result->selectedAssetRefs as $ref) {
            $this->assertArrayHasKey($ref->assetKey, $assetKeys, $ref->assetKey);
            $this->assertArrayNotHasKey($ref->assetKey, $unresolvedAssetKeys, $ref->assetKey);
        }

        $this->assertSame(92, count($result->unresolvedRefSuppressions));
        $this->assertSame(92, count($result->suppressedAssetRefs));
        $this->assertSame(
            array_keys($unresolvedAssetKeys),
            array_map(static fn (array $suppression): string => (string) $suppression['asset_key'], $result->unresolvedRefSuppressions),
        );
    }

    public function test_selector_output_contains_refs_and_suppression_only_not_body_payload(): void
    {
        $result = $this->selector()->select($this->o59Input())->toArray();
        $encodedSelectedRefs = json_encode($result['selected_asset_refs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ([
            'body_zh',
            'summary_zh',
            'public_payload',
            'internal_metadata',
            'source_reference',
            'selector_basis',
            'frontend_fallback',
            '[object Object]',
        ] as $forbiddenKeyOrTerm) {
            $this->assertStringNotContainsString($forbiddenKeyOrTerm, $encodedSelectedRefs, $forbiddenKeyOrTerm);
        }

        $this->assertArrayHasKey('selected_asset_refs', $result);
        $this->assertArrayHasKey('suppressed_asset_refs', $result);
        $this->assertArrayHasKey('unresolved_ref_suppressions', $result);
        $this->assertArrayHasKey('safety_decisions', $result);
        $this->assertArrayHasKey('selection_trace_internal', $result);
    }

    public function test_invalid_scale_code_fails_closed_without_selecting_assets(): void
    {
        $input = new BigFiveV2SelectorInput(
            scaleCode: 'MBTI',
            formCode: 'big5_120',
            domainBands: ['O' => 'mid', 'C' => 'low', 'E' => 'low', 'A' => 'mid', 'N' => 'high'],
            domainScores: ['O' => 59, 'C' => 32, 'E' => 20, 'A' => 55, 'N' => 68],
            facetSignals: [],
            qualityStatus: 'valid',
            normStatus: 'available',
            readingMode: 'quick',
            scenario: null,
            routeRow: $this->o59RouteRow(),
            includeSlots: $this->o59ExpectedSlots(),
            includeRegistryKeys: ['profile_signature_registry', 'domain_registry', 'coupling_registry', 'scenario_registry'],
        );

        $result = $this->selector()->select($input);

        $this->assertSame([], $result->selectedAssetRefs);
        $this->assertFalse($result->safetyDecisions['unresolved_refs_selectable']);
        $this->assertFalse($result->safetyDecisions['body_composition_allowed']);
    }

    private function selector(): BigFiveV2DeterministicSelector
    {
        return new BigFiveV2DeterministicSelector();
    }

    private function o59Input(): BigFiveV2SelectorInput
    {
        return BigFiveV2SelectorInput::fromGoldenCase($this->o59GoldenCase(), $this->o59RouteRow());
    }

    private function o59RouteRow(): \App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixRow
    {
        $result = (new BigFiveV2RouteMatrixParser())->parse();
        $this->assertSame([], $result->errors);

        $row = $result->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function o59GoldenCase(): array
    {
        foreach ($this->decodeJson(self::GOLDEN_CASES_PATH) as $case) {
            if (($case['case_key'] ?? null) === 'golden_case_31_o59_canonical_preview') {
                return $case;
            }
        }

        $this->fail('O59 canonical golden case is missing.');
    }

    /**
     * @return list<string>
     */
    private function o59ExpectedSlots(): array
    {
        return array_values((array) data_get($this->o59GoldenCase(), 'expected_selection.include_slots'));
    }

    /**
     * @return list<string>
     */
    private function o59ExpectedUnresolvedSlots(): array
    {
        $unresolvedAssetKeys = $this->unresolvedAssetKeys();
        $unresolvedSlots = [];

        foreach ($this->decodeJson(self::SELECTOR_ASSETS_PATH) as $asset) {
            if (isset($unresolvedAssetKeys[(string) ($asset['asset_key'] ?? '')])) {
                $unresolvedSlots[] = (string) ($asset['slot_key'] ?? '');
            }
        }

        return array_values(array_intersect($this->o59ExpectedSlots(), $unresolvedSlots));
    }

    /**
     * @return array<string,true>
     */
    private function selectorAssetKeys(): array
    {
        $keys = [];
        foreach ($this->decodeJson(self::SELECTOR_ASSETS_PATH) as $asset) {
            $keys[(string) ($asset['asset_key'] ?? '')] = true;
        }
        unset($keys['']);

        return $keys;
    }

    /**
     * @return array<string,true>
     */
    private function unresolvedAssetKeys(): array
    {
        $keys = [];
        foreach ((array) ($this->decodeJson(self::REFERENCE_REPORT_PATH)['checks'] ?? []) as $check) {
            foreach ((array) ($check['unresolved_references'] ?? []) as $reference) {
                $keys[(string) ($reference['asset_key'] ?? '')] = true;
            }
        }
        unset($keys['']);
        ksort($keys);

        return $keys;
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
