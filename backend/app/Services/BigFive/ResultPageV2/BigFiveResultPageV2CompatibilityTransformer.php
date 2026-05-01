<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

final class BigFiveResultPageV2CompatibilityTransformer implements BigFiveResultPageV2TransformerContract
{
    private const DOMAIN_ORDER = ['O', 'C', 'E', 'A', 'N'];

    /**
     * @param  array<string,mixed>  $input
     * @return array{big5_result_page_v2: array<string,mixed>}
     */
    public function transform(array $input): array
    {
        $projection = $this->buildProjection($input);
        $scope = (string) $projection['interpretation_scope'];

        return [
            BigFiveResultPageV2Contract::PAYLOAD_KEY => [
                'schema_version' => BigFiveResultPageV2Contract::SCHEMA_VERSION,
                'payload_key' => BigFiveResultPageV2Contract::PAYLOAD_KEY,
                'scale_code' => BigFiveResultPageV2Contract::SCALE_CODE,
                'projection_v2' => $projection,
                'modules' => $scope === 'low_quality'
                    ? $this->buildLowQualityModules()
                    : $this->buildStandardModules($projection, $input),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function buildProjection(array $input): array
    {
        $projectionV1 = $this->arrayAt($input, 'big5_public_projection_v1');
        $resultJson = $this->arrayAt($input, 'result_json');
        $scoreResult = $this->arrayAt($resultJson, 'normed_json');
        $scoresJson = $this->arrayAt($input, 'scores_json');
        $scoresPct = $this->arrayAt($input, 'scores_pct');
        $oldReport = $this->arrayAt($input, 'big5_report_engine_v2');

        $qualityStatus = strtoupper((string) data_get($input, 'quality_status', data_get($scoreResult, 'quality.status', 'B')));
        $normStatus = strtoupper((string) data_get($input, 'norm_status', data_get($scoreResult, 'norms.norm_status', data_get($resultJson, 'norms.norm_status', 'CALIBRATED'))));
        $normUnavailable = in_array($normStatus, ['MISSING', 'UNAVAILABLE', 'UNCALIBRATED'], true);
        $lowQuality = in_array($qualityStatus, ['D', 'LOW_QUALITY', 'DEGRADED'], true)
            || in_array('LOW_QUALITY', array_map('strtoupper', $this->stringList((array) data_get($input, 'quality_flags', []))), true);

        $domains = $this->buildDomains($projectionV1, $scoreResult, $scoresJson, $scoresPct, $oldReport, $normUnavailable);
        $domainBands = [];
        foreach (self::DOMAIN_ORDER as $domain) {
            $domainBands[$domain] = (string) data_get($domains, "{$domain}.band", 'mid');
        }

        $facets = $this->buildFacets($projectionV1, $scoreResult, $oldReport, $normUnavailable);
        $facetHighlights = $this->buildFacetHighlights($projectionV1, $oldReport, $facets);
        $dominantCouplings = $this->buildDominantCouplings($oldReport, $domainBands);
        $scope = $this->resolveScope($lowQuality, $normUnavailable, $domainBands, $dominantCouplings);

        return [
            'schema_version' => BigFiveResultPageV2Contract::PROJECTION_SCHEMA_VERSION,
            'attempt_id' => (string) ($input['attempt_id'] ?? data_get($oldReport, 'meta.attempt_id', 'big5_result_page_v2_dry_run')),
            'result_version' => 'big5_result_page_v2.compatibility_dry_run.v1',
            'scale_code' => BigFiveResultPageV2Contract::SCALE_CODE,
            'form_code' => (string) ($input['form_code'] ?? data_get($oldReport, 'form_code', 'big5_90')),
            'domains' => $lowQuality ? [] : $domains,
            'domain_bands' => $lowQuality ? [] : $domainBands,
            'facets' => $lowQuality ? [] : $facets,
            'facet_highlights' => $lowQuality ? [] : $facetHighlights,
            'norm_status' => $normStatus,
            'norm_group_id' => $normUnavailable ? '' : (string) data_get($input, 'norm_group_id', data_get($scoreResult, 'norms.norm_group_id', '')),
            'norm_version' => $normUnavailable ? '' : (string) data_get($input, 'norm_version', data_get($scoreResult, 'norms.norms_version', data_get($resultJson, 'norms.norms_version', ''))),
            'quality_status' => $qualityStatus,
            'quality_flags' => $lowQuality ? ['LOW_QUALITY'] : $this->stringList((array) data_get($input, 'quality_flags', [])),
            'profile_signature' => [
                'signature_key' => $scope.'_dry_run',
                'label_key' => 'signature.dry_run.'.$scope,
                'is_fixed_type' => false,
                'system' => 'trait_signature',
            ],
            'dominant_couplings' => $lowQuality ? [] : $dominantCouplings,
            'interpretation_scope' => $scope,
            'confidence_flags' => array_values(array_filter([
                $normUnavailable ? 'norm_unavailable' : 'normed',
                $lowQuality ? 'low_quality' : 'quality_acceptable',
                $oldReport !== [] ? 'old_v2_available' : null,
            ])),
            'safety_flags' => array_values(array_filter([
                'non_diagnostic',
                'not_type_system',
                $normUnavailable ? 'hide_percentile' : null,
                $normUnavailable ? 'hide_normal_curve' : null,
                $lowQuality ? 'degrade_explanation' : null,
                $lowQuality ? 'retest_recommended' : null,
            ])),
            'public_fields' => $this->publicFields($lowQuality, $normUnavailable),
            'internal_only_fields' => ['editor_note', 'qa_note', 'selection_guidance', 'import_policy', 'internal_metadata'],
        ];
    }

    /**
     * @return list<string>
     */
    private function publicFields(bool $lowQuality, bool $normUnavailable): array
    {
        if ($lowQuality) {
            return ['interpretation_scope', 'quality_status', 'quality_flags', 'safety_flags'];
        }
        if ($normUnavailable) {
            return ['domain_bands', 'interpretation_scope', 'safety_flags'];
        }

        return ['domains', 'domain_bands', 'facet_highlights', 'profile_signature', 'dominant_couplings', 'interpretation_scope'];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function buildStandardModules(array $projection, array $input): array
    {
        $oldReport = $this->arrayAt($input, 'big5_report_engine_v2');

        return [
            $this->module('module_00_trust_bar', [$this->block('module_00_trust_bar', 'trust_bar', 'boundary', ['summary_zh' => 'dry-run boundary'], ['safety_flags'], ['boundary_registry:dry_run'], 'boundary', 'descriptive')]),
            $this->module('module_01_hero', [
                $this->block('module_01_hero', 'hero_summary', 'hero', ['summary_zh' => 'dry-run hero summary'], ['profile_signature', 'domain_bands'], ['profile_signature_registry:dry_run'], 'standard', 'computed'),
                $this->block('module_01_hero', 'trait_bars', 'trait_bars', ['summary_zh' => 'dry-run trait bars'], ['domains'], ['domain_registry:dry_run'], 'standard', 'computed'),
            ]),
            $this->module('module_02_quick_understanding', [$this->block('module_02_quick_understanding', 'quick_cards', 'scope', ['summary_zh' => 'dry-run scope summary'], ['interpretation_scope'], ['state_scope_registry:'.(string) $projection['interpretation_scope']], 'standard', 'computed')]),
            $this->module('module_03_trait_deep_dive', [$this->domainBlock($oldReport)]),
            $this->module('module_04_coupling', [$this->couplingBlock($oldReport, (array) $projection['dominant_couplings'])]),
            $this->module('module_05_facet_reframe', [$this->facetBlock($oldReport, (array) $projection['facet_highlights'])]),
            $this->module('module_06_application_matrix', [$this->block('module_06_application_matrix', 'application_matrix', 'deferred', ['summary_zh' => 'dry-run deferred'], ['domain_bands'], ['scenario_registry:deferred'], 'boundary', 'descriptive', false, 'compatibility_wrapper', 'omit_block')]),
            $this->module('module_07_collaboration_manual', [$this->block('module_07_collaboration_manual', 'collaboration_manual', 'deferred', ['summary_zh' => 'dry-run share-safe deferred'], ['domain_bands'], ['scenario_registry:deferred', 'share_safety_registry:collaboration_manual'], 'share_safe', 'descriptive', true, 'compatibility_wrapper', 'share_safe_summary_only')]),
            $this->module('module_08_share_save', [$this->block('module_08_share_save', 'share_save', 'deferred', ['summary_zh' => 'dry-run share/save deferred'], ['profile_signature.label_key'], ['share_safety_registry:deferred'], 'share_safe', 'descriptive', true, 'compatibility_wrapper', 'share_safe_summary_only')]),
            $this->module('module_09_feedback_data_flywheel', [$this->block('module_09_feedback_data_flywheel', 'feedback_block', 'deferred', ['summary_zh' => 'dry-run feedback deferred'], ['interpretation_scope'], ['observation_feedback_registry:deferred'], 'boundary', 'descriptive', false, 'compatibility_wrapper', 'backend_required')]),
            $this->module('module_10_method_privacy', [$this->block('module_10_method_privacy', 'method_boundary', 'method', ['summary_zh' => 'dry-run method boundary'], ['norm_status', 'quality_status', 'safety_flags'], ['boundary_registry:method_privacy', 'method_registry:dry_run'], 'boundary', 'descriptive')]),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildLowQualityModules(): array
    {
        return [
            $this->module('module_00_trust_bar', [$this->block('module_00_trust_bar', 'trust_bar', 'low_quality', ['summary_zh' => 'dry-run low quality boundary'], ['quality_status', 'safety_flags'], ['boundary_registry:low_quality'], 'degraded', 'descriptive')]),
            $this->module('module_09_feedback_data_flywheel', [$this->block('module_09_feedback_data_flywheel', 'feedback_block', 'low_quality', ['summary_zh' => 'dry-run low quality feedback'], ['interpretation_scope'], ['observation_feedback_registry:low_quality'], 'degraded', 'descriptive')]),
            $this->module('module_10_method_privacy', [$this->block('module_10_method_privacy', 'method_boundary', 'low_quality', ['summary_zh' => 'dry-run low quality method boundary'], ['quality_status', 'quality_flags'], ['boundary_registry:method_privacy', 'method_registry:dry_run'], 'boundary', 'descriptive')]),
        ];
    }

    /**
     * @param  array<string,mixed>  $oldReport
     * @return array<string,mixed>
     */
    private function domainBlock(array $oldReport): array
    {
        if ($oldReport !== []) {
            return $this->block(
                'module_03_trait_deep_dive',
                'trait_deep_dive',
                'domain',
                ['summary_zh' => 'dry-run transformed domain source'],
                ['domain_bands'],
                ['domain_registry:dry_run_old_v2_atomic'],
                'standard',
                'computed',
                false,
                'transformed_old_v2_registry',
                'omit_block',
                ['old_v2_group' => 'atomic', 'mapped_registry' => 'domain_registry', 'reuse_status' => 'transform_required']
            );
        }

        return $this->block('module_03_trait_deep_dive', 'trait_deep_dive', 'domain', ['summary_zh' => 'dry-run domain source'], ['domain_bands'], ['domain_registry:dry_run'], 'standard', 'computed');
    }

    /**
     * @param  array<string,mixed>  $oldReport
     * @param  list<array<string,mixed>>  $dominantCouplings
     * @return array<string,mixed>
     */
    private function couplingBlock(array $oldReport, array $dominantCouplings): array
    {
        if ($oldReport !== [] && $dominantCouplings !== []) {
            return $this->block(
                'module_04_coupling',
                'coupling_cards',
                'coupling',
                ['summary_zh' => 'dry-run transformed coupling source'],
                ['dominant_couplings'],
                ['coupling_registry:dry_run_old_v2_synergy'],
                'standard',
                'computed',
                false,
                'transformed_old_v2_registry',
                'omit_block',
                ['old_v2_group' => 'synergies', 'mapped_registry' => 'coupling_registry', 'reuse_status' => 'transform_required']
            );
        }

        return $this->block('module_04_coupling', 'coupling_cards', 'deferred', ['summary_zh' => 'dry-run coupling deferred'], ['dominant_couplings'], ['coupling_registry:deferred'], 'boundary', 'descriptive', false, 'compatibility_wrapper', 'omit_block');
    }

    /**
     * @param  array<string,mixed>  $oldReport
     * @param  list<array<string,mixed>>  $facetHighlights
     * @return array<string,mixed>
     */
    private function facetBlock(array $oldReport, array $facetHighlights): array
    {
        $facets = array_map(
            static fn (array $facet): array => [
                'facet' => (string) ($facet['facet'] ?? ''),
                'item_count' => max(1, (int) ($facet['item_count'] ?? 1)),
                'confidence' => in_array((string) ($facet['confidence'] ?? ''), ['low', 'medium', 'high'], true) ? (string) $facet['confidence'] : 'low',
                'claim_strength' => 'interpretive_signal',
            ],
            array_slice($facetHighlights, 0, 3)
        );
        if ($facets === []) {
            $facets[] = ['facet' => 'N1', 'item_count' => 1, 'confidence' => 'low', 'claim_strength' => 'interpretive_signal'];
        }

        if ($oldReport !== []) {
            return $this->block(
                'module_05_facet_reframe',
                'facet_reframe',
                'facet',
                ['summary_zh' => 'dry-run transformed facet source', 'facets' => $facets],
                ['facet_highlights'],
                ['facet_registry:dry_run_old_v2_facet_glossary'],
                'standard',
                'computed',
                false,
                'transformed_old_v2_registry',
                'degrade_to_boundary',
                ['old_v2_group' => 'facet_glossary', 'mapped_registry' => 'facet_registry', 'reuse_status' => 'transform_required']
            );
        }

        return $this->block('module_05_facet_reframe', 'facet_reframe', 'facet', ['summary_zh' => 'dry-run facet source', 'facets' => $facets], ['facet_highlights'], ['facet_registry:dry_run'], 'standard', 'computed', false, 'compatibility_wrapper', 'degrade_to_boundary');
    }

    /**
     * @return array{module_key:string,blocks:list<array<string,mixed>>}
     */
    private function module(string $moduleKey, array $blocks): array
    {
        return ['module_key' => $moduleKey, 'blocks' => $blocks];
    }

    /**
     * @param  array<string,mixed>  $content
     * @param  list<string>  $projectionRefs
     * @param  list<string>  $registryRefs
     * @param  array<string,string>|null  $sourceAuthority
     * @return array<string,mixed>
     */
    private function block(
        string $moduleKey,
        string $blockKind,
        string $slot,
        array $content,
        array $projectionRefs,
        array $registryRefs,
        string $safetyLevel,
        string $evidenceLevel,
        bool $shareable = false,
        string $contentSource = 'compatibility_wrapper',
        string $fallbackPolicy = 'backend_required',
        ?array $sourceAuthority = null
    ): array {
        $block = [
            'block_key' => "{$moduleKey}.{$slot}.dry_run.v1",
            'block_kind' => $blockKind,
            'module_key' => $moduleKey,
            'content' => $content,
            'projection_refs' => $projectionRefs,
            'registry_refs' => $registryRefs,
            'safety_level' => $safetyLevel,
            'evidence_level' => $evidenceLevel,
            'shareable' => $shareable,
            'content_source' => $contentSource,
            'fallback_policy' => $fallbackPolicy,
        ];
        if ($sourceAuthority !== null) {
            $block['source_authority'] = $sourceAuthority;
        }

        return $block;
    }

    /**
     * @param  array<string,mixed>  $projectionV1
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $scoresJson
     * @param  array<string,mixed>  $scoresPct
     * @param  array<string,mixed>  $oldReport
     * @return array<string,array<string,mixed>>
     */
    private function buildDomains(array $projectionV1, array $scoreResult, array $scoresJson, array $scoresPct, array $oldReport, bool $normUnavailable): array
    {
        $domains = [];
        foreach (self::DOMAIN_ORDER as $domain) {
            $band = (string) data_get($projectionV1, "trait_bands.{$domain}", data_get($scoreResult, "facts.domain_buckets.{$domain}", data_get($oldReport, "score_vector.domains.{$domain}.band", 'mid')));
            $entry = [
                'band' => $this->normalizeBand($band),
            ];
            if (! $normUnavailable) {
                $entry['score'] = (int) data_get($scoresPct, $domain, data_get($scoreResult, "scores_0_100.domains_percentile.{$domain}", data_get($oldReport, "score_vector.domains.{$domain}.percentile", data_get($scoresJson, "domains_mean.{$domain}", 0))));
                $entry['percentile'] = (int) data_get($scoresPct, $domain, data_get($scoreResult, "scores_0_100.domains_percentile.{$domain}", data_get($oldReport, "score_vector.domains.{$domain}.percentile", 0)));
            }
            $domains[$domain] = $entry;
        }

        return $domains;
    }

    /**
     * @param  array<string,mixed>  $projectionV1
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $oldReport
     * @return array<string,array<string,mixed>>
     */
    private function buildFacets(array $projectionV1, array $scoreResult, array $oldReport, bool $normUnavailable): array
    {
        $facetVector = is_array($projectionV1['facet_vector'] ?? null) ? $projectionV1['facet_vector'] : [];
        $facets = [];
        foreach ($facetVector as $facet) {
            if (! is_array($facet)) {
                continue;
            }
            $key = (string) ($facet['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $entry = [
                'bucket' => $this->normalizeBand((string) ($facet['bucket'] ?? 'mid')),
                'item_count' => (int) ($facet['item_count'] ?? 1),
                'confidence' => (string) ($facet['confidence'] ?? 'low'),
            ];
            if (! $normUnavailable && array_key_exists('percentile', $facet)) {
                $entry['percentile'] = (int) $facet['percentile'];
            }
            $facets[$key] = $entry;
        }
        if ($facets !== []) {
            return $facets;
        }

        $oldFacets = $this->arrayAt($oldReport, 'score_vector.facets');
        foreach ($oldFacets as $key => $facet) {
            if (! is_array($facet)) {
                continue;
            }
            $entry = ['bucket' => 'mid', 'item_count' => 1, 'confidence' => 'low'];
            if (! $normUnavailable && array_key_exists('percentile', $facet)) {
                $entry['percentile'] = (int) $facet['percentile'];
            }
            $facets[(string) $key] = $entry;
        }
        if ($facets !== []) {
            return $facets;
        }

        $facetBuckets = $this->arrayAt($scoreResult, 'facts.facet_buckets');
        foreach ($facetBuckets as $key => $bucket) {
            $facets[(string) $key] = ['bucket' => $this->normalizeBand((string) $bucket), 'item_count' => 1, 'confidence' => 'low'];
        }

        return $facets;
    }

    /**
     * @param  array<string,mixed>  $projectionV1
     * @param  array<string,mixed>  $oldReport
     * @param  array<string,array<string,mixed>>  $facets
     * @return list<array<string,mixed>>
     */
    private function buildFacetHighlights(array $projectionV1, array $oldReport, array $facets): array
    {
        $highlights = [];
        $source = is_array($projectionV1['facet_vector'] ?? null) ? array_slice((array) $projectionV1['facet_vector'], 0, 3) : [];
        if ($source === []) {
            $source = is_array(data_get($oldReport, 'engine_decisions.standout_anomalies')) ? array_slice(data_get($oldReport, 'engine_decisions.standout_anomalies'), 0, 3) : [];
        }
        foreach ($source as $item) {
            if (! is_array($item)) {
                continue;
            }
            $facet = (string) ($item['key'] ?? $item['facet_code'] ?? '');
            if ($facet === '') {
                continue;
            }
            $highlights[] = [
                'facet' => $facet,
                'item_count' => (int) data_get($facets, "{$facet}.item_count", 1),
                'confidence' => (string) data_get($facets, "{$facet}.confidence", 'low'),
                'claim_strength' => 'interpretive_signal',
            ];
        }

        return $highlights;
    }

    /**
     * @param  array<string,mixed>  $oldReport
     * @param  array<string,string>  $domainBands
     * @return list<array<string,mixed>>
     */
    private function buildDominantCouplings(array $oldReport, array $domainBands): array
    {
        $synergies = is_array(data_get($oldReport, 'engine_decisions.selected_synergies')) ? data_get($oldReport, 'engine_decisions.selected_synergies') : [];
        $couplings = [];
        foreach ($synergies as $synergy) {
            if (! is_array($synergy)) {
                continue;
            }
            $key = (string) ($synergy['synergy_id'] ?? '');
            if ($key === '') {
                continue;
            }
            $couplings[] = [
                'coupling_key' => $key,
                'traits' => $this->traitsFromCouplingKey($key),
                'strength' => 'medium',
            ];
        }
        if ($couplings !== []) {
            return $couplings;
        }

        if (($domainBands['N'] ?? '') === 'high' && in_array($domainBands['O'] ?? '', ['mid_high', 'high'], true)) {
            return [['coupling_key' => 'n_high_x_o_mid_high', 'traits' => ['N', 'O'], 'strength' => 'medium']];
        }

        return [];
    }

    /**
     * @param  array<string,string>  $domainBands
     * @param  list<array<string,mixed>>  $dominantCouplings
     */
    private function resolveScope(bool $lowQuality, bool $normUnavailable, array $domainBands, array $dominantCouplings): string
    {
        if ($lowQuality) {
            return 'low_quality';
        }
        if ($normUnavailable) {
            return 'norm_unavailable';
        }
        if ($dominantCouplings !== []) {
            return 'high_tension_profile';
        }
        $uniqueBands = array_unique(array_values($domainBands));
        if (count($uniqueBands) <= 2 && in_array('mid', $uniqueBands, true)) {
            return 'balanced_profile';
        }

        return 'mixed_signature';
    }

    private function normalizeBand(string $band): string
    {
        return match (strtolower(trim($band))) {
            'low_mid', 'mid_low' => 'mid_low',
            'high_mid', 'mid_high' => 'mid_high',
            'high' => 'high',
            'low' => 'low',
            default => 'mid',
        };
    }

    /**
     * @return list<string>
     */
    private function traitsFromCouplingKey(string $key): array
    {
        $traits = [];
        foreach (self::DOMAIN_ORDER as $domain) {
            if (str_contains(strtoupper($key), $domain.'_') || str_contains(strtoupper($key), $domain.'-') || str_starts_with(strtoupper($key), $domain)) {
                $traits[] = $domain;
            }
        }

        return $traits !== [] ? array_values(array_unique($traits)) : ['N', 'O'];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function arrayAt(array $input, string $key): array
    {
        $value = data_get($input, $key);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map('strval', $values), static fn (string $value): bool => trim($value) !== ''));
    }
}
