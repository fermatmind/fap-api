<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2ContentAssetLookup;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteDrivenSelectorInputBuilder;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use RuntimeException;
use Tests\TestCase;

final class BigFiveResultPageV2ContentAssetLookupTest extends TestCase
{
    public function test_lookup_resolves_route_driven_o59_refs_across_repo_owned_content_registries(): void
    {
        $input = $this->o59RouteDrivenInput();
        $selection = (new BigFiveV2DeterministicSelector())->select($input);
        $lookup = new BigFiveV2ContentAssetLookup();

        $domain = $lookup->resolve($this->selectedRef($selection->selectedAssetRefs, 'domain_registry'), $input);
        $this->assertSame('B5-CONTENT-1', $domain->sourcePackage);
        $this->assertSame('domain_band', $domain->assetType);
        $this->assertArrayNotHasKey('runtime_use', $domain->publicContent);

        $profile = $lookup->resolve($this->selectedRef($selection->selectedAssetRefs, 'profile_signature_registry'), $input);
        $this->assertSame('B5-CONTENT-4', $profile->sourcePackage);
        $this->assertSame('canonical_profile_section', $profile->assetType);
        $this->assertSame('sensitive_independent_thinker', $profile->publicContent['profile_key'] ?? null);

        $facet = $lookup->resolve($this->selectedRef($selection->selectedAssetRefs, 'facet_pattern_registry'), $input);
        $this->assertSame('B5-CONTENT-3', $facet->sourcePackage);
        $this->assertStringStartsWith('facet_', (string) $facet->assetType);

        $scenario = $lookup->resolve($this->selectedRef($selection->selectedAssetRefs, 'scenario_registry'), $input);
        $this->assertSame('B5-CONTENT-5', $scenario->sourcePackage);
        $this->assertSame('scenario_action', $scenario->assetType);
        $this->assertSame('sensitive_independent_thinker', $scenario->publicContent['profile_key'] ?? null);
    }

    public function test_lookup_resolves_canonical_alias_and_supplemental_coupling_refs(): void
    {
        $input = $this->o59RouteDrivenInput();
        $selection = (new BigFiveV2DeterministicSelector())->select($input);
        $lookup = new BigFiveV2ContentAssetLookup();

        $canonical = $lookup->resolve(
            $this->selectedRef($selection->selectedAssetRefs, 'coupling_registry', 'module_04_coupling.coupling_card.e_n.low_high'),
            $input,
        );
        $this->assertSame('B5-CONTENT-2', $canonical->sourcePackage);
        $this->assertSame('n_high_x_e_low', $canonical->publicContent['coupling_key'] ?? null);
        $this->assertSame('approved_alias', data_get($canonical->metadata, 'coupling_resolution.decision_type'));

        $supplemental = $lookup->resolve(new BigFiveV2SelectedAssetRef(
            assetKey: 'asset.module_04_coupling.coupling_registry.a_n_high_high.v0_3',
            registryKey: 'coupling_registry',
            moduleKey: 'module_04_coupling',
            blockKey: 'module_04_coupling.coupling_registry.a_n_high_high.v0_3',
            slotKey: 'module_04_coupling.coupling_card.a_n.high_high',
            priority: 91,
            contentSource: 'gpt_generated_selector_ready_p0_full_v0_3',
        ), $input);
        $this->assertSame('B5-CONTENT-2B', $supplemental->sourcePackage);
        $this->assertSame('a_high_x_n_high', $supplemental->publicContent['coupling_key'] ?? null);
        $this->assertSame('supplemental_exact', data_get($supplemental->metadata, 'coupling_resolution.decision_type'));
    }

    public function test_lookup_filters_internal_metadata_and_production_flags_from_public_content(): void
    {
        $input = $this->o59RouteDrivenInput();
        $selection = (new BigFiveV2DeterministicSelector())->select($input);
        $lookup = new BigFiveV2ContentAssetLookup();
        $resolved = $lookup->resolve($this->selectedRef($selection->selectedAssetRefs, 'coupling_registry'), $input);

        $encoded = json_encode($resolved->publicContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ([
            'source_reference',
            'selector_basis',
            'qa_notes',
            'editor_notes',
            'internal_metadata',
            'review_status',
            'production_use_allowed',
            'ready_for_pilot',
            'ready_for_runtime',
            'ready_for_production',
            'frontend_fallback',
            'source_trace',
            'repair_log_refs',
        ] as $forbiddenPublicTerm) {
            $this->assertStringNotContainsString($forbiddenPublicTerm, $encoded, $forbiddenPublicTerm);
        }
    }

    public function test_lookup_fails_closed_for_missing_selected_ref(): void
    {
        $input = $this->o59RouteDrivenInput();
        $lookup = new BigFiveV2ContentAssetLookup();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('selector asset is missing');

        $lookup->resolve(new BigFiveV2SelectedAssetRef(
            assetKey: 'asset.missing.v0_0',
            registryKey: 'domain_registry',
            moduleKey: 'module_03_trait_deep_dive',
            blockKey: 'module_03_trait_deep_dive.domain_registry.missing.v0_0',
            slotKey: 'module_03_trait_deep_dive.domain_card.O.mid',
            priority: 1,
            contentSource: 'missing',
        ), $input);
    }

    /**
     * @param  list<BigFiveV2SelectedAssetRef>  $refs
     */
    private function selectedRef(array $refs, string $registryKey, ?string $slotKey = null): BigFiveV2SelectedAssetRef
    {
        foreach ($refs as $ref) {
            if ($ref->registryKey === $registryKey && ($slotKey === null || $ref->slotKey === $slotKey)) {
                return $ref;
            }
        }

        $this->fail("Selected ref not found for {$registryKey}".($slotKey === null ? '' : " / {$slotKey}"));
    }

    private function o59RouteDrivenInput(): BigFiveV2SelectorInput
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

        $parseResult = (new BigFiveV2RouteMatrixParser())->parse();
        $this->assertSame([], $parseResult->errors);
        $routeRow = $parseResult->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        $this->assertNotNull($routeRow);

        return (new BigFiveV2RouteDrivenSelectorInputBuilder())->build($routeInput, $routeRow);
    }
}
