<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Composer;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2ContentAssetLookup;
use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2ResolvedContentAsset;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectionResult;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use RuntimeException;

final class BigFiveV2PilotPayloadComposer
{
    public const CONTENT_VERSION = 'big5_result_page_v2.pilot_payload.v0_1';

    public const PACKAGE_VERSION = 'B5-CONTENT-staging-pilot.v0_1';

    private const SELECTOR_ASSETS_PATH = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json';

    private const MODULE_BLOCK_KINDS = [
        'module_00_trust_bar' => 'trust_bar',
        'module_01_hero' => 'hero_summary',
        'module_02_quick_understanding' => 'quick_cards',
        'module_03_trait_deep_dive' => 'trait_deep_dive',
        'module_04_coupling' => 'coupling_cards',
        'module_05_facet_reframe' => 'facet_reframe',
        'module_06_application_matrix' => 'application_matrix',
        'module_07_collaboration_manual' => 'collaboration_manual',
        'module_08_share_save' => 'share_save',
        'module_09_feedback_data_flywheel' => 'feedback_block',
        'module_10_method_privacy' => 'method_boundary',
    ];

    private const MODULE_REGISTRY_REFS = [
        'module_00_trust_bar' => ['method_registry:pilot_boundary'],
        'module_02_quick_understanding' => ['state_scope_registry:pending_asset_resolution'],
        'module_04_coupling' => ['coupling_registry:pending_asset_resolution'],
        'module_05_facet_reframe' => ['facet_registry:pending_asset_resolution'],
        'module_06_application_matrix' => ['scenario_registry:pending_asset_resolution'],
        'module_07_collaboration_manual' => ['scenario_registry:pending_asset_resolution'],
        'module_08_share_save' => ['share_safety_registry:pending_asset_resolution'],
        'module_09_feedback_data_flywheel' => ['state_scope_registry:pending_asset_resolution'],
        'module_10_method_privacy' => ['method_registry:pilot_boundary'],
    ];

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
        'asset_id',
        'asset_key',
        'asset_version',
        'asset_layer',
        'asset_type',
        'applies_to',
        'avoid_when',
        'body_quality',
        'can_combine_with',
        'cannot_combine_with',
        'copy_role',
        'dedupe_group',
        'fallback_allowed',
        'internal_combination_key',
        'section_key',
        'slot_key',
        'qa_status',
        'reading_mode',
        'render_surface',
        'selection_priority',
        'selection_specificity',
        'source_trace',
        'must_include_assets',
        'must_suppress_assets',
        'recommended_trait_band_assets',
        'recommended_coupling_assets',
        'recommended_facet_assets',
    ];

    public function __construct(
        private readonly BigFiveV2ContentAssetLookup $contentAssetLookup = new BigFiveV2ContentAssetLookup(),
    ) {}

    /**
     * @return array<string,array<string,mixed>>
     */
    private function selectorAssetsByKey(): array
    {
        $json = file_get_contents(base_path(self::SELECTOR_ASSETS_PATH));
        if (! is_string($json)) {
            throw new RuntimeException('Big Five V2 selector assets are unreadable.');
        }

        $assets = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($assets) || ! array_is_list($assets)) {
            throw new RuntimeException('Big Five V2 selector assets must be a JSON list.');
        }

        $byKey = [];
        foreach ($assets as $asset) {
            if (is_array($asset)) {
                $byKey[(string) ($asset['asset_key'] ?? '')] = $asset;
            }
        }
        unset($byKey['']);

        return $byKey;
    }

    /**
     * @return array<string,mixed>
     */
    public function compose(BigFiveV2SelectorInput $input, BigFiveV2SelectionResult $selection): array
    {
        $modules = [];
        foreach (BigFiveResultPageV2Contract::MODULE_KEYS as $moduleKey) {
            $modules[$moduleKey] = [
                'module_key' => $moduleKey,
                'blocks' => [],
            ];
        }

        $assetsByKey = $this->selectorAssetsByKey();
        foreach ($selection->selectedAssetRefs as $ref) {
            $asset = $assetsByKey[$ref->assetKey] ?? null;
            if ($asset === null) {
                throw new RuntimeException("Selected Big Five V2 asset ref does not resolve: {$ref->assetKey}");
            }

            if ($input->enableResolvedCouplingRefs) {
                $modules[$ref->moduleKey]['blocks'][] = $this->blockFromResolvedContentAsset(
                    $ref,
                    $asset,
                    $this->contentAssetLookup->resolve($ref, $input),
                );

                continue;
            }

            $publicPayload = $asset['public_payload'] ?? null;
            if (! is_array($publicPayload)) {
                throw new RuntimeException("Selected Big Five V2 asset has no public_payload: {$ref->assetKey}");
            }

            $modules[$ref->moduleKey]['blocks'][] = $this->blockFromSelectedRef($ref, $asset, $publicPayload);
        }

        foreach ($modules as $moduleKey => &$module) {
            if ($module['blocks'] === []) {
                $module['blocks'][] = $this->pendingBlock($moduleKey);
            }
        }
        unset($module);

        $payload = [
            'schema_version' => BigFiveResultPageV2Contract::SCHEMA_VERSION,
            'payload_key' => BigFiveResultPageV2Contract::PAYLOAD_KEY,
            'scale_code' => BigFiveResultPageV2Contract::SCALE_CODE,
            'fixture_key' => 'pilot_o59_staging_payload_v0_1',
            'content_version' => self::CONTENT_VERSION,
            'package_version' => self::PACKAGE_VERSION,
            'canonical_profile_key' => $input->routeRow->profileKey,
            'profile_label_zh' => (string) ($input->routeRow->data['nearest_canonical_profile_label_zh'] ?? ''),
            'projection_v2' => $this->projection($input),
            'modules' => array_values($modules),
        ];

        return [
            BigFiveResultPageV2Contract::PAYLOAD_KEY => $this->filterPublicPayload($payload),
        ];
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  array<string,mixed>  $publicPayload
     * @return array<string,mixed>
     */
    private function blockFromSelectedRef(BigFiveV2SelectedAssetRef $ref, array $asset, array $publicPayload): array
    {
        return [
            'block_key' => $ref->blockKey,
            'block_kind' => (string) ($asset['block_kind'] ?? ''),
            'module_key' => $ref->moduleKey,
            'content' => $this->filterPublicPayload($publicPayload),
            'projection_refs' => $this->projectionRefsForRegistry($ref->registryKey),
            'registry_refs' => ["{$ref->registryKey}:{$ref->assetKey}"],
            'safety_level' => $this->contractSafetyLevel((string) ($asset['safety_level'] ?? '')),
            'evidence_level' => $this->contractEvidenceLevel((string) ($asset['evidence_level'] ?? '')),
            'shareable' => false,
            'content_source' => 'registry_asset',
            'fallback_policy' => 'omit_block',
        ];
    }

    /**
     * @param  array<string,mixed>  $selectorAsset
     * @return array<string,mixed>
     */
    private function blockFromResolvedContentAsset(BigFiveV2SelectedAssetRef $ref, array $selectorAsset, BigFiveV2ResolvedContentAsset $resolved): array
    {
        return [
            'block_key' => $ref->blockKey,
            'block_kind' => (string) ($selectorAsset['block_kind'] ?? self::MODULE_BLOCK_KINDS[$ref->moduleKey] ?? ''),
            'module_key' => $ref->moduleKey,
            'content' => $this->filterPublicPayload($resolved->publicContent),
            'projection_refs' => $this->projectionRefsForRegistry($ref->registryKey),
            'registry_refs' => [
                $this->publicRegistryKey($ref->registryKey).":{$resolved->assetKey}",
            ],
            'safety_level' => $this->contractSafetyLevel((string) ($selectorAsset['safety_level'] ?? data_get($resolved->publicContent, 'safety_tags.0', ''))),
            'evidence_level' => $this->contractEvidenceLevel((string) ($selectorAsset['evidence_level'] ?? data_get($resolved->publicContent, 'asset_layer', ''))),
            'shareable' => false,
            'content_source' => 'registry_asset',
            'fallback_policy' => 'omit_block',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingBlock(string $moduleKey): array
    {
        $blockKind = self::MODULE_BLOCK_KINDS[$moduleKey] ?? 'method_boundary';
        $block = [
            'block_key' => "{$moduleKey}.pilot.pending_asset_resolution.v0_1",
            'block_kind' => $blockKind,
            'module_key' => $moduleKey,
            'content' => [
                'availability' => 'pending_asset_resolution',
            ],
            'projection_refs' => ['interpretation_scope', 'quality_status', 'norm_status'],
            'registry_refs' => self::MODULE_REGISTRY_REFS[$moduleKey] ?? ['method_registry:pilot_boundary'],
            'safety_level' => in_array($blockKind, ['trust_bar', 'method_boundary'], true) ? 'boundary' : 'standard',
            'evidence_level' => 'descriptive',
            'shareable' => false,
            'content_source' => 'composer_projection',
            'fallback_policy' => in_array($blockKind, ['trust_bar', 'method_boundary'], true) ? 'backend_required' : 'omit_block',
        ];

        if ($blockKind === 'facet_reframe') {
            $block['content']['facets'] = [];
        }

        return $block;
    }

    /**
     * @return array<string,mixed>
     */
    private function projection(BigFiveV2SelectorInput $input): array
    {
        return [
            'schema_version' => BigFiveResultPageV2Contract::PROJECTION_SCHEMA_VERSION,
            'attempt_id' => 'attempt_big5_o59_pilot_fixture',
            'result_version' => self::CONTENT_VERSION,
            'scale_code' => $input->scaleCode,
            'form_code' => $input->formCode,
            'domains' => $this->domains($input),
            'domain_bands' => $input->domainBands,
            'facets' => [],
            'facet_highlights' => $input->facetSignals,
            'norm_status' => $input->normStatus === 'available' ? 'CALIBRATED' : 'UNAVAILABLE',
            'norm_group_id' => $input->normStatus === 'available' ? 'pilot_fixture_norm_group' : null,
            'norm_version' => $input->normStatus === 'available' ? 'pilot_fixture_norm_v0_1' : null,
            'quality_status' => $input->qualityStatus,
            'quality_flags' => [],
            'profile_signature' => [
                'signature_key' => $input->routeRow->profileKey,
                'label_key' => 'signature.'.$input->routeRow->profileKey,
                'is_fixed_type' => false,
                'system' => 'trait_signature',
                'label_zh' => (string) ($input->routeRow->data['nearest_canonical_profile_label_zh'] ?? ''),
                'axis_zh' => (string) ($input->routeRow->data['primary_axis_zh'] ?? ''),
            ],
            'dominant_couplings' => array_map(
                static fn (string $coupling): array => [
                    'coupling_key' => $coupling,
                    'strength' => 'route_matrix_candidate',
                ],
                array_values((array) ($input->routeRow->data['primary_coupling_assets'] ?? [])),
            ),
            'interpretation_scope' => in_array($input->routeRow->interpretationScope, BigFiveResultPageV2Contract::INTERPRETATION_SCOPES, true)
                ? $input->routeRow->interpretationScope
                : 'high_tension_profile',
            'confidence_flags' => ['pilot_staging_fixture', 'selector_refs_only'],
            'safety_flags' => ['non_diagnostic', 'not_type_system', 'staging_only'],
            'public_fields' => [
                'domains',
                'domain_bands',
                'facet_highlights',
                'profile_signature',
                'dominant_couplings',
                'interpretation_scope',
            ],
            'internal_only_fields' => [],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function domains(BigFiveV2SelectorInput $input): array
    {
        $domains = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
            $domains[$domain] = [
                'score' => $input->domainScores[$domain] ?? null,
                'band' => $input->domainBands[$domain] ?? null,
            ];
        }

        return $domains;
    }

    /**
     * @return list<string>
     */
    private function projectionRefsForRegistry(string $registryKey): array
    {
        return match ($registryKey) {
            'domain_registry' => ['domains', 'domain_bands'],
            'profile_signature_registry' => ['profile_signature', 'interpretation_scope'],
            'coupling_registry' => ['dominant_couplings', 'domain_bands'],
            'scenario_registry', 'action_plan_registry' => ['domain_bands', 'interpretation_scope'],
            'facet_pattern_registry' => ['facet_highlights', 'quality_status'],
            default => ['interpretation_scope', 'quality_status', 'norm_status'],
        };
    }

    private function publicRegistryKey(string $registryKey): string
    {
        return match ($registryKey) {
            'facet_pattern_registry' => 'facet_registry',
            'action_plan_registry' => 'scenario_registry',
            default => $registryKey,
        };
    }

    private function contractSafetyLevel(string $safetyLevel): string
    {
        return match ($safetyLevel) {
            'sensitive_non_clinical' => 'boundary',
            'share_safe' => 'share_safe',
            'degraded' => 'degraded',
            default => 'standard',
        };
    }

    private function contractEvidenceLevel(string $evidenceLevel): string
    {
        return match ($evidenceLevel) {
            'computed', 'normed', 'registry_backed', 'data_supported', 'descriptive' => $evidenceLevel,
            'trait_band_interpretation', 'cross_trait_interpretation', 'scenario_interpretation' => 'registry_backed',
            default => 'descriptive',
        };
    }

    /**
     * @param  array<int|string,mixed>  $payload
     * @return array<int|string,mixed>
     */
    private function filterPublicPayload(array $payload): array
    {
        $filtered = [];
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, self::METADATA_NEVER_PUBLIC, true)) {
                continue;
            }

            $filtered[$key] = is_array($value) ? $this->filterPublicPayload($value) : $value;
        }

        return $filtered;
    }
}
