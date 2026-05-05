<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2SelectorReferenceConsistencyTest extends TestCase
{
    private const REPORT_PATH = 'content_assets/big5/result_page_v2/qa/selector_reference_consistency/v0_1/selector_reference_consistency_report_v0_1.json';

    public function test_report_is_advisory_staging_only_and_not_runtime(): void
    {
        $report = $this->report();

        $this->assertSame('fap.big5.result_page_v2.selector_reference_consistency_report.v0_1', $report['schema'] ?? null);
        $this->assertSame('staging_only_advisory_scan', $report['mode'] ?? null);
        $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
        $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_pilot'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_production'] ?? true));
        $this->assertFalse((bool) data_get($report, 'interpretation.blocking_for_staging_preview'));
        $this->assertTrue((bool) data_get($report, 'interpretation.blocking_for_runtime_selector'));
    }

    public function test_report_counts_match_current_selector_and_imported_asset_references(): void
    {
        $report = $this->report();
        $computed = $this->computeConsistency();

        $this->assertSame(325, data_get($report, 'summary.selector_asset_count'));
        $this->assertSame(3125, data_get($report, 'summary.route_matrix_row_count'));
        $this->assertSame(25, data_get($report, 'summary.trait_band_reference_count'));
        $this->assertSame(12, data_get($report, 'summary.coupling_key_count'));
        $this->assertSame(30, data_get($report, 'summary.facet_key_count'));
        $this->assertSame(8, data_get($report, 'summary.canonical_profile_key_count'));
        $this->assertSame(5, data_get($report, 'summary.scenario_key_count'));

        $this->assertSame($computed['unresolved_by_check'], data_get($report, 'summary.unresolved_by_check'));
        $this->assertSame(array_sum($computed['unresolved_by_check']), data_get($report, 'summary.unresolved_reference_count'));
        $this->assertSame([
            'coupling_key' => 41,
            'profile_key' => 19,
            'scenario_key' => 32,
        ], data_get($report, 'summary.unresolved_by_type'));
    }

    public function test_pass_and_gap_statuses_are_current(): void
    {
        $checks = $this->checksByKey($this->report());

        $this->assertSame('pass', data_get($checks, 'selector_domain_registry_to_trait_band_assets.status'));
        $this->assertSame(0, data_get($checks, 'selector_domain_registry_to_trait_band_assets.unresolved_reference_count'));
        $this->assertSame('pass', data_get($checks, 'selector_facet_pattern_registry_to_facet_assets.status'));
        $this->assertSame(0, data_get($checks, 'selector_facet_pattern_registry_to_facet_assets.unresolved_reference_count'));
        $this->assertSame('pass', data_get($checks, 'route_matrix_to_imported_content_assets.status'));
        $this->assertSame(0, data_get($checks, 'route_matrix_to_imported_content_assets.unresolved_reference_count'));

        $this->assertSame('advisory_gap', data_get($checks, 'selector_coupling_registry_to_coupling_assets.status'));
        $this->assertSame(41, data_get($checks, 'selector_coupling_registry_to_coupling_assets.unresolved_reference_count'));
        $this->assertSame('advisory_gap', data_get($checks, 'selector_profile_signature_registry_to_canonical_profiles.status'));
        $this->assertSame(19, data_get($checks, 'selector_profile_signature_registry_to_canonical_profiles.unresolved_reference_count'));
        $this->assertSame('advisory_gap', data_get($checks, 'selector_scenario_registry_to_scenario_action_assets.status'));
        $this->assertSame(32, data_get($checks, 'selector_scenario_registry_to_scenario_action_assets.unresolved_reference_count'));
    }

    public function test_unresolved_references_are_reported_with_asset_keys(): void
    {
        $checks = $this->checksByKey($this->report());

        $this->assertContains([
            'asset_key' => 'asset.module_04_coupling.coupling_registry.o_c_high_high.v0_3',
            'reference_type' => 'coupling_key',
            'reference' => 'o_high_x_c_high',
        ], data_get($checks, 'selector_coupling_registry_to_coupling_assets.unresolved_references'));

        $this->assertContains([
            'asset_key' => 'asset.module_01_hero.profile_signature_registry.structured_stabilizer.v0_3',
            'reference_type' => 'profile_key',
            'reference' => 'structured_stabilizer',
        ], data_get($checks, 'selector_profile_signature_registry_to_canonical_profiles.unresolved_references'));

        $this->assertContains([
            'asset_key' => 'asset.module_06_application_matrix.scenario_registry.work_deep_processing_environment.v0_3',
            'reference_type' => 'scenario_key',
            'reference' => 'work',
        ], data_get($checks, 'selector_scenario_registry_to_scenario_action_assets.unresolved_references'));
    }

    /**
     * @return array<string,mixed>
     */
    private function computeConsistency(): array
    {
        $selectorAssets = $this->decodeJson('content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json');
        $traitAssets = data_get($this->decodeJson('content_assets/big5/result_page_v2/trait_band_assets/v0_1/big5_trait_band_assets_v0_1.json'), 'assets');
        $couplingAssets = data_get($this->decodeJson('content_assets/big5/result_page_v2/coupling_assets/v0_1/big5_coupling_assets_v0_1.json'), 'items');
        $facetAssets = data_get($this->decodeJson('content_assets/big5/result_page_v2/facet_assets/v0_1/big5_facet_assets_v0_1.json'), 'items');
        $profileMetadata = data_get($this->decodeJson('content_assets/big5/result_page_v2/canonical_profiles/v0_1/big5_canonical_profile_assets_v0_1.json'), 'profile_metadata');
        $scenarioPackage = $this->decodeJson('content_assets/big5/result_page_v2/scenario_action_assets/v0_1_1/big5_scenario_action_assets_v0_1.json');

        $this->assertIsArray($selectorAssets);
        $this->assertIsArray($traitAssets);
        $this->assertIsArray($couplingAssets);
        $this->assertIsArray($facetAssets);
        $this->assertIsArray($profileMetadata);

        $traitPairs = [];
        $traitRefs = [];
        foreach ($traitAssets as $asset) {
            $trait = (string) data_get($asset, 'trait.code');
            $band = (string) data_get($asset, 'band.internal_band');
            $traitPairs["{$trait}:{$band}"] = true;
            $traitRefs["{$trait}_{$band}"] = true;
        }

        $couplingKeys = array_fill_keys(array_map(static fn (array $asset): string => (string) $asset['coupling_key'], $couplingAssets), true);
        $facetKeys = array_fill_keys(array_map(static fn (array $asset): string => (string) $asset['facet_key'], $facetAssets), true);
        $profileKeys = array_fill_keys(array_map(static fn (array $profile): string => (string) $profile['profile_key'], $profileMetadata), true);
        $scenarioKeys = array_fill_keys(array_keys((array) ($scenarioPackage['scenarios'] ?? [])), true);

        $unresolvedByCheck = [
            'selector_domain_registry_to_trait_band_assets' => 0,
            'selector_coupling_registry_to_coupling_assets' => 0,
            'selector_facet_pattern_registry_to_facet_assets' => 0,
            'selector_profile_signature_registry_to_canonical_profiles' => 0,
            'selector_scenario_registry_to_scenario_action_assets' => 0,
            'route_matrix_to_imported_content_assets' => 0,
        ];

        foreach ($selectorAssets as $asset) {
            $registryKey = (string) ($asset['registry_key'] ?? '');
            if ($registryKey === 'domain_registry') {
                foreach ((array) data_get($asset, 'trigger.domain_bands', []) as $trait => $bands) {
                    foreach ((array) $bands as $band) {
                        if (! isset($traitPairs["{$trait}:{$band}"])) {
                            $unresolvedByCheck['selector_domain_registry_to_trait_band_assets']++;
                        }
                    }
                }
            }

            if ($registryKey === 'coupling_registry') {
                foreach ((array) data_get($asset, 'trigger.coupling_keys', []) as $couplingKey) {
                    if (! isset($couplingKeys[(string) $couplingKey])) {
                        $unresolvedByCheck['selector_coupling_registry_to_coupling_assets']++;
                    }
                }
            }

            if ($registryKey === 'facet_pattern_registry') {
                foreach ((array) data_get($asset, 'trigger.facet_patterns', []) as $pattern) {
                    if (! isset($facetKeys[(string) ($pattern['facet'] ?? '')])) {
                        $unresolvedByCheck['selector_facet_pattern_registry_to_facet_assets']++;
                    }
                }
            }

            if ($registryKey === 'profile_signature_registry') {
                $signatureKey = (string) data_get($asset, 'internal_metadata.selector_basis.signature_key');
                if ($signatureKey !== '' && ! isset($profileKeys[$signatureKey])) {
                    $unresolvedByCheck['selector_profile_signature_registry_to_canonical_profiles']++;
                }
            }

            if ($registryKey === 'scenario_registry') {
                foreach ((array) data_get($asset, 'trigger.scenario', []) as $scenarioKey) {
                    if (! isset($scenarioKeys[(string) $scenarioKey])) {
                        $unresolvedByCheck['selector_scenario_registry_to_scenario_action_assets']++;
                    }
                }
            }
        }

        foreach ($this->routeMatrixRows() as $row) {
            foreach (array_merge((array) ($row['primary_trait_band_assets'] ?? []), (array) ($row['secondary_trait_band_assets'] ?? [])) as $reference) {
                if (! isset($traitRefs[(string) $reference])) {
                    $unresolvedByCheck['route_matrix_to_imported_content_assets']++;
                }
            }
            foreach (array_merge((array) ($row['primary_coupling_assets'] ?? []), (array) ($row['secondary_coupling_assets'] ?? [])) as $reference) {
                if (! isset($couplingKeys[(string) $reference])) {
                    $unresolvedByCheck['route_matrix_to_imported_content_assets']++;
                }
            }
            if (! isset($profileKeys[(string) ($row['nearest_canonical_profile_key'] ?? '')])) {
                $unresolvedByCheck['route_matrix_to_imported_content_assets']++;
            }
            foreach ((array) ($row['scenario_priorities'] ?? []) as $reference) {
                if (! isset($scenarioKeys[(string) $reference])) {
                    $unresolvedByCheck['route_matrix_to_imported_content_assets']++;
                }
            }
        }

        return [
            'unresolved_by_check' => $unresolvedByCheck,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function routeMatrixRows(): array
    {
        $rows = [];
        $files = glob(base_path('content_assets/big5/result_page_v2/route_matrix/v0_1_1/big5_3125_route_matrix_O*_v0_1_1.jsonl'));
        $this->assertIsArray($files);
        sort($files);

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($lines);
            foreach ($lines as $line) {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                $rows[] = $decoded;
            }
        }

        $this->assertCount(3125, $rows);

        return $rows;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function checksByKey(array $report): array
    {
        $checks = [];
        foreach ((array) ($report['checks'] ?? []) as $check) {
            $checks[(string) ($check['check_key'] ?? '')] = $check;
        }

        return $checks;
    }

    /**
     * @return array<string,mixed>
     */
    private function report(): array
    {
        return $this->decodeJson(self::REPORT_PATH);
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
