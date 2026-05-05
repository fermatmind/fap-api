<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\ContentAssets;

use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2CouplingResolver;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use RuntimeException;

final class BigFiveV2ContentAssetLookup
{
    private const SELECTOR_ASSETS_PATH = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json';

    private const TRAIT_ASSETS_PATH = 'content_assets/big5/result_page_v2/trait_band_assets/v0_1/big5_trait_band_assets_v0_1.json';

    private const CANONICAL_COUPLING_ASSETS_PATH = 'content_assets/big5/result_page_v2/coupling_assets/v0_1/big5_coupling_assets_v0_1.json';

    private const SUPPLEMENTAL_COUPLING_ASSETS_PATH = 'content_assets/big5/result_page_v2/coupling_assets/supplemental/v0_1/big5_supplemental_coupling_assets_v0_1.json';

    private const FACET_ASSETS_PATH = 'content_assets/big5/result_page_v2/facet_assets/v0_1/big5_facet_assets_v0_1.json';

    private const PROFILE_ASSETS_PATH = 'content_assets/big5/result_page_v2/canonical_profiles/v0_1/big5_canonical_profile_assets_v0_1.json';

    private const SCENARIO_ASSETS_PATH = 'content_assets/big5/result_page_v2/scenario_action_assets/v0_1_1/big5_scenario_action_assets_v0_1.json';

    private const METADATA_NEVER_PUBLIC = [
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
        'source_trace',
        'repair_log_refs',
    ];

    /**
     * @var array<string,array<string,mixed>>|null
     */
    private ?array $selectorAssetsByKey = null;

    /**
     * @var array<string,list<array<string,mixed>>>|null
     */
    private ?array $assetsByPackage = null;

    public function __construct(
        private readonly BigFiveV2AssetPackageLoader $packageLoader = new BigFiveV2AssetPackageLoader(),
        private readonly BigFiveV2CouplingResolver $couplingResolver = new BigFiveV2CouplingResolver(),
    ) {}

