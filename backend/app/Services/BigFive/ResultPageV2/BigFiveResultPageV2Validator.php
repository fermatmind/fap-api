<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

final class BigFiveResultPageV2Validator
{
    /**
     * @param  array<string,mixed>  $envelope
     * @return list<string>
     */
    public function validateEnvelope(array $envelope): array
    {
        return $this->validateEnvelopeWithMode($envelope, false);
    }

    /**
     * Runtime/public attachment must use this stricter boundary so a caller
     * cannot bypass production semantics by changing content_version.
     *
     * @param  array<string,mixed>  $envelope
     * @return list<string>
     */
    public function validateProductionEnvelope(array $envelope): array
    {
        return $this->validateEnvelopeWithMode($envelope, true);
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return list<string>
     */
    private function validateEnvelopeWithMode(array $envelope, bool $production): array
    {
        $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
        if (! is_array($payload)) {
            return ['Missing big5_result_page_v2 payload'];
        }

        return $this->validatePayload($payload, $production);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function validatePayload(array $payload, bool $production = false): array
    {
        $errors = [];
        $errors = array_merge($errors, $this->validatePublicFieldBoundary($payload));

        if ((string) ($payload['schema_version'] ?? '') !== BigFiveResultPageV2Contract::SCHEMA_VERSION) {
            $errors[] = 'big5_result_page_v2.schema_version must be '.BigFiveResultPageV2Contract::SCHEMA_VERSION;
        }
        if ((string) ($payload['payload_key'] ?? '') !== BigFiveResultPageV2Contract::PAYLOAD_KEY) {
            $errors[] = 'big5_result_page_v2.payload_key must be '.BigFiveResultPageV2Contract::PAYLOAD_KEY;
        }
        if ((string) ($payload['scale_code'] ?? '') !== BigFiveResultPageV2Contract::SCALE_CODE) {
            $errors[] = 'big5_result_page_v2.scale_code must be BIG5_OCEAN';
        }

        $strictRuntime = $production;
        if ($production && (string) ($payload['content_version'] ?? '') !== 'big5_result_page_v2.runtime.v2') {
            $errors[] = 'Production payload content_version must be big5_result_page_v2.runtime.v2';
        }
        if ($strictRuntime) {
            $this->collectForbiddenKeys($payload, [
                'fixture_key',
                'production_use_allowed',
                'runtime_use',
                'ready_for_pilot',
                'ready_for_runtime',
                'ready_for_production',
            ], 'big5_result_page_v2', $errors);
        }
        $projection = is_array($payload['projection_v2'] ?? null) ? $payload['projection_v2'] : [];
        $errors = array_merge($errors, $this->validateProjection($projection, $strictRuntime));

        $scope = (string) ($projection['interpretation_scope'] ?? '');
        $normUnavailable = $this->isNormUnavailable($projection);

        $modules = is_array($payload['modules'] ?? null) ? $payload['modules'] : null;
        if (! is_array($modules)) {
            $errors[] = 'big5_result_page_v2.modules must be an array';

            return $errors;
        }

        $seenModules = [];
        foreach ($modules as $index => $module) {
            if (! is_array($module)) {
                $errors[] = "Module {$index} must be an object";

                continue;
            }

            $errors = array_merge($errors, $this->validateModule($module, (int) $index, $scope, $normUnavailable, $strictRuntime));
            $moduleKey = (string) ($module['module_key'] ?? '');
            if ($moduleKey !== '') {
                if (in_array($moduleKey, $seenModules, true)) {
                    $errors[] = "Duplicate module_key: {$moduleKey}";
                }
                $seenModules[] = $moduleKey;
            }
        }

        if ($strictRuntime) {
            $errors = array_merge($errors, $this->validateSemanticContract($payload));
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return list<string>
     */
    private function validateProjection(array $projection, bool $strictRuntime): array
    {
        $errors = [];
        if ((string) ($projection['schema_version'] ?? '') !== BigFiveResultPageV2Contract::PROJECTION_SCHEMA_VERSION) {
            $errors[] = 'projection_v2.schema_version must be '.BigFiveResultPageV2Contract::PROJECTION_SCHEMA_VERSION;
        }
        if ((string) ($projection['scale_code'] ?? '') !== BigFiveResultPageV2Contract::SCALE_CODE) {
            $errors[] = 'projection_v2.scale_code must be BIG5_OCEAN';
        }

        foreach (BigFiveResultPageV2Contract::REQUIRED_PROJECTION_FIELDS as $field) {
            if (! array_key_exists($field, $projection)) {
                $errors[] = "projection_v2 missing {$field}";
            }
        }
        if ($strictRuntime && trim((string) ($projection['attempt_id'] ?? '')) === '') {
            $errors[] = 'projection_v2.attempt_id must be an actual non-empty attempt id';
        }
        if ($strictRuntime && str_contains(strtolower((string) ($projection['attempt_id'] ?? '')), 'pilot_fixture')) {
            $errors[] = 'projection_v2.attempt_id must not use a pilot fixture identity';
        }
        if ($strictRuntime && trim((string) ($projection['result_version'] ?? '')) === '') {
            $errors[] = 'projection_v2.result_version must be an actual non-empty result version';
        }
        if ($strictRuntime && ! in_array((string) ($projection['form_code'] ?? ''), ['big5_90', 'big5_120'], true)) {
            $errors[] = 'projection_v2.form_code must be big5_90 or big5_120';
        }
        if ($strictRuntime && ! in_array((string) ($projection['quality_status'] ?? ''), ['valid', 'degraded'], true)) {
            $errors[] = 'projection_v2.quality_status must be valid or degraded';
        }
        if ($strictRuntime && ! in_array((string) ($projection['norm_status'] ?? ''), ['CALIBRATED', 'PROVISIONAL', 'UNAVAILABLE'], true)) {
            $errors[] = 'projection_v2.norm_status is invalid';
        }

        $scope = (string) ($projection['interpretation_scope'] ?? '');
        if (! in_array($scope, BigFiveResultPageV2Contract::INTERPRETATION_SCOPES, true)) {
            $errors[] = "projection_v2.interpretation_scope is invalid: {$scope}";
        }
        foreach (['public_fields', 'internal_only_fields', 'quality_flags', 'confidence_flags', 'safety_flags'] as $field) {
            if (! is_array($projection[$field] ?? null)) {
                $errors[] = "projection_v2.{$field} must be an array";
            }
        }

        $signature = is_array($projection['profile_signature'] ?? null) ? $projection['profile_signature'] : [];
        if (($signature['is_fixed_type'] ?? false) === true) {
            $errors[] = 'profile_signature must not be marked as a fixed type';
        }
        if (strtolower((string) ($signature['system'] ?? '')) === 'type') {
            $errors[] = 'profile_signature.system must not be type';
        }

        if (! $strictRuntime && $this->isNormUnavailable($projection)) {
            foreach (['domains', 'facets'] as $field) {
                $this->collectForbiddenKeys((array) ($projection[$field] ?? []), ['score', 'percentile', 'percentiles', 'normal_curve'], "projection_v2.{$field}", $errors);
            }
        }

        if ($strictRuntime) {
            $domains = is_array($projection['domains'] ?? null) ? $projection['domains'] : [];
            $expectedDomains = ['O', 'C', 'E', 'A', 'N'];
            if (array_keys($domains) !== $expectedDomains) {
                $errors[] = 'projection_v2.domains must contain ordered O,C,E,A,N exactly once';
            }
            foreach ($expectedDomains as $domain) {
                $entry = is_array($domains[$domain] ?? null) ? $domains[$domain] : [];
                $score = $entry['score'] ?? null;
                if (! is_int($score) || $score < 0 || $score > 100) {
                    $errors[] = "projection_v2.domains.{$domain}.score must be an integer within 0..100";

                    continue;
                }
                $expectedBand = match (true) {
                    $score < 20 => 'very_low',
                    $score < 40 => 'low',
                    $score < 60 => 'mid',
                    $score < 80 => 'high',
                    default => 'very_high',
                };
                if (($entry['band'] ?? null) !== $expectedBand || ($projection['domain_bands'][$domain] ?? null) !== $expectedBand) {
                    $errors[] = "projection_v2.domains.{$domain} score/band route mismatch";
                }
            }

            if (($projection['percentile_display_allowed'] ?? false) !== true) {
                $this->collectForbiddenKeys($projection, ['percentile', 'percentiles'], 'projection_v2', $errors);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $module
     * @return list<string>
     */
    private function validateModule(array $module, int $index, string $scope, bool $normUnavailable, bool $strictRuntime): array
    {
        $errors = [];
        $moduleKey = (string) ($module['module_key'] ?? '');
        if (! in_array($moduleKey, BigFiveResultPageV2Contract::MODULE_KEYS, true)) {
            $errors[] = "Unknown module_key: {$moduleKey}";
        }

        if ($scope === 'low_quality' && ! in_array($moduleKey, BigFiveResultPageV2Contract::LOW_QUALITY_ALLOWED_MODULE_KEYS, true)) {
            $errors[] = "low_quality payload must not expose {$moduleKey}";
        }

        $blocks = is_array($module['blocks'] ?? null) ? $module['blocks'] : [];
        if ($blocks === []) {
            $errors[] = "Module {$moduleKey} must include at least one block";
        }
        foreach ($blocks as $blockIndex => $block) {
            if (! is_array($block)) {
                $errors[] = "Module {$index} block {$blockIndex} must be an object";

                continue;
            }

            $errors = array_merge($errors, $this->validateBlock($block, $moduleKey, (int) $blockIndex, $scope, $normUnavailable, $strictRuntime));
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $block
     * @return list<string>
     */
    private function validateBlock(array $block, string $moduleKey, int $blockIndex, string $scope, bool $normUnavailable, bool $strictRuntime): array
    {
        $errors = [];
        foreach (['block_key', 'block_kind', 'module_key', 'content', 'projection_refs', 'registry_refs', 'safety_level', 'evidence_level', 'shareable', 'content_source'] as $field) {
            if (! array_key_exists($field, $block)) {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} missing {$field}";
            }
        }

        if ((string) ($block['module_key'] ?? '') !== $moduleKey) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} module_key mismatch";
        }
        $blockKey = (string) ($block['block_key'] ?? '');
        if ($blockKey !== '' && $moduleKey !== '' && ! str_starts_with($blockKey, $moduleKey.'.')) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} block_key must start with module_key";
        }
        foreach (['content', 'projection_refs', 'registry_refs'] as $field) {
            if (! is_array($block[$field] ?? null)) {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} {$field} must be an array";
            }
        }

        $content = is_array($block['content'] ?? null) ? $block['content'] : [];
        if ($strictRuntime && ! $this->hasMeaningfulContent($content)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} content must not be empty";
        }
        if ($strictRuntime && $this->containsPlaceholder($content)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} contains placeholder or deferred content";
        }

        $blockKind = (string) ($block['block_kind'] ?? '');
        if (! in_array($blockKind, BigFiveResultPageV2Contract::BLOCK_KINDS, true)) {
            $errors[] = "Unknown block_kind: {$blockKind}";
        }

        $safetyLevel = (string) ($block['safety_level'] ?? '');
        if (! in_array($safetyLevel, BigFiveResultPageV2Contract::SAFETY_LEVELS, true)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} safety_level is invalid: {$safetyLevel}";
        }

        $evidenceLevel = (string) ($block['evidence_level'] ?? '');
        if (! in_array($evidenceLevel, BigFiveResultPageV2Contract::EVIDENCE_LEVELS, true)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} evidence_level is invalid: {$evidenceLevel}";
        }

