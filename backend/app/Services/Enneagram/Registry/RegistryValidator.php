<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Registry;

final class RegistryValidator
{
    /**
     * @var array<string,int>
     */
    private const REQUIRED_TYPE_WORK_PACK_COUNTS = [
        'work_strengths' => 4,
        'work_friction_points' => 4,
        'ideal_environment' => 3,
        'collaboration_manual' => 3,
        'managed_by_others' => 2,
        'leadership_pattern' => 2,
        'workplace_trigger_points' => 2,
    ];

    /**
     * @var array<string,int>
     */
    private const REQUIRED_TYPE_GROWTH_PACK_COUNTS = [
        'growth_strengths' => 4,
        'growth_costs' => 4,
        'early_warning_signs' => 4,
        'recovery_protocol' => 3,
        'small_experiments' => 3,
    ];

    /**
     * @var array<string,int>
     */
    private const REQUIRED_TYPE_RELATIONSHIP_PACK_COUNTS = [
        'relationship_strengths' => 4,
        'relationship_traps' => 4,
        'communication_manual' => 3,
        'conflict_trigger_points' => 3,
        'repair_language' => 3,
        'partner_facing_notes' => 2,
    ];

    private const REQUIRED_TYPE_DEEP_DIVE_FIELDS = [
        'core_desire',
        'core_fear',
        'defense_pattern',
        'misread_by_others',
        'self_misread',
        'work_mechanism',
        'relationship_script',
        'conflict_pattern',
        'stress_signal',
        'recovery_action',
        'growth_principle',
        'thirty_day_experiment',
    ];

    private const UNSUPPORTED_TYPE_CLAIM_SNIPPETS = [
        '临床诊断',
        '临床判断',
        '招聘筛选',
        '筛选候选人',
        '准确率',
        '效度验证',
        '外部效度',
        'health level',
        '健康层级判定',
        '高健康层级',
        '低健康层级',
        '子类型判定',
        '翼型判定',
        '箭头判定',
        '适合招聘',
        '筛掉',
        '录用',
    ];

    /**
     * @var array<string,string>
     */
    private const REQUIRED_REGISTRIES = [
        'enneagram_type_registry' => 'type_registry.json',
        'enneagram_pair_registry' => 'pair_registry.json',
        'enneagram_group_registry' => 'group_registry.json',
        'enneagram_scenario_registry' => 'scenario_registry.json',
        'enneagram_state_registry' => 'state_registry.json',
        'enneagram_theory_hint_registry' => 'theory_hint_registry.json',
        'enneagram_observation_registry' => 'observation_registry.json',
        'enneagram_method_registry' => 'method_registry.json',
        'enneagram_ui_copy_registry' => 'ui_copy_registry.json',
        'enneagram_sample_report_registry' => 'sample_report_registry.json',
        'enneagram_technical_note_registry' => 'technical_note_registry.json',
    ];

    private const VALID_FORM_VARIANTS = ['all', 'e105', 'fc144'];

    private const VALID_CONTEXT_MODES = ['individual', 'workplace', 'team'];

    private const VALID_STATUSES = ['draft', 'review', 'published', 'archived', 'deprecated'];

    private const VALID_CONTENT_MATURITY = ['scaffold', 'p0_placeholder', 'p0_ready', 'p1_expanded', 'experimental', 'deprecated'];

    private const VALID_EVIDENCE_LEVEL = ['descriptive', 'theory_based', 'data_supported', 'validated_internal', 'validated_external'];

    private const VALID_FALLBACK_POLICIES = ['required', 'optional', 'fallback_to_type_base', 'fallback_to_generic', 'none'];

    private const REQUIRED_TYPE_IDS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private const REQUIRED_PAIR_KEYS = ['1_3', '1_6', '1_8', '2_3', '2_9', '3_7', '3_8', '4_5', '4_9', '5_6', '5_9', '6_9', '6_1', '7_3', '8_1'];

