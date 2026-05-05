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
        private readonly BigFiveV2AssetPackageLoader $packageLoader = new BigFiveV2AssetPackageLoader(),
        private readonly BigFiveV2CouplingResolver $couplingResolver = new BigFiveV2CouplingResolver(),
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

        $selected = [];
        $suppressed = [];
        $desiredSlots = array_fill_keys($input->includeSlots, true);
        $desiredSlotOrder = array_flip($input->includeSlots);
        $desiredRegistries = array_fill_keys($input->includeRegistryKeys, true);

        foreach ($assets as $asset) {
            $assetKey = (string) ($asset['asset_key'] ?? '');
            if ($assetKey === '') {
                continue;
            }

            if (isset($unresolvedAssetKeys[$assetKey])) {
                $suppressed[] = $this->suppression($asset, 'unresolved_asset_reference');
                continue;
            }

            if (! $this->matchesRequestedSlot($asset, $desiredSlots)) {
                continue;
            }

            if (! $this->matchesRequestedRegistry($asset, $desiredRegistries)) {
                continue;
            }

            if (! $this->matchesBasicSafety($asset, $input)) {
                continue;
            }

            $selected[] = new BigFiveV2SelectedAssetRef(
                assetKey: $assetKey,
                registryKey: (string) ($asset['registry_key'] ?? ''),
                moduleKey: (string) ($asset['module_key'] ?? ''),
                blockKey: (string) ($asset['block_key'] ?? ''),
                slotKey: (string) ($asset['slot_key'] ?? ''),
                priority: (int) ($asset['priority'] ?? 0),
                contentSource: (string) ($asset['content_source'] ?? ''),
            );
        }

        usort($selected, static function (BigFiveV2SelectedAssetRef $left, BigFiveV2SelectedAssetRef $right) use ($desiredSlotOrder): int {
            $leftOrder = $desiredSlotOrder[$left->slotKey] ?? PHP_INT_MAX;
            $rightOrder = $desiredSlotOrder[$right->slotKey] ?? PHP_INT_MAX;

            return [$leftOrder, $left->moduleKey, $left->slotKey, -$left->priority, $left->assetKey]
                <=> [$rightOrder, $right->moduleKey, $right->slotKey, -$right->priority, $right->assetKey];
        });

        return new BigFiveV2SelectionResult(
            selectedAssetRefs: $selected,
            suppressedAssetRefs: $suppressed,
            unresolvedRefSuppressions: $unresolvedSuppressions,
            pendingSurfaces: ['pdf', 'share_card', 'history', 'compare'],
            safetyDecisions: [
                'scale_code' => $input->scaleCode,
                'form_code' => $input->formCode,
                'runtime_use' => 'staging_only',
                'production_use_allowed' => false,
                'ready_for_pilot' => false,
                'ready_for_runtime' => false,
                'ready_for_production' => false,
                'consumer_side_body_fallback_allowed' => false,
                'unresolved_refs_selectable' => false,
                'body_composition_allowed' => false,
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

        if (($asset['review_status'] ?? null) === 'production_ready') {
            return false;
        }

        return true;
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
