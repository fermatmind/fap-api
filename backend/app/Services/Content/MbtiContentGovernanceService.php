<?php

declare(strict_types=1);

namespace App\Services\Content;

final class MbtiContentGovernanceService
{
    private const INVENTORY_FILE = 'mbti_content_inventory.json';

    private const INVENTORY_SCHEMA = 'fap.mbti.content_inventory.v1';

    /**
     * @var list<string>
     */
    private const REQUIRED_FRAGMENT_FAMILIES = [
        'explainability_fragment',
        'boundary_fragment',
        'stress_fragment',
        'recovery_fragment',
        'scene_fragment',
        'work_fragment',
        'relationship_fragment',
        'action_fragment',
        'watchout_fragment',
        'recommendation_fragment',
        'cta_bundle_fragment',
        'tone_fragment',
        'revisit_fragment',
        'adaptive_response_fragment',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_SELECTION_TAG_KEYS = [
        'section_key',
        'block_family',
        'axis_band',
        'boundary_flag',
        'scene_key',
        'intent_cluster',
        'memory_state',
        'adaptive_state',
        'cross_assessment_key',
        'tone_mode',
        'access_tier',
        'locale_scope',
        'evidence_ref',
        'cta_intent',
        'cta_softness_mode',
        'cta_entry_reason',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_OBJECTIZED_FRAGMENT_GROUPS = [
        'scene_fragment',
        'action_fragment',
        'narrative_fragment',
        'faq_explainability_copy',
        'tone_fragment',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_SECTION_KEYS = [
        'overview',
        'trait_overview',
        'traits.why_this_type',
        'growth.summary',
        'growth.stability_confidence',
        'traits.adjacent_type_contrast',
        'growth.next_actions',
        'growth.watchouts',
        'career.summary',
        'career.next_step',
        'career.work_experiments',
        'relationships.summary',
        'relationships.try_this_week',
        'recommendation.surface',
        'cta.surface',
    ];

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
        'commercial_spec.json',
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

    public function inventoryPath(string $baseDir): string
    {
        return $baseDir.DIRECTORY_SEPARATOR.self::INVENTORY_FILE;
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
     * @return array<string,mixed>|null
     */
    public function loadInventoryDocument(string $baseDir): ?array
    {
        $path = $this->inventoryPath($baseDir);
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

        $inventoryPath = $this->inventoryPath($baseDir);
        if (! is_file($inventoryPath)) {
            $errors[] = $this->error($path, 'inventory.doc', 'MBTI pack requires mbti_content_inventory.json.');
        } else {
            $inventoryDoc = $this->loadInventoryDocument($baseDir);
            if (! is_array($inventoryDoc)) {
                $errors[] = $this->error($inventoryPath, 'inventory.doc', 'Invalid inventory JSON.');
            } else {
                $errors = array_merge($errors, $this->lintInventory($inventoryPath, $inventoryDoc, $manifest, $context));
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
    public function compileInventorySpec(array $pack, array $doc): array
    {
        $fragmentFamilies = is_array($doc['fragment_families'] ?? null) ? array_values($doc['fragment_families']) : [];
        $fragmentObjectGroups = is_array($doc['fragment_object_groups'] ?? null) ? array_values($doc['fragment_object_groups']) : [];
        $selectionTagSchema = is_array($doc['selection_tag_schema'] ?? null) ? $doc['selection_tag_schema'] : [];
        $sectionFamilyMatrix = is_array($doc['section_family_matrix'] ?? null) ? array_values($doc['section_family_matrix']) : [];

        return [
            'schema' => 'fap.mbti.content_inventory.compiled.v1',
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'generated_at' => now()->toIso8601String(),
            'inventory_contract_version' => (int) ($doc['inventory_contract_version'] ?? 1),
            'inventory_fingerprint' => (string) ($doc['inventory_fingerprint'] ?? ''),
            'governance_profile' => (string) ($doc['governance_profile'] ?? 'mbti_content_inventory.v1'),
            'fragment_families' => $fragmentFamilies,
            'fragment_object_groups' => $fragmentObjectGroups,
            'selection_tag_schema' => $selectionTagSchema,
            'section_family_matrix' => $sectionFamilyMatrix,
            'summary' => $this->summarizeInventory($doc),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeInventory(array $doc): array
    {
        $fragmentFamilies = array_values(array_filter((array) ($doc['fragment_families'] ?? []), 'is_array'));
        $fragmentObjectGroups = array_values(array_filter((array) ($doc['fragment_object_groups'] ?? []), 'is_array'));
        $selectionTagSchema = is_array($doc['selection_tag_schema'] ?? null) ? $doc['selection_tag_schema'] : [];
        $sectionFamilyMatrix = array_values(array_filter((array) ($doc['section_family_matrix'] ?? []), 'is_array'));

        $fragmentFamilyKeys = [];
        foreach ($fragmentFamilies as $family) {
            $key = trim((string) ($family['key'] ?? ''));
            if ($key !== '') {
                $fragmentFamilyKeys[] = $key;
            }
        }

        $sectionKeys = [];
        foreach ($sectionFamilyMatrix as $row) {
            $key = trim((string) ($row['section_key'] ?? ''));
            if ($key !== '') {
                $sectionKeys[] = $key;
            }
        }

        $fragmentObjectGroupKeys = [];
        foreach ($fragmentObjectGroups as $group) {
            $key = trim((string) ($group['object_group_key'] ?? ''));
            if ($key !== '') {
                $fragmentObjectGroupKeys[] = $key;
            }
        }

        return [
            'inventory_contract_version' => (int) ($doc['inventory_contract_version'] ?? 1),
            'inventory_fingerprint' => (string) ($doc['inventory_fingerprint'] ?? ''),
            'governance_profile' => (string) ($doc['governance_profile'] ?? 'mbti_content_inventory.v1'),
            'fragment_family_count' => count($fragmentFamilyKeys),
            'fragment_family_keys' => $fragmentFamilyKeys,
            'fragment_object_group_count' => count($fragmentObjectGroupKeys),
            'fragment_object_group_keys' => $fragmentObjectGroupKeys,
            'selection_tag_count' => count($selectionTagSchema),
            'selection_tag_keys' => array_keys($selectionTagSchema),
            'section_family_count' => count($sectionKeys),
            'section_family_keys' => $sectionKeys,
        ];
    }

    /**
     * @param  array<string,mixed>  $doc
     * @return list<array{file:string,block_id:string,message:string}>
     */
    private function lintInventory(string $path, array $doc, array $manifest, string $context): array
    {
        $errors = [];

        if (($doc['schema'] ?? null) !== self::INVENTORY_SCHEMA) {
            $errors[] = $this->error($path, 'inventory.schema', "schema must be '".self::INVENTORY_SCHEMA."'.");
        }

        if ((int) ($doc['inventory_contract_version'] ?? 0) < 1) {
            $errors[] = $this->error($path, 'inventory.inventory_contract_version', 'inventory_contract_version must be >= 1.');
        }

        if (trim((string) ($doc['pack_id'] ?? '')) !== (string) ($manifest['pack_id'] ?? '')) {
            $errors[] = $this->error($path, 'inventory.pack_id', 'inventory pack_id must match manifest pack_id.');
        }

        if (trim((string) ($doc['cultural_context'] ?? '')) !== $context) {
            $errors[] = $this->error($path, 'inventory.cultural_context', 'inventory cultural_context must match manifest region/locale.');
        }

        if (trim((string) ($doc['inventory_fingerprint'] ?? '')) === '') {
            $errors[] = $this->error($path, 'inventory.inventory_fingerprint', 'inventory_fingerprint must be non-empty.');
        }

        if (trim((string) ($doc['governance_profile'] ?? '')) === '') {
            $errors[] = $this->error($path, 'inventory.governance_profile', 'governance_profile must be non-empty.');
        }

        $fragmentFamilies = array_values(array_filter((array) ($doc['fragment_families'] ?? []), 'is_array'));
        if ($fragmentFamilies === []) {
            $errors[] = $this->error($path, 'inventory.fragment_families', 'fragment_families must be a non-empty array.');
        }

        $familyIndex = [];
        foreach ($fragmentFamilies as $index => $family) {
            $familyPath = 'fragment_families.'.$index;
            $key = trim((string) ($family['key'] ?? ''));
            if ($key === '') {
                $errors[] = $this->error($path, $familyPath.'.key', 'fragment family requires key.');

                continue;
            }

            if (isset($familyIndex[$key])) {
                $errors[] = $this->error($path, $familyPath.'.key', "fragment family '{$key}' appears more than once.");
            }
            $familyIndex[$key] = true;

            if (! in_array($key, self::REQUIRED_FRAGMENT_FAMILIES, true)) {
                $errors[] = $this->error($path, $familyPath.'.key', "fragment family '{$key}' is not part of the CE-1 required inventory.");
            }

            if (trim((string) ($family['label'] ?? '')) === '') {
                $errors[] = $this->error($path, $familyPath.'.label', "fragment family '{$key}' requires label.");
            }

            $allowedSections = array_values(array_filter(array_map('strval', (array) ($family['allowed_section_keys'] ?? []))));
            if ($allowedSections === []) {
                $errors[] = $this->error($path, $familyPath.'.allowed_section_keys', "fragment family '{$key}' requires allowed_section_keys.");
            }

            $selectionTags = array_values(array_filter(array_map('strval', (array) ($family['selection_tags'] ?? []))));
            if ($selectionTags === []) {
                $errors[] = $this->error($path, $familyPath.'.selection_tags', "fragment family '{$key}' requires selection_tags.");
            } else {
                foreach ($selectionTags as $selectionTag) {
                    if (! in_array($selectionTag, self::REQUIRED_SELECTION_TAG_KEYS, true)) {
                        $errors[] = $this->error($path, $familyPath.'.selection_tags', "fragment family '{$key}' references unknown selection tag '{$selectionTag}'.");
                    }
                }
            }
        }

        foreach (self::REQUIRED_FRAGMENT_FAMILIES as $requiredKey) {
            if (! isset($familyIndex[$requiredKey])) {
                $errors[] = $this->error($path, 'inventory.fragment_families', "Missing required fragment family '{$requiredKey}'.");
            }
        }

        $objectGroups = array_values(array_filter((array) ($doc['fragment_object_groups'] ?? []), 'is_array'));
        if ($objectGroups === []) {
            $errors[] = $this->error($path, 'inventory.fragment_object_groups', 'fragment_object_groups must be a non-empty array.');
        }

        $objectGroupIndex = [];
        foreach ($objectGroups as $index => $group) {
            $groupPath = 'fragment_object_groups.'.$index;
            $objectGroupKey = trim((string) ($group['object_group_key'] ?? ''));
            if ($objectGroupKey === '') {
                $errors[] = $this->error($path, $groupPath.'.object_group_key', 'objectized fragment group requires object_group_key.');

                continue;
            }

            if (isset($objectGroupIndex[$objectGroupKey])) {
                $errors[] = $this->error($path, $groupPath.'.object_group_key', "objectized fragment group '{$objectGroupKey}' appears more than once.");
            }
            $objectGroupIndex[$objectGroupKey] = true;

            $contentObjectType = trim((string) ($group['content_object_type'] ?? ''));
            if ($contentObjectType === '') {
                $errors[] = $this->error($path, $groupPath.'.content_object_type', "objectized fragment group '{$objectGroupKey}' requires content_object_type.");
            }

            $fragmentFamily = trim((string) ($group['fragment_family'] ?? ''));
            if ($fragmentFamily === '') {
                $errors[] = $this->error($path, $groupPath.'.fragment_family', "objectized fragment group '{$objectGroupKey}' requires fragment_family.");
            } elseif (! isset($familyIndex[$fragmentFamily])) {
                $errors[] = $this->error($path, $groupPath.'.fragment_family', "objectized fragment group '{$objectGroupKey}' references unknown fragment family '{$fragmentFamily}'.");
            }

            foreach ([
                'label',
                'authoring_scope',
                'review_state_profile',
                'preview_target_key',
                'release_candidate_policy',
                'publish_target_policy',
                'rollback_target_policy',
                'runtime_binding',
                'locale_scope',
                'experiment_scope',
                'governance_profile',
            ] as $requiredField) {
                if (trim((string) ($group[$requiredField] ?? '')) === '') {
                    $errors[] = $this->error($path, $groupPath.'.'.$requiredField, "objectized fragment group '{$objectGroupKey}' requires {$requiredField}.");
                }
            }

            $sourceRefs = array_values(array_filter(array_map('strval', (array) ($group['source_refs'] ?? []))));
            if ($sourceRefs === []) {
                $errors[] = $this->error($path, $groupPath.'.source_refs', "objectized fragment group '{$objectGroupKey}' requires source_refs.");
            }
        }

        foreach (self::REQUIRED_OBJECTIZED_FRAGMENT_GROUPS as $requiredKey) {
            if (! isset($objectGroupIndex[$requiredKey])) {
                $errors[] = $this->error($path, 'inventory.fragment_object_groups', "Missing required objectized fragment group '{$requiredKey}'.");
            }
        }

        $tagSchema = is_array($doc['selection_tag_schema'] ?? null) ? $doc['selection_tag_schema'] : [];
        foreach (self::REQUIRED_SELECTION_TAG_KEYS as $requiredTagKey) {
            $tagSchemaNode = is_array($tagSchema[$requiredTagKey] ?? null) ? $tagSchema[$requiredTagKey] : null;
            if ($tagSchemaNode === null) {
                $errors[] = $this->error($path, 'inventory.selection_tag_schema', "Missing selection tag schema for '{$requiredTagKey}'.");

                continue;
            }

            if (trim((string) ($tagSchemaNode['type'] ?? '')) === '') {
                $errors[] = $this->error($path, 'inventory.selection_tag_schema', "Selection tag '{$requiredTagKey}' requires type.");
            }
        }

        $sectionMatrix = array_values(array_filter((array) ($doc['section_family_matrix'] ?? []), 'is_array'));
        $matrixIndex = [];
        foreach ($sectionMatrix as $index => $row) {
            $sectionKey = trim((string) ($row['section_key'] ?? ''));
            if ($sectionKey === '') {
                $errors[] = $this->error($path, 'inventory.section_family_matrix.'.$index.'.section_key', 'section_family_matrix requires section_key.');

                continue;
            }

            $matrixIndex[$sectionKey] = true;

            $primaryFamily = trim((string) ($row['primary_family'] ?? ''));
            if ($primaryFamily === '') {
                $errors[] = $this->error($path, 'inventory.section_family_matrix.'.$index.'.primary_family', "section '{$sectionKey}' requires primary_family.");
            } elseif (! isset($familyIndex[$primaryFamily])) {
                $errors[] = $this->error($path, 'inventory.section_family_matrix.'.$index.'.primary_family', "section '{$sectionKey}' references unknown fragment family '{$primaryFamily}'.");
            }

            $secondaryFamilies = array_values(array_filter(array_map('strval', (array) ($row['secondary_families'] ?? []))));
            foreach ($secondaryFamilies as $secondaryFamily) {
                if (! isset($familyIndex[$secondaryFamily])) {
                    $errors[] = $this->error($path, 'inventory.section_family_matrix.'.$index.'.secondary_families', "section '{$sectionKey}' references unknown fragment family '{$secondaryFamily}'.");
                }
            }
        }

        foreach (self::REQUIRED_SECTION_KEYS as $requiredSectionKey) {
            if (! isset($matrixIndex[$requiredSectionKey])) {
                $errors[] = $this->error($path, 'inventory.section_family_matrix', "Missing required section mapping '{$requiredSectionKey}'.");
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
