<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Routing;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixRow;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2CouplingResolver;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use RuntimeException;

final class BigFiveV2RouteDrivenSelectorInputBuilder
{
    private const SELECTOR_ASSETS_PATH = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json';

    /**
     * @var list<array<string,mixed>>|null
     */
    private ?array $selectorAssets = null;

    public function __construct(
        private readonly BigFiveV2CouplingResolver $couplingResolver = new BigFiveV2CouplingResolver,
    ) {}

    public function build(
        BigFiveV2RouteInput $routeInput,
        BigFiveV2RouteMatrixRow $routeRow,
        string $readingMode = 'standard',
        string $scaleCode = 'BIG5_OCEAN',
        string $formCode = 'big5_120',
        ?string $scenario = null,
        string $attemptId = '',
        string $resultVersion = '',
    ): BigFiveV2SelectorInput {
        $slots = [];
        $registries = [];
        $suppressed = $this->suppressedRouteReferences($routeRow);
        $domainBands = $this->domainBands($routeInput, $routeRow);

        $this->includeProfileSlot($routeRow, $readingMode, $suppressed, $slots, $registries);
        $this->includeDomainSlots($domainBands, $readingMode, $suppressed, $slots, $registries);
        $this->includeCouplingSlots($routeRow, $readingMode, $suppressed, $slots, $registries);
        $this->includeFacetSlots($routeInput, $readingMode, $suppressed, $slots, $registries);
        $this->includeScenarioSlots($routeRow, $readingMode, $suppressed, $slots, $registries);

        $interpretationScope = $this->effectiveInterpretationScope($routeInput, $routeRow);
        $assetKeys = $this->matchingAssetKeys(
            $routeInput,
            $routeRow,
            $readingMode,
            $formCode,
            $scenario,
            $interpretationScope,
            array_keys($slots),
            array_keys($registries),
        );

        return new BigFiveV2SelectorInput(
            scaleCode: $scaleCode,
            formCode: $formCode,
            domainBands: $domainBands,
            domainScores: $routeInput->domainScores,
            facetSignals: $routeInput->facetRouteSignals,
            qualityStatus: $routeInput->qualityStatus,
            normStatus: $routeInput->normStatus,
            readingMode: $readingMode,
            scenario: $scenario,
            routeRow: $routeRow,
            includeSlots: array_keys($slots),
            includeRegistryKeys: array_keys($registries),
            enableResolvedCouplingRefs: true,
            includeAssetKeys: $assetKeys,
            requiredSemanticSlots: array_map(
                static fn (string $trait): string => "module_01_hero:domain:{$trait}",
                ['O', 'C', 'E', 'A', 'N'],
            ),
            domainPercentiles: $routeInput->domainPercentiles,
            qualityFlags: $routeInput->qualityFlags,
            attemptId: trim($attemptId),
            resultVersion: trim($resultVersion),
            normGroupId: $routeInput->normGroupId,
            normVersion: $routeInput->normVersion,
            percentileDisplayAllowed: $routeInput->percentileDisplayEligible
                && (bool) config('big5_result_page_v2.public_percentile_display_enabled', false),
            interpretationScope: $interpretationScope,
        );
    }

    private function effectiveInterpretationScope(BigFiveV2RouteInput $routeInput, BigFiveV2RouteMatrixRow $routeRow): string
    {
        if ($routeInput->qualityStatus !== 'valid') {
            return 'low_quality';
        }

        if ($routeInput->normStatus === 'missing') {
            return 'norm_unavailable';
        }

        if ($routeRow->interpretationScope === 'high_tension_or_mixed') {
            return data_get($routeRow->toArray(), 'tension_level') === 'high'
                ? 'high_tension_profile'
                : 'mixed_signature';
        }
        if ($routeRow->interpretationScope === 'balanced_or_diffuse') {
            return 'balanced_profile';
        }

        return $routeRow->interpretationScope;
    }

