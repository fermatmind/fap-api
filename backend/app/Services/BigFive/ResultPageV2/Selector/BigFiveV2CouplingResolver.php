<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Selector;

use RuntimeException;

final class BigFiveV2CouplingResolver
{
    private const CANONICAL_COUPLING_PATH = 'content_assets/big5/result_page_v2/coupling_assets/v0_1/big5_coupling_assets_v0_1.json';

    private const SUPPLEMENTAL_COUPLING_PATH = 'content_assets/big5/result_page_v2/coupling_assets/supplemental/v0_1/big5_supplemental_coupling_assets_v0_1.json';

    private const REFERENCE_REPORT_PATH = 'content_assets/big5/result_page_v2/qa/selector_reference_consistency/v0_1/selector_reference_consistency_report_v0_1.json';

    private const RESULT_PAGE_SURFACE = 'result_page';

    private const UNSAFE_SURFACES = [
        'compare',
        'history',
        'pdf',
        'share',
        'share_card',
    ];

    /**
     * @var array<string,array<string,array<string,mixed>>>
     */
    private ?array $canonicalAssetsByKeyAndRole = null;

    /**
     * @var array<string,array<string,array<string,mixed>>>
     */
    private ?array $supplementalAssetsByKeyAndRole = null;

    /**
     * @var array<string,array<string,mixed>>
     */
    private ?array $approvedAliases = null;

    public function resolve(string $couplingKey, string $surface = self::RESULT_PAGE_SURFACE, ?string $assetRole = null): BigFiveV2CouplingResolution
    {
        $couplingKey = strtolower(trim($couplingKey));
        $surface = strtolower(trim($surface));
        $assetRole = $this->normalizeAssetRole($assetRole);

        if ($couplingKey === '') {
            return $this->suppressed('', 'unknown', $surface, $assetRole, 'empty_coupling_key');
        }

        if ($this->isUnsafeSurface($surface)) {
            return new BigFiveV2CouplingResolution(
                requestedKey: $couplingKey,
                decisionType: 'unsafe_surface_suppressed',
                resolvedKey: null,
                sourcePackage: null,
                selectable: false,
                surface: $surface,
                assetRole: $assetRole,
                suppressionReason: 'surface_not_enabled_for_routing4',
            );
        }

        $canonical = $this->canonicalAssetsByKeyAndRole();
        if ($this->hasRole($canonical, $couplingKey, $assetRole)) {
            return new BigFiveV2CouplingResolution(
                requestedKey: $couplingKey,
                decisionType: 'canonical_exact',
                resolvedKey: $couplingKey,
                sourcePackage: 'B5-CONTENT-2',
                selectable: true,
                surface: $surface,
                assetRole: $assetRole,
            );
        }

        $aliases = $this->approvedAliases();
        if (isset($aliases[$couplingKey])) {
            $resolvedTo = (string) ($aliases[$couplingKey]['resolved_to'] ?? '');
            if ($this->hasRole($canonical, $resolvedTo, $assetRole)) {
                return new BigFiveV2CouplingResolution(
                    requestedKey: $couplingKey,
                    decisionType: 'approved_alias',
                    resolvedKey: $resolvedTo,
                    sourcePackage: 'B5-CONTENT-2',
                    selectable: true,
                    surface: $surface,
                    assetRole: $assetRole,
                    aliasDecisionType: (string) ($aliases[$couplingKey]['decision_type'] ?? ''),
                );
            }

            return $this->suppressed($couplingKey, 'approved_alias', $surface, $assetRole, 'approved_alias_target_missing_or_role_missing');
        }

        $supplemental = $this->supplementalAssetsByKeyAndRole();
        if ($this->hasRole($supplemental, $couplingKey, $assetRole)) {
            return new BigFiveV2CouplingResolution(
                requestedKey: $couplingKey,
                decisionType: 'supplemental_exact',
                resolvedKey: $couplingKey,
                sourcePackage: 'B5-CONTENT-2B',
                selectable: true,
                surface: $surface,
                assetRole: $assetRole,
            );
        }

        if (isset($supplemental[$couplingKey]) || isset($canonical[$couplingKey])) {
            return $this->suppressed($couplingKey, 'unknown', $surface, $assetRole, 'asset_role_missing');
        }

        return $this->suppressed($couplingKey, 'unknown', $surface, $assetRole, 'unknown_coupling_key');
    }

