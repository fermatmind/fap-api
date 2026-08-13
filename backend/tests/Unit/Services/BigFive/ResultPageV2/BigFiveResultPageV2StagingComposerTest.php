<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use App\Services\BigFive\ResultPageV2\Composer\BigFiveV2PilotPayloadComposer;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteDrivenSelectorInputBuilder;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectionResult;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use RuntimeException;
use Tests\TestCase;

final class BigFiveResultPageV2StagingComposerTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private static ?array $routeDrivenO59Envelope = null;

    public function test_route_driven_payload_uses_real_projection_and_validates(): void
    {
        $envelope = $this->composeRouteDrivenO59Envelope();
        $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
        $this->assertIsArray($payload);

        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope($envelope));
        $this->assertSame('attempt-real-o59', data_get($payload, 'projection_v2.attempt_id'));
        $this->assertSame('big5-engine-real-v7', data_get($payload, 'projection_v2.result_version'));
        $this->assertSame('big5_120', data_get($payload, 'projection_v2.form_code'));
        $this->assertSame('valid', data_get($payload, 'projection_v2.quality_status'));
        $this->assertSame('CALIBRATED', data_get($payload, 'projection_v2.norm_status'));
        $this->assertSame('norm-group-real', data_get($payload, 'projection_v2.norm_group_id'));
        $this->assertSame('norm-v7', data_get($payload, 'projection_v2.norm_version'));
        $this->assertSame([
            'O' => ['score' => 59, 'band' => 'mid'],
            'C' => ['score' => 32, 'band' => 'low'],
            'E' => ['score' => 20, 'band' => 'low'],
            'A' => ['score' => 55, 'band' => 'mid'],
            'N' => ['score' => 68, 'band' => 'high'],
        ], data_get($payload, 'projection_v2.domains'));
    }

    public function test_composer_emits_only_selected_assets_and_omits_empty_optional_modules(): void
    {
        $payload = $this->composeRouteDrivenO59Envelope()[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $modules = $this->modulesByKey($payload);

        $this->assertSame([
            'module_01_hero',
            'module_03_trait_deep_dive',
            'module_04_coupling',
            'module_05_facet_reframe',
            'module_06_application_matrix',
            'module_07_collaboration_manual',
        ], array_keys($modules));
        foreach ($modules as $module) {
            $this->assertNotSame([], $module['blocks'] ?? []);
        }

        $traits = array_map(
            static fn (array $block): string => (string) data_get($block, 'content.trait.code'),
            (array) data_get($modules, 'module_01_hero.blocks', []),
        );
        $this->assertSame(['', 'O', 'C', 'E', 'A', 'N'], $traits);

        $applicationScenarios = array_map(
            static fn (array $block): string => (string) data_get($block, 'content.scenario'),
            (array) data_get($modules, 'module_06_application_matrix.blocks', []),
        );
        $this->assertSame(['workplace', 'relationships', 'stress_recovery', 'personal_growth'], $applicationScenarios);
        $this->assertSame($applicationScenarios, array_values(array_unique($applicationScenarios)));
        $this->assertCount(1, (array) data_get($modules, 'module_07_collaboration_manual.blocks', []));
    }

    public function test_facet_polarity_is_unique_and_matches_actual_bucket(): void
    {
        $payload = $this->composeRouteDrivenO59Envelope()[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $blocks = (array) data_get($this->modulesByKey($payload), 'module_05_facet_reframe.blocks', []);

        $actual = [];
        foreach ($blocks as $block) {
            $actual[(string) data_get($block, 'content.facet_key')] = (string) data_get($block, 'content.facet_direction');
        }

        $this->assertSame(['C1' => 'low', 'N1' => 'high'], $actual);
    }

    public function test_public_payload_has_no_fixture_placeholder_staging_or_unauthorized_percentile(): void
    {
        $encoded = json_encode(
            $this->composeRouteDrivenO59Envelope()[BigFiveResultPageV2Contract::PAYLOAD_KEY],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        foreach ([
            'fixture_key',
            'attempt_big5_o59_pilot_fixture',
            'pending_asset_resolution',
            'placeholder',
            'deferred',
            'staging_only',
            'route_matrix_candidate',
            'source_reference',
            'selector_basis',
            'internal_metadata',
            'review_status',
            'production_use_allowed',
            'runtime_use',
            'ready_for_pilot',
            'ready_for_runtime',
            'ready_for_production',
            'frontend_fallback',
            'source_trace',
            'repair_log_refs',
            '"percentile":',
            '[object Object]',
        ] as $forbiddenPublicTerm) {
            $this->assertStringNotContainsString($forbiddenPublicTerm, $encoded, $forbiddenPublicTerm);
        }
    }

    public function test_missing_selected_ref_fails_closed(): void
    {
        $input = $this->routeDrivenO59Input();
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

        (new BigFiveV2PilotPayloadComposer)->compose($input, $selection);
    }

    /** @return array<string,mixed> */
    private function composeRouteDrivenO59Envelope(): array
    {
        if (self::$routeDrivenO59Envelope !== null) {
            return self::$routeDrivenO59Envelope;
        }

        $input = $this->routeDrivenO59Input();
        $selection = (new BigFiveV2DeterministicSelector)->select($input);

        return self::$routeDrivenO59Envelope = (new BigFiveV2PilotPayloadComposer)->compose($input, $selection);
    }

    private function routeDrivenO59Input(): BigFiveV2SelectorInput
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter)->fromScoreResult([
            'scale_code' => 'BIG5_OCEAN',
            'scores_0_100' => [
                'domains_percentile' => [
                    'O' => 59,
                    'C' => 32,
                    'E' => 20,
                    'A' => 55,
                    'N' => 68,
                ],
                'facets_percentile' => [
                    'N1' => 82,
                    'C1' => 24,
                ],
            ],
            'quality' => ['level' => 'A', 'flags' => ['CONSISTENT']],
            'norms' => [
                'status' => 'CALIBRATED',
                'group_id' => 'norm-group-real',
                'norms_version' => 'norm-v7',
                'percentile_display_allowed' => true,
            ],
        ]);
        $this->assertNotNull($routeInput);

        $result = (new BigFiveV2RouteMatrixParser)->parse();
        $this->assertSame([], $result->errors);
        $row = $result->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($row);

        return (new BigFiveV2RouteDrivenSelectorInputBuilder)->build(
            $routeInput,
            $row,
            attemptId: 'attempt-real-o59',
            resultVersion: 'big5-engine-real-v7',
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,array<string,mixed>>
     */
    private function modulesByKey(array $payload): array
    {
        $modules = [];
        foreach ((array) ($payload['modules'] ?? []) as $module) {
            if (is_array($module)) {
                $modules[(string) ($module['module_key'] ?? '')] = $module;
            }
        }
        unset($modules['']);

        return $modules;
    }
}
