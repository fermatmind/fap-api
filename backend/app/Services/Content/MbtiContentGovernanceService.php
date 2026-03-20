<?php

declare(strict_types=1);

namespace App\Services\Content;

final class MbtiContentGovernanceService
{
    /**
     * @var list<string>
     */
    private const REQUIRED_LAYERS = [
        'skeleton',
        'intensity',
        'boundary',
        'scene',
        'explainability',
        'action',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_POLICY_FILES = [
        'type_profiles.json',
        'identity_layers.json',
        'report_identity_cards.json',
        'report_roles.json',
        'report_strategies.json',
        'report_highlights.json',
        'report_highlights_policy.json',
        'report_highlights_pools.json',
        'report_highlights_rules.json',
        'report_borderline_notes.json',
        'report_borderline_templates.json',
        'report_cards_traits.json',
        'report_cards_growth.json',
        'report_cards_career.json',
        'report_cards_relationships.json',
        'report_cards_stress_recovery.json',
        'report_cards_fallback_traits.json',
        'report_cards_fallback_growth.json',
        'report_cards_fallback_career.json',
        'report_cards_fallback_relationships.json',
        'report_cards_fallback_stress_recovery.json',
        'report_dynamic_sections.json',
        'report_recommended_reads.json',
        'report_select_rules.json',
        'report_section_policies.json',
        'report_rules.json',
        'report_overrides.json',
    ];

    public function appliesTo(array $pack): bool
    {
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $schema = trim((string) data_get($manifest, 'schemas.content_governance', ''));
        $assetPaths = data_get($manifest, 'assets.content_governance', []);

        return $schema === 'fap.mbti.content_governance.v1'
            || (is_array($assetPaths) && $assetPaths !== []);
    }

    public function governancePath(string $baseDir): string
    {
        return $baseDir.DIRECTORY_SEPARATOR.'report_content_governance.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadDocument(string $baseDir): ?array
    {
        $path = $this->governancePath($baseDir);
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<array{file:string,block_id:string,message:string}>
     */
    public function lintPack(array $pack): array
    {
        $baseDir = trim((string) ($pack['base_dir'] ?? ''));
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $manifestPackId = trim((string) ($pack['pack_id'] ?? ''));
        $context = trim((string) ($manifest['region'] ?? '')).'.'.trim((string) ($manifest['locale'] ?? ''));

        $path = $this->governancePath($baseDir);
        if (! is_file($path)) {
            return [$this->error($path, 'governance.doc', 'MBTI pack requires report_content_governance.json.')];
        }

        $doc = $this->loadDocument($baseDir);
        if (! is_array($doc)) {
            return [$this->error($path, 'governance.doc', 'Invalid governance JSON.')];
        }

        $errors = [];

        if (($doc['schema'] ?? null) !== 'fap.mbti.content_governance.v1') {
            $errors[] = $this->error($path, 'governance.schema', "schema must be 'fap.mbti.content_governance.v1'.");
        }

        if (trim((string) ($doc['pack_id'] ?? '')) !== $manifestPackId) {
            $errors[] = $this->error($path, 'governance.pack_id', 'governance pack_id must match manifest pack_id.');
        }

        if (trim((string) ($doc['cultural_context'] ?? '')) !== $context) {
            $errors[] = $this->error($path, 'governance.cultural_context', 'cultural_context must match manifest region/locale.');
        }

        $taxonomyLayers = is_array(data_get($doc, 'taxonomy.layers')) ? data_get($doc, 'taxonomy.layers') : [];
        $blockKindIndex = [];
        foreach (self::REQUIRED_LAYERS as $layer) {
            $layerNode = is_array($taxonomyLayers[$layer] ?? null) ? $taxonomyLayers[$layer] : null;
            if ($layerNode === null) {
                $errors[] = $this->error($path, "taxonomy.layers.{$layer}", "Missing required governance layer '{$layer}'.");
                continue;
            }

            if (trim((string) ($layerNode['label'] ?? '')) === '') {
                $errors[] = $this->error($path, "taxonomy.layers.{$layer}", "Layer '{$layer}' requires a non-empty label.");
            }

            $blockKinds = array_values(array_filter(array_map('strval', (array) ($layerNode['block_kinds'] ?? []))));
            if ($blockKinds === []) {
                $errors[] = $this->error($path, "taxonomy.layers.{$layer}", "Layer '{$layer}' requires block_kinds.");
                continue;
            }

            foreach ($blockKinds as $blockKind) {
                if (isset($blockKindIndex[$blockKind])) {
                    $errors[] = $this->error(
                        $path,
                        "taxonomy.layers.{$layer}",
                        "Block kind '{$blockKind}' is assigned to multiple layers."
                    );
                    continue;
                }
                $blockKindIndex[$blockKind] = $layer;
            }
        }

        $dynamicDocPath = $baseDir.DIRECTORY_SEPARATOR.'report_dynamic_sections.json';
        $dynamicDoc = $this->loadJsonFile($dynamicDocPath);
        $dynamicBlockKinds = array_keys(is_array(data_get($dynamicDoc, 'labels.block_kinds')) ? data_get($dynamicDoc, 'labels.block_kinds') : []);
        foreach ($dynamicBlockKinds as $blockKind) {
            if (! isset($blockKindIndex[$blockKind])) {
                $errors[] = $this->error(
                    $path,
                    'taxonomy.block_kinds',
                    "Dynamic block kind '{$blockKind}' is missing from governance taxonomy."
                );
            }
        }

        $tierPolicies = is_array($doc['tier_policies'] ?? null) ? $doc['tier_policies'] : [];
        foreach (['stable', 'experiment', 'commercial_overlay'] as $tier) {
            if (! is_array($tierPolicies[$tier] ?? null)) {
                $errors[] = $this->error($path, "tier_policies.{$tier}", "Missing tier policy '{$tier}'.");
            }
        }

        $filePolicies = is_array($doc['file_policies'] ?? null) ? $doc['file_policies'] : [];
        foreach (self::REQUIRED_POLICY_FILES as $fileName) {
            $policy = is_array($filePolicies[$fileName] ?? null) ? $filePolicies[$fileName] : null;
            if ($policy === null) {
                $errors[] = $this->error($path, "file_policies.{$fileName}", "Missing governance policy for {$fileName}.");
                continue;
            }

            $layers = array_values(array_filter(array_map('strval', (array) ($policy['layers'] ?? []))));
            if ($layers === []) {
                $errors[] = $this->error($path, "file_policies.{$fileName}", "{$fileName} must declare at least one layer.");
            }

            foreach ($layers as $layer) {
                if (! in_array($layer, self::REQUIRED_LAYERS, true)) {
                    $errors[] = $this->error($path, "file_policies.{$fileName}", "{$fileName} references unknown layer '{$layer}'.");
                }
            }

            $tier = trim((string) ($policy['content_tier'] ?? ''));
            if ($tier === '' || ! is_array($tierPolicies[$tier] ?? null)) {
                $errors[] = $this->error($path, "file_policies.{$fileName}", "{$fileName} references unknown content_tier '{$tier}'.");
                continue;
            }
            $tierPolicy = is_array($tierPolicies[$tier] ?? null) ? $tierPolicies[$tier] : [];

            $policyIsCanonical = (bool) ($policy['is_canonical'] ?? false);
            $tierIsCanonical = (bool) ($tierPolicy['is_canonical'] ?? false);
            $experimentKey = trim((string) ($policy['experiment_key'] ?? ''));
            $requiresExperimentKey = (bool) ($tierPolicy['requires_experiment_key'] ?? false);

            if ($policyIsCanonical !== $tierIsCanonical) {
                $errors[] = $this->error(
                    $path,
                    "file_policies.{$fileName}",
                    "{$fileName} canonical flag must match tier policy '{$tier}'."
                );
            }

            if ($requiresExperimentKey && $experimentKey === '') {
                $errors[] = $this->error(
                    $path,
                    "file_policies.{$fileName}",
                    "{$fileName} tier '{$tier}' requires experiment_key."
                );
            }

            if (! $requiresExperimentKey && $experimentKey !== '') {
                $errors[] = $this->error(
                    $path,
                    "file_policies.{$fileName}",
                    "{$fileName} stable/commercial policy must not set experiment_key."
                );
            }

            $allowedFiles = array_values(array_filter(array_map('strval', (array) ($tierPolicy['allowed_files'] ?? []))));
            if ($allowedFiles !== [] && ! in_array($fileName, $allowedFiles, true)) {
                $errors[] = $this->error(
                    $path,
                    "file_policies.{$fileName}",
                    "{$fileName} is not allowed to use tier '{$tier}'."
                );
            }

            $allowedTargets = array_values(array_filter(array_map('strval', (array) ($tierPolicy['allowed_targets'] ?? []))));
            $targets = array_values(array_filter(array_map('strval', (array) ($policy['targets'] ?? []))));
            if ($allowedTargets !== []) {
                if ($targets === []) {
                    $errors[] = $this->error(
                        $path,
                        "file_policies.{$fileName}",
                        "{$fileName} tier '{$tier}' requires targets drawn from the allowed target list."
                    );
                }

                foreach ($targets as $target) {
                    if (! in_array($target, $allowedTargets, true)) {
                        $errors[] = $this->error(
                            $path,
                            "file_policies.{$fileName}",
                            "{$fileName} target '{$target}' is not allowed for tier '{$tier}'."
                        );
                    }
                }
            } elseif ($targets !== []) {
                $errors[] = $this->error(
                    $path,
                    "file_policies.{$fileName}",
                    "{$fileName} must not declare targets for tier '{$tier}'."
                );
            }

            if (trim((string) ($policy['cultural_context'] ?? '')) !== $context) {
                $errors[] = $this->error(
                    $path,
                    "file_policies.{$fileName}",
                    "{$fileName} cultural_context must match manifest region/locale."
                );
            }
        }

        $fallback = array_values(array_filter(array_map('strval', (array) data_get($doc, 'locale_guardrails.fallback', []))));
        $manifestFallback = array_values(array_filter(array_map('strval', (array) ($manifest['fallback'] ?? []))));
        if (trim((string) data_get($doc, 'locale_guardrails.region', '')) !== trim((string) ($manifest['region'] ?? ''))) {
            $errors[] = $this->error($path, 'locale_guardrails.region', 'governance region must match manifest region.');
        }
        if (trim((string) data_get($doc, 'locale_guardrails.locale', '')) !== trim((string) ($manifest['locale'] ?? ''))) {
            $errors[] = $this->error($path, 'locale_guardrails.locale', 'governance locale must match manifest locale.');
        }
        if ($fallback !== $manifestFallback) {
            $errors[] = $this->error($path, 'locale_guardrails.fallback', 'governance fallback must match manifest fallback.');
        }

        foreach (self::REQUIRED_POLICY_FILES as $fileName) {
            if (! is_file($baseDir.DIRECTORY_SEPARATOR.$fileName)) {
                $errors[] = $this->error($path, "file_policies.{$fileName}", "{$fileName} is missing from the MBTI pack root.");
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $doc
     * @return array<string,mixed>
     */
    public function compileSpec(array $pack, array $doc): array
    {
        $layers = is_array(data_get($doc, 'taxonomy.layers')) ? data_get($doc, 'taxonomy.layers') : [];
        ksort($layers);

        $blockKindIndex = [];
        foreach ($layers as $layer => $layerNode) {
            foreach ((array) ($layerNode['block_kinds'] ?? []) as $blockKind) {
                $blockKind = trim((string) $blockKind);
                if ($blockKind === '') {
                    continue;
                }
                $blockKindIndex[$blockKind] = (string) $layer;
            }
        }
        ksort($blockKindIndex);

        $filePolicies = is_array($doc['file_policies'] ?? null) ? $doc['file_policies'] : [];
        ksort($filePolicies);

        return [
            'schema' => 'fap.mbti.content_governance.compiled.v1',
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'generated_at' => now()->toIso8601String(),
            'required_layers' => self::REQUIRED_LAYERS,
            'taxonomy' => [
                'layers' => $layers,
                'block_kind_index' => $blockKindIndex,
            ],
            'tier_policies' => (array) ($doc['tier_policies'] ?? []),
            'file_policies' => $filePolicies,
            'locale_guardrails' => (array) ($doc['locale_guardrails'] ?? []),
            'snapshot_fixtures' => (array) ($doc['snapshot_fixtures'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{file:string,block_id:string,message:string}
     */
    private function error(string $file, string $blockId, string $message): array
    {
        return [
            'file' => $file,
            'block_id' => $blockId,
            'message' => $message,
        ];
    }
}
