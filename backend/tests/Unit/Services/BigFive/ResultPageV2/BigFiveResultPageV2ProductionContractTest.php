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
use Tests\TestCase;

final class BigFiveResultPageV2ProductionContractTest extends TestCase
{
    public function test_runtime_envelope_passes_strict_production_contract(): void
    {
        $envelope = $this->runtimeEnvelope();

        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateProductionEnvelope($envelope));
    }

    public function test_runtime_contract_rejects_placeholder_and_duplicate_visible_content(): void
    {
        $envelope = $this->runtimeEnvelope();
        $payload = &$envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $deepIndex = $this->moduleIndex($payload, 'module_03_trait_deep_dive');
        $payload['modules'][$deepIndex]['blocks'][0]['content']['action_zh'] = 'pending_asset_resolution';
        $payload['modules'][$deepIndex]['blocks'][1]['content']['title_zh'] =
            $payload['modules'][$deepIndex]['blocks'][0]['content']['title_zh'];

        $errors = app(BigFiveResultPageV2Validator::class)->validateProductionEnvelope($envelope);

        $this->assertContains('module_03_trait_deep_dive.blocks.0 contains placeholder or deferred content', $errors);
        $this->assertContains('Duplicate normalized visible content', $errors);
    }

    public function test_runtime_contract_rejects_empty_method_module(): void
    {
        $envelope = $this->runtimeEnvelope();
        $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][] = [
            'module_key' => 'module_10_method_privacy',
            'blocks' => [],
        ];

        $errors = app(BigFiveResultPageV2Validator::class)->validateProductionEnvelope($envelope);

        $this->assertContains('Module module_10_method_privacy must include at least one block', $errors);
    }

    public function test_runtime_contract_rejects_both_facet_polarities(): void
    {
        $envelope = $this->runtimeEnvelope();
        $payload = &$envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $facetIndex = $this->moduleIndex($payload, 'module_05_facet_reframe');
        $opposite = $payload['modules'][$facetIndex]['blocks'][0];
        $opposite['block_key'] .= '.opposite';
        $opposite['content']['facet_direction'] = 'high';
        $payload['modules'][$facetIndex]['blocks'][] = $opposite;

        $errors = app(BigFiveResultPageV2Validator::class)->validateProductionEnvelope($envelope);

        $this->assertContains('facet C1 must not contain both high and low polarity', $errors);
        $this->assertContains('facet C1 polarity does not match projection bucket', $errors);
    }

    public function test_runtime_contract_rejects_multiple_candidates_for_same_slot(): void
    {
        $envelope = $this->runtimeEnvelope();
        $payload = &$envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $heroIndex = $this->moduleIndex($payload, 'module_01_hero');
        $duplicate = $payload['modules'][$heroIndex]['blocks'][1];
        $duplicate['block_key'] .= '.candidate';
        $payload['modules'][$heroIndex]['blocks'][] = $duplicate;

        $errors = app(BigFiveResultPageV2Validator::class)->validateProductionEnvelope($envelope);

        $this->assertContains('Multiple candidates for semantic slot: module_01_hero:trait_bars:O', $errors);
        $this->assertContains('trait_bars must contain O exactly once', $errors);
    }

    public function test_runtime_contract_rejects_score_band_mismatch_and_unauthorized_percentile(): void
    {
        $envelope = $this->runtimeEnvelope();
        $payload = &$envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $payload['projection_v2']['domains']['O']['score'] = 90;
        $payload['projection_v2']['domains']['C']['percentile'] = 32;
        $heroIndex = $this->moduleIndex($payload, 'module_01_hero');
        $payload['modules'][$heroIndex]['blocks'][1]['content']['band']['internal_band'] = 'high';
        $payload['canonical_profile_key'] = 'wrong-profile';

        $errors = app(BigFiveResultPageV2Validator::class)->validateProductionEnvelope($envelope);

        $this->assertContains('projection_v2.domains.O score/band route mismatch', $errors);
        $this->assertContains('Forbidden public field projection_v2.domains.C.percentile', $errors);
        $this->assertContains('trait_bars O band does not match projection', $errors);
        $this->assertContains('canonical profile must match projection profile route', $errors);
    }

    public function test_production_contract_cannot_be_bypassed_with_an_old_content_version(): void
    {
        $envelope = $this->runtimeEnvelope();
        $payload = &$envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        $payload['content_version'] = 'big5_result_page_v2.pilot_payload.v0_1';
        $payload['projection_v2']['attempt_id'] = '';

        $errors = app(BigFiveResultPageV2Validator::class)->validateProductionEnvelope($envelope);

        $this->assertContains('Production payload content_version must be big5_result_page_v2.runtime.v2', $errors);
        $this->assertContains('projection_v2.attempt_id must be an actual non-empty attempt id', $errors);
    }

    /** @return array<string,mixed> */
    private function runtimeEnvelope(): array
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter)->fromScoreResult([
            'scale_code' => 'BIG5_OCEAN',
            'scores_0_100' => [
                'domains_percentile' => ['O' => 59, 'C' => 32, 'E' => 20, 'A' => 55, 'N' => 68],
                'facets_percentile' => ['N1' => 82, 'C1' => 24],
            ],
            'quality' => ['level' => 'A'],
            'norms' => [
                'status' => 'CALIBRATED',
                'group_id' => 'norm-group-real',
                'norms_version' => 'norm-v7',
                'percentile_display_allowed' => true,
            ],
        ]);
        $this->assertNotNull($routeInput);

        $parsed = (new BigFiveV2RouteMatrixParser)->parse();
        $this->assertSame([], $parsed->errors);
        $row = $parsed->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($row);

        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder)->build(
            $routeInput,
            $row,
            attemptId: 'attempt-real-o59',
            resultVersion: 'engine-real-v7',
        );
        $selection = (new BigFiveV2DeterministicSelector)->select($input);

        return (new BigFiveV2PilotPayloadComposer)->compose($input, $selection);
    }

    /** @param array<string,mixed> $payload */
    private function moduleIndex(array $payload, string $moduleKey): int
    {
        foreach ((array) ($payload['modules'] ?? []) as $index => $module) {
            if (is_array($module) && ($module['module_key'] ?? null) === $moduleKey) {
                return (int) $index;
            }
        }

        $this->fail("Missing module {$moduleKey}");
    }
}
