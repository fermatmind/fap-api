<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteDrivenSelectorInputBuilder;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class BigFiveResultPageV2DeterministicSelectorTest extends TestCase
{
    public function test_route_driven_selector_only_selects_exact_shortlist_winners(): void
    {
        $input = $this->o59Input();
        $result = (new BigFiveV2DeterministicSelector)->select($input);
        $allowed = array_fill_keys($input->includeAssetKeys, true);

        $this->assertNotSame([], $result->selectedAssetRefs);
        foreach ($result->selectedAssetRefs as $ref) {
            $this->assertArrayHasKey($ref->assetKey, $allowed, $ref->assetKey);
        }

        $assetKeys = array_map(
            static fn (BigFiveV2SelectedAssetRef $ref): string => $ref->assetKey,
            $result->selectedAssetRefs,
        );
        $this->assertSame($assetKeys, array_values(array_unique($assetKeys)));
        $this->assertSame([], $result->pendingSurfaces);
        $this->assertTrue($result->safetyDecisions['body_composition_allowed']);
        $this->assertFalse($result->safetyDecisions['consumer_side_body_fallback_allowed']);
        $this->assertSame('testing', $result->safetyDecisions['runtime_use']);
    }

    public function test_application_scenario_and_facet_each_have_one_stable_winner(): void
    {
        $result = (new BigFiveV2DeterministicSelector)->select($this->o59Input());
        $applicationSlots = [];
        $facetSlots = [];

        foreach ($result->selectedAssetRefs as $ref) {
            if ($ref->moduleKey === 'module_06_application_matrix') {
                $scenario = match (true) {
                    str_contains($ref->slotKey, '.work_') => 'workplace',
                    str_contains($ref->slotKey, '.relationship_') => 'relationships',
                    str_contains($ref->slotKey, '.stress_') => 'stress_recovery',
                    str_contains($ref->slotKey, '.action_') => 'personal_growth',
                    default => '',
                };
                $applicationSlots[] = $scenario;
            }
            if ($ref->moduleKey === 'module_05_facet_reframe') {
                $facetSlots[] = $ref->slotKey;
            }
        }

        $this->assertSame(['workplace', 'relationships', 'stress_recovery', 'personal_growth'], $applicationSlots);
        $this->assertSame($applicationSlots, array_values(array_unique($applicationSlots)));
        $this->assertSame([
            'module_05_facet_reframe.facet_card.c1.low',
            'module_05_facet_reframe.facet_card.n1.high',
        ], $facetSlots);
    }

    public function test_selected_refs_do_not_leak_registry_bodies_or_candidate_universe(): void
    {
        $input = $this->o59Input();
        $result = (new BigFiveV2DeterministicSelector)->select($input)->toArray();
        $encoded = json_encode($result['selected_asset_refs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
            $this->assertStringNotContainsString($forbiddenKeyOrTerm, $encoded, $forbiddenKeyOrTerm);
        }

        $this->assertLessThan(count($input->includeAssetKeys), count($result['selected_asset_refs']));
        $this->assertSame(count($result['selected_asset_refs']), data_get($result, 'selection_trace_internal.selected_asset_count'));
    }

    public function test_zero_required_core_assets_fails_closed(): void
    {
        $source = $this->o59Input();
        $input = new BigFiveV2SelectorInput(
            scaleCode: 'MBTI',
            formCode: $source->formCode,
            domainBands: $source->domainBands,
            domainScores: $source->domainScores,
            facetSignals: $source->facetSignals,
            qualityStatus: $source->qualityStatus,
            normStatus: $source->normStatus,
            readingMode: $source->readingMode,
            scenario: $source->scenario,
            routeRow: $source->routeRow,
            includeSlots: $source->includeSlots,
            includeRegistryKeys: $source->includeRegistryKeys,
            enableResolvedCouplingRefs: $source->enableResolvedCouplingRefs,
            includeAssetKeys: $source->includeAssetKeys,
            requiredSemanticSlots: $source->requiredSemanticSlots,
            domainPercentiles: $source->domainPercentiles,
            qualityFlags: $source->qualityFlags,
            attemptId: $source->attemptId,
            resultVersion: $source->resultVersion,
            normGroupId: $source->normGroupId,
            normVersion: $source->normVersion,
            percentileDisplayAllowed: $source->percentileDisplayAllowed,
            interpretationScope: $source->interpretationScope,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('required core asset selection failed');

        (new BigFiveV2DeterministicSelector)->select($input);
    }

    public function test_review_status_gate_allows_production_ready_and_requires_release_gate_for_drafts(): void
    {
        $selector = new BigFiveV2DeterministicSelector;
        $method = new ReflectionMethod($selector, 'reviewStatusAllowed');

        $this->assertTrue($method->invoke($selector, 'production_ready'));
        $this->assertTrue($method->invoke($selector, 'draft_for_psychometric_review'));

        app()->detectEnvironment(static fn (): string => 'production');
        try {
            config()->set('big5_result_page_v2.production_import_gate_passed', false);
            config()->set('big5_result_page_v2.production_release_snapshot_id', 'release-v0-4');
            config()->set('big5_result_page_v2.production_approved_release_snapshot_ids', ['release-v0-4']);
            $this->assertTrue($method->invoke($selector, 'production_ready'));
            $this->assertFalse($method->invoke($selector, 'draft_for_psychometric_review'));

            config()->set('big5_result_page_v2.production_import_gate_passed', true);
            $this->assertTrue($method->invoke($selector, 'draft_for_psychometric_review'));
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
        }
    }

    private function o59Input(): BigFiveV2SelectorInput
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter)->fromScoreResult([
            'scale_code' => 'BIG5_OCEAN',
            'scores_0_100' => [
                'domains_percentile' => ['O' => 59, 'C' => 32, 'E' => 20, 'A' => 55, 'N' => 68],
                'facets_percentile' => ['N1' => 82, 'C1' => 24],
            ],
            'quality' => ['level' => 'A'],
            'norms' => ['status' => 'CALIBRATED'],
        ]);
        $this->assertNotNull($routeInput);

        $parsed = (new BigFiveV2RouteMatrixParser)->parse();
        $this->assertSame([], $parsed->errors);
        $row = $parsed->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($row);

        return (new BigFiveV2RouteDrivenSelectorInputBuilder)->build(
            $routeInput,
            $row,
            attemptId: 'attempt-real-o59',
            resultVersion: 'engine-real-v7',
        );
    }
}
