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
        $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
        if (! is_array($payload)) {
            return ['Missing big5_result_page_v2 payload'];
        }

        return $this->validatePayload($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function validatePayload(array $payload): array
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

        $projection = is_array($payload['projection_v2'] ?? null) ? $payload['projection_v2'] : [];
        $errors = array_merge($errors, $this->validateProjection($projection));

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

            $errors = array_merge($errors, $this->validateModule($module, (int) $index, $scope, $normUnavailable));
            $moduleKey = (string) ($module['module_key'] ?? '');
            if ($moduleKey !== '') {
                if (in_array($moduleKey, $seenModules, true)) {
                    $errors[] = "Duplicate module_key: {$moduleKey}";
                }
                $seenModules[] = $moduleKey;
            }
        }

        foreach (BigFiveResultPageV2Contract::MODULE_KEYS as $requiredModuleKey) {
            if (! in_array($requiredModuleKey, $seenModules, true) && $scope !== 'low_quality') {
                $errors[] = "Missing V2 module {$requiredModuleKey}";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return list<string>
     */
    private function validateProjection(array $projection): array
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

        if ($this->isNormUnavailable($projection)) {
            foreach (['domains', 'facets'] as $field) {
                $this->collectForbiddenKeys((array) ($projection[$field] ?? []), ['percentile', 'percentiles', 'normal_curve'], "projection_v2.{$field}", $errors);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $module
     * @return list<string>
     */
    private function validateModule(array $module, int $index, string $scope, bool $normUnavailable): array
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

            $errors = array_merge($errors, $this->validateBlock($block, $moduleKey, (int) $blockIndex, $scope, $normUnavailable));
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $block
     * @return list<string>
     */
    private function validateBlock(array $block, string $moduleKey, int $blockIndex, string $scope, bool $normUnavailable): array
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
