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
    public const CONTENT_VERSION = 'big5_result_page_v2.runtime.v2';

    public const PACKAGE_VERSION = 'big5_result_page_v2_v0_4';

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
        private readonly BigFiveV2ContentAssetLookup $contentAssetLookup = new BigFiveV2ContentAssetLookup,
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

        $assetsByKey = $this->selectorAssetsByKey();
        foreach ($selection->selectedAssetRefs as $ref) {
            $asset = $assetsByKey[$ref->assetKey] ?? null;
            if ($asset === null) {
                throw new RuntimeException("Selected Big Five V2 asset ref does not resolve: {$ref->assetKey}");
            }

            $modules[$ref->moduleKey] ??= [
                'module_key' => $ref->moduleKey,
                'blocks' => [],
            ];

            if ($input->enableResolvedCouplingRefs && in_array($ref->registryKey, [
                'domain_registry',
                'coupling_registry',
                'facet_pattern_registry',
                'profile_signature_registry',
                'scenario_registry',
                'action_plan_registry',
            ], true)) {
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

        $modules = array_values(array_filter(array_map(
            static fn (string $moduleKey): ?array => isset($modules[$moduleKey]) && $modules[$moduleKey]['blocks'] !== []
                ? $modules[$moduleKey]
                : null,
            BigFiveResultPageV2Contract::MODULE_KEYS,
        )));
        $modules = $this->deduplicateVisibleContent($modules);

        $payload = [
            'schema_version' => BigFiveResultPageV2Contract::SCHEMA_VERSION,
            'payload_key' => BigFiveResultPageV2Contract::PAYLOAD_KEY,
            'scale_code' => BigFiveResultPageV2Contract::SCALE_CODE,
            'content_version' => self::CONTENT_VERSION,
            'package_version' => self::PACKAGE_VERSION,
            'canonical_profile_key' => $input->routeRow->profileKey,
            'profile_label_zh' => (string) ($input->routeRow->data['nearest_canonical_profile_label_zh'] ?? ''),
            'projection_v2' => $this->projection($input),
            'modules' => $modules,
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
            'content' => $this->contentForBlockKind(
                (string) ($asset['block_kind'] ?? ''),
                $this->filterPublicPayload($publicPayload),
                $asset,
            ),
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
            'content' => $this->contentForBlockKind(
                (string) ($selectorAsset['block_kind'] ?? self::MODULE_BLOCK_KINDS[$ref->moduleKey] ?? ''),
                $this->filterPublicPayload($resolved->publicContent),
                $selectorAsset,
            ),
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
     * Keep scores exclusively in projection_v2 and avoid repeating deep-dive prose
     * inside the compact trait-bars block.
     *
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function contentForBlockKind(string $blockKind, array $content, array $selectorAsset): array
    {
        if ($blockKind !== 'trait_bars') {
            unset($content['module_key']);

            return $content;
        }

        $domainBands = (array) data_get($selectorAsset, 'trigger.domain_bands', []);
        $trait = strtoupper((string) array_key_first($domainBands));
        $bands = (array) ($domainBands[$trait] ?? []);
        $band = strtolower((string) ($bands[0] ?? ''));

        return [
            'trait' => ['code' => $trait],
            'band' => ['internal_band' => $band],
        ];
    }

    /**
     * A registry asset may repeat a summary as a short body, and profile-scoped
     * assets may repeat the same boundary copy across modules. Keep the first
     * visible occurrence and omit later duplicates from the public payload.
     *
     * @param  list<array<string,mixed>>  $modules
     * @return list<array<string,mixed>>
     */
    private function deduplicateVisibleContent(array $modules): array
    {
        $visibleKeys = array_fill_keys([
            'title', 'title_zh', 'title_en', 'summary', 'summary_zh', 'summary_en',
            'body', 'body_zh', 'body_en', 'short_body', 'short_body_zh', 'short_body_en',
            'benefit', 'benefit_zh', 'benefit_en', 'cost', 'cost_zh', 'cost_en',
            'action', 'action_zh', 'action_en', 'repair', 'repair_zh', 'repair_en',
            'common_misread', 'common_misread_zh', 'common_misread_en',
        ], true);
        $seen = [];

        $walk = function (array $value) use (&$walk, &$seen, $visibleKeys): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $walk($item);

                    continue;
                }
                if (! is_string($item) || ! isset($visibleKeys[(string) $key])) {
                    continue;
                }

                $normalized = preg_replace('/\s+/u', '', trim($item));
                if (! is_string($normalized) || mb_strlen($normalized) < 8) {
                    continue;
                }
                $normalized = mb_strtolower($normalized);
                if (isset($seen[$normalized])) {
                    unset($value[$key]);

                    continue;
                }
                $seen[$normalized] = true;
            }

            return $value;
        };

        return $walk($modules);
    }

    /**
     * @return array<string,mixed>
     */
    private function projection(BigFiveV2SelectorInput $input): array
    {
        return [
            'schema_version' => BigFiveResultPageV2Contract::PROJECTION_SCHEMA_VERSION,
            'attempt_id' => $input->attemptId,
            'result_version' => $input->resultVersion,
            'scale_code' => $input->scaleCode,
            'form_code' => $input->formCode,
            'domains' => $this->domains($input),
            'domain_bands' => $input->domainBands,
            'facets' => [],
            'facet_highlights' => $this->facetHighlights($input),
            'norm_status' => match ($input->normStatus) {
                'available' => 'CALIBRATED',
                'provisional' => 'PROVISIONAL',
                default => 'UNAVAILABLE',
            },
            'norm_group_id' => $input->normStatus === 'available' ? $input->normGroupId : null,
            'norm_version' => $input->normStatus === 'available' ? $input->normVersion : null,
            'quality_status' => $input->qualityStatus,
            'quality_flags' => $input->qualityFlags,
            'profile_signature' => [
                'signature_key' => $input->routeRow->profileKey,
                'label_key' => 'signature.'.$input->routeRow->profileKey,
                'is_fixed_type' => false,
                'system' => 'trait_signature',
                'label_zh' => (string) ($input->routeRow->data['nearest_canonical_profile_label_zh'] ?? ''),
                'axis_zh' => (string) ($input->routeRow->data['primary_axis_zh'] ?? ''),
            ],
            'dominant_couplings' => array_map(
                static fn (string $coupling): array => ['coupling_key' => $coupling],
                array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array) ($input->routeRow->data['primary_coupling_assets'] ?? []),
                )))),
            ),
            'interpretation_scope' => in_array($input->interpretationScope, BigFiveResultPageV2Contract::INTERPRETATION_SCOPES, true)
                ? $input->interpretationScope
                : 'high_tension_profile',
            'confidence_flags' => array_values(array_filter([
                $input->qualityStatus !== 'valid' ? 'quality_degraded' : null,
                $input->normStatus !== 'available' ? 'norm_unavailable' : null,
            ])),
            'safety_flags' => ['non_diagnostic', 'not_type_system'],
            'percentile_display_allowed' => $input->percentileDisplayAllowed,
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
            if ($input->percentileDisplayAllowed && isset($input->domainPercentiles[$domain])) {
                $domains[$domain]['percentile'] = $input->domainPercentiles[$domain];
            }
        }

        return $domains;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function facetHighlights(BigFiveV2SelectorInput $input): array
    {
        $highlights = [];
        foreach ($input->facetSignals as $signal) {
            $key = strtoupper(trim((string) ($signal['key'] ?? $signal['facet'] ?? '')));
            if ($key === '') {
                continue;
            }
            $bucket = strtolower(trim((string) ($signal['bucket'] ?? '')));
            if ($bucket === '' && is_numeric($signal['percentile'] ?? null)) {
                $value = (int) $signal['percentile'];
                $bucket = match (true) {
                    $value < 20 => 'very_low',
                    $value < 40 => 'low',
                    $value < 60 => 'mid',
                    $value < 80 => 'high',
                    default => 'very_high',
                };
            }
            $highlight = ['key' => $key, 'bucket' => $bucket];
            if ($input->percentileDisplayAllowed && isset($signal['percentile'])) {
                $highlight['percentile'] = (int) $signal['percentile'];
            }
            $highlights[] = $highlight;
        }

        return $highlights;
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