    private const REQUIRED_GROUP_KEYS = [
        'center:body',
        'center:heart',
        'center:head',
        'stance:assertive',
        'stance:compliant',
        'stance:withdrawn',
        'harmonic:positive_outlook',
        'harmonic:competency',
        'harmonic:reactive',
    ];

    private const REQUIRED_METHOD_KEYS = [
        'e105_standard_methodology',
        'fc144_forced_choice_methodology',
        'same_model_not_same_score_space',
        'cross_form_compare_blocked',
        'non_diagnostic_boundary',
        'user_confirmed_type_boundary',
        'low_quality_boundary',
        'diffuse_boundary',
        'close_call_boundary',
    ];

    private const REQUIRED_UI_COPY_KEYS = [
        'instant_summary.clear',
        'instant_summary.close_call',
        'instant_summary.diffuse',
        'instant_summary.low_quality',
        'form_badge.e105',
        'form_badge.fc144',
        'close_call_card.title',
        'diffuse_boundary.title',
        'low_quality_boundary.title',
        'technical_note.link_label',
        'observation.assign_cta',
        'observation.day3_title',
        'observation.day7_title',
        'observation.self_confirmation_boundary',
    ];

    private const VALID_TECHNICAL_NOTE_DATA_STATUS = ['planned', 'collecting', 'provisional', 'available', 'deprecated'];

    private const REQUIRED_TECHNICAL_NOTE_KEYS = [
        'test_goal',
        'e105_fc144_forms',
        'score_space_boundary',
        'dominance_gap',
        'confidence_band',
        'close_call',
        'diffuse',
        'low_quality',
        'retake_stability',
        'e105_fc144_agreement',
        'resonance_feedback',
        'method_boundaries',
        'privacy',
    ];