    public function resolve(BigFiveV2SelectedAssetRef $ref, ?BigFiveV2SelectorInput $input = null): BigFiveV2ResolvedContentAsset
    {
        $inventory = $this->packageLoader->inventory();
        if (! $inventory->isValid()) {
            throw new RuntimeException('Big Five V2 content asset lookup requires validator-clean staging assets.');
        }

        $selectorAsset = $this->selectorAsset($ref->assetKey);

        return match ($ref->registryKey) {
            'domain_registry' => $this->resolveTraitBand($ref, $selectorAsset, $input),
            'coupling_registry' => $this->resolveCoupling($ref, $selectorAsset),
            'facet_pattern_registry' => $this->resolveFacet($ref, $selectorAsset),
            'profile_signature_registry' => $this->resolveProfile($ref, $selectorAsset, $input),
            'scenario_registry', 'action_plan_registry' => $this->resolveScenario($ref, $selectorAsset, $input),
            default => throw new RuntimeException("Unsupported Big Five V2 content asset registry: {$ref->registryKey}"),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function selectorAsset(string $assetKey): array
    {
        $asset = $this->selectorAssetsByKey()[$assetKey] ?? null;
        if ($asset === null) {
            throw new RuntimeException("Selected Big Five V2 selector asset is missing: {$assetKey}");
        }

        return $asset;
    }

    /**
     * @param  array<string,mixed>  $selectorAsset
     */
    private function resolveTraitBand(BigFiveV2SelectedAssetRef $ref, array $selectorAsset, ?BigFiveV2SelectorInput $input): BigFiveV2ResolvedContentAsset
    {
        foreach ((array) data_get($selectorAsset, 'trigger.domain_bands', []) as $trait => $bands) {
            $trait = (string) $trait;
            $band = $this->preferredBand((array) $bands, $input?->domainBands[$trait] ?? null);
            if ($trait === '' || $band === null) {
                continue;
            }

            foreach ($this->assets('trait_band') as $asset) {
                if (($asset['asset_type'] ?? null) !== 'domain_band') {
                    continue;
                }

                if (($asset['trait']['code'] ?? null) === $trait && ($asset['band']['internal_band'] ?? null) === $band) {
                    return $this->resolved($ref, 'B5-CONTENT-1', $asset);
                }
            }
        }

        throw new RuntimeException("Selected Big Five V2 trait-band ref does not resolve: {$ref->assetKey}");
    }

    /**
     * @param  array<string,mixed>  $selectorAsset
     */
    private function resolveCoupling(BigFiveV2SelectedAssetRef $ref, array $selectorAsset): BigFiveV2ResolvedContentAsset
    {
        foreach ((array) data_get($selectorAsset, 'trigger.coupling_keys', []) as $couplingKey) {
            $resolution = $this->couplingResolver->resolve((string) $couplingKey, 'result_page', 'coupling_core_explanation');
            if (! $resolution->selectable || $resolution->resolvedKey === null) {
                continue;
            }

            $packageKey = $resolution->sourcePackage === 'B5-CONTENT-2B' ? 'coupling_supplemental' : 'coupling_canonical';
            foreach ($this->assets($packageKey) as $asset) {
                if (($asset['coupling_key'] ?? null) === $resolution->resolvedKey
                    && ($asset['slot_key'] ?? null) === 'primary_coupling.core_explanation') {
                    return $this->resolved($ref, (string) $resolution->sourcePackage, $asset, [
                        'coupling_resolution' => $resolution->toArray(),
                    ]);
                }
            }
        }

        throw new RuntimeException("Selected Big Five V2 coupling ref does not resolve: {$ref->assetKey}");
    }

    /**
     * @param  array<string,mixed>  $selectorAsset
     */
    private function resolveFacet(BigFiveV2SelectedAssetRef $ref, array $selectorAsset): BigFiveV2ResolvedContentAsset
    {
        foreach ((array) data_get($selectorAsset, 'trigger.facet_patterns', []) as $pattern) {
            if (! is_array($pattern)) {
                continue;
            }

            $facet = strtoupper((string) ($pattern['facet'] ?? ''));
            $band = $this->preferredBand((array) ($pattern['band'] ?? []), null);
            $direction = $this->facetDirection($band);
            if ($facet === '' || $direction === null) {
                continue;
            }

            foreach ($this->assets('facet') as $asset) {
                if (($asset['facet_key'] ?? null) === $facet && ($asset['facet_direction'] ?? null) === $direction) {
                    return $this->resolved($ref, 'B5-CONTENT-3', $asset);
                }
            }
        }

        throw new RuntimeException("Selected Big Five V2 facet ref does not resolve: {$ref->assetKey}");
    }

    /**
     * @param  array<string,mixed>  $selectorAsset
     */
    private function resolveProfile(BigFiveV2SelectedAssetRef $ref, array $selectorAsset, ?BigFiveV2SelectorInput $input): BigFiveV2ResolvedContentAsset
    {
        $profileKey = $input?->routeRow->profileKey ?: $this->profileKeyFromSelectorAsset($selectorAsset);
        if ($profileKey === '') {
            throw new RuntimeException("Selected Big Five V2 profile ref has no profile key: {$ref->assetKey}");
        }

        foreach ($this->assets('profile') as $asset) {
            if (($asset['profile_key'] ?? null) !== $profileKey) {
                continue;
            }

            $moduleKeys = (array) ($asset['module_keys'] ?? []);
            if ($moduleKeys !== [] && ! in_array($ref->moduleKey, $moduleKeys, true)) {
                continue;
            }

            return $this->resolved($ref, 'B5-CONTENT-4', $asset);
        }

        throw new RuntimeException("Selected Big Five V2 profile ref does not resolve: {$ref->assetKey}");
    }

    /**
     * @param  array<string,mixed>  $selectorAsset
     */
    private function resolveScenario(BigFiveV2SelectedAssetRef $ref, array $selectorAsset, ?BigFiveV2SelectorInput $input): BigFiveV2ResolvedContentAsset
    {
        $profileKey = $input?->routeRow->profileKey ?? '';
        $scenarioKeys = [];
        foreach ((array) data_get($selectorAsset, 'trigger.scenario', []) as $scenario) {
            foreach ($this->scenarioAliases((string) $scenario) as $alias) {
                $scenarioKeys[$alias] = true;
            }
        }

        foreach ($this->assets('scenario') as $asset) {
            if ($profileKey !== '' && ($asset['profile_key'] ?? null) !== $profileKey) {
                continue;
            }

            if (isset($scenarioKeys[(string) ($asset['scenario'] ?? '')])) {
                return $this->resolved($ref, 'B5-CONTENT-5', $asset);
            }
        }

        throw new RuntimeException("Selected Big Five V2 scenario ref does not resolve: {$ref->assetKey}");
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  array<string,mixed>  $metadata
     */
    private function resolved(BigFiveV2SelectedAssetRef $ref, string $sourcePackage, array $asset, array $metadata = []): BigFiveV2ResolvedContentAsset
    {
        $this->assertStagingOnlyAsset($asset, $sourcePackage);

        return new BigFiveV2ResolvedContentAsset(
            selectedRef: $ref,
            sourcePackage: $sourcePackage,
            assetKey: (string) ($asset['asset_key'] ?? ''),
            assetType: (string) ($asset['asset_type'] ?? ''),
            publicContent: $this->filterPublicContent($asset),
            metadata: [
                'resolved_from_registry_key' => $ref->registryKey,
                ...$metadata,
            ],
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function selectorAssetsByKey(): array
    {
        if ($this->selectorAssetsByKey !== null) {
            return $this->selectorAssetsByKey;
        }

        $assets = $this->decodeJsonFile(base_path(self::SELECTOR_ASSETS_PATH));
        if (! array_is_list($assets)) {
            throw new RuntimeException('Big Five V2 selector assets must be a JSON list.');
        }

        $byKey = [];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $assetKey = (string) ($asset['asset_key'] ?? '');
            if ($assetKey !== '') {
                $byKey[$assetKey] = $asset;
            }
        }

        return $this->selectorAssetsByKey = $byKey;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function assets(string $packageKey): array
    {
        if ($this->assetsByPackage === null) {
            $this->assetsByPackage = [
                'trait_band' => $this->documentAssets(self::TRAIT_ASSETS_PATH, 'assets'),
                'coupling_canonical' => $this->documentAssets(self::CANONICAL_COUPLING_ASSETS_PATH, 'items'),
                'coupling_supplemental' => $this->documentAssets(self::SUPPLEMENTAL_COUPLING_ASSETS_PATH, 'items'),
                'facet' => $this->documentAssets(self::FACET_ASSETS_PATH, 'items'),
                'profile' => $this->documentAssets(self::PROFILE_ASSETS_PATH, 'assets'),
                'scenario' => $this->documentAssets(self::SCENARIO_ASSETS_PATH, 'assets'),
            ];
        }

        return $this->assetsByPackage[$packageKey] ?? [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function documentAssets(string $relativePath, string $key): array
    {
        $document = $this->decodeJsonFile(base_path($relativePath));
        $assets = (array) ($document[$key] ?? []);

        return array_values(array_filter($assets, 'is_array'));
    }

    /**
     * @param  list<string>  $bands
     */
    private function preferredBand(array $bands, ?string $preferred): ?string
    {
        $bands = array_values(array_filter(array_map('strval', $bands)));
        if ($preferred !== null && in_array($preferred, $bands, true)) {
            return $preferred;
        }

        return $bands[0] ?? null;
    }

    private function facetDirection(?string $band): ?string
    {
        return match ($band) {
            'high', 'very_high' => 'high',
            'low', 'very_low' => 'low',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $selectorAsset
     */
    private function profileKeyFromSelectorAsset(array $selectorAsset): string
    {
        $encoded = json_encode($selectorAsset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ($this->assets('profile') as $asset) {
            $profileKey = (string) ($asset['profile_key'] ?? '');
            if ($profileKey !== '' && str_contains($encoded, $profileKey)) {
                return $profileKey;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function scenarioAliases(string $scenario): array
    {
        return match ($scenario) {
            'work' => ['workplace'],
            'relationship' => ['relationships'],
            'stress' => ['stress_recovery'],
            'action' => ['personal_growth'],
            default => [$scenario],
        };
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function assertStagingOnlyAsset(array $asset, string $sourcePackage): void
    {
        $assetKey = (string) ($asset['asset_key'] ?? 'unknown');
        if (($asset['runtime_use'] ?? null) !== 'staging_only') {
            throw new RuntimeException("{$sourcePackage} content asset {$assetKey} must remain staging_only.");
        }

        if (($asset['production_use_allowed'] ?? true) !== false) {
            throw new RuntimeException("{$sourcePackage} content asset {$assetKey} must not allow production use.");
        }

        foreach (['ready_for_pilot', 'ready_for_runtime', 'ready_for_production'] as $field) {
            if (($asset[$field] ?? false) === true) {
                throw new RuntimeException("{$sourcePackage} content asset {$assetKey} must not set {$field}=true.");
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function filterPublicContent(array $payload): array
    {
        $filtered = [];
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, self::METADATA_NEVER_PUBLIC, true)) {
                continue;
            }

            $filtered[$key] = is_array($value) ? $this->filterPublicContent($value) : $value;
        }

        return $filtered;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if (! is_string($json)) {
            throw new RuntimeException("Big Five V2 content asset lookup input is unreadable: {$path}");
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Big Five V2 content asset lookup input is not JSON: {$path}");
        }

        return $decoded;
    }
}
