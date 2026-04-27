<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

final class BigFiveResultPageV2SelectorAssetValidator
{
    /**
     * @param  array<string,mixed>  $asset
     * @return list<string>
     */
    public function validate(array $asset): array
    {
        $errors = [];

        foreach (BigFiveResultPageV2SelectorAssetContract::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $asset)) {
                $errors[] = "selector asset missing {$field}";
            }
        }

        if ((string) ($asset['version'] ?? '') !== BigFiveResultPageV2SelectorAssetContract::SCHEMA_VERSION) {
            $errors[] = 'version must be '.BigFiveResultPageV2SelectorAssetContract::SCHEMA_VERSION;
        }

        $registryKey = (string) ($asset['registry_key'] ?? '');
        $moduleKey = (string) ($asset['module_key'] ?? '');
        $blockKind = (string) ($asset['block_kind'] ?? '');

        if (! in_array($registryKey, BigFiveResultPageV2SelectorAssetContract::REGISTRY_KEYS, true)) {
            $errors[] = "registry_key is invalid: {$registryKey}";
        }
        if (! in_array($moduleKey, BigFiveResultPageV2Contract::MODULE_KEYS, true)) {
            $errors[] = "module_key is invalid: {$moduleKey}";
        }
        if (! in_array($blockKind, BigFiveResultPageV2Contract::BLOCK_KINDS, true)) {
            $errors[] = "block_kind is invalid: {$blockKind}";
        }
        if (! in_array((string) ($asset['content_source'] ?? ''), BigFiveResultPageV2SelectorAssetContract::CONTENT_SOURCES, true)) {
            $errors[] = 'content_source is invalid: '.(string) ($asset['content_source'] ?? '');
        }
        if (! in_array((string) ($asset['review_status'] ?? ''), BigFiveResultPageV2SelectorAssetContract::REVIEW_STATUSES, true)) {
            $errors[] = 'review_status is invalid: '.(string) ($asset['review_status'] ?? '');
        }

        if ($registryKey !== '' && $moduleKey !== '') {
            $allowedModules = BigFiveResultPageV2SelectorAssetContract::REGISTRY_MODULES[$registryKey] ?? [];
            if ($allowedModules !== [] && ! in_array($moduleKey, $allowedModules, true)) {
                $errors[] = "registry_key {$registryKey} is not allowed for module_key {$moduleKey}";
            }
        }
        if ($registryKey !== '' && $blockKind !== '') {
            $allowedBlockKinds = BigFiveResultPageV2SelectorAssetContract::REGISTRY_BLOCK_KINDS[$registryKey] ?? [];
            if ($allowedBlockKinds !== [] && ! in_array($blockKind, $allowedBlockKinds, true)) {
                $errors[] = "registry_key {$registryKey} is not allowed for block_kind {$blockKind}";
            }
        }

        $errors = array_merge($errors, $this->validateKeys($asset, $moduleKey));
        $errors = array_merge($errors, $this->validateSelectorFields($asset));
        $errors = array_merge($errors, $this->validateTrigger($asset));
        $errors = array_merge($errors, $this->validateSafety($asset, $registryKey));

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $assets
     * @return list<string>
     */
    public function validateAssetSet(array $assets): array
    {
        $errors = [];
        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) {
                $errors[] = "selector asset {$index} must be an object";

                continue;
            }

            foreach ($this->validate($asset) as $error) {
                $errors[] = "selector asset {$index}: {$error}";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<string>
     */
    private function validateKeys(array $asset, string $moduleKey): array
    {
        $errors = [];

        foreach (['asset_key', 'block_key', 'slot_key', 'mutual_exclusion_group'] as $field) {
            if ((string) ($asset[$field] ?? '') === '') {
                $errors[] = "{$field} must not be empty";
            }
        }

        $blockKey = (string) ($asset['block_key'] ?? '');
        if ($moduleKey !== '' && $blockKey !== '' && ! str_starts_with($blockKey, $moduleKey.'.')) {
            $errors[] = 'block_key must start with module_key';
        }

        $slotKey = (string) ($asset['slot_key'] ?? '');
        if ($moduleKey !== '' && $slotKey !== '') {
            $allowedPrefixes = BigFiveResultPageV2SelectorAssetContract::MODULE_SLOT_PREFIXES[$moduleKey] ?? [];
            $matchesPrefix = false;
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($slotKey, $prefix)) {
                    $matchesPrefix = true;
                    break;
                }
            }
            if (! $matchesPrefix) {
                $errors[] = "slot_key {$slotKey} is not valid for module_key {$moduleKey}";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<string>
     */
    private function validateSelectorFields(array $asset): array
    {
        $errors = [];
        $priority = $asset['priority'] ?? null;
        if (! is_int($priority) || $priority < 1 || $priority > 100) {
            $errors[] = 'priority must be an integer from 1 to 100';
        }

        foreach (['can_stack_with', 'reading_modes', 'forbidden_public_fields'] as $field) {
            if (! is_array($asset[$field] ?? null)) {
                $errors[] = "{$field} must be an array";
            }
        }
        foreach (['public_payload', 'internal_metadata'] as $field) {
            if (! is_array($asset[$field] ?? null)) {
                $errors[] = "{$field} must be an object";
            }
        }
        foreach (['provenance', 'replacement_policy'] as $field) {
            if (! is_array($asset[$field] ?? null) && ! is_string($asset[$field] ?? null)) {
                $errors[] = "{$field} must be an object or string";
            }
        }

        foreach ((array) ($asset['reading_modes'] ?? []) as $readingMode) {
            if (! in_array($readingMode, BigFiveResultPageV2SelectorAssetContract::READING_MODES, true)) {
                $errors[] = "reading_mode is invalid: {$readingMode}";
            }
        }
        $scenario = (string) (($asset['scenario'] ?? null) ?: 'unspecified');
        if (! in_array($scenario, BigFiveResultPageV2SelectorAssetContract::SCENARIOS, true)) {
            $errors[] = 'scenario is invalid: '.$scenario;
        }
        if (! in_array((string) ($asset['scope'] ?? ''), BigFiveResultPageV2SelectorAssetContract::SCOPES, true)) {
            $errors[] = 'scope is invalid: '.(string) ($asset['scope'] ?? '');
        }
        if (! is_bool($asset['shareable'] ?? null)) {
            $errors[] = 'shareable must be a boolean';
        }
        if (! in_array((string) ($asset['shareable_policy'] ?? ''), BigFiveResultPageV2SelectorAssetContract::SHAREABLE_POLICIES, true)) {
            $errors[] = 'shareable_policy is invalid: '.(string) ($asset['shareable_policy'] ?? '');
        }
        if (! in_array((string) ($asset['fallback_policy'] ?? ''), BigFiveResultPageV2SelectorAssetContract::FALLBACK_POLICIES, true)) {
            $errors[] = 'fallback_policy is invalid: '.(string) ($asset['fallback_policy'] ?? '');
        }
        if (in_array((string) ($asset['fallback_policy'] ?? ''), ['frontend_fallback', 'consumer_generated', 'frontend_authored_interpretation'], true)) {
            $errors[] = 'fallback_policy must not use frontend-authored interpretation fallback';
        }
        $evidenceLevels = array_merge(
            BigFiveResultPageV2Contract::EVIDENCE_LEVELS,
            BigFiveResultPageV2SelectorAssetContract::SELECTOR_EVIDENCE_LEVELS,
        );
        if (! in_array((string) ($asset['required_evidence_level'] ?? ''), $evidenceLevels, true)) {
            $errors[] = 'required_evidence_level is invalid: '.(string) ($asset['required_evidence_level'] ?? '');
        }
        if (! in_array((string) ($asset['evidence_level'] ?? ''), $evidenceLevels, true)) {
            $errors[] = 'evidence_level is invalid: '.(string) ($asset['evidence_level'] ?? '');
        }
        $safetyLevels = array_merge(
            BigFiveResultPageV2Contract::SAFETY_LEVELS,
            BigFiveResultPageV2SelectorAssetContract::SELECTOR_SAFETY_LEVELS,
        );
        if (! in_array((string) ($asset['safety_level'] ?? ''), $safetyLevels, true)) {
            $errors[] = 'safety_level is invalid: '.(string) ($asset['safety_level'] ?? '');
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<string>
     */
    private function validateTrigger(array $asset): array
    {
        $errors = [];
        $trigger = $asset['trigger'] ?? null;
        if (! is_array($trigger) || $trigger === []) {
            return ['trigger must be a non-empty object'];
        }

        foreach (BigFiveResultPageV2SelectorAssetContract::REQUIRED_TRIGGER_KEYS as $field) {
            if (! array_key_exists($field, $trigger)) {
                $errors[] = "trigger missing {$field}";
            }
        }

        $this->collectForbiddenKeys($trigger, ['fixed_type', 'user_confirmed_type', 'diagnosis'], 'trigger', $errors);

        foreach ((array) ($trigger['reading_mode'] ?? []) as $readingMode) {
            if (! in_array($readingMode, (array) ($asset['reading_modes'] ?? []), true)) {
                $errors[] = "trigger reading_mode {$readingMode} must be included in reading_modes";
            }
        }

        $triggerScenarios = (array) ($trigger['scenario'] ?? []);
        $scenario = (string) (($asset['scenario'] ?? null) ?: 'unspecified');
        if ($triggerScenarios !== [] && ! in_array($scenario, $triggerScenarios, true)) {
            $errors[] = 'trigger scenario must include scenario';
        }

        $scope = (string) ($asset['scope'] ?? '');
        if ($scope === 'norm_unavailable' || in_array('norm_unavailable', (array) ($trigger['interpretation_scopes'] ?? []), true)) {
            $this->collectForbiddenKeys($asset, ['percentile', 'percentiles', 'normal_curve', 'show_percentile', 'show_normal_curve'], 'asset', $errors);
        }

        if ($scope === 'low_quality' || in_array('low_quality', (array) ($trigger['interpretation_scopes'] ?? []), true)) {
            if (! in_array((string) ($asset['safety_level'] ?? ''), ['boundary', 'degraded', 'required_boundary'], true)) {
                $errors[] = 'low_quality selector assets must use boundary/degraded/required_boundary safety level';
            }
            if (! in_array((string) ($asset['fallback_policy'] ?? ''), ['backend_required', 'degrade_to_boundary', 'boundary_only', 'neutral_unavailable'], true)) {
                $errors[] = 'low_quality selector assets must use backend_required/degrade_to_boundary/boundary_only/neutral_unavailable fallback policy';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<string>
     */
    private function validateSafety(array $asset, string $registryKey): array
    {
        $errors = [];
        $publicPayload = is_array($asset['public_payload'] ?? null) ? $asset['public_payload'] : [];
        $this->collectForbiddenKeys($publicPayload, BigFiveResultPageV2SelectorAssetContract::FORBIDDEN_PUBLIC_FIELDS, 'public_payload', $errors);

        $assetText = strtolower(json_encode([
            'trigger' => $asset['trigger'] ?? [],
            'public_payload' => $publicPayload,
            'replacement_policy' => $asset['replacement_policy'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        foreach (['fixed type', 'fixed_type', 'user_confirmed_type', 'diagnosis', 'clinical diagnosis', 'hiring screen'] as $forbiddenPhrase) {
            if (str_contains($assetText, $forbiddenPhrase)) {
                $errors[] = "selector asset contains forbidden phrase: {$forbiddenPhrase}";
            }
        }
        if (str_contains($assetText, 'frontend_fallback') || str_contains($assetText, 'frontend-authored interpretation')) {
            $errors[] = 'selector asset must not reference frontend-authored interpretation fallback';
        }

        if ($registryKey === 'profile_signature_registry') {
            $signaturePolicy = (string) data_get($asset, 'trigger.signature_policy', '');
            if ($signaturePolicy !== '' && $signaturePolicy !== 'auxiliary_label_only') {
                $errors[] = 'profile_signature_registry assets must use auxiliary_label_only signature_policy';
            }
        }

        if ($registryKey === 'observation_feedback_registry') {
            $this->collectForbiddenKeys($asset, ['user_confirmed_type'], 'asset', $errors);
        }

        if ($registryKey === 'facet_pattern_registry') {
            $inferenceOnly = (bool) data_get($asset, 'trigger.facet_support.inference_only', false);
            $hasItemCount = is_int(data_get($asset, 'trigger.facet_support.item_count')) && (int) data_get($asset, 'trigger.facet_support.item_count') > 0;
            $hasConfidence = in_array((string) data_get($asset, 'trigger.facet_support.confidence'), ['low', 'medium', 'high'], true);
            if (! $inferenceOnly && (! $hasItemCount || ! $hasConfidence)) {
                $errors[] = 'facet_pattern_registry assets require item_count and confidence or inference_only=true';
            }
            if ((string) data_get($asset, 'trigger.facet_support.claim_strength') === 'independent_measurement') {
                $errors[] = 'facet_pattern_registry assets must not claim independent measurement';
            }
        }

        if (($asset['shareable'] ?? false) === true) {
            if (! in_array((string) ($asset['shareable_policy'] ?? ''), ['share_safe_behavioral_only', 'required_for_every_shareable_true_block'], true)) {
                $errors[] = 'shareable=true selector assets require share-safe policy';
            }
            $this->collectForbiddenKeys($asset, BigFiveResultPageV2Contract::SHARE_FORBIDDEN_SCORE_FIELDS, 'shareable_asset', $errors);
        }

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
                $errors[] = "Forbidden field {$nextPath}";
            }
            if (is_array($value)) {
                $this->collectForbiddenKeys($value, $forbiddenKeys, $nextPath, $errors);
            }
        }
    }
}
