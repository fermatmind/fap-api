<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use Tests\TestCase;

final class BigFiveResultPageV2GoldenCasesSelectorTest extends TestCase
{
    private const GOLDEN_CASES_PATH = 'content_assets/big5/result_page_v2/selector_qa_policy/v0_1/big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json';

    public function test_golden_cases_select_only_resolved_refs_or_fail_closed_with_suppression_trace(): void
    {
        $selector = new BigFiveV2DeterministicSelector();
        $routeRow = $this->o59RouteRow();

        foreach ($this->goldenCases() as $case) {
            $input = BigFiveV2SelectorInput::fromGoldenCase($case, $routeRow);
            $result = $selector->select($input);

            $this->assertFalse($result->safetyDecisions['unresolved_refs_selectable']);
            $this->assertFalse($result->safetyDecisions['body_composition_allowed']);
            $this->assertSame(92, count($result->unresolvedRefSuppressions));

            foreach ($result->selectedAssetRefs as $ref) {
                $this->assertNotSame('', $ref->assetKey);
                $this->assertStringStartsWith('asset.', $ref->assetKey);
            }
        }
    }

    public function test_o59_golden_case_stays_selector_qa_only_and_not_runtime_payload(): void
    {
        $selector = new BigFiveV2DeterministicSelector();
        $result = $selector->select(BigFiveV2SelectorInput::fromGoldenCase($this->o59GoldenCase(), $this->o59RouteRow()));

        $this->assertSame('O3_C2_E2_A3_N4', data_get($result->selectionTraceInternal, 'route_combination_key'));
        $this->assertSame('sensitive_independent_thinker', data_get($result->selectionTraceInternal, 'route_profile_key'));
        $this->assertSame(6, count($result->selectedAssetRefs));
        $this->assertFalse($result->safetyDecisions['ready_for_pilot']);
        $this->assertFalse($result->safetyDecisions['ready_for_runtime']);
        $this->assertFalse($result->safetyDecisions['ready_for_production']);
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
        foreach ($this->goldenCases() as $case) {
            if (($case['case_key'] ?? null) === 'golden_case_31_o59_canonical_preview') {
                return $case;
            }
        }

        $this->fail('O59 canonical golden case is missing.');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function goldenCases(): array
    {
        $cases = $this->decodeJson(self::GOLDEN_CASES_PATH);
        $this->assertIsList($cases);

        return $cases;
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
