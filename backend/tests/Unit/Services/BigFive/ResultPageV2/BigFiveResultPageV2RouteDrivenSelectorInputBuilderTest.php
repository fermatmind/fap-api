<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixRow;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteDrivenSelectorInputBuilder;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteInput;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use Tests\TestCase;

final class BigFiveResultPageV2RouteDrivenSelectorInputBuilderTest extends TestCase
{
    public function test_o59_route_row_builds_selector_input_without_changing_selector_semantics(): void
    {
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $this->o59RouteRow());

        $this->assertSame('BIG5_OCEAN', $input->scaleCode);
        $this->assertSame('big5_120', $input->formCode);
        $this->assertSame('standard', $input->readingMode);
        $this->assertTrue($input->enableResolvedCouplingRefs);
        $this->assertSame('valid', $input->qualityStatus);
        $this->assertSame('available', $input->normStatus);
        $this->assertSame(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY, $input->routeRow->combinationKey);

        $this->assertSame([
            'O' => 'mid',
            'C' => 'low',
            'E' => 'low',
            'A' => 'mid',
            'N' => 'high',
        ], $input->domainBands);
        $this->assertSame([
            'O' => 3,
            'C' => 2,
            'E' => 2,
            'A' => 3,
            'N' => 4,
        ], $input->domainScores);

