<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use App\Services\BigFive\ResultPageV2\Composer\BigFiveV2PilotPayloadComposer;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectionResult;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use RuntimeException;
use Tests\TestCase;

final class BigFiveResultPageV2StagingComposerTest extends TestCase
{
    private const GOLDEN_CASES_PATH = 'content_assets/big5/result_page_v2/selector_qa_policy/v0_1/big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json';

    private const FIXTURE_PATH = 'tests/Fixtures/big5_result_page_v2/pilot_o59_staging_payload_v0_1.payload.json';

    public function test_o59_pilot_payload_composes_from_selected_refs_and_validates(): void
    {
        $envelope = $this->composeO59Envelope();
        $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
        $this->assertIsArray($payload);

        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope($envelope));
        $this->assertSame(BigFiveV2PilotPayloadComposer::CONTENT_VERSION, $payload['content_version'] ?? null);
        $this->assertSame(BigFiveV2PilotPayloadComposer::PACKAGE_VERSION, $payload['package_version'] ?? null);
        $this->assertSame('sensitive_independent_thinker', $payload['canonical_profile_key'] ?? null);
        $this->assertSame('敏锐的独立思考者', $payload['profile_label_zh'] ?? null);
        $this->assertSame(BigFiveResultPageV2Contract::MODULE_KEYS, array_map(
            static fn (array $module): string => (string) $module['module_key'],
            (array) ($payload['modules'] ?? []),
        ));
    }

    public function test_public_payload_filters_internal_trace_and_runtime_flags(): void
    {
        $encoded = json_encode(
            $this->composeO59Envelope()[BigFiveResultPageV2Contract::PAYLOAD_KEY],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        foreach ([
            'source_reference',
            'selector_basis',
            'qa_notes',
            'editor_notes',
            'internal_metadata',
            'review_status',
            'production_use_allowed',
            'runtime_use',
            'ready_for_pilot',
            'ready_for_runtime',
            'ready_for_production',
            'frontend_fallback',
            '[object Object]',
        ] as $forbiddenPublicTerm) {
            $this->assertStringNotContainsString($forbiddenPublicTerm, $encoded, $forbiddenPublicTerm);
        }
    }

    public function test_o59_pilot_payload_contains_selected_available_content_and_pending_placeholders(): void
    {
        $payload = $this->composeO59Envelope()[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $modulesByKey = [];
        foreach ((array) ($payload['modules'] ?? []) as $module) {
            $modulesByKey[(string) ($module['module_key'] ?? '')] = $module;
        }

        $this->assertSame('敏锐的独立思考者', data_get($modulesByKey, 'module_01_hero.blocks.0.content.label_zh'));
        $this->assertSame('开放性中位｜优势、代价与使用方式', data_get($modulesByKey, 'module_03_trait_deep_dive.blocks.0.content.title_zh'));
        $this->assertSame('pending_asset_resolution', data_get($modulesByKey, 'module_04_coupling.blocks.0.content.availability'));
        $this->assertSame('pending_asset_resolution', data_get($modulesByKey, 'module_06_application_matrix.blocks.0.content.availability'));
    }

    public function test_fixture_matches_current_composer_output(): void
    {
        $this->assertSame($this->composeO59Envelope(), $this->decodeJson(self::FIXTURE_PATH));
    }

    public function test_missing_selected_ref_fails_closed(): void
    {
        $input = $this->o59Input();
        $selection = new BigFiveV2SelectionResult(
            selectedAssetRefs: [
                new BigFiveV2SelectedAssetRef(
                    assetKey: 'asset.missing.v0_0',
                    registryKey: 'domain_registry',
                    moduleKey: 'module_03_trait_deep_dive',
                    blockKey: 'module_03_trait_deep_dive.domain_registry.missing.v0_0',
                    slotKey: 'module_03_trait_deep_dive.domain_card.O.mid',
                    priority: 1,
                    contentSource: 'missing',
                ),
            ],
            suppressedAssetRefs: [],
            unresolvedRefSuppressions: [],
            pendingSurfaces: [],
            safetyDecisions: [],
            selectionTraceInternal: [],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve');

        (new BigFiveV2PilotPayloadComposer())->compose($input, $selection);
    }

    /**
     * @return array<string,mixed>
     */
    private function composeO59Envelope(): array
    {
        $input = $this->o59Input();
        $selection = (new BigFiveV2DeterministicSelector())->select($input);

        return (new BigFiveV2PilotPayloadComposer())->compose($input, $selection);
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
