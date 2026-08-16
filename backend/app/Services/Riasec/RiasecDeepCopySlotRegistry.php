<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecDeepCopySlotRegistry
{
    private const CONTENT_VERSION = 'riasec_dimension_deep_copy_v1.zh-CN.r3';

    private const DIMENSION_ASSET_PATH = '/content_assets/riasec/dimension_deep_copy_v1.zh-CN.r3.json';

    private const PAIR_BLEND_ASSET_PATH = '/content_assets/riasec/pair_blend_15_pairs_v1.zh-CN.jsonl';

    private const TOP3_CHAIN_ASSET_PATH = '/content_assets/riasec/top3_code_chain_strategy_v1.zh-CN.jsonl';

    private const LAYER_140Q_ASSET_PATH = '/content_assets/riasec/140q_task_environment_role_v1.zh-CN.jsonl';

    private const LOW_QUALITY_ASSET_PATH = '/content_assets/riasec/low_quality_cautious_reading_v1.zh-CN.json';

    private const PROFILE_SHAPE_ASSET_PATH = '/content_assets/riasec/profile_shape_copy_v1.zh-CN.json';

    private const TOP_CODE_CONFIDENCE_ASSET_PATH = '/content_assets/riasec/top_code_confidence_copy_v1.zh-CN.json';

    private const NEAR_TIE_ASSET_PATH = '/content_assets/riasec/near_tie_alternate_code_copy_v1.zh-CN.json';

    private const ASPIRATIONS_ASSET_PATH = '/content_assets/riasec/aspirations_calibration_v1.zh-CN.jsonl';

    private const DISAGREE_PATH_ASSET_PATH = '/content_assets/riasec/disagree_path_v1.zh-CN.jsonl';

    /** @var array<string,array<string,mixed>>|null */
    private ?array $dimensionContentCache = null;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $pairBlendContentCache = null;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $top3ChainContentCache = null;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $layer140qContentCache = null;

    /** @var array<string,mixed>|null */
    private ?array $lowQualityAssetCache = null;

    /** @var array<string,mixed>|null */
    private ?array $profileShapeAssetCache = null;

    /** @var array<string,mixed>|null */
    private ?array $topCodeConfidenceAssetCache = null;

    /** @var array<string,mixed>|null */
    private ?array $nearTieAssetCache = null;

    /** @var list<array<string,mixed>>|null */
    private ?array $aspirationsAssetRowsCache = null;

    /** @var list<array<string,mixed>>|null */
    private ?array $disagreePathAssetRowsCache = null;

    /** @var list<string> */
    public const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    /** @var list<string> */
    public const PAIRS = [
        'R_I', 'R_A', 'R_S', 'R_E', 'R_C',
        'I_A', 'I_S', 'I_E', 'I_C',
        'A_S', 'A_E', 'A_C',
        'S_E', 'S_C',
        'E_C',
    ];

    /** @var list<string> */
    public const TOP3_COMBOS = [
        'R_I_A', 'R_I_S', 'R_I_E', 'R_I_C',
        'R_A_S', 'R_A_E', 'R_A_C',
        'R_S_E', 'R_S_C',
        'R_E_C',
        'I_A_S', 'I_A_E', 'I_A_C',
        'I_S_E', 'I_S_C',
        'I_E_C',
        'A_S_E', 'A_S_C',
        'A_E_C',
        'S_E_C',
    ];

    /** @var list<string> */
    public const LAYER_140Q_STATES = [
        'agreement',
        'tension',
        'unavailable',
        'insufficient_quality',
        'not_applicable_60q_only',
        'low_quality_hidden',
        '60q_only_cta',
        '140q_completed_state',
    ];

    /** @var list<string> */
    public const LAYER_140Q_DIMENSION_LAYERS = [
        'task',
        'environment',
        'role',
        'commercial_state',
    ];

    /** @var list<string> */
    public const QUALITY_COPY_STATES = [
        'normal',
        'caution',
        'low_quality',
        'retake_recommended',
        'minimal_quality_boundary_60q',
    ];

    /** @var list<string> */
    public const PROFILE_SHAPES = [
        'clear_code',
        'blended_code',
        'broad_profile',
        'near_tie',
        'low_quality',
        'low_clarity',
    ];

    /** @var list<string> */
    public const TOP_CODE_CONFIDENCE_STATES = [
        'high_confidence',
        'moderate_confidence',
        'near_tie',
        'broad_profile',
        'low_clarity',
    ];

    /** @var list<string> */
    public const NEAR_TIE_COPY_STATES = [
        'top1_top2_near_tie',
        'top2_top3_near_tie',
        'multi_near_tie',
        'alternate_code_available',
    ];

    /** @var list<string> */
    public const STRUCTURAL_DIFFERENCE_STATES = [
        'same_structure',
        'different_emphasis',
        'layer_tension',
        'insufficient_basis',
        'cross_form_not_comparable',
        'near_tie_shift',
        'quality_limited',
    ];

    /** @var list<string> */
    public const ASPIRATIONS_STATES = [
        'not_provided',
        'overlap',
        'tension',
        'needs_reality_check',
        'high_risk_boundary',
        'low_quality_suppressed',
    ];

    /** @var list<string> */
    public const DISAGREE_STATES = [
        'disagrees_quality_normal',
        'disagrees_quality_caution',
        'retake_recommended',
        'save_feedback_only',
    ];

    public function __construct(
        private readonly RiasecContentRegistrySlotContract $contract = new RiasecContentRegistrySlotContract,
        private readonly RiasecPrivateResultSourceRepository $privateResultSource = new RiasecPrivateResultSourceRepository,
    ) {}

    /**
     * @return array<string,array<string,mixed>>
     */
    public function dimensionSlots(): array
    {
        return $this->dimensionContent();
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveDimensionSlot(string $dimensionCode): array
    {
        $dimensionCode = strtoupper(trim($dimensionCode));
        $slot = $this->dimensionSlots()[$dimensionCode] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => 'dimension_deep_copy',
                'dimension_code' => $dimensionCode,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'missing_dimension_deep_copy_slot',
            ];
        }

        return $slot;
    }

    /**
     * Keep deep-result content locale-bound. A slot may only be rendered when
     * its declared locale equals the request locale; callers must omit a
     * missing locale rather than infer or translate it at runtime.
     *
     * @param  array<string,mixed>  $slot
     * @return array<string,mixed>
     */
    public function resolveForLocale(array $slot, string $locale): array
    {
        $requestedLocale = $this->normalizeContentLocale($locale);
        $slotLocale = $this->normalizeContentLocale((string) ($slot['locale'] ?? ''));

        if ($slotLocale === $requestedLocale) {
            return $slot;
        }

        return [
            'slot_key' => (string) ($slot['slot_key'] ?? 'riasec_deep_copy'),
            'slot_group' => (string) ($slot['slot_group'] ?? ''),
            'slot_name' => (string) ($slot['slot_name'] ?? ''),
            'locale' => $requestedLocale,
            'requested_locale' => $requestedLocale,
            'source_locale' => $slotLocale,
            'content_status' => 'unavailable',
            'module_state' => 'omitted',
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
            'reason' => 'locale_content_unavailable',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function pairBlendSlots(): array
    {
        $slots = [];
        foreach (self::PAIRS as $pairKey) {
            $slots[$pairKey] = $this->pendingPairSlot($pairKey);
        }

        foreach ($this->pairBlendContentFromAsset() as $pairKey => $content) {
            $slots[$pairKey] = $this->authoredPairSlot($pairKey, $content);
        }

        return $slots;
    }

    /**
     * @param  list<string>|string  $pair
     * @return array<string,mixed>
     */
    public function resolvePairBlendSlot(array|string $pair): array
    {
        $pairKey = $this->normalizePairKey($pair);
        $slot = $this->pairBlendSlots()[$pairKey] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => 'pair_blend_copy',
                'pair_key' => $pairKey,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_pair_blend_slot',
            ];
        }

        return $slot;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function top3ChainSlots(): array
    {
        $slots = [];
        foreach (self::TOP3_COMBOS as $top3Key) {
            $slots[$top3Key] = $this->pendingTop3ChainSlot($top3Key);
        }

        foreach ($this->top3ChainContentFromAsset() as $top3Key => $content) {
            $slots[$top3Key] = $this->authoredTop3ChainSlot($top3Key, $content);
        }

        return $slots;
    }

    /**
     * @param  list<string>|string  $top3
     * @return array<string,mixed>
     */
    public function resolveTop3ChainSlot(array|string $top3): array
    {
        $top3Key = $this->normalizeTop3Key($top3);
        $slot = $this->top3ChainSlots()[$top3Key] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => 'triad_blend_copy',
                'top3_key' => $top3Key,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_top3_chain_slot',
            ];
        }

        return $this->withOrderedTop3Emphasis($slot, $this->orderedTop3Dimensions($top3));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function layer140qSlots(): array
    {
        $slots = [];
        foreach ((array) $this->privateResultSource->get('generic_slots.layer_140q', []) as $slotName => $content) {
            if (! is_array($content)) {
                continue;
            }
            $slotKey = match ($slotName) {
                'task_activity_card' => '140q_task_card_copy',
                'environment_card' => '140q_environment_card_copy',
                'role_responsibility_card' => '140q_role_card_copy',
                'layer_agreement' => '140q_layer_agreement_copy',
                'layer_tension' => '140q_tension_copy',
                'layer_unavailable' => '140q_layer_unavailable_copy',
                '140q_cta' => '140q_cta_copy',
                '140q_not_recommended' => '140q_not_recommended_copy',
                default => null,
            };
            if ($slotKey !== null) {
                $slots[$slotName] = $this->layer140qSlot($slotKey, (string) $slotName, $content);
            }
        }

        return $slots;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve140qLayerSlot(string $slotName): array
    {
        $slotName = trim($slotName);
        $slot = $this->layer140qSlots()[$slotName] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => '140q_layer_unknown',
                'slot_name' => $slotName,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_140q_layer_slot',
            ];
        }

        return $slot;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function layer140qAssetSlots(): array
    {
        $slots = [];
        foreach ($this->layer140qContentFromAsset() as $slotKey => $content) {
            $slot = $this->authored140qAssetSlot($slotKey, $content);
            if ($this->validateSlot($slot) !== []) {
                continue;
            }

            $slots[$slotKey] = $slot;
        }

        return $slots;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve140qDimensionLayerSlot(string $dimensionCode, string $layer, string $layerState): array
    {
        $dimensionCode = strtoupper(trim($dimensionCode));
        $layer = strtolower(trim($layer));
        $layerState = $this->normalize140qLayerState($layerState);
        $slotName = $this->layer140qAssetSlotName($dimensionCode, $layer, $layerState);
        $slot = $this->layer140qAssetSlots()[$slotName] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => $this->layer140qSlotKeyForLayer($layer),
                'slot_name' => $slotName,
                'layer_dimension' => $dimensionCode,
                'layer' => $layer,
                'layer_state' => $layerState,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_140q_dimension_layer_slot',
            ];
        }

        return $slot;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function lowQualitySlots(): array
    {
        $slots = [];
        foreach ($this->lowQualitySlotsFromAsset() as $slotName => $content) {
            $slotKey = in_array($slotName, ['cautious_reading_notice', 'minimal_quality_boundary_60q'], true)
                ? 'cautious_reading_copy'
                : 'low_quality_copy';
            $slots[$slotName] = $this->qualitySlot($slotKey, $slotName, $content);
        }

        return array_filter($slots, fn (array $slot): bool => $this->validateSlot($slot) === []);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function interpretationStateCopySlots(): array
    {
        return array_filter(array_merge(
            $this->profileShapeCopySlots(),
            $this->topCodeConfidenceCopySlots(),
            $this->nearTieAlternateCodeCopySlots()
        ), fn (array $slot): bool => $this->validateSlot($slot) === []);
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveInterpretationStateCopySlot(string $slotKey, string $slotName): array
    {
        $slotName = trim($slotName);
        $slotId = trim($slotKey).':'.$slotName;
        $slot = $this->interpretationStateCopySlots()[$slotId] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => trim($slotKey),
                'slot_name' => $slotName,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_interpretation_state_copy_slot',
            ];
        }

        return $slot;
    }

    /**
     * @return array<string,mixed>
     */
    public function lowQualityModuleDowngradePolicy(): array
    {
        return [
            'quality_state' => 'low_quality',
            'visible_modules' => ['trust_bar', 'six_dimension_map', 'low_quality_notice', 'technical_note_summary', 'faq', 'final_user_exit'],
            'hidden_modules' => ['hero_activity_chain', 'pair_blend', 'activity_explorer', 'occupation_examples', '140q_cta', '140q_three_cards'],
            'collapsed_modules' => ['share_card', 'pdf', 'history'],
            'score_mutation_allowed' => false,
            'measured_holland_code_mutation_allowed' => false,
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function profileShapeCopySlots(): array
    {
        $asset = $this->profileShapeAsset();
        $slots = [];
        foreach ((array) ($asset['profile_shapes'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $shape = trim((string) ($row['shape'] ?? ''));
            if (! in_array($shape, self::PROFILE_SHAPES, true)) {
                continue;
            }

            $slot = $this->interpretationStateSlot('profile_shape_copy', $shape, [
                'profile_shape' => $shape,
                'title' => (string) ($row['title'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
                'body' => (string) ($row['summary'] ?? ''),
                'module_policy' => (string) ($row['module_policy'] ?? ''),
                'content_version' => (string) ($asset['asset_id'] ?? 'profile_shape_copy_v1.zh-CN'),
                'user_visible_boundary' => (string) ($asset['user_visible_boundary'] ?? ''),
                'source_review_status' => (string) ($asset['review_status'] ?? ''),
            ]);
            $slots[(string) $slot['slot_key'].':'.$shape] = $slot;
        }

        return $slots;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function topCodeConfidenceCopySlots(): array
    {
        $asset = $this->topCodeConfidenceAsset();
        $slots = [];
        foreach ((array) ($asset['states'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = trim((string) ($row['state'] ?? ''));
            if (! in_array($state, self::TOP_CODE_CONFIDENCE_STATES, true)) {
                continue;
            }

            $slot = $this->interpretationStateSlot('top_code_confidence_copy', $state, [
                'confidence_state' => $state,
                'title' => (string) ($row['label'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'summary' => (string) ($row['copy'] ?? ''),
                'body' => (string) ($row['copy'] ?? ''),
                'copy' => (string) ($row['copy'] ?? ''),
                'content_version' => (string) ($asset['asset_id'] ?? 'top_code_confidence_copy_v1.zh-CN'),
                'user_visible_boundary' => (string) ($asset['user_visible_boundary'] ?? ''),
                'source_review_status' => (string) ($asset['review_status'] ?? ''),
            ]);
            $slots[(string) $slot['slot_key'].':'.$state] = $slot;
        }

        return $slots;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function nearTieAlternateCodeCopySlots(): array
    {
        $asset = $this->nearTieAsset();
        $slots = [];
        foreach ((array) ($asset['states'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = trim((string) ($row['state'] ?? ''));
            if (! in_array($state, self::NEAR_TIE_COPY_STATES, true)) {
                continue;
            }

            $slot = $this->interpretationStateSlot('near_tie_alternate_code_copy', $state, [
                'near_tie_copy_state' => $state,
                'title' => (string) ($row['title'] ?? ''),
                'summary' => (string) ($row['copy'] ?? ''),
                'body' => (string) ($row['copy'] ?? ''),
                'copy' => (string) ($row['copy'] ?? ''),
                'content_version' => (string) ($asset['asset_id'] ?? 'near_tie_alternate_code_copy_v1.zh-CN'),
                'user_visible_boundary' => (string) ($asset['user_visible_boundary'] ?? ''),
                'source_review_status' => (string) ($asset['review_status'] ?? ''),
            ]);
            $slots[(string) $slot['slot_key'].':'.$state] = $slot;
        }

        return $slots;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function structuralDifferenceSlots(): array
    {
        $slots = [];
        foreach ((array) $this->privateResultSource->get('generic_slots.structural_difference', []) as $slotName => $content) {
            if (is_array($content)) {
                $slots[$slotName] = $this->structuralDifferenceSlot((string) $slotName, $content);
            }
        }

        return $slots;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveStructuralDifferenceSlot(string $slotName): array
    {
        $slotName = trim($slotName);
        $slot = $this->structuralDifferenceSlots()[$slotName] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => 'structural_difference_copy',
                'slot_name' => $slotName,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_structural_difference_slot',
            ];
        }

        return $slot;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function aspirationsSlots(): array
    {
        $slots = [];
        foreach ((array) $this->privateResultSource->get('generic_slots.aspirations', []) as $slotName => $content) {
            if (is_array($content)) {
                $slots[$slotName] = $this->aspirationSlot((string) $slotName, $content);
            }
        }

        return array_merge($slots, $this->aspirationAssetSlots());
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveAspirationsSlot(string $slotName): array
    {
        $slotName = trim($slotName);
        $slot = $this->aspirationsSlots()[$slotName] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => 'aspirations_calibration_copy',
                'slot_name' => $slotName,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_aspirations_slot',
            ];
        }

        return $slot;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function disagreePathSlots(): array
    {
        $slots = [];
        foreach ((array) $this->privateResultSource->get('generic_slots.disagree', []) as $slotName => $content) {
            if (is_array($content)) {
                $slots[$slotName] = $this->disagreePathSlot((string) $slotName, $content);
            }
        }

        return array_merge($slots, $this->disagreePathAssetSlots());
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveDisagreePathSlot(string $slotName): array
    {
        $slotName = trim($slotName);
        $slot = $this->disagreePathSlots()[$slotName] ?? null;

        if ($slot === null) {
            return [
                'slot_key' => 'disagree_path_copy',
                'slot_name' => $slotName,
                'content_status' => 'unavailable',
                'module_state' => 'omitted',
                'fallback_behavior' => 'omit_module',
                'frontend_fallback_allowed' => false,
                'reason' => 'unsupported_disagree_path_slot',
            ];
        }

        return $slot;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function aspirationAssetSlots(): array
    {
        $slots = [];
        foreach ($this->aspirationAssetRows() as $row) {
            $slotName = (string) $row['domain_key'];
            $questions = array_values(array_map('strval', (array) $row['reality_questions']));
            $slot = $this->aspirationSlot($slotName, [
                'title' => (string) $row['user_aspiration_label'],
                'summary' => (string) $row['overlap_reading'],
                'body' => (string) $row['next_low_risk_experiment'],
                'example_question' => (string) ($questions[0] ?? ''),
                'aspirations_state' => $this->aspirationStateFromAssetRow($row),
                'content_version' => 'aspirations_calibration_v1.zh-CN',
                'evidence_level' => 'expert_review_required',
                'source_status' => 'reviewed_content_copy',
                'review_status' => 'content_review',
                'required_boundaries' => array_values(array_unique(array_merge(
                    $this->requiredBoundaries(),
                    array_map('strval', (array) $row['required_boundaries'])
                ))),
                'forbidden_claims' => array_values(array_map('strval', (array) $row['forbidden_claims'])),
                'applicable_dimensions' => array_values(array_map('strval', (array) $row['likely_overlap_dimensions'])),
                'validation_questions_only' => (bool) ($row['validation_questions_only'] ?? true),
                'aspiration_override_allowed' => (bool) ($row['aspiration_override_allowed'] ?? false),
                'aspiration_replaces_measured_result_allowed' => (bool) ($row['aspiration_replaces_measured_result_allowed'] ?? false),
                'recommended_output' => (string) ($row['recommended_output'] ?? 'validation_questions_and_low_risk_experiment'),
                'result_binding' => (string) ($row['result_binding'] ?? 'overlay_only_does_not_mutate_measured_result'),
            ]);

            if ($this->validateSlot($slot) === []) {
                $slots[$slotName] = $slot;
            }
        }

        return $slots;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function disagreePathAssetSlots(): array
    {
        $slots = [];
        foreach ($this->disagreePathAssetRows() as $row) {
            $slotName = (string) $row['state'];
            $questions = array_values(array_map('strval', (array) $row['questions']));
            $slot = $this->disagreePathSlot($slotName, [
                'title' => (string) $row['title'],
                'summary' => (string) $row['summary'],
                'body' => (string) $row['recommended_next_action'],
                'example_question' => (string) ($questions[0] ?? ''),
                'disagree_state' => $this->disagreeStateFromAssetRow($row),
                'content_version' => 'disagree_path_v1.zh-CN',
                'evidence_level' => 'expert_review_required',
                'source_status' => 'reviewed_content_copy',
                'review_status' => 'content_review',
                'required_boundaries' => array_values(array_unique(array_merge(
                    $this->requiredBoundaries(),
                    array_map('strval', (array) $row['required_boundaries'])
                ))),
                'forbidden_claims' => array_values(array_map('strval', (array) $row['forbidden_claims'])),
                'report_snapshot_mutation_allowed' => false,
                'share_pdf_payload_expansion_allowed' => false,
                'raw_feedback_exposure_allowed' => false,
                'next_steps_only' => (bool) ($row['next_steps_only'] ?? true),
                'feedback_replaces_measured_result_allowed' => (bool) ($row['feedback_replaces_measured_result_allowed'] ?? false),
                'result_override_allowed' => (bool) ($row['result_override_allowed'] ?? false),
                'snapshot_share_pdf_mutation_allowed' => (bool) ($row['snapshot_share_pdf_mutation_allowed'] ?? false),
                'raw_feedback_public_exposure_allowed' => (bool) ($row['raw_feedback_public_exposure_allowed'] ?? false),
                'recommended_output' => (string) ($row['recommended_output'] ?? 'next_steps_and_optional_retake_only'),
                'result_binding' => (string) ($row['result_binding'] ?? 'overlay_only_does_not_mutate_snapshot_share_pdf'),
            ]);

            if ($this->validateSlot($slot) === []) {
                $slots[$slotName] = $slot;
            }
        }

        return $slots;
    }

    /**
     * @return list<string>
     */
    public function validateSlot(array $slot): array
    {
        $contractResult = $this->contract->validate($slot);
        $errors = $contractResult['errors'];

        if (($slot['slot_key'] ?? null) === 'dimension_deep_copy') {
            foreach ($this->dimensionRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['dimension_code'] ?? ''), self::DIMENSIONS, true)) {
                $errors[] = 'unsupported_dimension_code';
            }
        }

        if (($slot['slot_key'] ?? null) === 'pair_blend_copy') {
            foreach ($this->pairRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['pair_key'] ?? ''), self::PAIRS, true)) {
                $errors[] = 'unsupported_pair_key';
            }
            if (($slot['content_status'] ?? null) === 'authored') {
                foreach ($this->authoredPairRequiredFields() as $field) {
                    if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                        $errors[] = 'missing_'.$field;
                    }
                }
            }
        }

        if (($slot['slot_key'] ?? null) === 'triad_blend_copy') {
            foreach ($this->top3ChainRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['top3_key'] ?? ''), self::TOP3_COMBOS, true)) {
                $errors[] = 'unsupported_top3_key';
            }
            if (($slot['content_status'] ?? null) === 'authored') {
                foreach ($this->authoredTop3ChainRequiredFields() as $field) {
                    if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                        $errors[] = 'missing_'.$field;
                    }
                }
            }
        }

        if (($slot['slot_group'] ?? null) === '140q_layer_copy') {
            foreach ($this->layer140qRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['layer_state'] ?? ''), self::LAYER_140Q_STATES, true)) {
                $errors[] = 'unsupported_140q_layer_state';
            }
        }

        if (($slot['slot_group'] ?? null) === 'quality_copy') {
            foreach ($this->qualityCopyRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['quality_state'] ?? ''), self::QUALITY_COPY_STATES, true)) {
                $errors[] = 'unsupported_quality_copy_state';
            }
            foreach (['user_blame_allowed', 'upsell_140q_allowed', 'strong_interpretation_allowed', 'result_mutation_allowed'] as $flag) {
                if (($slot[$flag] ?? true) !== false) {
                    $errors[] = 'quality_copy_'.$flag.'_must_be_false';
                }
            }
            if (! in_array((string) ($slot['recommended_action_type'] ?? ''), [
                'cautious_reading_or_retake_only',
                'cautious_reading_or_low_risk_observation',
                'minimal_boundary_cautious_reading',
                'retake_when_ready',
                'hide_strong_interpretation',
                'safe_private_record_only',
            ], true)) {
                $errors[] = 'unsupported_quality_copy_recommended_action_type';
            }
        }

        if (($slot['slot_group'] ?? null) === 'structural_difference_copy') {
            foreach ($this->structuralDifferenceRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['structural_difference_state'] ?? ''), self::STRUCTURAL_DIFFERENCE_STATES, true)) {
                $errors[] = 'unsupported_structural_difference_state';
            }
            if (($slot['emphasis_difference_only'] ?? false) !== true) {
                $errors[] = 'structural_difference_emphasis_difference_only_must_be_true';
            }
            foreach ([
                'correctness_ranking_allowed',
                'raw_score_comparison_allowed',
                'result_override_allowed',
                'code_conversion_allowed',
            ] as $flag) {
                if (($slot[$flag] ?? true) !== false) {
                    $errors[] = 'structural_difference_'.$flag.'_must_be_false';
                }
            }
        }

        if (($slot['slot_key'] ?? null) === 'aspirations_calibration_copy') {
            foreach ($this->aspirationsRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['aspirations_state'] ?? ''), self::ASPIRATIONS_STATES, true)) {
                $errors[] = 'unsupported_aspirations_state';
            }
            if (($slot['validation_questions_only'] ?? false) !== true) {
                $errors[] = 'aspirations_validation_questions_only_must_be_true';
            }
            foreach (['aspiration_override_allowed', 'aspiration_replaces_measured_result_allowed'] as $flag) {
                if (($slot[$flag] ?? true) !== false) {
                    $errors[] = 'aspirations_'.$flag.'_must_be_false';
                }
            }
            if ((string) ($slot['recommended_output'] ?? '') !== 'validation_questions_and_low_risk_experiment') {
                $errors[] = 'unsupported_aspirations_recommended_output';
            }
            if ((string) ($slot['result_binding'] ?? '') !== 'overlay_only_does_not_mutate_measured_result') {
                $errors[] = 'unsupported_aspirations_result_binding';
            }
        }

        if (($slot['slot_key'] ?? null) === 'disagree_path_copy') {
            foreach ($this->disagreePathRequiredFields() as $field) {
                if (! array_key_exists($field, $slot) || $this->isBlank($slot[$field])) {
                    $errors[] = 'missing_'.$field;
                }
            }
            if (! in_array((string) ($slot['disagree_state'] ?? ''), self::DISAGREE_STATES, true)) {
                $errors[] = 'unsupported_disagree_state';
            }
            if (($slot['next_steps_only'] ?? false) !== true) {
                $errors[] = 'disagree_next_steps_only_must_be_true';
            }
            foreach ([
                'feedback_replaces_measured_result_allowed',
                'result_override_allowed',
                'snapshot_share_pdf_mutation_allowed',
                'raw_feedback_public_exposure_allowed',
            ] as $flag) {
                if (($slot[$flag] ?? true) !== false) {
                    $errors[] = 'disagree_'.$flag.'_must_be_false';
                }
            }
            if ((string) ($slot['recommended_output'] ?? '') !== 'next_steps_and_optional_retake_only') {
                $errors[] = 'unsupported_disagree_recommended_output';
            }
            if ((string) ($slot['result_binding'] ?? '') !== 'overlay_only_does_not_mutate_snapshot_share_pdf') {
                $errors[] = 'unsupported_disagree_result_binding';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    public function dimensionRequiredFields(): array
    {
        return [
            'dimension_code',
            'title',
            'core_drive',
            'positive_value',
            'real_world_cost',
            'high_score_reading',
            'medium_score_reading',
            'low_score_safe_reading',
            'work_activity_examples',
            'interest_activity_focus',
            'possible_drains',
            'context_costs',
            'common_misread',
            'misread_guardrails',
            'action_advice',
            'validation_questions',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
        ];
    }

    /**
     * @return list<string>
     */
    public function pairRequiredFields(): array
    {
        return [
            'pair_key',
            'pair_label',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
            'content_status',
        ];
    }

    /**
     * @return list<string>
     */
    public function authoredPairRequiredFields(): array
    {
        return [
            'chemistry',
            'positive_value',
            'pair_tension',
            'real_world_cost',
            'context_costs',
            'common_misread',
            'activities_to_validate',
            'validation_questions',
        ];
    }

    /**
     * @return list<string>
     */
    public function top3ChainRequiredFields(): array
    {
        return [
            'top3_key',
            'strategy_label',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
            'content_status',
        ];
    }

    /**
     * @return list<string>
     */
    public function authoredTop3ChainRequiredFields(): array
    {
        return [
            'activity_chain',
            'core_reading',
            'positive_value',
            'real_world_cost',
            'first_experiment',
            'primary_activity_chain',
            'secondary_support_line',
            'tertiary_stabilizer',
            'likely_tension',
            'activity_sequence',
            'when_to_use_140q',
            'when_not_to_overread',
        ];
    }

    /**
     * @return list<string>
     */
    public function layer140qRequiredFields(): array
    {
        return [
            'slot_name',
            'title',
            'summary',
            'layer_state',
            'layer_focus',
            'science_boundary',
            'observation_question',
            'contextual_detail_only',
            'result_mutation_allowed',
            'raw_score_comparison_allowed',
            'accuracy_upgrade_claim_allowed',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
            'content_status',
        ];
    }

    /**
     * @return list<string>
     */
    public function qualityCopyRequiredFields(): array
    {
        return [
            'slot_name',
            'title',
            'summary',
            'quality_state',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
            'content_status',
            'user_blame_allowed',
            'upsell_140q_allowed',
            'strong_interpretation_allowed',
            'result_mutation_allowed',
            'recommended_action_type',
        ];
    }

    /**
     * @return list<string>
     */
    public function structuralDifferenceRequiredFields(): array
    {
        return [
            'slot_name',
            'title',
            'summary',
            'structural_difference_state',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
            'content_status',
            'emphasis_difference_only',
            'correctness_ranking_allowed',
            'raw_score_comparison_allowed',
            'result_override_allowed',
            'code_conversion_allowed',
            'selection_basis',
        ];
    }

    /**
     * @return list<string>
     */
    public function aspirationsRequiredFields(): array
    {
        return [
            'slot_name',
            'title',
            'summary',
            'aspirations_state',
            'affects_measured_code',
            'affects_score',
            'report_snapshot_mutation_allowed',
            'share_pdf_payload_expansion_allowed',
            'raw_feedback_exposure_allowed',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
            'content_status',
            'validation_questions_only',
            'aspiration_override_allowed',
            'aspiration_replaces_measured_result_allowed',
            'recommended_output',
            'result_binding',
        ];
    }

    /**
     * @return list<string>
     */
    public function disagreePathRequiredFields(): array
    {
        return [
            'slot_name',
            'title',
            'summary',
            'disagree_state',
            'affects_measured_code',
            'affects_score',
            'report_snapshot_mutation_allowed',
            'share_pdf_payload_expansion_allowed',
            'raw_feedback_exposure_allowed',
            'forbidden_claims',
            'user_visible_boundary',
            'content_version',
            'evidence_level',
            'content_status',
            'next_steps_only',
            'feedback_replaces_measured_result_allowed',
            'result_override_allowed',
            'snapshot_share_pdf_mutation_allowed',
            'raw_feedback_public_exposure_allowed',
            'recommended_output',
            'result_binding',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function dimensionContent(): array
    {
        $assetSlots = $this->dimensionContentFromAsset();

        return $assetSlots;
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function dimensionSlot(string $dimensionCode, string $title, array $content): array
    {
        return array_merge([
            'slot_key' => 'dimension_deep_copy',
            'slot_group' => 'dimension_deep_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => self::CONTENT_VERSION,
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie', 'low_clarity'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_dimensions' => [$dimensionCode],
            'dimension_code' => $dimensionCode,
            'title' => $title,
            'forbidden_claims' => [
                'ability_or_skill_inference',
                'personality_identity',
                'job_fit',
                'career_success_prediction',
                'hiring_or_screening_use',
                'occupation_matching',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.dimension'),
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'approved_for_production',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'frontend_fallback_allowed' => false,
        ], $content);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function dimensionContentFromAsset(): array
    {
        if ($this->dimensionContentCache !== null) {
            return $this->dimensionContentCache;
        }

        $path = dirname(__DIR__, 3).self::DIMENSION_ASSET_PATH;
        if (! is_file($path)) {
            return $this->dimensionContentCache = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return $this->dimensionContentCache = [];
        }

        $dimensions = $decoded['dimensions'] ?? null;
        if (! is_array($dimensions)) {
            return $this->dimensionContentCache = [];
        }

        $allowedContentFields = array_flip([
            'core_drive',
            'positive_value',
            'real_world_cost',
            'high_score_reading',
            'medium_score_reading',
            'low_score_safe_reading',
            'work_activity_examples',
            'interest_activity_focus',
            'possible_drains',
            'context_costs',
            'common_misread',
            'misread_guardrails',
            'action_advice',
            'validation_questions',
            'required_boundaries',
            'forbidden_claims',
            'content_status',
            'content_version',
            'review_status',
            'evidence_level',
            'source_status',
            'user_visible_boundary',
        ]);

        $slots = [];
        foreach ($dimensions as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $dimensionCode = strtoupper(trim((string) ($dimension['dimension_code'] ?? '')));
            if (! in_array($dimensionCode, self::DIMENSIONS, true)) {
                continue;
            }

            $title = trim((string) ($dimension['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $content = array_intersect_key($dimension, $allowedContentFields);
            $content['content_version'] = (string) ($content['content_version'] ?? $decoded['content_version'] ?? self::CONTENT_VERSION);
            $content['review_status'] = (string) ($content['review_status'] ?? $decoded['review_status'] ?? 'approved_for_production');
            $content['evidence_level'] = (string) ($content['evidence_level'] ?? $decoded['evidence_level'] ?? 'expert_reviewed');
            $content['source_status'] = (string) ($content['source_status'] ?? $decoded['source_status'] ?? 'reviewed_content_copy');

            $slots[$dimensionCode] = $this->dimensionSlot($dimensionCode, $title, $content);
        }

        return $this->dimensionContentCache = $slots;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function pairBlendContentFromAsset(): array
    {
        if ($this->pairBlendContentCache !== null) {
            return $this->pairBlendContentCache;
        }

        $path = dirname(__DIR__, 3).self::PAIR_BLEND_ASSET_PATH;
        if (! is_file($path)) {
            return $this->pairBlendContentCache = [];
        }

        $slots = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $pairKey = strtoupper(trim((string) ($decoded['pair_key'] ?? '')));
            if (! in_array($pairKey, self::PAIRS, true)) {
                continue;
            }

            $content = $this->normalizePairBlendAssetRow($decoded);
            $slot = $this->authoredPairSlot($pairKey, $content);
            if ($this->validateSlot($slot) !== []) {
                continue;
            }

            $slots[$pairKey] = $content;
        }

        return $this->pairBlendContentCache = $slots;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizePairBlendAssetRow(array $row): array
    {
        $pairKey = strtoupper(trim((string) ($row['pair_key'] ?? '')));
        $dimensions = $row['dimensions'] ?? explode('_', $pairKey);

        return [
            'content_version' => (string) ($row['asset_version'] ?? 'pair_blend_15_pairs_v1.zh-CN'),
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'content_review',
            'evidence_level' => 'expert_reviewed',
            'content_status' => 'authored',
            'applicable_form_codes' => $row['applicable_form_codes'] ?? ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => $row['applicable_profile_shapes'] ?? ['clear_code', 'blended_code', 'near_tie'],
            'applicable_quality_states' => $row['applicable_quality_states'] ?? ['normal', 'caution'],
            'applicable_dimensions' => is_array($dimensions) ? array_values($dimensions) : explode('_', $pairKey),
            'pair_label' => (string) ($row['pair_label'] ?? ''),
            'short_label' => (string) ($row['short_label'] ?? ''),
            'chemistry' => (string) ($row['chemistry'] ?? ''),
            'positive_value' => (string) ($row['positive_value'] ?? ''),
            'pair_tension' => (string) ($row['pair_tension'] ?? ''),
            'real_world_cost' => (string) ($row['real_world_cost'] ?? ''),
            'context_costs' => $row['context_costs'] ?? [],
            'common_misread' => (string) ($row['common_misread'] ?? ''),
            'activities_to_validate' => $row['activities_to_validate'] ?? [],
            'validation_questions' => $row['validation_questions'] ?? [],
            'micro_experiment' => (string) ($row['micro_experiment'] ?? ''),
            'result_page_teaser' => (string) ($row['result_page_teaser'] ?? ''),
            'deep_report_extension_hint' => (string) ($row['deep_report_extension_hint'] ?? ''),
            'forbidden_claims' => $row['forbidden_claims'] ?? [
                'personality_identity',
                'career_match',
                'ability_proof',
                'success_prediction',
                'job_fit',
            ],
            'required_boundaries' => $row['required_boundaries'] ?? $this->requiredBoundaries(),
            'user_visible_boundary' => (string) ($row['user_visible_boundary'] ?? $this->privateResultSource->get('boundaries.pair')),
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function top3ChainContentFromAsset(): array
    {
        if ($this->top3ChainContentCache !== null) {
            return $this->top3ChainContentCache;
        }

        $path = dirname(__DIR__, 3).self::TOP3_CHAIN_ASSET_PATH;
        if (! is_file($path)) {
            return $this->top3ChainContentCache = [];
        }

        $slots = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $top3Key = strtoupper(trim((string) ($decoded['unordered_top3_key'] ?? '')));
            if (! in_array($top3Key, self::TOP3_COMBOS, true)) {
                continue;
            }

            $content = $this->normalizeTop3ChainAssetRow($decoded);
            $slot = $this->authoredTop3ChainSlot($top3Key, $content);
            if ($this->validateSlot($slot) !== []) {
                continue;
            }

            $slots[$top3Key] = $content;
        }

        return $this->top3ChainContentCache = $slots;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeTop3ChainAssetRow(array $row): array
    {
        $top3Key = strtoupper(trim((string) ($row['unordered_top3_key'] ?? '')));
        $dimensions = $row['dimensions'] ?? explode('_', $top3Key);

        return [
            'content_version' => (string) ($row['asset_version'] ?? 'top3_code_chain_strategy_v1.zh-CN'),
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'content_review',
            'evidence_level' => 'expert_reviewed',
            'content_status' => 'authored',
            'applicable_form_codes' => $row['applicable_form_codes'] ?? ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => $row['applicable_profile_shapes'] ?? ['clear_code', 'blended_code', 'near_tie'],
            'applicable_quality_states' => $row['applicable_quality_states'] ?? ['normal', 'caution'],
            'applicable_dimensions' => is_array($dimensions) ? array_values($dimensions) : explode('_', $top3Key),
            'strategy_label' => (string) ($row['strategy_label'] ?? ''),
            'activity_chain' => (string) ($row['activity_chain'] ?? ''),
            'core_reading' => (string) ($row['core_reading'] ?? ''),
            'positive_value' => (string) ($row['positive_value'] ?? ''),
            'real_world_cost' => (string) ($row['real_world_cost'] ?? ''),
            'first_experiment' => (string) ($row['first_experiment'] ?? ''),
            'ordered_code_handling' => (string) ($row['ordered_code_handling'] ?? ''),
            'low_risk_validation' => (string) ($row['low_risk_validation'] ?? ''),
            'primary_activity_chain' => (string) ($row['primary_activity_chain'] ?? ''),
            'secondary_support_line' => (string) ($row['secondary_support_line'] ?? ''),
            'tertiary_stabilizer' => (string) ($row['tertiary_stabilizer'] ?? ''),
            'likely_tension' => (string) ($row['likely_tension'] ?? ''),
            'activity_sequence' => $row['activity_sequence'] ?? [],
            'when_to_use_140q' => (string) ($row['when_to_use_140q'] ?? ''),
            'when_not_to_overread' => (string) ($row['when_not_to_overread'] ?? ''),
            'free_page_teaser' => (string) ($row['free_page_teaser'] ?? ''),
            'deep_report_extension' => (string) ($row['deep_report_extension'] ?? ''),
            'forbidden_claims' => $row['forbidden_claims'] ?? [
                'personality_identity',
                'career_match',
                'ability_proof',
                'success_prediction',
                'job_fit',
            ],
            'required_boundaries' => $row['required_boundaries'] ?? $this->requiredBoundaries(),
            'user_visible_boundary' => (string) ($row['user_visible_boundary'] ?? $this->privateResultSource->get('boundaries.top3')),
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function layer140qContentFromAsset(): array
    {
        if ($this->layer140qContentCache !== null) {
            return $this->layer140qContentCache;
        }

        $path = dirname(__DIR__, 3).self::LAYER_140Q_ASSET_PATH;
        if (! is_file($path)) {
            return $this->layer140qContentCache = [];
        }

        $slots = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $dimensionCode = strtoupper(trim((string) ($decoded['dimension'] ?? '')));
            $layer = strtolower(trim((string) ($decoded['layer'] ?? '')));
            $layerState = $this->normalize140qLayerState((string) ($decoded['layer_state'] ?? ''));

            if (! in_array($dimensionCode, self::DIMENSIONS, true)) {
                continue;
            }
            if (! in_array($layer, self::LAYER_140Q_DIMENSION_LAYERS, true)) {
                continue;
            }
            if (! in_array($layerState, self::LAYER_140Q_STATES, true)) {
                continue;
            }

            $slotName = $this->layer140qAssetSlotName($dimensionCode, $layer, $layerState);
            $content = $this->normalize140qLayerAssetRow($decoded, $dimensionCode, $layer, $layerState, $slotName);
            $slot = $this->authored140qAssetSlot($slotName, $content);
            if ($this->validateSlot($slot) !== []) {
                continue;
            }

            $slots[$slotName] = $content;
        }

        return $this->layer140qContentCache = $slots;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalize140qLayerAssetRow(array $row, string $dimensionCode, string $layer, string $layerState, string $slotName): array
    {
        return [
            'slot_key' => $this->layer140qSlotKeyForLayer($layer),
            'content_version' => (string) ($row['asset_version'] ?? 'riasec_140q_task_environment_role_v1.zh-CN'),
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'content_review',
            'evidence_level' => 'expert_reviewed',
            'content_status' => 'authored',
            'applicable_form_codes' => $layerState === 'not_applicable_60q_only' || $layerState === '60q_only_cta'
                ? ['riasec_60']
                : ['riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie', 'low_clarity'],
            'applicable_quality_states' => $layerState === 'low_quality_hidden'
                ? ['low_quality', 'retake_recommended']
                : ['normal', 'caution'],
            'applicable_codes' => [$dimensionCode.'_'.$layer.'_'.$layerState],
            'applicable_dimensions' => [$dimensionCode],
            'slot_name' => $slotName,
            'layer_dimension' => $dimensionCode,
            'layer' => $layer,
            'layer_state' => $layerState,
            'title' => (string) ($row['title'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'layer_focus' => (string) ($row['layer_focus'] ?? ''),
            'science_boundary' => (string) ($row['science_boundary'] ?? ''),
            'observation_question' => (string) ($row['observation_question'] ?? ''),
            'contextual_detail_only' => (bool) ($row['contextual_detail_only'] ?? true),
            'result_mutation_allowed' => (bool) ($row['result_mutation_allowed'] ?? false),
            'raw_score_comparison_allowed' => (bool) ($row['raw_score_comparison_allowed'] ?? false),
            'accuracy_upgrade_claim_allowed' => (bool) ($row['accuracy_upgrade_claim_allowed'] ?? false),
            'example_question' => (string) ($row['example_question'] ?? ''),
            'task_activity_card' => (string) ($row['task_activity_card'] ?? ''),
            'environment_card' => (string) ($row['environment_card'] ?? ''),
            'role_responsibility_card' => (string) ($row['role_responsibility_card'] ?? ''),
            'forbidden_claims' => $row['forbidden_claims'] ?? [
                '140q_accuracy_claim',
                '60q_override',
                'raw_score_delta',
                'job_fit',
                'ability_or_skill_inference',
            ],
            'required_boundaries' => array_values(array_unique(array_merge(
                $this->requiredBoundaries(),
                array_values((array) ($row['required_boundaries'] ?? []))
            ))),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.layer_140q'),
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function authored140qAssetSlot(string $slotName, array $content): array
    {
        return array_merge($this->layer140qAssetSlotBase($slotName, $content), $content, [
            'content_status' => 'authored',
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function layer140qAssetSlotBase(string $slotName, array $content): array
    {
        return [
            'slot_key' => (string) ($content['slot_key'] ?? '140q_layer_unavailable_copy'),
            'slot_group' => '140q_layer_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => 'riasec_140q_task_environment_role_v1.zh-CN',
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie', 'low_clarity'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_codes' => [$slotName],
            'slot_name' => $slotName,
            'forbidden_claims' => [
                '140q_accuracy_claim',
                '60q_override',
                'raw_score_delta',
                'job_fit',
                'ability_or_skill_inference',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.layer_140q'),
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'content_review',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function authoredPairSlot(string $pairKey, array $content): array
    {
        return array_merge($this->pairSlotBase($pairKey), $content, [
            'content_status' => 'authored',
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingPairSlot(string $pairKey): array
    {
        return array_merge($this->pairSlotBase($pairKey), [
            'content_version' => 'riasec_pair_blend_pending_v1',
            'pair_label' => str_replace('_', '×', $pairKey),
            'source_status' => 'docs_only_candidate',
            'review_status' => 'content_review',
            'evidence_level' => 'expert_review_required',
            'content_status' => 'pending',
            'module_state' => 'omitted',
            'reason' => 'pair_blend_copy_not_approved_for_runtime',
        ]);
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function authoredTop3ChainSlot(string $top3Key, array $content): array
    {
        return array_merge($this->top3ChainSlotBase($top3Key), $content, [
            'content_status' => 'authored',
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingTop3ChainSlot(string $top3Key): array
    {
        return array_merge($this->top3ChainSlotBase($top3Key), [
            'content_version' => 'riasec_top3_chain_pending_v1',
            'strategy_label' => str_replace('_', '×', $top3Key),
            'source_status' => 'docs_only_candidate',
            'review_status' => 'content_review',
            'evidence_level' => 'expert_review_required',
            'content_status' => 'pending',
            'module_state' => 'omitted',
            'reason' => 'top3_code_chain_strategy_not_approved_for_runtime',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function top3ChainSlotBase(string $top3Key): array
    {
        $dimensions = explode('_', $top3Key);

        return [
            'slot_key' => 'triad_blend_copy',
            'slot_group' => 'pair_blend_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'near_tie'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_codes' => [$top3Key],
            'applicable_dimensions' => $dimensions,
            'top3_key' => $top3Key,
            'unordered_top3_key' => $top3Key,
            'forbidden_claims' => [
                'personality_identity',
                'career_match',
                'ability_proof',
                'success_prediction',
                'job_fit',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.top3'),
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @param  list<string>  $orderedDimensions
     * @return array<string,mixed>
     */
    private function withOrderedTop3Emphasis(array $slot, array $orderedDimensions): array
    {
        if (count($orderedDimensions) !== 3) {
            return $slot;
        }

        $canonical = (array) ($slot['applicable_dimensions'] ?? explode('_', (string) ($slot['top3_key'] ?? '')));
        sort($canonical);
        $orderedCanonical = $orderedDimensions;
        sort($orderedCanonical);
        if ($canonical !== $orderedCanonical) {
            return $slot;
        }

        $readingLines = (array) $this->privateResultSource->get('dimension_reading_lines', []);
        $first = $readingLines[$orderedDimensions[0]] ?? null;
        $second = $readingLines[$orderedDimensions[1]] ?? null;
        $third = $readingLines[$orderedDimensions[2]] ?? null;
        if ($first === null || $second === null || $third === null) {
            return $slot;
        }

        $labels = [$first['label'], $second['label'], $third['label']];
        $shorts = [$first['short'], $second['short'], $third['short']];
        $orderedCode = implode('', $orderedDimensions);
        $orderedKey = implode('_', $orderedDimensions);
        $tokens = [
            '{code}' => $orderedCode,
            '{labels}' => implode('、', $labels),
            '{shorts}' => implode('-', $shorts),
            '{first_label}' => (string) $first['label'],
            '{second_label}' => (string) $second['label'],
            '{third_label}' => (string) $third['label'],
            '{first_short}' => (string) $first['short'],
            '{second_short}' => (string) $second['short'],
            '{third_short}' => (string) $third['short'],
            '{first_focus}' => (string) $first['focus'],
            '{second_focus}' => (string) $second['focus'],
            '{third_focus}' => (string) $third['focus'],
            '{first_action}' => (string) $first['action'],
            '{second_action}' => (string) $second['action'],
            '{third_action}' => (string) $third['action'],
        ];
        $templates = (array) $this->privateResultSource->get('top3_ordered_templates', []);

        return array_merge($slot, [
            'ordered_code' => $orderedCode,
            'ordered_top3_key' => $orderedKey,
            'ordered_top3_dimensions' => $orderedDimensions,
            'canonical_unordered_top3_key' => (string) ($slot['top3_key'] ?? ''),
            'strategy_label' => strtr((string) ($templates['strategy_label'] ?? ''), $tokens),
            'activity_chain' => strtr((string) ($templates['activity_chain'] ?? ''), $tokens),
            'core_reading' => strtr((string) ($templates['core_reading'] ?? ''), $tokens),
            'positive_value' => strtr((string) ($templates['positive_value'] ?? ''), $tokens),
            'first_experiment' => strtr((string) ($templates['first_experiment'] ?? ''), $tokens),
            'ordered_code_handling' => strtr((string) ($templates['ordered_code_handling'] ?? ''), $tokens),
            'low_risk_validation' => strtr((string) ($templates['low_risk_validation'] ?? ''), $tokens),
            'primary_activity_chain' => strtr((string) ($templates['primary_activity_chain'] ?? ''), $tokens),
            'secondary_support_line' => strtr((string) ($templates['secondary_support_line'] ?? ''), $tokens),
            'tertiary_stabilizer' => strtr((string) ($templates['tertiary_stabilizer'] ?? ''), $tokens),
            'activity_sequence' => array_map(static fn (string $template): string => strtr($template, $tokens), (array) ($templates['activity_sequence'] ?? [])),
            'free_page_teaser' => strtr((string) ($templates['free_page_teaser'] ?? ''), $tokens),
        ]);
    }

    /**
     * @param  list<string>|string  $top3
     * @return list<string>
     */
    private function orderedTop3Dimensions(array|string $top3): array
    {
        $raw = is_array($top3) ? implode('', $top3) : $top3;
        $letters = [];
        foreach (str_split(strtoupper((string) preg_replace('/[^RIASEC]/i', '', (string) $raw))) as $letter) {
            if (in_array($letter, self::DIMENSIONS, true) && ! in_array($letter, $letters, true)) {
                $letters[] = $letter;
            }
            if (count($letters) === 3) {
                break;
            }
        }

        return $letters;
    }

    /**
     * @return array<string,mixed>
     */
    private function pairSlotBase(string $pairKey): array
    {
        $dimensions = explode('_', $pairKey);

        return [
            'slot_key' => 'pair_blend_copy',
            'slot_group' => 'pair_blend_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'near_tie'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_codes' => [$pairKey],
            'applicable_dimensions' => $dimensions,
            'pair_key' => $pairKey,
            'forbidden_claims' => [
                'personality_identity',
                'career_match',
                'ability_proof',
                'success_prediction',
                'job_fit',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.pair'),
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function lowQualitySlotsFromAsset(): array
    {
        $asset = $this->lowQualityAsset();
        $copyBySlot = [];
        foreach ((array) ($asset['copy_slots'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slotName = (string) ($row['slot'] ?? '');
            if (! in_array($slotName, ['top_notice', 'user_not_blamed_message', 'what_happened_explanation', 'retake_guidance', 'hidden_modules_explanation', 'share_pdf_boundary', 'next_step'], true)) {
                continue;
            }
            $recommendedActionType = (string) ($row['recommended_action_type'] ?? match ($slotName) {
                'retake_guidance' => 'retake_when_ready',
                'hidden_modules_explanation' => 'hide_strong_interpretation',
                'share_pdf_boundary' => 'safe_private_record_only',
                default => 'cautious_reading_or_retake_only',
            });
            $copyBySlot[$slotName] = [
                'title' => (string) ($row['title'] ?? ''),
                'summary' => (string) ($row['text'] ?? ''),
                'quality_state' => in_array($slotName, ['retake_guidance', 'next_step'], true)
                    ? 'retake_recommended'
                    : 'low_quality',
                'content_version' => (string) ($asset['asset_id'] ?? 'low_quality_cautious_reading_v1.zh-CN'),
                'user_visible_boundary' => (string) data_get($asset, 'runtime_copy_boundaries.default', ''),
                'user_blame_allowed' => false,
                'upsell_140q_allowed' => false,
                'strong_interpretation_allowed' => false,
                'result_mutation_allowed' => false,
                'recommended_action_type' => $recommendedActionType,
            ];
        }

        $states = [];
        foreach ((array) ($asset['states'] ?? []) as $row) {
            if (is_array($row) && isset($row['quality_state'])) {
                $states[(string) $row['quality_state']] = (string) ($row['copy'] ?? '');
            }
        }
        if (($states['caution'] ?? '') !== '') {
            $copyBySlot['cautious_reading_notice'] = [
                'title' => (string) data_get($asset, 'runtime_state_titles.caution', ''),
                'summary' => $states['caution'],
                'quality_state' => 'caution',
                'content_version' => (string) ($asset['asset_id'] ?? 'low_quality_cautious_reading_v1.zh-CN'),
                'user_visible_boundary' => (string) data_get($asset, 'runtime_copy_boundaries.caution', ''),
                'user_blame_allowed' => false,
                'upsell_140q_allowed' => false,
                'strong_interpretation_allowed' => false,
                'result_mutation_allowed' => false,
                'recommended_action_type' => 'cautious_reading_or_low_risk_observation',
            ];
        }
        if (($states['minimal_60q'] ?? '') !== '') {
            $copyBySlot['minimal_quality_boundary_60q'] = [
                'title' => (string) data_get($asset, 'runtime_state_titles.minimal_60q', ''),
                'summary' => $states['minimal_60q'],
                'quality_state' => 'minimal_quality_boundary_60q',
                'content_version' => (string) ($asset['asset_id'] ?? 'low_quality_cautious_reading_v1.zh-CN'),
                'user_visible_boundary' => (string) data_get($asset, 'runtime_copy_boundaries.minimal_60q', ''),
                'user_blame_allowed' => false,
                'upsell_140q_allowed' => false,
                'strong_interpretation_allowed' => false,
                'result_mutation_allowed' => false,
                'recommended_action_type' => 'minimal_boundary_cautious_reading',
            ];
        }

        return $copyBySlot;
    }

    /**
     * @return array<string,mixed>
     */
    private function lowQualityAsset(): array
    {
        if ($this->lowQualityAssetCache !== null) {
            return $this->lowQualityAssetCache;
        }

        return $this->lowQualityAssetCache = $this->jsonAsset(self::LOW_QUALITY_ASSET_PATH);
    }

    /**
     * @return array<string,mixed>
     */
    private function profileShapeAsset(): array
    {
        if ($this->profileShapeAssetCache !== null) {
            return $this->profileShapeAssetCache;
        }

        return $this->profileShapeAssetCache = $this->jsonAsset(self::PROFILE_SHAPE_ASSET_PATH);
    }

    /**
     * @return array<string,mixed>
     */
    private function topCodeConfidenceAsset(): array
    {
        if ($this->topCodeConfidenceAssetCache !== null) {
            return $this->topCodeConfidenceAssetCache;
        }

        return $this->topCodeConfidenceAssetCache = $this->jsonAsset(self::TOP_CODE_CONFIDENCE_ASSET_PATH);
    }

    /**
     * @return array<string,mixed>
     */
    private function nearTieAsset(): array
    {
        if ($this->nearTieAssetCache !== null) {
            return $this->nearTieAssetCache;
        }

        return $this->nearTieAssetCache = $this->jsonAsset(self::NEAR_TIE_ASSET_PATH);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function aspirationAssetRows(): array
    {
        if ($this->aspirationsAssetRowsCache !== null) {
            return $this->aspirationsAssetRowsCache;
        }

        $rows = [];
        foreach ($this->jsonlAssetRows(self::ASPIRATIONS_ASSET_PATH) as $row) {
            if ($this->isValidAspirationAssetRow($row)) {
                $rows[] = $row;
            }
        }

        return $this->aspirationsAssetRowsCache = $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function disagreePathAssetRows(): array
    {
        if ($this->disagreePathAssetRowsCache !== null) {
            return $this->disagreePathAssetRowsCache;
        }

        $rows = [];
        foreach ($this->jsonlAssetRows(self::DISAGREE_PATH_ASSET_PATH) as $row) {
            if ($this->isValidDisagreePathAssetRow($row)) {
                $rows[] = $row;
            }
        }

        return $this->disagreePathAssetRowsCache = $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function jsonlAssetRows(string $relativePath): array
    {
        $path = dirname(__DIR__, 3).$relativePath;
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                return [];
            }
            $rows[] = $decoded;
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function isValidAspirationAssetRow(array $row): bool
    {
        if (($row['schema_version'] ?? null) !== 'riasec.aspirations_calibration.v1') {
            return false;
        }
        if (($row['asset_version'] ?? null) !== 'riasec_aspirations_calibration_v1.zh-CN') {
            return false;
        }
        foreach (['domain_key', 'user_aspiration_label', 'overlap_reading', 'next_low_risk_experiment'] as $field) {
            if (! is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                return false;
            }
        }
        if (($row['score_mutation_allowed'] ?? true) !== false || ($row['measured_holland_code_mutation_allowed'] ?? true) !== false) {
            return false;
        }
        if (($row['validation_questions_only'] ?? false) !== true
            || ($row['aspiration_override_allowed'] ?? true) !== false
            || ($row['aspiration_replaces_measured_result_allowed'] ?? true) !== false
        ) {
            return false;
        }
        if (($row['recommended_output'] ?? null) !== 'validation_questions_and_low_risk_experiment'
            || ($row['result_binding'] ?? null) !== 'overlay_only_does_not_mutate_measured_result'
        ) {
            return false;
        }
        if (($row['not_a_recommendation'] ?? false) !== true || ($row['frontend_fallback_allowed'] ?? true) !== false) {
            return false;
        }
        if (! is_array($row['likely_overlap_dimensions'] ?? null) || ! is_array($row['reality_questions'] ?? null)) {
            return false;
        }
        foreach ((array) $row['likely_overlap_dimensions'] as $dimension) {
            if (! in_array((string) $dimension, self::DIMENSIONS, true)) {
                return false;
            }
        }

        return count((array) $row['reality_questions']) >= 3;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function isValidDisagreePathAssetRow(array $row): bool
    {
        if (($row['schema_version'] ?? null) !== 'riasec.disagree_path.v1') {
            return false;
        }
        if (($row['asset_version'] ?? null) !== 'riasec_disagree_path_v1.zh-CN') {
            return false;
        }
        foreach (['state', 'title', 'summary', 'recommended_next_action'] as $field) {
            if (! is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                return false;
            }
        }
        if (($row['score_mutation_allowed'] ?? true) !== false || ($row['measured_holland_code_mutation_allowed'] ?? true) !== false) {
            return false;
        }
        if (($row['snapshot_mutation_allowed'] ?? true) !== false || ($row['share_pdf_exposure_allowed'] ?? true) !== false) {
            return false;
        }
        if (($row['next_steps_only'] ?? false) !== true
            || ($row['feedback_replaces_measured_result_allowed'] ?? true) !== false
            || ($row['result_override_allowed'] ?? true) !== false
            || ($row['snapshot_share_pdf_mutation_allowed'] ?? true) !== false
            || ($row['raw_feedback_public_exposure_allowed'] ?? true) !== false
        ) {
            return false;
        }
        if (($row['recommended_output'] ?? null) !== 'next_steps_and_optional_retake_only'
            || ($row['result_binding'] ?? null) !== 'overlay_only_does_not_mutate_snapshot_share_pdf'
        ) {
            return false;
        }
        if (($row['frontend_fallback_allowed'] ?? true) !== false) {
            return false;
        }
        if (! is_array($row['questions'] ?? null)) {
            return false;
        }

        return count((array) $row['questions']) >= 3;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function aspirationStateFromAssetRow(array $row): string
    {
        $domainKey = (string) ($row['domain_key'] ?? '');
        if ($this->containsAny($domainKey, (array) $this->privateResultSource->get('aspiration_state_tokens.tension', []))) {
            return 'tension';
        }
        if ($this->containsAny($domainKey, (array) $this->privateResultSource->get('aspiration_state_tokens.needs_reality_check', []))) {
            return 'needs_reality_check';
        }

        return 'overlap';
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function disagreeStateFromAssetRow(array $row): string
    {
        $state = (string) ($row['state'] ?? '');
        if (str_contains($state, 'low_quality') || str_contains($state, 'retake')) {
            return 'retake_recommended';
        }
        if (str_contains($state, 'near_tie') || str_contains($state, 'broad') || str_contains($state, '60Q_140Q')) {
            return 'disagrees_quality_caution';
        }

        return 'disagrees_quality_normal';
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonAsset(string $relativePath): array
    {
        $path = dirname(__DIR__, 3).$relativePath;
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function layer140qSlot(string $slotKey, string $slotName, array $content): array
    {
        return array_merge([
            'slot_key' => $slotKey,
            'slot_group' => '140q_layer_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => 'riasec_140q_layer_state_copy_v1',
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie', 'low_clarity'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_codes' => ['any'],
            'slot_name' => $slotName,
            'layer_focus' => (string) ($content['question'] ?? $content['summary'] ?? ''),
            'science_boundary' => (string) $this->privateResultSource->get('boundaries.layer_140q'),
            'observation_question' => (string) ($content['question'] ?? $content['summary'] ?? ''),
            'contextual_detail_only' => (bool) ($content['contextual_detail_only'] ?? true),
            'result_mutation_allowed' => false,
            'raw_score_comparison_allowed' => false,
            'accuracy_upgrade_claim_allowed' => (bool) ($content['accuracy_upgrade_claim_allowed'] ?? false),
            'forbidden_claims' => [
                '140q_accuracy_claim',
                '60q_override',
                'raw_score_delta',
                'job_fit',
                'ability_or_skill_inference',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.layer_140q'),
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'approved_for_staging',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'frontend_fallback_allowed' => false,
        ], $content);
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function qualitySlot(string $slotKey, string $slotName, array $content): array
    {
        return array_merge([
            'slot_key' => $slotKey,
            'slot_group' => 'quality_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => 'riasec_low_quality_copy_slots_v1',
            'quality_rule_version' => 'riasec_quality_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['low_quality', 'low_clarity', 'broad_profile', 'clear_code', 'blended_code', 'near_tie'],
            'applicable_quality_states' => ['caution', 'low_quality', 'retake_recommended', 'minimal_quality_boundary_60q'],
            'applicable_codes' => ['any'],
            'slot_name' => $slotName,
            'forbidden_claims' => [
                'user_blame',
                '140q_upsell_on_low_quality',
                'career_recommendation',
                'accuracy_promise',
                'score_mutation',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.quality'),
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'approved_for_staging',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'frontend_fallback_allowed' => false,
            'user_blame_allowed' => false,
            'upsell_140q_allowed' => false,
            'strong_interpretation_allowed' => false,
            'result_mutation_allowed' => false,
            'recommended_action_type' => 'cautious_reading_or_retake_only',
        ], $content);
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function interpretationStateSlot(string $slotKey, string $slotName, array $content): array
    {
        return array_merge([
            'slot_key' => $slotKey,
            'slot_group' => 'interpretation_state_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => 'riasec_interpretation_state_copy_v1.zh-CN',
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie', 'low_quality', 'low_clarity'],
            'applicable_quality_states' => ['normal', 'caution', 'low_quality'],
            'applicable_codes' => [$slotName],
            'slot_name' => $slotName,
            'forbidden_claims' => [
                'personality_identity',
                'career_recommendation',
                'job_fit',
                'success_prediction',
                'accuracy_probability',
                'ability_or_skill_inference',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.quality'),
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'content_review',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'frontend_fallback_allowed' => false,
        ], $content);
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function structuralDifferenceSlot(string $slotName, array $content): array
    {
        return array_merge([
            'slot_key' => 'structural_difference_copy',
            'slot_group' => 'structural_difference_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => 'riasec_structural_difference_copy_v1',
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie', 'low_clarity'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_codes' => ['any'],
            'slot_name' => $slotName,
            'forbidden_claims' => [
                'cross_form_raw_score_delta',
                '140q_accuracy_claim',
                '60q_wrong_claim',
                'form_override_claim',
                'code_conversion_claim',
                'career_recommendation',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.structural'),
            'emphasis_difference_only' => true,
            'correctness_ranking_allowed' => false,
            'raw_score_comparison_allowed' => false,
            'result_override_allowed' => false,
            'code_conversion_allowed' => false,
            'selection_basis' => 'task_environment_role_emphasis_only',
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'approved_for_staging',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'frontend_fallback_allowed' => false,
        ], $content);
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function aspirationSlot(string $slotName, array $content): array
    {
        return array_merge($this->explorationCopyBase('aspirations_calibration_copy', 'aspirations_copy', $slotName, 'riasec_aspirations_calibration_copy_v1'), [
            'forbidden_claims' => [
                'aspiration_overrides_measured_result',
                'career_suitability_claim',
                'job_fit',
                'ability_or_skill_inference',
                'qualification_judgment',
            ],
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.aspirations'),
            'validation_questions_only' => true,
            'aspiration_override_allowed' => false,
            'aspiration_replaces_measured_result_allowed' => false,
            'recommended_output' => 'validation_questions_and_low_risk_experiment',
            'result_binding' => 'overlay_only_does_not_mutate_measured_result',
        ], $content);
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function disagreePathSlot(string $slotName, array $content): array
    {
        return array_merge($this->explorationCopyBase('disagree_path_copy', 'feedback_response_copy', $slotName, 'riasec_feedback_response_copy_v1'), [
            'forbidden_claims' => [
                'feedback_overrides_measured_result',
                'score_correction',
                'career_recommendation',
                'job_fit',
                'raw_feedback_public_exposure',
            ],
            'user_visible_boundary' => (string) $this->privateResultSource->get('boundaries.disagree'),
            'next_steps_only' => true,
            'feedback_replaces_measured_result_allowed' => false,
            'result_override_allowed' => false,
            'snapshot_share_pdf_mutation_allowed' => false,
            'raw_feedback_public_exposure_allowed' => false,
            'recommended_output' => 'next_steps_and_optional_retake_only',
            'result_binding' => 'overlay_only_does_not_mutate_snapshot_share_pdf',
        ], $content);
    }

    /**
     * @return array<string,mixed>
     */
    private function explorationCopyBase(string $slotKey, string $slotGroup, string $slotName, string $contentVersion): array
    {
        return [
            'slot_key' => $slotKey,
            'slot_group' => $slotGroup,
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => $contentVersion,
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => ['riasec_60', 'riasec_140'],
            'applicable_profile_shapes' => ['clear_code', 'blended_code', 'broad_profile', 'near_tie', 'low_clarity'],
            'applicable_quality_states' => ['normal', 'caution'],
            'applicable_codes' => ['any'],
            'slot_name' => $slotName,
            'required_boundaries' => $this->requiredBoundaries(),
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'approved_for_staging',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'affects_measured_code' => false,
            'affects_score' => false,
            'report_snapshot_mutation_allowed' => false,
            'share_pdf_payload_expansion_allowed' => false,
            'raw_feedback_exposure_allowed' => false,
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @param  list<string>|string  $pair
     */
    private function normalizePairKey(array|string $pair): string
    {
        $parts = is_array($pair) ? $pair : preg_split('/[_×x-]/', $pair);
        $parts = array_values(array_filter(array_map(
            fn (mixed $part): string => strtoupper(trim((string) $part)),
            (array) $parts
        )));
        if (is_string($pair) && count($parts) === 1) {
            $letters = $this->orderedDimensions($pair, 2);
            if (count($letters) === 2) {
                $parts = $letters;
            }
        }

        if (count($parts) !== 2) {
            return strtoupper(trim(is_string($pair) ? $pair : implode('_', $parts)));
        }

        $order = array_flip(self::DIMENSIONS);
        usort($parts, fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode('_', $parts);
    }

    /**
     * @param  list<string>|string  $top3
     */
    private function normalizeTop3Key(array|string $top3): string
    {
        $parts = is_array($top3) ? $top3 : preg_split('/[_×x-]/', $top3);
        $parts = array_values(array_unique(array_filter(array_map(
            fn (mixed $part): string => strtoupper(trim((string) $part)),
            (array) $parts
        ))));
        if (is_string($top3) && count($parts) === 1) {
            $letters = $this->orderedDimensions($top3, 3);
            if (count($letters) === 3) {
                $parts = $letters;
            }
        }

        if (count($parts) !== 3) {
            return strtoupper(trim(is_string($top3) ? $top3 : implode('_', $parts)));
        }

        $order = array_flip(self::DIMENSIONS);
        usort($parts, fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode('_', $parts);
    }

    /**
     * @return list<string>
     */
    private function orderedDimensions(string $value, int $limit): array
    {
        $letters = [];
        foreach (str_split(strtoupper((string) preg_replace('/[^RIASEC]/i', '', $value))) as $letter) {
            if (in_array($letter, self::DIMENSIONS, true) && ! in_array($letter, $letters, true)) {
                $letters[] = $letter;
            }
            if (count($letters) === $limit) {
                break;
            }
        }

        return $letters;
    }

    private function layer140qAssetSlotName(string $dimensionCode, string $layer, string $layerState): string
    {
        return $dimensionCode.'_'.$layer.'_'.$layerState;
    }

    private function layer140qSlotKeyForLayer(string $layer): string
    {
        return match ($layer) {
            'task' => '140q_task_card_copy',
            'environment' => '140q_environment_card_copy',
            'role' => '140q_role_card_copy',
            default => '140q_layer_unavailable_copy',
        };
    }

    private function normalize140qLayerState(string $layerState): string
    {
        $layerState = trim($layerState);
        if ($layerState === '60Q_only_CTA') {
            return '60q_only_cta';
        }

        return strtolower($layerState);
    }

    /**
     * @return list<string>
     */
    private function requiredBoundaries(): array
    {
        return [
            'interest_evidence_only',
            'not_career_recommendation',
            'not_job_fit',
            'not_success_prediction',
            'not_ability_or_skill_measure',
            'no_60q_140q_raw_delta',
            '140q_contextual_not_more_accurate',
            'feedback_does_not_mutate_measured_result',
            'missing_content_fails_closed',
            'frontend_fallback_forbidden',
        ];
    }

    private function normalizeContentLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