        foreach ([
            'profile_signature_registry',
            'domain_registry',
            'coupling_registry',
            'facet_pattern_registry',
            'scenario_registry',
            'action_plan_registry',
        ] as $registryKey) {
            $this->assertContains($registryKey, $input->includeRegistryKeys, $registryKey);
        }
    }

    public function test_o59_route_trait_assets_translate_to_domain_selector_slots(): void
    {
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $this->o59RouteRow());

        foreach ([
            'module_01_hero.domain_signal.O.mid',
            'module_03_trait_deep_dive.domain_card.O.mid',
            'module_01_hero.domain_signal.C.low',
            'module_03_trait_deep_dive.domain_card.C.low',
            'module_01_hero.domain_signal.E.low',
            'module_03_trait_deep_dive.domain_card.E.low',
            'module_01_hero.domain_signal.A.mid',
            'module_03_trait_deep_dive.domain_card.A.mid',
            'module_01_hero.domain_signal.N.high',
            'module_03_trait_deep_dive.domain_card.N.high',
        ] as $slotKey) {
            $this->assertContains($slotKey, $input->includeSlots, $slotKey);
        }
    }

    public function test_o59_route_coupling_candidates_translate_through_canonical_and_alias_slots(): void
    {
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $this->o59RouteRow());

        $this->assertSame([
            'n_high_x_o_mid_high',
            'n_high_x_e_low',
            'e_low_x_c_low',
            'c_low_x_n_high',
            'a_mid_x_n_high',
        ], data_get($input->routeRow->toArray(), 'primary_coupling_assets'));

        foreach ([
            'module_04_coupling.coupling_card.o_n.mid_high',
            'module_04_coupling.coupling_card.e_n.low_high',
            'module_04_coupling.coupling_card.c_e.low_low',
            'module_04_coupling.coupling_card.c_n.low_high',
            'module_04_coupling.coupling_card.a_n.mid_high',
        ] as $slotKey) {
            $this->assertContains($slotKey, $input->includeSlots, $slotKey);
        }
    }

    public function test_profile_label_is_assistive_and_only_used_for_high_confidence_profile_rows(): void
    {
        $builder = new BigFiveV2RouteDrivenSelectorInputBuilder();

        $highConfidence = $builder->build($this->o59RouteInput(), $this->o59RouteRow());
        $this->assertContains('module_01_hero.primary_signature.sensitive_independent_thinker', $highConfidence->includeSlots);

        $lowConfidenceRow = $this->routeRowWith([
            'profile_match_confidence' => 'low',
        ]);
        $lowConfidence = $builder->build($this->o59RouteInput(), $lowConfidenceRow);

        $this->assertNotContains('module_01_hero.primary_signature.sensitive_independent_thinker', $lowConfidence->includeSlots);
    }

    public function test_must_suppress_assets_wins_over_route_recommended_assets(): void
    {
        $row = $this->routeRowWith([
            'must_suppress_assets' => [
                'coupling:a_mid_x_n_high',
                'module_04_coupling.coupling_card.a_n.mid_high',
                'share_card',
                'pdf',
                'history',
                'compare',
            ],
        ]);

        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $row);

        $this->assertNotContains('module_04_coupling.coupling_card.a_n.mid_high', $input->includeSlots);
        $this->assertContains('module_04_coupling.coupling_card.c_n.low_high', $input->includeSlots);
        $this->assertNotContains('share_safety_registry', $input->includeRegistryKeys);
    }

    public function test_facets_are_only_added_when_route_input_carries_matching_facet_signals(): void
    {
        $builder = new BigFiveV2RouteDrivenSelectorInputBuilder();
        $withoutFacets = $builder->build(new BigFiveV2RouteInput(
            domainRouteBands: ['O' => 3, 'C' => 2, 'E' => 2, 'A' => 3, 'N' => 4],
            combinationKey: BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY,
            displayBandLabels: [],
            qualityStatus: 'valid',
            normStatus: 'available',
        ), $this->o59RouteRow());

        $this->assertNotContains('facet_pattern_registry', $withoutFacets->includeRegistryKeys);

        $withFacets = $builder->build($this->o59RouteInput(), $this->o59RouteRow());
        $this->assertContains('facet_pattern_registry', $withFacets->includeRegistryKeys);
    }

    public function test_route_driven_input_can_be_passed_to_deterministic_selector_as_refs_only_contract(): void
    {
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $this->o59RouteRow());
        $result = (new BigFiveV2DeterministicSelector())->select($input);

        $this->assertNotSame([], $result->selectedAssetRefs);
        $this->assertContains('pdf', $result->pendingSurfaces);
        $this->assertContains('share_card', $result->pendingSurfaces);
        $this->assertContains('history', $result->pendingSurfaces);
        $this->assertContains('compare', $result->pendingSurfaces);

        $selectedRegistries = array_values(array_unique(array_map(
            static fn (BigFiveV2SelectedAssetRef $ref): string => $ref->registryKey,
            $result->selectedAssetRefs,
        )));

        $this->assertContains('profile_signature_registry', $selectedRegistries);
        $this->assertContains('domain_registry', $selectedRegistries);
        $this->assertContains('coupling_registry', $selectedRegistries);
        $this->assertNotContains('share_safety_registry', $selectedRegistries);

        $encodedSelectedRefs = json_encode(
            array_map(static fn (BigFiveV2SelectedAssetRef $ref): array => $ref->toArray(), $result->selectedAssetRefs),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        foreach ([
            'body_zh',
            'summary_zh',
            'public_payload',
            'internal_metadata',
            'source_reference',
            'selector_basis',
            'frontend_fallback',
            'fixed_type_language',
            '[object Object]',
        ] as $forbiddenTerm) {
            $this->assertStringNotContainsString($forbiddenTerm, $encodedSelectedRefs, $forbiddenTerm);
        }

        $this->assertFalse($result->safetyDecisions['body_composition_allowed']);
        $this->assertFalse($result->safetyDecisions['consumer_side_body_fallback_allowed']);
        $this->assertFalse($result->safetyDecisions['production_use_allowed']);
    }

    public function test_route_driven_selector_selects_resolved_o59_coupling_slots(): void
    {
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $this->o59RouteRow());
        $result = (new BigFiveV2DeterministicSelector())->select($input);
        $selectedSlots = array_map(
            static fn (BigFiveV2SelectedAssetRef $ref): string => $ref->slotKey,
            $result->selectedAssetRefs,
        );

        foreach ([
            'module_04_coupling.coupling_card.o_n.mid_high',
            'module_04_coupling.coupling_card.e_n.low_high',
            'module_04_coupling.coupling_card.c_e.low_low',
            'module_04_coupling.coupling_card.c_n.low_high',
            'module_04_coupling.coupling_card.a_n.mid_high',
        ] as $slotKey) {
            $this->assertContains($slotKey, $selectedSlots, $slotKey);
        }

        foreach ($result->unresolvedRefSuppressions as $suppression) {
            foreach ((array) ($suppression['references'] ?? []) as $reference) {
                $this->assertNotSame('coupling_key', $reference['reference_type'] ?? null);
            }
        }
    }

    public function test_unresolved_profile_and_scenario_refs_remain_suppressed_for_route_driven_selection(): void
    {
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $this->o59RouteRow());
        $result = (new BigFiveV2DeterministicSelector())->select($input);

        $suppressedTypes = [];
        $suppressedAssetKeys = [];
        foreach ($result->unresolvedRefSuppressions as $suppression) {
            $suppressedAssetKeys[(string) ($suppression['asset_key'] ?? '')] = true;
            foreach ((array) ($suppression['references'] ?? []) as $reference) {
                $suppressedTypes[(string) ($reference['reference_type'] ?? '')] = true;
            }
        }
        unset($suppressedAssetKeys[''], $suppressedTypes['']);

        $this->assertArrayHasKey('profile_key', $suppressedTypes);
        $this->assertArrayHasKey('scenario_key', $suppressedTypes);
        $this->assertArrayNotHasKey('coupling_key', $suppressedTypes);

        foreach ($result->selectedAssetRefs as $ref) {
            $this->assertArrayNotHasKey($ref->assetKey, $suppressedAssetKeys, $ref->assetKey);
        }
    }

    public function test_unknown_route_requested_slots_do_not_enter_selected_refs(): void
    {
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($this->o59RouteInput(), $this->o59RouteRow());
        $inputWithUnknownSlot = new BigFiveV2SelectorInput(
            scaleCode: $input->scaleCode,
            formCode: $input->formCode,
            domainBands: $input->domainBands,
            domainScores: $input->domainScores,
            facetSignals: $input->facetSignals,
            qualityStatus: $input->qualityStatus,
            normStatus: $input->normStatus,
            readingMode: $input->readingMode,
            scenario: $input->scenario,
            routeRow: $input->routeRow,
            includeSlots: [
                ...$input->includeSlots,
                'module_04_coupling.coupling_card.unknown_unsafe.high_low',
            ],
            includeRegistryKeys: $input->includeRegistryKeys,
            enableResolvedCouplingRefs: $input->enableResolvedCouplingRefs,
        );

        $result = (new BigFiveV2DeterministicSelector())->select($inputWithUnknownSlot);

        foreach ($result->selectedAssetRefs as $ref) {
            $this->assertNotSame('module_04_coupling.coupling_card.unknown_unsafe.high_low', $ref->slotKey);
        }
    }

    private function o59RouteInput(): BigFiveV2RouteInput
    {
        $routeInput = (new BigFiveV2ProjectionRouteInputAdapter())->fromScoreResult([
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
            'quality' => ['level' => 'A'],
            'norms' => ['status' => 'CALIBRATED'],
        ]);

        $this->assertNotNull($routeInput);

        return $routeInput;
    }

    private function o59RouteRow(): BigFiveV2RouteMatrixRow
    {
        $result = (new BigFiveV2RouteMatrixParser())->parse();
        $this->assertSame([], $result->errors);

        $row = $result->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function routeRowWith(array $overrides): BigFiveV2RouteMatrixRow
    {
        $row = $this->o59RouteRow();
        $data = array_replace_recursive($row->toArray(), $overrides);

        return new BigFiveV2RouteMatrixRow(
            combinationKey: $row->combinationKey,
            profileFamily: $row->profileFamily,
            profileKey: $row->profileKey,
            interpretationScope: $row->interpretationScope,
            data: $data,
        );
    }
}