    /**
     * @return list<string>
     */
    public function canonicalKeys(): array
    {
        $keys = array_keys($this->canonicalAssetsByKeyAndRole());
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function supplementalKeys(): array
    {
        $keys = array_keys($this->supplementalAssetsByKeyAndRole());
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string,string>
     */
    public function approvedAliasMap(): array
    {
        $aliases = [];
        foreach ($this->approvedAliases() as $source => $alias) {
            $aliases[$source] = (string) ($alias['resolved_to'] ?? '');
        }
        ksort($aliases);

        return $aliases;
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function canonicalAssetsByKeyAndRole(): array
    {
        return $this->canonicalAssetsByKeyAndRole ??= $this->assetsByKeyAndRole(self::CANONICAL_COUPLING_PATH, 'B5-CONTENT-2');
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function supplementalAssetsByKeyAndRole(): array
    {
        return $this->supplementalAssetsByKeyAndRole ??= $this->assetsByKeyAndRole(self::SUPPLEMENTAL_COUPLING_PATH, 'B5-CONTENT-2B');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function approvedAliases(): array
    {
        if ($this->approvedAliases !== null) {
            return $this->approvedAliases;
        }

        $report = $this->decodeJsonFile(base_path(self::REFERENCE_REPORT_PATH));
        $aliases = [];
        foreach ((array) data_get($report, 'public_pilot_resolution_policy.approved_coupling_aliases', []) as $alias) {
            if (! is_array($alias)) {
                continue;
            }

            $source = strtolower(trim((string) ($alias['source_coupling_key'] ?? '')));
            $target = strtolower(trim((string) ($alias['resolved_to'] ?? '')));
            if ($source === '' || $target === '') {
                continue;
            }

            $aliases[$source] = [
                'resolved_to' => $target,
                'decision_type' => (string) ($alias['decision_type'] ?? ''),
            ];
        }

        ksort($aliases);
        $this->approvedAliases = $aliases;

        return $aliases;
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function assetsByKeyAndRole(string $relativePath, string $sourcePackage): array
    {
        $decoded = $this->decodeJsonFile(base_path($relativePath));
        $items = (array) ($decoded['items'] ?? []);
        $assets = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->assertStagingOnlyAsset($item, $sourcePackage);
            $key = strtolower(trim((string) ($item['coupling_key'] ?? '')));
            $role = $this->normalizeAssetRole((string) ($item['slot_key'] ?? ''));
            if ($key === '' || $role === null) {
                continue;
            }

            $assets[$key][$role] = $item;
        }

        ksort($assets);
        foreach ($assets as &$roles) {
            ksort($roles);
        }
        unset($roles);

        return $assets;
    }

    /**
     * @param  array<string,array<string,array<string,mixed>>>  $assets
     */
    private function hasRole(array $assets, string $couplingKey, ?string $assetRole): bool
    {
        if (! isset($assets[$couplingKey])) {
            return false;
        }

        if ($assetRole === null) {
            return $assets[$couplingKey] !== [];
        }

        return isset($assets[$couplingKey][$assetRole]);
    }

    private function normalizeAssetRole(?string $assetRole): ?string
    {
        $assetRole = trim((string) $assetRole);
        if ($assetRole === '') {
            return null;
        }

        return match ($assetRole) {
            'primary_coupling.core_explanation' => 'coupling_core_explanation',
            'primary_coupling.benefit_cost' => 'coupling_benefit_cost',
            'primary_coupling.common_misread' => 'coupling_common_misread',
            'primary_coupling.action_strategy' => 'coupling_action_strategy',
            'primary_coupling.scenario_bridge' => 'coupling_scenario_bridge',
            default => $assetRole,
        };
    }

    private function isUnsafeSurface(string $surface): bool
    {
        return in_array($surface, self::UNSAFE_SURFACES, true);
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function assertStagingOnlyAsset(array $asset, string $sourcePackage): void
    {
        $assetKey = (string) ($asset['asset_key'] ?? 'unknown');
        if (($asset['runtime_use'] ?? null) !== 'staging_only') {
            throw new RuntimeException("{$sourcePackage} coupling asset {$assetKey} must remain staging_only.");
        }

        if (($asset['production_use_allowed'] ?? true) !== false) {
            throw new RuntimeException("{$sourcePackage} coupling asset {$assetKey} must not allow production use.");
        }

        foreach (['ready_for_pilot', 'ready_for_runtime', 'ready_for_production'] as $field) {
            if (($asset[$field] ?? false) === true) {
                throw new RuntimeException("{$sourcePackage} coupling asset {$assetKey} must not set {$field}=true.");
            }
        }
    }

    private function suppressed(string $couplingKey, string $decisionType, string $surface, ?string $assetRole, string $reason): BigFiveV2CouplingResolution
    {
        return new BigFiveV2CouplingResolution(
            requestedKey: $couplingKey,
            decisionType: $decisionType,
            resolvedKey: null,
            sourcePackage: null,
            selectable: false,
            surface: $surface,
            assetRole: $assetRole,
            suppressionReason: $reason,
        );
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if (! is_string($json)) {
            throw new RuntimeException("Big Five V2 coupling resolver input is unreadable: {$path}");
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Big Five V2 coupling resolver input is not a JSON object or list: {$path}");
        }

        return $decoded;
    }
}