        $errors = array_merge($errors, $this->validateSourceAuthority($block, $moduleKey, $blockIndex, $blockKind));

        if ($scope === 'low_quality' && ! in_array($safetyLevel, ['boundary', 'degraded'], true)) {
            $errors[] = "low_quality block {$block['block_key']} must use boundary/degraded safety level";
        }

        if ($normUnavailable) {
            $this->collectForbiddenKeys($block, ['percentile', 'percentiles', 'normal_curve', 'show_percentile', 'show_normal_curve'], (string) ($block['block_key'] ?? $moduleKey), $errors);
        }

        if (($block['shareable'] ?? false) === true) {
            $this->collectForbiddenKeys($block, BigFiveResultPageV2Contract::SHARE_FORBIDDEN_SCORE_FIELDS, (string) ($block['block_key'] ?? $moduleKey), $errors);
        }

        if ($blockKind === 'facet_reframe') {
            $errors = array_merge($errors, $this->validateFacetReframeBlock($block));
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateSemanticContract(array $payload): array
    {
        $errors = [];
        $seenBlockKeys = [];
        $seenSemanticSlots = [];
        $seenVisibleText = [];
        $traitBarCounts = array_fill_keys(['O', 'C', 'E', 'A', 'N'], 0);
        $facetPolarities = [];
        $projection = (array) ($payload['projection_v2'] ?? []);
        if (($payload['canonical_profile_key'] ?? null) !== data_get($projection, 'profile_signature.signature_key')) {
            $errors[] = 'canonical profile must match projection profile route';
        }
        $facetBuckets = [];
        foreach ((array) ($projection['facet_highlights'] ?? []) as $highlight) {
            if (is_array($highlight)) {
                $key = strtoupper((string) ($highlight['key'] ?? ''));
                if ($key !== '') {
                    $facetBuckets[$key] = strtolower((string) ($highlight['bucket'] ?? ''));
                }
            }
        }

        foreach ((array) ($payload['modules'] ?? []) as $module) {
            if (! is_array($module)) {
                continue;
            }
            foreach ((array) ($module['blocks'] ?? []) as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $blockKey = (string) ($block['block_key'] ?? '');
                if ($blockKey !== '') {
                    if (isset($seenBlockKeys[$blockKey])) {
                        $errors[] = "Duplicate block_key: {$blockKey}";
                    }
                    $seenBlockKeys[$blockKey] = true;
                }

                $slot = $this->semanticSlot($block);
                if ($slot !== '') {
                    if (isset($seenSemanticSlots[$slot])) {
                        $errors[] = "Multiple candidates for semantic slot: {$slot}";
                    }
                    $seenSemanticSlots[$slot] = true;
                }

                $kind = (string) ($block['block_kind'] ?? '');
                $content = (array) ($block['content'] ?? []);
                if (in_array($kind, ['trait_bars', 'trait_deep_dive'], true)) {
                    $trait = strtoupper((string) data_get($content, 'trait.code', ''));
                    if ($kind === 'trait_bars' && array_key_exists($trait, $traitBarCounts)) {
                        $traitBarCounts[$trait]++;
                    }
                    $contentBand = strtolower((string) data_get($content, 'band.internal_band', ''));
                    $projectionBand = strtolower((string) data_get($projection, "domains.{$trait}.band", ''));
                    if ($trait !== '' && $contentBand !== '' && $contentBand !== $projectionBand) {
                        $errors[] = "{$kind} {$trait} band does not match projection";
                    }
                }

                if ($kind === 'facet_reframe') {
                    $facet = strtoupper((string) ($content['facet_key'] ?? ''));
                    $polarity = strtolower((string) ($content['facet_direction'] ?? ''));
                    if ($facet !== '' && in_array($polarity, ['high', 'low'], true)) {
                        $facetPolarities[$facet][$polarity] = true;
                        $bucket = $facetBuckets[$facet] ?? '';
                        $expected = in_array($bucket, ['high', 'very_high'], true)
                            ? 'high'
                            : (in_array($bucket, ['low', 'very_low'], true) ? 'low' : '');
                        if ($expected === '' || $expected !== $polarity) {
                            $errors[] = "facet {$facet} polarity does not match projection bucket";
                        }
                    }
                }

                foreach ($this->visibleProse($content) as $text) {
                    $normalized = $this->normalizeVisibleText($text);
                    if ($normalized === '') {
                        continue;
                    }
                    if (isset($seenVisibleText[$normalized])) {
                        $errors[] = 'Duplicate normalized visible content';
                    }
                    $seenVisibleText[$normalized] = true;
                }
            }
        }

        foreach ($traitBarCounts as $trait => $count) {
            if ($count !== 1) {
                $errors[] = "trait_bars must contain {$trait} exactly once";
            }
        }
        foreach ($facetPolarities as $facet => $polarities) {
            if (isset($polarities['high'], $polarities['low'])) {
                $errors[] = "facet {$facet} must not contain both high and low polarity";
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $block */
    private function semanticSlot(array $block): string
    {
        $module = (string) ($block['module_key'] ?? '');
        $kind = (string) ($block['block_kind'] ?? '');
        $content = (array) ($block['content'] ?? []);

        return match ($kind) {
            'trait_bars', 'trait_deep_dive' => "{$module}:{$kind}:".strtoupper((string) data_get($content, 'trait.code', '')),
            'coupling_cards' => "{$module}:{$kind}:".(string) ($content['coupling_key'] ?? ''),
            'facet_reframe' => "{$module}:{$kind}:".strtoupper((string) ($content['facet_key'] ?? '')),
            'application_matrix', 'collaboration_manual' => "{$module}:{$kind}:".(string) ($content['scenario'] ?? ''),
            default => "{$module}:{$kind}",
        };
    }

    /** @param array<int|string,mixed> $content */
    private function hasMeaningfulContent(array $content): bool
    {
        foreach ($content as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                return true;
            }
            if (is_array($value) && $this->hasMeaningfulContent($value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int|string,mixed> $content */
    private function containsPlaceholder(array $content): bool
    {
        foreach ($content as $value) {
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                foreach (['pending_asset_resolution', 'placeholder', 'dry-run deferred', 'deferred', '此模块暂未启用', 'this module is not available yet'] as $marker) {
                    if (str_contains($normalized, strtolower($marker))) {
                        return true;
                    }
                }
            }
            if (is_array($value) && $this->containsPlaceholder($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string,mixed>  $content
     * @return list<string>
     */
    private function visibleProse(array $content): array
    {
        $visibleKeys = [
            'title', 'title_zh', 'title_en', 'summary', 'summary_zh', 'summary_en',
            'body', 'body_zh', 'body_en', 'short_body', 'short_body_zh', 'short_body_en',
            'benefit', 'benefit_zh', 'benefit_en', 'cost', 'cost_zh', 'cost_en',
            'action', 'action_zh', 'action_en', 'repair', 'repair_zh', 'repair_en',
            'common_misread', 'common_misread_zh', 'common_misread_en',
        ];
        $texts = [];
        foreach ($content as $key => $value) {
            if (is_string($value) && in_array((string) $key, $visibleKeys, true) && trim($value) !== '') {
                $texts[] = $value;
            }
            if (is_array($value)) {
                $texts = [...$texts, ...$this->visibleProse($value)];
            }
        }

        return $texts;
    }

    private function normalizeVisibleText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($text));
        if (! is_string($normalized) || mb_strlen($normalized) < 8) {
            return '';
        }

        return mb_strtolower($normalized);
    }

    /**
     * @param  array<string,mixed>  $block
     * @return list<string>
     */
    private function validateSourceAuthority(array $block, string $moduleKey, int $blockIndex, string $blockKind): array
    {
        $errors = [];
        $contentSource = (string) ($block['content_source'] ?? '');
        if (! in_array($contentSource, BigFiveResultPageV2SourceAuthorityMap::CONTENT_SOURCES, true)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} content_source is invalid: {$contentSource}";
        }

        $registryRefs = is_array($block['registry_refs'] ?? null) ? $block['registry_refs'] : [];
        foreach ($registryRefs as $refIndex => $registryRef) {
            if (! is_string($registryRef) || ! str_contains($registryRef, ':')) {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} registry_refs.{$refIndex} must use registry_key:item_key";

                continue;
            }

            [$registryKey] = explode(':', $registryRef, 2);
            if (in_array($registryKey, BigFiveResultPageV2SourceAuthorityMap::OLD_V2_DIRECT_PREFIXES, true)) {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} must not reference {$registryKey} directly; use transformed_old_v2_registry with a mapped V2.0 registry";

                continue;
            }

            if (! BigFiveResultPageV2SourceAuthorityMap::isKnownRegistryKey($registryKey)) {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} registry_ref uses unknown registry: {$registryKey}";

                continue;
            }

            if (! BigFiveResultPageV2SourceAuthorityMap::registryAllowsModule($registryKey, $moduleKey)) {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} registry {$registryKey} is not allowed for module {$moduleKey}";
            }
        }

        $registryKeys = array_map(
            static fn (string $registryRef): string => explode(':', $registryRef, 2)[0],
            array_values(array_filter($registryRefs, static fn ($registryRef): bool => is_string($registryRef) && str_contains($registryRef, ':')))
        );
        if (($block['shareable'] ?? false) === true && ! in_array('share_safety_registry', $registryKeys, true)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} shareable block must reference share_safety_registry";
        }

        if ($contentSource === 'transformed_old_v2_registry') {
            $errors = array_merge($errors, $this->validateTransformedOldV2Source($block, $moduleKey, $blockIndex, $registryKeys));
        }

        $fallbackPolicy = (string) ($block['fallback_policy'] ?? '');
        if ($fallbackPolicy !== '' && ! in_array($fallbackPolicy, BigFiveResultPageV2SourceAuthorityMap::FALLBACK_POLICIES, true)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} fallback_policy is invalid: {$fallbackPolicy}";
        }
        if (in_array($blockKind, ['trust_bar', 'method_boundary'], true)) {
            if ($fallbackPolicy === '') {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} {$blockKind} requires fallback_policy";
            }
            if (in_array($fallbackPolicy, ['frontend_fallback', 'consumer_generated'], true)) {
                $errors[] = "{$moduleKey}.blocks.{$blockIndex} {$blockKind} must not use frontend fallback";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  list<string>  $registryKeys
     * @return list<string>
     */
    private function validateTransformedOldV2Source(array $block, string $moduleKey, int $blockIndex, array $registryKeys): array
    {
        $errors = [];
        $sourceAuthority = is_array($block['source_authority'] ?? null) ? $block['source_authority'] : null;
        if (! is_array($sourceAuthority)) {
            return ["{$moduleKey}.blocks.{$blockIndex} transformed_old_v2_registry requires source_authority"];
        }

        $oldV2Group = (string) ($sourceAuthority['old_v2_group'] ?? '');
        $mappedRegistry = (string) ($sourceAuthority['mapped_registry'] ?? '');
        $reuseStatus = (string) ($sourceAuthority['reuse_status'] ?? '');
        if (! BigFiveResultPageV2SourceAuthorityMap::isKnownOldV2Group($oldV2Group)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} source_authority.old_v2_group is invalid: {$oldV2Group}";
        }
        if (! BigFiveResultPageV2SourceAuthorityMap::isKnownRegistryKey($mappedRegistry)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} source_authority.mapped_registry is invalid: {$mappedRegistry}";
        }
        if ($oldV2Group !== '' && $mappedRegistry !== '' && ! BigFiveResultPageV2SourceAuthorityMap::oldV2GroupTargetsRegistry($oldV2Group, $mappedRegistry)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} source_authority {$oldV2Group} does not map to {$mappedRegistry}";
        }
        if ($mappedRegistry !== '' && ! in_array($mappedRegistry, $registryKeys, true)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} source_authority.mapped_registry must also appear in registry_refs";
        }
        if (! in_array($reuseStatus, BigFiveResultPageV2SourceAuthorityMap::OLD_V2_ALLOWED_REUSE_STATUSES, true)) {
            $errors[] = "{$moduleKey}.blocks.{$blockIndex} source_authority.reuse_status is invalid: {$reuseStatus}";
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $block
     * @return list<string>
     */
    private function validateFacetReframeBlock(array $block): array
    {
        $errors = [];
        $facets = is_array(data_get($block, 'content.facets')) ? data_get($block, 'content.facets') : [];
        foreach ($facets as $index => $facet) {
            if (! is_array($facet)) {
                $errors[] = "facet_reframe facet {$index} must be an object";

                continue;
            }

            if (! array_key_exists('item_count', $facet) || (int) ($facet['item_count'] ?? 0) <= 0) {
                $errors[] = "facet_reframe facet {$index} missing item_count";
            }
            if (! in_array((string) ($facet['confidence'] ?? ''), ['low', 'medium', 'high'], true)) {
                $errors[] = "facet_reframe facet {$index} missing confidence";
            }
            if ((string) ($facet['claim_strength'] ?? '') === 'independent_measurement') {
                $errors[] = "facet_reframe facet {$index} must not claim independent measurement";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $projection
     */
    private function isNormUnavailable(array $projection): bool
    {
        $scope = (string) ($projection['interpretation_scope'] ?? '');
        $status = strtoupper((string) ($projection['norm_status'] ?? ''));

        return $scope === 'norm_unavailable' || in_array($status, ['MISSING', 'UNAVAILABLE'], true);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validatePublicFieldBoundary(array $payload): array
    {
        $errors = [];
        $this->collectForbiddenKeys($payload, BigFiveResultPageV2Contract::FORBIDDEN_PUBLIC_FIELDS, 'big5_result_page_v2', $errors);

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $forbiddenKeys
     * @param  list<string>  $errors
     */
    private function collectForbiddenKeys(array $payload, array $forbiddenKeys, string $path, array &$errors): void
    {
        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            $nextPath = $path.'.'.$keyString;
            if (in_array($keyString, $forbiddenKeys, true)) {
                $errors[] = "Forbidden public field {$nextPath}";
            }
            if (is_array($value)) {
                $this->collectForbiddenKeys($value, $forbiddenKeys, $nextPath, $errors);
            }
        }
    }
}