    /**
     * Build an ordered shortlist of exact asset identities. The selector revalidates
     * every candidate and chooses one winner for each semantic slot.
     *
     * @param  list<string>  $includeSlots
     * @param  list<string>  $includeRegistries
     * @return list<string>
     */
    private function matchingAssetKeys(
        BigFiveV2RouteInput $routeInput,
        BigFiveV2RouteMatrixRow $routeRow,
        string $readingMode,
        string $formCode,
        ?string $scenario,
        string $interpretationScope,
        array $includeSlots,
        array $includeRegistries,
    ): array {
        $slots = array_fill_keys($includeSlots, true);
        $registries = array_fill_keys($includeRegistries, true);
        $keys = [];

        foreach ($this->selectorAssets() as $asset) {
            $assetKey = trim((string) ($asset['asset_key'] ?? ''));
            if ($assetKey === ''
                || ! isset($slots[(string) ($asset['slot_key'] ?? '')])
                || ! isset($registries[(string) ($asset['registry_key'] ?? '')])) {
                continue;
            }

            if ($this->assetMatchesContext(
                $asset,
                $routeInput,
                $routeRow,
                $readingMode,
                $formCode,
                $scenario,
                $interpretationScope,
            )) {
                $keys[] = $assetKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function assetMatchesContext(
        array $asset,
        BigFiveV2RouteInput $routeInput,
        BigFiveV2RouteMatrixRow $routeRow,
        string $readingMode,
        string $formCode,
        ?string $scenario,
        string $interpretationScope,
    ): bool {
        if (! $this->assetSupportsReadingMode($asset, $readingMode)) {
            return false;
        }

        $trigger = (array) ($asset['trigger'] ?? []);
        foreach ((array) ($trigger['domain_bands'] ?? []) as $trait => $allowedBands) {
            if (! in_array($this->domainBands($routeInput, $routeRow)[(string) $trait] ?? '', (array) $allowedBands, true)) {
                return false;
            }
        }

        $scopes = (array) ($trigger['interpretation_scope'] ?? []);
        if ($scopes !== [] && ! in_array($interpretationScope, $scopes, true)) {
            return false;
        }

        $qualityStatuses = (array) ($trigger['quality_status'] ?? []);
        if ($qualityStatuses !== [] && ! in_array($routeInput->qualityStatus, $qualityStatuses, true)) {
            return false;
        }

        $normStatuses = (array) ($trigger['norm_status'] ?? []);
        $normStatus = $routeInput->normStatus === 'missing' ? 'unavailable' : $routeInput->normStatus;
        if ($normStatuses !== [] && ! in_array($normStatus, $normStatuses, true)) {
            return false;
        }

        $formCodes = (array) data_get($asset, 'scope.form_codes', []);
        if ($formCodes !== [] && ! in_array($formCode, $formCodes, true)) {
            return false;
        }

        if (! $this->matchesFacetContext($trigger, $routeInput)) {
            return false;
        }

        if (! $this->matchesScenarioContext($trigger, $routeRow, $scenario)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $trigger
     */
    private function matchesFacetContext(array $trigger, BigFiveV2RouteInput $routeInput): bool
    {
        $patterns = (array) ($trigger['facet_patterns'] ?? []);
        if ($patterns === []) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (! is_array($pattern)) {
                continue;
            }
            $facet = strtoupper(trim((string) ($pattern['facet'] ?? '')));
            foreach ($routeInput->facetRouteSignals as $signal) {
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

    /**
     * @param  array<string,mixed>  $signal
     */
    private function facetBand(array $signal): ?string
    {
        $bucket = strtolower(trim((string) ($signal['bucket'] ?? '')));
        if (in_array($bucket, ['very_low', 'low', 'mid', 'high', 'very_high'], true)) {
            return $bucket;
        }

        $percentile = $signal['percentile'] ?? null;
        if (! is_numeric($percentile)) {
            return null;
        }

        $value = (int) $percentile;

        return match (true) {
            $value < 20 => 'very_low',
            $value < 40 => 'low',
            $value < 60 => 'mid',
            $value < 80 => 'high',
            default => 'very_high',
        };
    }

    /**
     * @param  array<string,mixed>  $trigger
     */
    private function matchesScenarioContext(array $trigger, BigFiveV2RouteMatrixRow $routeRow, ?string $scenario): bool
    {
        $assetScenarios = array_values(array_filter(array_map('strval', (array) ($trigger['scenario'] ?? []))));
        if ($assetScenarios === []) {
            return true;
        }

        $allowed = [];
        $source = $scenario !== null && trim($scenario) !== ''
            ? [trim($scenario)]
            : (array) data_get($routeRow->toArray(), 'scenario_priorities', []);
        foreach ($source as $routeScenario) {
            foreach ($this->scenarioAliases((string) $routeScenario) as $alias) {
                $allowed[$alias] = true;
            }
        }

        foreach ($assetScenarios as $assetScenario) {
            if (isset($allowed[$assetScenario])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,bool>  $suppressed
     * @param  array<string,true>  $slots
     * @param  array<string,true>  $registries
     */
    private function includeProfileSlot(BigFiveV2RouteMatrixRow $routeRow, string $readingMode, array $suppressed, array &$slots, array &$registries): void
    {
        $row = $routeRow->toArray();
        if (($row['profile_match_confidence'] ?? null) !== 'high') {
            return;
        }

        if (($row['profile_label_public_allowed'] ?? false) !== true || ($row['not_fixed_type'] ?? false) !== true) {
            return;
        }

        $profileKey = (string) ($row['nearest_canonical_profile_key'] ?? '');
        if ($profileKey === '') {
            return;
        }

        foreach ($this->selectorAssets() as $asset) {
            if (($asset['registry_key'] ?? null) !== 'profile_signature_registry') {
                continue;
            }

            if (! $this->assetSupportsReadingMode($asset, $readingMode)) {
                continue;
            }

            if (! str_contains(json_encode($asset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $profileKey)) {
                continue;
            }

            $this->includeAsset($asset, $suppressed, $slots, $registries, ["profile:{$profileKey}"]);
        }
    }

    /**
     * @param  array<string,string>  $domainBands
     * @param  array<string,bool>  $suppressed
     * @param  array<string,true>  $slots
     * @param  array<string,true>  $registries
     */
    private function includeDomainSlots(array $domainBands, string $readingMode, array $suppressed, array &$slots, array &$registries): void
    {
        foreach ($this->selectorAssets() as $asset) {
            if (($asset['registry_key'] ?? null) !== 'domain_registry') {
                continue;
            }

            if (! $this->assetSupportsReadingMode($asset, $readingMode)) {
                continue;
            }

            foreach ($domainBands as $trait => $band) {
                $triggerBands = (array) data_get($asset, "trigger.domain_bands.{$trait}", []);
                if (in_array($band, $triggerBands, true)) {
                    $this->includeAsset($asset, $suppressed, $slots, $registries, ["{$trait}_{$band}"]);
                    break;
                }
            }
        }
    }

    /**
     * @param  array<string,bool>  $suppressed
     * @param  array<string,true>  $slots
     * @param  array<string,true>  $registries
     */
    private function includeCouplingSlots(BigFiveV2RouteMatrixRow $routeRow, string $readingMode, array $suppressed, array &$slots, array &$registries): void
    {
        $routeCouplingKeys = array_fill_keys(array_map(
            static fn (mixed $key): string => (string) $key,
            (array) data_get($routeRow->toArray(), 'primary_coupling_assets', []),
        ), true);
        unset($routeCouplingKeys['']);

        if ($routeCouplingKeys === []) {
            return;
        }

        foreach ($this->selectorAssets() as $asset) {
            if (($asset['registry_key'] ?? null) !== 'coupling_registry') {
                continue;
            }

            if (! $this->assetSupportsReadingMode($asset, $readingMode)) {
                continue;
            }

            foreach ((array) data_get($asset, 'trigger.coupling_keys', []) as $assetCouplingKey) {
                $assetCouplingKey = (string) $assetCouplingKey;
                $resolution = $this->couplingResolver->resolve($assetCouplingKey, 'result_page');
                $resolvedKey = $resolution->resolvedKey ?? $assetCouplingKey;

                if ($resolution->selectable && isset($routeCouplingKeys[$resolvedKey])) {
                    $this->includeAsset($asset, $suppressed, $slots, $registries, ["coupling:{$resolvedKey}", "coupling:{$assetCouplingKey}", $resolvedKey, $assetCouplingKey]);
                    break;
                }
            }
        }
    }

    /**
     * @param  array<string,bool>  $suppressed
     * @param  array<string,true>  $slots
     * @param  array<string,true>  $registries
     */
    private function includeFacetSlots(BigFiveV2RouteInput $routeInput, string $readingMode, array $suppressed, array &$slots, array &$registries): void
    {
        $facetKeys = array_fill_keys(array_map(
            static fn (array $signal): string => (string) ($signal['key'] ?? $signal['facet'] ?? ''),
            $routeInput->facetRouteSignals,
        ), true);
        unset($facetKeys['']);

        if ($facetKeys === []) {
            return;
        }

        foreach ($this->selectorAssets() as $asset) {
            if (($asset['registry_key'] ?? null) !== 'facet_pattern_registry') {
                continue;
            }

            if (! $this->assetSupportsReadingMode($asset, $readingMode)) {
                continue;
            }

            foreach ((array) data_get($asset, 'trigger.facet_patterns', []) as $pattern) {
                $facet = (string) ((array) $pattern)['facet'] ?? '';
                if ($facet !== '' && isset($facetKeys[$facet])) {
                    $this->includeAsset($asset, $suppressed, $slots, $registries, ["facet:{$facet}"]);
                    break;
                }
            }
        }
    }

    /**
     * @param  array<string,bool>  $suppressed
     * @param  array<string,true>  $slots
     * @param  array<string,true>  $registries
     */
    private function includeScenarioSlots(BigFiveV2RouteMatrixRow $routeRow, string $readingMode, array $suppressed, array &$slots, array &$registries): void
    {
        $scenarioKeys = [];
        foreach ((array) data_get($routeRow->toArray(), 'scenario_priorities', []) as $scenario) {
            foreach ($this->scenarioAliases((string) $scenario) as $alias) {
                $scenarioKeys[$alias] = true;
            }
        }

        if ($scenarioKeys === []) {
            return;
        }

        foreach ($this->selectorAssets() as $asset) {
            if (! in_array(($asset['registry_key'] ?? null), ['scenario_registry', 'action_plan_registry'], true)) {
                continue;
            }

            if (! $this->assetSupportsReadingMode($asset, $readingMode)) {
                continue;
            }

            foreach ((array) data_get($asset, 'trigger.scenario', []) as $scenario) {
                $scenario = (string) $scenario;
                if ($scenario !== '' && isset($scenarioKeys[$scenario])) {
                    $this->includeAsset($asset, $suppressed, $slots, $registries, ["scenario:{$scenario}", $scenario]);
                    break;
                }
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function domainBands(BigFiveV2RouteInput $routeInput, BigFiveV2RouteMatrixRow $routeRow): array
    {
        $bands = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $trait) {
            $internalBand = data_get($routeRow->toArray(), "domain_bands.{$trait}.internal_band");
            if (is_string($internalBand) && $internalBand !== '') {
                $bands[$trait] = $internalBand;

                continue;
            }

            $bands[$trait] = $this->bandIndexToInternalBand((int) ($routeInput->domainRouteBands[$trait] ?? 0));
        }

        return $bands;
    }

    private function bandIndexToInternalBand(int $band): string
    {
        return match ($band) {
            1 => 'very_low',
            2 => 'low',
            3 => 'mid',
            4 => 'high',
            5 => 'very_high',
            default => 'unknown',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function suppressedRouteReferences(BigFiveV2RouteMatrixRow $routeRow): array
    {
        $suppressed = [];
        foreach ((array) data_get($routeRow->toArray(), 'must_suppress_assets', []) as $reference) {
            $reference = (string) $reference;
            if ($reference !== '') {
                $suppressed[$reference] = true;
            }
        }

        return $suppressed;
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function assetSupportsReadingMode(array $asset, string $readingMode): bool
    {
        $readingModes = (array) ($asset['reading_modes'] ?? data_get($asset, 'trigger.reading_mode', []));

        return $readingModes === [] || in_array($readingMode, $readingModes, true);
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  array<string,bool>  $suppressed
     * @param  array<string,true>  $slots
     * @param  array<string,true>  $registries
     * @param  list<string>  $routeReferences
     */
    private function includeAsset(array $asset, array $suppressed, array &$slots, array &$registries, array $routeReferences = []): void
    {
        $slotKey = (string) ($asset['slot_key'] ?? '');
        $registryKey = (string) ($asset['registry_key'] ?? '');
        $assetKey = (string) ($asset['asset_key'] ?? '');

        foreach (array_filter([$slotKey, $registryKey, $assetKey, ...$routeReferences]) as $reference) {
            if (isset($suppressed[(string) $reference])) {
                return;
            }
        }

        if ($slotKey !== '') {
            $slots[$slotKey] = true;
        }

        if ($registryKey !== '') {
            $registries[$registryKey] = true;
        }
    }

    /**
     * @return list<string>
     */
    private function scenarioAliases(string $scenario): array
    {
        return match ($scenario) {
            'workplace' => ['work'],
            'relationships' => ['relationship'],
            'stress_recovery' => ['stress'],
            'personal_growth' => ['action'],
            default => [$scenario],
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function selectorAssets(): array
    {
        if ($this->selectorAssets !== null) {
            return $this->selectorAssets;
        }

        $path = base_path(self::SELECTOR_ASSETS_PATH);
        $json = file_get_contents($path);
        if (! is_string($json)) {
            throw new RuntimeException("Big Five V2 selector assets are unreadable: {$path}");
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! array_is_list($decoded)) {
            throw new RuntimeException('Big Five V2 selector assets must be a JSON list.');
        }

        return $this->selectorAssets = array_values(array_filter($decoded, 'is_array'));
    }
}
