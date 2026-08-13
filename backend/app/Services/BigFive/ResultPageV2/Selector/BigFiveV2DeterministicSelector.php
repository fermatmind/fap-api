<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Selector;

use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2AssetPackageLoader;
use RuntimeException;

final class BigFiveV2DeterministicSelector
{
    private const SELECTOR_ASSETS_PATH = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json';

    private const REFERENCE_REPORT_PATH = 'content_assets/big5/result_page_v2/qa/selector_reference_consistency/v0_1/selector_reference_consistency_report_v0_1.json';

    public function __construct(
        private readonly BigFiveV2AssetPackageLoader $packageLoader = new BigFiveV2AssetPackageLoader,
        private readonly BigFiveV2CouplingResolver $couplingResolver = new BigFiveV2CouplingResolver,
    ) {}

    public function select(BigFiveV2SelectorInput $input): BigFiveV2SelectionResult
    {
        $inventory = $this->packageLoader->inventory();
        if (! $inventory->isValid()) {
            throw new RuntimeException('Big Five V2 staging asset inventory is not validator-clean.');
        }

        $assets = $this->selectorAssets();
        $unresolvedSuppressions = $this->unresolvedReferenceSuppressions($input->enableResolvedCouplingRefs);
        $unresolvedAssetKeys = array_fill_keys(array_map(
            static fn (array $suppression): string => (string) $suppression['asset_key'],
            $unresolvedSuppressions,
        ), true);

        $candidatesBySemanticSlot = [];
        $suppressed = [];
        $desiredSlots = array_fill_keys($input->includeSlots, true);
        $desiredRegistries = array_fill_keys($input->includeRegistryKeys, true);
        $desiredAssetKeys = array_fill_keys($input->includeAssetKeys, true);
        $desiredAssetOrder = array_flip($input->includeAssetKeys);

        foreach ($assets as $asset) {
            $assetKey = (string) ($asset['asset_key'] ?? '');
            if ($assetKey === '') {
                continue;
            }

            if (isset($unresolvedAssetKeys[$assetKey])) {
                $suppressed[] = $this->suppression($asset, 'unresolved_asset_reference');

                continue;
            }

            if ($desiredAssetKeys !== [] && ! isset($desiredAssetKeys[$assetKey])) {
                continue;
            }

            if (! $this->matchesRequestedSlot($asset, $desiredSlots)) {
                continue;
            }

            if (! $this->matchesRequestedRegistry($asset, $desiredRegistries)) {
                continue;
            }

            if (! $this->matchesBasicSafety($asset, $input) || ! $this->matchesRuntimeContext($asset, $input)) {
                continue;
            }

            $semanticSlot = $this->semanticSlot($asset);
            $candidatesBySemanticSlot[$semanticSlot][] = $asset;
        }

        $selectedWithOrder = [];
        foreach ($candidatesBySemanticSlot as $semanticSlot => $candidates) {
            usort($candidates, fn (array $left, array $right): int => [
                -$this->specificity($left),
                -(int) ($left['priority'] ?? 0),
                (string) ($left['asset_key'] ?? ''),
            ] <=> [
                -$this->specificity($right),
                -(int) ($right['priority'] ?? 0),
                (string) ($right['asset_key'] ?? ''),
            ]);
            $winner = $candidates[0];
            $winnerKey = (string) ($winner['asset_key'] ?? '');
            $selectedWithOrder[] = [
                'order' => $desiredAssetOrder[$winnerKey] ?? PHP_INT_MAX,
                'semantic_slot' => $semanticSlot,
                'ref' => new BigFiveV2SelectedAssetRef(
                    assetKey: $winnerKey,
                    registryKey: (string) ($winner['registry_key'] ?? ''),
                    moduleKey: (string) ($winner['module_key'] ?? ''),
                    blockKey: (string) ($winner['block_key'] ?? ''),
                    slotKey: (string) ($winner['slot_key'] ?? ''),
                    priority: (int) ($winner['priority'] ?? 0),
                    contentSource: (string) ($winner['content_source'] ?? ''),
                ),
            ];
        }

        usort($selectedWithOrder, static function (array $left, array $right): int {
            /** @var BigFiveV2SelectedAssetRef $leftRef */
            $leftRef = $left['ref'];
            /** @var BigFiveV2SelectedAssetRef $rightRef */
            $rightRef = $right['ref'];

            return [$left['order'], $leftRef->moduleKey, $left['semantic_slot'], $leftRef->assetKey]
                <=> [$right['order'], $rightRef->moduleKey, $right['semantic_slot'], $rightRef->assetKey];
        });
        $selected = array_map(static fn (array $winner): BigFiveV2SelectedAssetRef => $winner['ref'], $selectedWithOrder);

        $selectedSemanticSlots = array_fill_keys(array_map(
            static fn (array $winner): string => (string) $winner['semantic_slot'],
            $selectedWithOrder,
        ), true);
        $missingRequired = array_values(array_filter(
            $input->requiredSemanticSlots,
            static fn (string $slot): bool => ! isset($selectedSemanticSlots[$slot]),
        ));
        if ($missingRequired !== []) {
            throw new RuntimeException('Big Five V2 required core asset selection failed: '.implode(', ', $missingRequired));
        }

        $productionAllowed = (string) app()->environment() === 'production';

        return new BigFiveV2SelectionResult(
            selectedAssetRefs: $selected,
            suppressedAssetRefs: $suppressed,
            unresolvedRefSuppressions: $unresolvedSuppressions,
            pendingSurfaces: [],
            safetyDecisions: [
                'scale_code' => $input->scaleCode,
                'form_code' => $input->formCode,
                'runtime_use' => (string) app()->environment(),
                'production_use_allowed' => $productionAllowed,
                'consumer_side_body_fallback_allowed' => false,
                'unresolved_refs_selectable' => false,
                'body_composition_allowed' => true,
                'selection_complete' => true,
            ],
            selectionTraceInternal: [
                'route_combination_key' => $input->routeRow->combinationKey,
                'route_profile_key' => $input->routeRow->profileKey,
                'route_interpretation_scope' => $input->routeRow->interpretationScope,
                'selector_asset_count' => count($assets),
                'selected_asset_count' => count($selected),
                'suppressed_unresolved_asset_count' => count($suppressed),
                'requested_slot_count' => count($input->includeSlots),
                'requested_registry_count' => count($input->includeRegistryKeys),
                'requested_asset_count' => count($input->includeAssetKeys),
                'semantic_slot_count' => count($candidatesBySemanticSlot),
            ],
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function selectorAssets(): array
    {
        $decoded = $this->decodeJsonFile(base_path(self::SELECTOR_ASSETS_PATH));
        if (! array_is_list($decoded)) {
            throw new RuntimeException('Big Five V2 selector assets must be a JSON list.');
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function unresolvedReferenceSuppressions(bool $enableResolvedCouplingRefs): array
    {
        $report = $this->decodeJsonFile(base_path(self::REFERENCE_REPORT_PATH));
        $suppressionsByAssetKey = [];

        foreach ((array) ($report['checks'] ?? []) as $check) {
            foreach ((array) ($check['unresolved_references'] ?? []) as $reference) {
                if (! is_array($reference)) {
                    continue;
                }

                $assetKey = (string) ($reference['asset_key'] ?? '');
                if ($assetKey === '') {
                    continue;
                }

                $referenceType = (string) ($reference['reference_type'] ?? '');
                $referenceValue = (string) ($reference['reference'] ?? '');
                if ($enableResolvedCouplingRefs && in_array($referenceType, ['profile_key', 'scenario_key'], true)) {
                    continue;
                }
                if ($enableResolvedCouplingRefs && $referenceType === 'coupling_key') {
                    $resolution = $this->couplingResolver->resolve($referenceValue, 'result_page');
                    if ($resolution->selectable) {
                        continue;
                    }
                }

                $suppressionsByAssetKey[$assetKey] ??= [
                    'asset_key' => $assetKey,
                    'reason' => 'unresolved_selector_reference',
                    'references' => [],
                ];
                $suppressionsByAssetKey[$assetKey]['references'][] = [
                    'reference_type' => $referenceType,
                    'reference' => $referenceValue,
                ];
            }
        }

        ksort($suppressionsByAssetKey);

        return array_values($suppressionsByAssetKey);
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  array<string,bool>  $desiredSlots
     */
    private function matchesRequestedSlot(array $asset, array $desiredSlots): bool
    {
        return $desiredSlots === [] || isset($desiredSlots[(string) ($asset['slot_key'] ?? '')]);
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  array<string,bool>  $desiredRegistries
     */
    private function matchesRequestedRegistry(array $asset, array $desiredRegistries): bool
    {
        return $desiredRegistries === [] || isset($desiredRegistries[(string) ($asset['registry_key'] ?? '')]);
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function matchesBasicSafety(array $asset, BigFiveV2SelectorInput $input): bool
    {
        if ($input->scaleCode !== 'BIG5_OCEAN') {
            return false;
        }

        if (! in_array($input->readingMode, ['quick', 'standard', 'deep'], true)) {
            return false;
        }

        return $this->reviewStatusAllowed((string) ($asset['review_status'] ?? ''));
    }

    private function reviewStatusAllowed(string $status): bool
    {
        if ($status === 'production_ready') {
            return true;
        }

        if ($status !== 'draft_for_psychometric_review') {
            return false;
        }

        if ((string) app()->environment() !== 'production') {
            return true;
        }

        $snapshotId = trim((string) config('big5_result_page_v2.production_release_snapshot_id', ''));
        $approved = config('big5_result_page_v2.production_approved_release_snapshot_ids', []);
        if (is_string($approved)) {
            $approved = explode(',', $approved);
        }

        return (bool) config('big5_result_page_v2.production_import_gate_passed', false)
            && $snapshotId !== ''
            && is_array($approved)
            && in_array($snapshotId, array_map(static fn (mixed $value): string => trim((string) $value), $approved), true);
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function matchesRuntimeContext(array $asset, BigFiveV2SelectorInput $input): bool
    {
        $trigger = (array) ($asset['trigger'] ?? []);
        foreach ((array) ($trigger['domain_bands'] ?? []) as $trait => $allowedBands) {
            if (! in_array($input->domainBands[(string) $trait] ?? '', (array) $allowedBands, true)) {
                return false;
            }
        }

        $readingModes = (array) ($asset['reading_modes'] ?? $trigger['reading_mode'] ?? []);
        if ($readingModes !== [] && ! in_array($input->readingMode, $readingModes, true)) {
            return false;
        }

        $scopes = (array) ($trigger['interpretation_scope'] ?? []);
        $scope = $input->interpretationScope !== '' ? $input->interpretationScope : $input->routeRow->interpretationScope;
        if ($scopes !== [] && ! in_array($scope, $scopes, true)) {
            return false;
        }

        $qualityStatuses = (array) ($trigger['quality_status'] ?? []);
        if ($qualityStatuses !== [] && ! in_array($input->qualityStatus, $qualityStatuses, true)) {
            return false;
        }

        $normStatuses = (array) ($trigger['norm_status'] ?? []);
        $normStatus = $input->normStatus === 'missing' ? 'unavailable' : $input->normStatus;
        if ($normStatuses !== [] && ! in_array($normStatus, $normStatuses, true)) {
            return false;
        }

        $formCodes = (array) data_get($asset, 'scope.form_codes', []);
        if ($formCodes !== [] && ! in_array($input->formCode, $formCodes, true)) {
            return false;
        }

        if (! $this->matchesFacetSignals($trigger, $input->facetSignals)) {
            return false;
        }

        if (! $this->matchesCouplingRoute($trigger, $input)) {
            return false;
        }

        if (! $this->matchesScenarioRoute($trigger, $input)) {
            return false;
        }

        if (($asset['registry_key'] ?? null) === 'profile_signature_registry') {
            $encoded = json_encode($asset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! str_contains($encoded, $input->routeRow->profileKey)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $trigger */
    private function matchesScenarioRoute(array $trigger, BigFiveV2SelectorInput $input): bool
    {
        $assetScenarios = array_values(array_filter(array_map('strval', (array) ($trigger['scenario'] ?? []))));
        if ($assetScenarios === []) {
            return true;
        }

        $routeScenarios = $input->scenario !== null && trim($input->scenario) !== ''
            ? [trim($input->scenario)]
            : (array) data_get($input->routeRow->toArray(), 'scenario_priorities', []);
        $aliases = [];
        foreach ($routeScenarios as $scenario) {
            foreach (match ((string) $scenario) {
                'workplace' => ['work'],
                'relationships' => ['relationship'],
                'stress_recovery' => ['stress'],
                'personal_growth' => ['action'],
                default => [(string) $scenario],
            } as $alias) {
                $aliases[$alias] = true;
            }
        }

        foreach ($assetScenarios as $assetScenario) {
            if (isset($aliases[$assetScenario])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $trigger
     * @param  list<array<string,mixed>>  $signals
     */
    private function matchesFacetSignals(array $trigger, array $signals): bool
    {
        $patterns = (array) ($trigger['facet_patterns'] ?? []);
        if ($patterns === []) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (! is_array($pattern)) {
                continue;
            }
            $facet = strtoupper((string) ($pattern['facet'] ?? ''));
            foreach ($signals as $signal) {
                if (strtoupper((string) ($signal['key'] ?? $signal['facet'] ?? '')) !== $facet) {
                    continue;
                }
                $band = $this->facetBand($signal);
                if ($band !== null && in_array($band, (array) ($pattern['band'] ?? []), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $signal */
    private function facetBand(array $signal): ?string
    {
        $bucket = strtolower(trim((string) ($signal['bucket'] ?? '')));
        if (in_array($bucket, ['very_low', 'low', 'mid', 'high', 'very_high'], true)) {
            return $bucket;
        }
        if (! is_numeric($signal['percentile'] ?? null)) {
            return null;
        }
        $value = (int) $signal['percentile'];

        return match (true) {
            $value < 20 => 'very_low',
            $value < 40 => 'low',
            $value < 60 => 'mid',
            $value < 80 => 'high',
            default => 'very_high',
        };
    }

    /** @param array<string,mixed> $trigger */
    private function matchesCouplingRoute(array $trigger, BigFiveV2SelectorInput $input): bool
    {
        $keys = (array) ($trigger['coupling_keys'] ?? []);
        if ($keys === []) {
            return true;
        }
        $routeKeys = array_fill_keys(array_map('strval', (array) data_get($input->routeRow->toArray(), 'primary_coupling_assets', [])), true);
        foreach ($keys as $key) {
            $resolution = $this->couplingResolver->resolve((string) $key, 'result_page');
            if ($resolution->selectable && isset($routeKeys[$resolution->resolvedKey ?? (string) $key])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $asset */
    private function semanticSlot(array $asset): string
    {
        $module = (string) ($asset['module_key'] ?? '');
        $registry = (string) ($asset['registry_key'] ?? '');
        $trigger = (array) ($asset['trigger'] ?? []);

        if ($registry === 'domain_registry') {
            $trait = (string) array_key_first((array) ($trigger['domain_bands'] ?? []));

            return "{$module}:domain:{$trait}";
        }
        if ($registry === 'profile_signature_registry') {
            return "{$module}:profile";
        }
        if ($registry === 'coupling_registry') {
            $key = (string) (((array) ($trigger['coupling_keys'] ?? []))[0] ?? '');
            $resolution = $this->couplingResolver->resolve($key, 'result_page');

            return "{$module}:coupling:".($resolution->resolvedKey ?? $key);
        }
        if ($registry === 'facet_pattern_registry') {
            $pattern = (array) (((array) ($trigger['facet_patterns'] ?? []))[0] ?? []);

            return "{$module}:facet:".strtoupper((string) ($pattern['facet'] ?? ''));
        }
        if (in_array($registry, ['scenario_registry', 'action_plan_registry'], true)) {
            return "{$module}:scenario:".(string) (((array) ($trigger['scenario'] ?? []))[0] ?? '');
        }

        $mutualExclusionGroup = trim((string) ($asset['mutual_exclusion_group'] ?? ''));

        return $mutualExclusionGroup !== '' ? "{$module}:{$mutualExclusionGroup}" : "{$module}:".(string) ($asset['slot_key'] ?? '');
    }

    /** @param array<string,mixed> $asset */
    private function specificity(array $asset): int
    {
        $trigger = (array) ($asset['trigger'] ?? []);
        $specificity = 0;
        foreach ($trigger as $value) {
            if (is_array($value) && $value !== []) {
                $specificity += array_is_list($value) ? 1 : count($value);
            } elseif ($value !== null && $value !== '') {
                $specificity++;
            }
        }

        return $specificity;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function suppression(array $asset, string $reason): array
    {
        return [
            'asset_key' => (string) ($asset['asset_key'] ?? ''),
            'registry_key' => (string) ($asset['registry_key'] ?? ''),
            'slot_key' => (string) ($asset['slot_key'] ?? ''),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if (! is_string($json)) {
            throw new RuntimeException("Big Five V2 selector input is unreadable: {$path}");
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Big Five V2 selector input is not a JSON object or list: {$path}");
        }

        return $decoded;
    }
}