    /**
     * @param  array<string,mixed>  $registryPack
     * @return list<string>
     */
    public function validate(array $registryPack): array
    {
        $errors = [];
        $manifest = is_array($registryPack['manifest'] ?? null) ? $registryPack['manifest'] : [];
        $registries = is_array($registryPack['registries'] ?? null) ? $registryPack['registries'] : [];

        $errors = array_merge($errors, $this->validateManifest($manifest));

        foreach (self::REQUIRED_REGISTRIES as $registryKey => $file) {
            $payload = is_array($registries[$registryKey] ?? null) ? $registries[$registryKey] : null;
            if (! is_array($payload)) {
                $errors[] = "Missing registry payload {$registryKey}";

                continue;
            }
            $errors = array_merge($errors, $this->validateRegistryMetadata($payload, $registryKey));
        }

        if ($registries !== []) {
            $errors = array_merge($errors, $this->validateTypeRegistry((array) ($registries['enneagram_type_registry'] ?? [])));
            $errors = array_merge($errors, $this->validatePairRegistry((array) ($registries['enneagram_pair_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateGroupRegistry((array) ($registries['enneagram_group_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateScenarioRegistry((array) ($registries['enneagram_scenario_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateStateRegistry((array) ($registries['enneagram_state_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateObservationRegistry((array) ($registries['enneagram_observation_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateMethodRegistry((array) ($registries['enneagram_method_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateTheoryHintRegistry((array) ($registries['enneagram_theory_hint_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateUiCopyRegistry((array) ($registries['enneagram_ui_copy_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateSampleReportRegistry((array) ($registries['enneagram_sample_report_registry'] ?? [])));
            $errors = array_merge($errors, $this->validateTechnicalNoteRegistry((array) ($registries['enneagram_technical_note_registry'] ?? [])));
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<string>
     */
    private function validateManifest(array $manifest): array
    {
        $errors = [];

        foreach (['schema_version', 'scale_code', 'registry_version', 'release_id', 'registries', 'locales', 'supported_form_variants', 'supported_context_modes', 'content_maturity_values', 'evidence_level_values'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                $errors[] = "Registry manifest missing {$field}";
            }
        }

        if ((string) ($manifest['scale_code'] ?? '') !== 'ENNEAGRAM') {
            $errors[] = 'Registry manifest scale_code must be ENNEAGRAM';
        }
        if ((string) ($manifest['registry_version'] ?? '') !== 'enneagram_registry.v1') {
            $errors[] = 'Registry manifest registry_version must be enneagram_registry.v1';
        }

        $manifestRegistries = is_array($manifest['registries'] ?? null) ? $manifest['registries'] : [];
        $expected = self::REQUIRED_REGISTRIES;
        if (count($manifestRegistries) !== count($expected)) {
            $errors[] = 'Registry manifest must list all required registries';
        }
        foreach ($manifestRegistries as $row) {
            if (! is_array($row)) {
                $errors[] = 'Registry manifest contains invalid registry row';

                continue;
            }
            $key = (string) ($row['registry_key'] ?? '');
            $file = (string) ($row['file'] ?? '');
            if (! isset($expected[$key])) {
                $errors[] = "Registry manifest contains unexpected registry key {$key}";

                continue;
            }
            if ($expected[$key] !== $file) {
                $errors[] = "Registry manifest file mismatch for {$key}";
            }
        }

        if (($manifest['supported_form_variants'] ?? null) !== self::VALID_FORM_VARIANTS) {
            $errors[] = 'Registry manifest supported_form_variants must be exactly all/e105/fc144';
        }
        if (($manifest['supported_context_modes'] ?? null) !== self::VALID_CONTEXT_MODES) {
            $errors[] = 'Registry manifest supported_context_modes must be exactly individual/workplace/team';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateRegistryMetadata(array $payload, string $expectedKey): array
    {
        $errors = [];
        foreach ([
            'id',
            'schema_version',
            'registry_key',
            'locale',
            'form_variant',
            'context_mode',
            'status',
            'version',
            'release_id',
            'content_release_hash',
            'content_maturity',
            'evidence_level',
            'preview_enabled',
            'fallback_policy',
        ] as $field) {
            if (! array_key_exists($field, $payload)) {
                $errors[] = "{$expectedKey} missing metadata field {$field}";
            }
        }

        if ((string) ($payload['registry_key'] ?? '') !== $expectedKey) {
            $errors[] = "{$expectedKey} registry_key mismatch";
        }
        if (! in_array((string) ($payload['form_variant'] ?? ''), self::VALID_FORM_VARIANTS, true)) {
            $errors[] = "{$expectedKey} has invalid form_variant";
        }
        if (! in_array((string) ($payload['context_mode'] ?? ''), self::VALID_CONTEXT_MODES, true)) {
            $errors[] = "{$expectedKey} has invalid context_mode";
        }
        if (! in_array((string) ($payload['status'] ?? ''), self::VALID_STATUSES, true)) {
            $errors[] = "{$expectedKey} has invalid status";
        }
        if (! in_array((string) ($payload['content_maturity'] ?? ''), self::VALID_CONTENT_MATURITY, true)) {
            $errors[] = "{$expectedKey} has invalid content_maturity";
        }
        if (! in_array((string) ($payload['evidence_level'] ?? ''), self::VALID_EVIDENCE_LEVEL, true)) {
            $errors[] = "{$expectedKey} has invalid evidence_level";
        }
        if (! in_array((string) ($payload['fallback_policy'] ?? ''), self::VALID_FALLBACK_POLICIES, true)) {
            $errors[] = "{$expectedKey} has invalid fallback_policy";
        }
        if (! is_bool($payload['preview_enabled'] ?? null)) {
            $errors[] = "{$expectedKey} preview_enabled must be boolean";
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateTypeRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $typeIds = [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Type registry contains invalid entry';

                continue;
            }
            $typeId = (string) ($entry['type_id'] ?? '');
            $typeIds[] = $typeId;
            foreach ([
                'type_name_cn',
                'type_name_en',
                'short_title',
                'core_logic',
                'surface_impression',
                'internal_tension',
                'validation_hook',
                'hero_summary',
                'work_summary',
                'growth_summary',
                'relationship_summary',
                'healthy_expression',
                'average_expression',
                'strained_expression',
                'blind_spot_copy',
                'blind_spot_link',
                'seven_day_question',
                'content_maturity',
                'evidence_level',
                'fallback_policy',
            ] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "Type registry {$typeId} missing {$field}";
                }
            }
            $deepDive = is_array($entry['deep_dive'] ?? null) ? $entry['deep_dive'] : null;
            if ($deepDive === null) {
                $errors[] = "Type registry {$typeId} missing deep_dive";

                continue;
            }
            foreach (self::REQUIRED_TYPE_DEEP_DIVE_FIELDS as $field) {
                if (trim((string) ($deepDive[$field] ?? '')) === '') {
                    $errors[] = "Type registry {$typeId} missing deep_dive.{$field}";
                }
            }
            if (($entry['content_maturity'] ?? null) !== 'p0_ready') {
                $errors[] = "Type registry {$typeId} content_maturity must remain p0_ready";
            }
            foreach (self::REQUIRED_TYPE_DEEP_DIVE_FIELDS as $field) {
                $value = (string) ($deepDive[$field] ?? '');
                $snippet = $this->unsupportedSnippetForText($value);
                if ($snippet !== null) {
                    $errors[] = "Type registry {$typeId} deep_dive.{$field} contains unsupported claim snippet: {$snippet}";
                }
            }
            $this->validateTypePackItems($errors, $typeId, 'work_pack', (array) ($entry['work_pack'] ?? []), self::REQUIRED_TYPE_WORK_PACK_COUNTS);
            $this->validateTypePackItems($errors, $typeId, 'growth_pack', (array) ($entry['growth_pack'] ?? []), self::REQUIRED_TYPE_GROWTH_PACK_COUNTS);
            $this->validateStateSpectrumCopy($errors, $typeId, (array) data_get($entry, 'growth_pack.state_spectrum_copy', []));
            $this->validateTypePackItems($errors, $typeId, 'relationship_pack', (array) ($entry['relationship_pack'] ?? []), self::REQUIRED_TYPE_RELATIONSHIP_PACK_COUNTS);
        }
        sort($typeIds);
        if ($typeIds !== self::REQUIRED_TYPE_IDS) {
            $errors[] = 'Type registry must cover types 1-9 exactly once';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validatePairRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $keys = [];
        $errors = [];
        $fallbackTemplate = is_array($payload['fallback_template'] ?? null) ? $payload['fallback_template'] : [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Pair registry contains invalid entry';

                continue;
            }
            $pairKey = (string) ($entry['pair_key'] ?? '');
            $keys[] = $pairKey;
            foreach ([
                'type_a',
                'type_b',
                'shared_surface_similarity',
                'core_motivation_difference',
                'fear_difference',
                'stress_reaction_difference',
                'relationship_difference',
                'work_difference',
                'seven_day_observation_question',
                'resonance_feedback_prompt',
                'short_compare_copy',
                'fallback_policy',
                'content_maturity',
                'evidence_level',
            ] as $field) {
                if (! array_key_exists($field, $entry)) {
                    $errors[] = "Pair registry {$pairKey} missing {$field}";
                }
            }
            if (! in_array((string) ($entry['fallback_policy'] ?? ''), self::VALID_FALLBACK_POLICIES, true)) {
                $errors[] = "Pair registry {$pairKey} has invalid fallback_policy";
            }
        }
        sort($keys);
        $expected = self::REQUIRED_PAIR_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            $errors[] = 'Pair registry must include all required P0 pair keys';
        }
        foreach (['same_surface', 'motivation_difference', 'pressure_difference', 'relationship_difference', 'work_difference', 'observation_question'] as $field) {
            if (trim((string) ($fallbackTemplate[$field] ?? '')) === '') {
                $errors[] = "Pair registry fallback_template missing {$field}";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateGroupRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $keys = [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Group registry contains invalid entry';

                continue;
            }
            $groupType = (string) ($entry['group_type'] ?? '');
            $groupKey = (string) ($entry['group_key'] ?? '');
            $keys[] = $groupType.':'.$groupKey;
            foreach (['description', 'strength_expression', 'cost_expression', 'stress_signal', 'observation_question', 'content_maturity'] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "Group registry {$groupType}:{$groupKey} missing {$field}";
                }
            }
        }
        sort($keys);
        $expected = self::REQUIRED_GROUP_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            $errors[] = 'Group registry must include centers, stances, and harmonic groups';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateMethodRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $keys = [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Method registry contains invalid entry';

                continue;
            }
            $methodKey = (string) ($entry['method_key'] ?? '');
            $keys[] = $methodKey;
            if (trim((string) ($entry['copy'] ?? '')) === '') {
                $errors[] = "Method registry {$methodKey} missing copy";
            }
        }
        sort($keys);
        $expected = self::REQUIRED_METHOD_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            $errors[] = 'Method registry must include required methodology and boundary keys';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateScenarioRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $requiredKeys = [
            'work_style_summary',
            'collaboration_strengths',
            'collaboration_friction',
            'leadership_pattern',
            'managed_by_others',
            'relationship_need',
            'conflict_script',
            'communication_manual',
        ];

        $keys = [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Scenario registry contains invalid entry';

                continue;
            }
            $scenarioKey = (string) ($entry['scenario_key'] ?? '');
            $keys[] = $scenarioKey;
            foreach (['module_key', 'title', 'body', 'module_purpose', 'content_maturity', 'fallback_policy'] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "Scenario registry {$scenarioKey} missing {$field}";
                }
            }
        }
        sort($keys);
        sort($requiredKeys);
        if ($keys !== $requiredKeys) {
            $errors[] = 'Scenario registry must include required scaffold modules';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateStateRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $errors = [];

        if (count($entries) !== 1) {
            $errors[] = 'State registry must include one state spectrum entry for P0';
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'State registry contains invalid entry';

                continue;
            }
            foreach (['state_key', 'stable_expression', 'average_expression', 'strained_expression', 'recovery_action', 'disclaimer', 'content_maturity'] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "State registry missing {$field}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateObservationRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $days = [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Observation registry contains invalid entry';

                continue;
            }
            $day = (int) ($entry['day'] ?? 0);
            $days[] = $day;
            foreach (['phase', 'title', 'prompt', 'example', 'analytics_event_key', 'suggested_next_action', 'boundary_copy', 'content_maturity'] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "Observation registry day {$day} missing {$field}";
                }
            }
            if (! is_array($entry['user_input_schema'] ?? null)) {
                $errors[] = "Observation registry day {$day} user_input_schema must be object";
            }
        }
        sort($days);
        if ($days !== [1, 2, 3, 4, 5, 6, 7]) {
            $errors[] = 'Observation registry must scaffold Day1-Day7 exactly once';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateTheoryHintRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Theory hint registry contains invalid entry';

                continue;
            }
            $theoryKey = (string) ($entry['theory_key'] ?? '');
            if (($entry['hard_judgement_allowed'] ?? null) !== false) {
                $errors[] = "Theory hint {$theoryKey} must mark hard_judgement_allowed=false";
            }
            foreach (['visibility_scope', 'boundary_copy', 'user_facing_boundary', 'content_maturity', 'evidence_level'] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "Theory hint {$theoryKey} missing {$field}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateUiCopyRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $keys = array_values(array_map('strval', array_keys($entries)));
        sort($keys);
        $expected = self::REQUIRED_UI_COPY_KEYS;
        sort($expected);

        return $keys === $expected ? [] : ['UI copy registry must include required scaffold keys'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateSampleReportRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $expected = ['clear_sample', 'close_call_sample', 'diffuse_sample'];
        $keys = array_values(array_map('strval', array_keys($entries)));
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            return ['Sample report registry must include clear/close_call/diffuse scaffold references'];
        }

        $errors = [];
        foreach ($entries as $entryKey => $entry) {
            if (! is_array($entry)) {
                $errors[] = "Sample report registry {$entryKey} must be object";

                continue;
            }
            foreach ([
                'sample_key',
                'sample_type',
                'form_code',
                'interpretation_scope',
                'projection_fixture_id',
                'public_url_slug',
                'short_summary',
                'page_1_preview',
                'method_boundary',
                'content_maturity',
                'evidence_level',
            ] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "Sample report registry {$entryKey} missing {$field}";
                }
            }
            if (! is_array($entry['top_types'] ?? null) || count((array) $entry['top_types']) < 3) {
                $errors[] = "Sample report registry {$entryKey} top_types must include at least 3 items";
            }
            if ((string) ($entry['content_maturity'] ?? '') !== 'p0_ready') {
                $errors[] = "Sample report registry {$entryKey} content_maturity must be p0_ready";
            }
            if (! in_array((string) ($entry['evidence_level'] ?? ''), self::VALID_EVIDENCE_LEVEL, true)) {
                $errors[] = "Sample report registry {$entryKey} has invalid evidence_level";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateTechnicalNoteRegistry(array $payload): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $keys = [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Technical note registry contains invalid entry';

                continue;
            }
            $sectionKey = (string) ($entry['section_key'] ?? '');
            $keys[] = $sectionKey;
            foreach (['title', 'body', 'data_status', 'content_maturity'] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    $errors[] = "Technical note registry {$sectionKey} missing {$field}";
                }
            }
            if (! is_array($entry['metric_refs'] ?? null)) {
                $errors[] = "Technical note registry {$sectionKey} metric_refs must be array";
            }
            if (! in_array((string) ($entry['data_status'] ?? ''), self::VALID_TECHNICAL_NOTE_DATA_STATUS, true)) {
                $errors[] = "Technical note registry {$sectionKey} has invalid data_status";
            }
        }
        sort($keys);
        $expected = self::REQUIRED_TECHNICAL_NOTE_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            $errors[] = 'Technical note registry must include required scaffold sections';
        }

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $pack
     * @param  array<string,int>  $requiredFields
     */
    private function validateTypePackItems(array &$errors, string $typeId, string $packKey, array $pack, array $requiredFields): void
    {
        if ($pack === []) {
            $errors[] = "Type registry {$typeId} missing {$packKey}";

            return;
        }

        foreach ($requiredFields as $field => $minCount) {
            $items = $pack[$field] ?? null;
            if (! is_array($items) || count($items) < $minCount) {
                $errors[] = "Type registry {$typeId} {$packKey}.{$field} must include at least {$minCount} items";

                continue;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    $errors[] = "Type registry {$typeId} {$packKey}.{$field}[{$index}] must be object";

                    continue;
                }

                foreach (['title', 'body'] as $itemField) {
                    $value = trim((string) ($item[$itemField] ?? ''));
                    if ($value === '') {
                        $errors[] = "Type registry {$typeId} {$packKey}.{$field}[{$index}] missing {$itemField}";

                        continue;
                    }

                    $snippet = $this->unsupportedSnippetForText($value);
                    if ($snippet !== null) {
                        $errors[] = "Type registry {$typeId} {$packKey}.{$field}[{$index}].{$itemField} contains unsupported claim snippet: {$snippet}";
                    }
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $copy
     */
    private function validateStateSpectrumCopy(array &$errors, string $typeId, array $copy): void
    {
        foreach (['stable_expression', 'default_expression', 'strained_expression'] as $field) {
            $value = trim((string) ($copy[$field] ?? ''));
            if ($value === '') {
                $errors[] = "Type registry {$typeId} growth_pack.state_spectrum_copy.{$field} missing";

                continue;
            }

            $snippet = $this->unsupportedSnippetForText($value);
            if ($snippet !== null) {
                $errors[] = "Type registry {$typeId} growth_pack.state_spectrum_copy.{$field} contains unsupported claim snippet: {$snippet}";
            }
        }
    }

    private function unsupportedSnippetForText(string $value): ?string
    {
        foreach (self::UNSUPPORTED_TYPE_CLAIM_SNIPPETS as $snippet) {
            if (str_contains($value, $snippet)) {
                return $snippet;
            }
        }

        return null;
    }
}
