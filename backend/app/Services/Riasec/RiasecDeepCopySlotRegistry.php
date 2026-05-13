<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecDeepCopySlotRegistry
{
    private const CONTENT_VERSION = 'riasec_dimension_deep_copy_v1';

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
    public const LAYER_140Q_STATES = [
        'agreement',
        'tension',
        'unavailable',
        'insufficient_quality',
        'not_applicable_60q_only',
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
     * @return array<string,array<string,mixed>>
     */
    public function pairBlendSlots(): array
    {
        $slots = [];
        foreach (self::PAIRS as $pairKey) {
            $slots[$pairKey] = $this->pendingPairSlot($pairKey);
        }

        foreach ($this->authoredPairContent() as $pairKey => $content) {
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
    public function layer140qSlots(): array
    {
        return [
            'task_activity_card' => $this->layer140qSlot('140q_task_card_copy', 'task_activity_card', [
                'title' => '任务活动卡',
                'question' => '你真正喜欢做的，是哪类工作活动？',
                'summary' => '这张卡把兴趣拆成更具体的任务活动，帮助区分你喜欢的是问题本身，还是某个职业名带来的想象。',
                'what_user_sees' => ['更容易激活你的任务活动', '可能消耗你的任务活动', '值得先验证的一个小任务'],
                'layer_state' => 'agreement',
            ]),
            'environment_card' => $this->layer140qSlot('140q_environment_card_copy', 'environment_card', [
                'title' => '工作环境卡',
                'question' => '这些活动出现在哪种环境里，你仍然有兴趣？',
                'summary' => '同一个任务在不同环境里感受会不同。环境卡帮助把任务兴趣和工作日常拆开。',
                'what_user_sees' => ['安静深研 vs 真实反馈', '合作支持 vs 结果导向', '开放表达 vs 流程约束'],
                'layer_state' => 'agreement',
            ]),
            'role_responsibility_card' => $this->layer140qSlot('140q_role_card_copy', 'role_responsibility_card', [
                'title' => '角色责任卡',
                'question' => '你愿意在工作里承担哪种责任？',
                'summary' => '喜欢一个任务，不等于喜欢它所在岗位的责任。角色卡帮助看见你想定义问题、表达答案、支持理解、推动落地，还是守住流程。',
                'what_user_sees' => ['定义问题', '表达答案', '支持理解', '推动落地', '守住流程'],
                'layer_state' => 'agreement',
            ]),
            'layer_agreement' => $this->layer140qSlot('140q_layer_agreement_copy', 'layer_agreement', [
                'title' => '任务、环境和角色线索大体一致',
                'summary' => '你的任务活动、工作环境和角色责任线索大体一致。下一步可以选择一个低风险任务进行验证，而不是急着锁定职业名称。',
                'layer_state' => 'agreement',
            ]),
            'layer_tension' => $this->layer140qSlot('140q_tension_copy', 'layer_tension', [
                'title' => '任务兴趣和工作日常线索有张力',
                'summary' => '你的任务兴趣和工作日常线索强调了不同层面。更稳妥的读法是：喜欢的任务、能长期投入的环境、愿意承担的角色责任，需要分开验证。',
                'layer_state' => 'tension',
            ]),
            'layer_unavailable' => $this->layer140qSlot('140q_layer_unavailable_copy', 'layer_unavailable', [
                'title' => '工作日常三张卡暂不可用',
                'summary' => '当前结果可以看基础兴趣方向。任务、环境和角色责任三张卡需要完成 140Q 后才会显示；它们只会让工作日常线索更具体。',
                'layer_state' => 'not_applicable_60q_only',
            ]),
            '140q_cta' => $this->layer140qSlot('140q_cta_copy', '140q_cta', [
                'title' => '你喜欢的是任务本身，还是这份工作的真实日常？',
                'summary' => '60Q 看兴趣方向；140Q 看工作日常。140Q 提供更具体的情境线索，不代表正确性更高，也不会覆盖 60Q。',
                'button_label' => '查看 140Q 工作日常三张卡',
                'layer_state' => 'unavailable',
            ]),
            '140q_not_recommended' => $this->layer140qSlot('140q_not_recommended_copy', '140q_not_recommended', [
                'title' => '暂不建议继续深入版',
                'summary' => '由于本次作答需要谨慎阅读，暂不展示 140Q 三张卡。建议稍后重测，而不是继续做强解释。',
                'layer_state' => 'insufficient_quality',
            ]),
        ];
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
    public function lowQualitySlots(): array
    {
        return [
            'top_notice' => $this->qualitySlot('low_quality_copy', 'top_notice', [
                'title' => '这次结果适合谨慎阅读',
                'summary' => '本次结果可以作为初步兴趣线索，但不适合用三字母代码做强结论。更稳妥的做法是先看六维概览，或稍后在状态更稳定时重测。',
                'quality_state' => 'low_quality',
            ]),
            'user_not_blamed_message' => $this->qualitySlot('low_quality_copy', 'user_not_blamed_message', [
                'title' => '这不是对你的评价',
                'summary' => '作答太快、注意力分散、题目想象不清楚、缺题或当时状态不稳定，都可能影响结果的可读性。',
                'quality_state' => 'low_quality',
            ]),
            'what_happened_explanation' => $this->qualitySlot('low_quality_copy', 'what_happened_explanation', [
                'title' => '为什么要降级阅读',
                'summary' => '当作答质量或结果清晰度不足时，系统暂不把结果写成强解释，以避免把初步线索误读成固定结论。',
                'quality_state' => 'low_quality',
            ]),
            'hidden_modules_explanation' => $this->qualitySlot('low_quality_copy', 'hidden_modules_explanation', [
                'title' => '哪些模块会暂时隐藏',
                'summary' => '本次暂不展示单一活动链、维度组合深解、职业例子、140Q CTA 和强分享卡，只保留六维概览、方法边界和重测建议。',
                'quality_state' => 'low_quality',
            ]),
            'retake_guidance' => $this->qualitySlot('low_quality_copy', 'retake_guidance', [
                'title' => '下次作答时，试着这样做',
                'summary' => '只判断你对活动本身是否有兴趣；没有经验时按是否愿意尝试回答；注意力分散时先休息后再完成。',
                'quality_state' => 'retake_recommended',
            ]),
            'share_pdf_boundary' => $this->qualitySlot('low_quality_copy', 'share_pdf_boundary', [
                'title' => '分享和 PDF 边界',
                'summary' => '这次结果不适合生成强结论分享卡。个人 PDF 可以保存谨慎阅读版，但公开分享默认不展示 Holland Code、活动链或职业例子。',
                'quality_state' => 'low_quality',
            ]),
            'next_step' => $this->qualitySlot('low_quality_copy', 'next_step', [
                'title' => '下一步',
                'summary' => '先保存谨慎阅读版，或稍后在状态更稳定时重测。当前不推荐继续进入更长版本。',
                'quality_state' => 'retake_recommended',
            ]),
            'cautious_reading_notice' => $this->qualitySlot('cautious_reading_copy', 'cautious_reading_notice', [
                'title' => '轻量参考',
                'summary' => '本次结果适合放轻阅读。建议先看六维概览，再用一个小实验验证兴趣线索。',
                'quality_state' => 'caution',
            ]),
            'minimal_quality_boundary_60q' => $this->qualitySlot('cautious_reading_copy', 'minimal_quality_boundary_60q', [
                'title' => '60Q 最小质量边界',
                'summary' => '60Q 当前只在缺题或完成度不足等明确条件下做强降级；其他较弱信号只用于提示谨慎阅读。',
                'quality_state' => 'minimal_quality_boundary_60q',
            ]),
        ];
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
    public function structuralDifferenceSlots(): array
    {
        return [
            'summary' => $this->structuralDifferenceSlot('summary', [
                'title' => '两次结果强调的兴趣线索不同',
                'summary' => '60Q 看基础兴趣结构；140Q 看任务、环境和角色责任。两次结果强调不同线索时，更适合把差异读成需要验证的线索，而不是最终结论。',
                'structural_difference_state' => 'different_emphasis',
            ]),
            'task_layer_explanation' => $this->structuralDifferenceSlot('task_layer_explanation', [
                'title' => '任务活动层',
                'summary' => '任务活动层关注你更容易被哪些具体活动吸引，例如分析、表达、支持、推动或整理。它不说明能力高低，也不替代基础兴趣结构。',
                'structural_difference_state' => 'same_structure',
            ]),
            'environment_layer_explanation' => $this->structuralDifferenceSlot('environment_layer_explanation', [
                'title' => '工作环境层',
                'summary' => '同一类任务出现在不同环境里，感受可能不同。环境层帮助你看见哪些情境更容易支持长期投入。',
                'structural_difference_state' => 'different_emphasis',
            ]),
            'role_layer_explanation' => $this->structuralDifferenceSlot('role_layer_explanation', [
                'title' => '角色责任层',
                'summary' => '角色责任层关注你更愿意承担定义问题、表达答案、支持理解、推动落地或守住流程中的哪类责任。',
                'structural_difference_state' => 'layer_tension',
            ]),
            'correct_reading' => $this->structuralDifferenceSlot('correct_reading', [
                'title' => '正确读法',
                'summary' => '先看两次结果中仍然重叠的活动线索，再看任务、环境和角色责任各自需要验证的部分。排序接近时，也要把 near-tie 当作阅读边界。',
                'structural_difference_state' => 'near_tie_shift',
            ]),
            'forbidden_reading' => $this->structuralDifferenceSlot('forbidden_reading', [
                'title' => '不要这样读',
                'summary' => '不要把跨表单差异读成某一次结果失效、长表单覆盖短表单、兴趣身份发生转换，或任何分数涨跌结论。',
                'structural_difference_state' => 'cross_form_not_comparable',
            ]),
            'next_validation_step' => $this->structuralDifferenceSlot('next_validation_step', [
                'title' => '下一步验证',
                'summary' => '选择一个低风险任务、一个真实环境和一个角色责任进行小实验。记录它们分别带来能量还是消耗，再决定是否需要重测。',
                'structural_difference_state' => 'insufficient_basis',
            ]),
        ];
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
        return [
            'intro' => $this->aspirationSlot('intro', [
                'title' => '把你原本想探索的方向放到旁边看',
                'summary' => '你可以记录职业、专业、课程、项目或工作场景。它们只用于生成验证问题，不进入测评分数。',
                'aspirations_state' => 'not_provided',
            ]),
            'input_boundary' => $this->aspirationSlot('input_boundary', [
                'title' => '输入边界',
                'summary' => '愿望是探索材料，不是测评答案。系统只会帮助你看这些方向里有哪些活动与当前兴趣线索重叠，哪些现实部分需要验证。',
                'aspirations_state' => 'not_provided',
            ]),
            'overlap_reading' => $this->aspirationSlot('overlap_reading', [
                'title' => '有活动重叠',
                'summary' => '这个方向与你当前兴趣结构有活动重叠。下一步是验证这些活动进入真实任务、环境和角色责任后是否仍然有能量。',
                'aspirations_state' => 'overlap',
            ]),
            'tension_reading' => $this->aspirationSlot('tension_reading', [
                'title' => '有张力，需要拆开看',
                'summary' => '这个方向与你当前兴趣结构存在张力。张力不是排除结论，只说明其中的日常任务、环境或角色责任需要先验证。',
                'aspirations_state' => 'tension',
            ]),
            'reality_questions' => $this->aspirationSlot('reality_questions', [
                'title' => '现实验证问题',
                'summary' => '先问三个问题：你喜欢的是任务本身还是职业想象；你能接受这个方向的环境约束吗；你愿意承担它的角色责任吗。',
                'aspirations_state' => 'needs_reality_check',
            ]),
            'education_skill_qualification_boundary' => $this->aspirationSlot('education_skill_qualification_boundary', [
                'title' => '教育、技能、资格和伦理边界',
                'summary' => '涉及教育要求、专业技能、资格证书、行业法规或伦理责任的方向，必须另行验证训练、作品、证书、监督和现实机会。',
                'aspirations_state' => 'high_risk_boundary',
            ]),
            'next_experiment_prompt' => $this->aspirationSlot('next_experiment_prompt', [
                'title' => '下一步小实验',
                'summary' => '选择一个低风险任务，用 15 到 30 分钟验证它让你更有能量还是更消耗。先验证活动，不急着形成职业结论。',
                'aspirations_state' => 'needs_reality_check',
            ]),
            'no_score_mutation_boundary' => $this->aspirationSlot('no_score_mutation_boundary', [
                'title' => '不改写测评结果',
                'summary' => '愿望不会覆盖 measured Holland Code，也不会改变 RIASEC 分数、报告快照、分享内容或 PDF 内容。',
                'aspirations_state' => 'not_provided',
            ]),
        ];
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
        return [
            'user_not_wrong_message' => $this->disagreePathSlot('user_not_wrong_message', [
                'title' => '你可以不认同这个结果',
                'summary' => '不认同结果本身是有效反馈。它会进入探索路径，帮助你检查作答状态、近似并列和活动验证方向。',
                'disagree_state' => 'disagrees_quality_normal',
            ]),
            'possible_reasons' => $this->disagreePathSlot('possible_reasons', [
                'title' => '可能原因',
                'summary' => '结果不像你，可能来自按能力作答、职业名想象、前几个维度接近、profile 较宽，或当时状态不稳定。',
                'disagree_state' => 'disagrees_quality_normal',
            ]),
            'retake_when' => $this->disagreePathSlot('retake_when', [
                'title' => '什么时候适合重测',
                'summary' => '如果作答时注意力不稳定、题目想象不清楚，或结果质量需要谨慎阅读，稍后重测比手动修正结果更可靠。',
                'disagree_state' => 'retake_recommended',
            ]),
            'experiment_when' => $this->disagreePathSlot('experiment_when', [
                'title' => '什么时候适合做实验',
                'summary' => '如果你只是更认同另一个方向，可以选择一个活动做低风险实验。实验记录只帮助探索下一步，不形成职业结论。',
                'disagree_state' => 'save_feedback_only',
            ]),
            'record_preferred_direction_boundary' => $this->disagreePathSlot('record_preferred_direction_boundary', [
                'title' => '记录偏好方向的边界',
                'summary' => '你可以记录更想探索的方向；它只作为偏好线索保存，不覆盖 measured Holland Code，不重算六维分数。',
                'disagree_state' => 'save_feedback_only',
            ]),
            'feedback_no_mutation_boundary' => $this->disagreePathSlot('feedback_no_mutation_boundary', [
                'title' => '反馈不改分',
                'summary' => '不认同、收藏、排除和实验反馈都不会修改测评结果、报告快照、默认分享内容或 PDF 内容。',
                'disagree_state' => 'disagrees_quality_caution',
            ]),
            'next_step' => $this->disagreePathSlot('next_step', [
                'title' => '下一步',
                'summary' => '先检查作答质量和 near-tie，再选择重测、保存偏好方向，或做一个小实验验证具体活动。',
                'disagree_state' => 'save_feedback_only',
            ]),
        ];
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
            'low_score_safe_reading',
            'work_activity_examples',
            'possible_drains',
            'common_misread',
            'action_advice',
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
            'real_world_cost',
            'common_misread',
            'activities_to_validate',
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
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function dimensionContent(): array
    {
        return [
            'R' => $this->dimensionSlot('R', '实作型', [
                'core_drive' => '你的燃料来自把想法落到真实物体、真实空间、真实系统或可操作成果上。',
                'positive_value' => 'R 让人愿意接触现实材料、工具、设备、现场和可见成果。它能把抽象想法拉回可执行、可验证、可修正的现实。',
                'real_world_cost' => '当 R 很高时，纯讨论、长时间抽象会议和没有落地对象的方案容易显得空转；当 R 较低时，现场操作、工具维护、重复实操未必自然提供能量。',
                'high_score_reading' => '你可能更愿意动手试、调试、搭建、修理、实施，或者在真实系统里解决问题。',
                'low_score_safe_reading' => '低 R 不是不能动手，也不是不能做技术或工程；它只表示实物、现场、工具和机械操作不是当前最自然的兴趣入口。',
                'work_activity_examples' => ['搭建或修理', '现场调试', '操作工具或设备', '把方案做成样品', '处理真实系统问题'],
                'possible_drains' => ['长时间纯理论讨论', '没有可见成果的空转', '必须大量实物操作但缺少理解空间'],
                'common_misread' => '不要把低 R 读成手笨、不能学技术或不能做实操；RIASEC 不测能力。',
                'action_advice' => '如果你对 R 类方向有现实机会，先做一个短任务验证：修一个小物件、完成一次工具操作、把一个想法做成原型。',
                'forbidden_expression' => '不得写成实操能力、工程胜任力、体力水平、职业资格或安全操作能力判断。',
            ]),
            'I' => $this->dimensionSlot('I', '研究型', [
                'core_drive' => '你的燃料是把问题看清楚：原因、证据、模型、假设和底层机制。',
                'positive_value' => 'I 让人不满足于表面答案。它能提供求证、判断、拆解复杂问题和建立可靠解释的动力。',
                'real_world_cost' => '当 I 很高时，拍脑袋推进、粗糙结论和只要速度不要证据的环境会消耗你；也可能出现过度研究、迟迟不交付的风险。',
                'high_score_reading' => '你可能喜欢阅读资料、比较解释、分析数据、提出假设、找出事情为什么会这样。',
                'low_score_safe_reading' => '低 I 不代表不聪明，也不代表不能做分析；它只说明深度研究和证据推理不是当前最自然的兴趣入口。',
                'work_activity_examples' => ['分析复杂问题', '阅读研究资料', '提出假设', '比较证据', '建立模型或判断'],
                'possible_drains' => ['只有结论没有依据', '不允许深入理解', '长期没有现实反馈的孤立研究'],
                'common_misread' => '高 I 是兴趣线索，不是智力证明；低 I 也不是认知能力否定。',
                'action_advice' => '选一个真实问题，写下 3 个可能原因、证据来源和你目前最相信的判断。',
                'forbidden_expression' => '不得写成智商、研究能力、专业资格、学术潜力或长期结果预测。',
            ]),
            'A' => $this->dimensionSlot('A', '艺术型', [
                'core_drive' => '你的燃料是表达空间：把想法、感受、信息或体验变成有质感、有个人判断的形式。',
                'positive_value' => 'A 让人愿意创造、改写、设计、叙事和打破模板。它能让复杂内容有温度、有形状、有记忆点。',
                'real_world_cost' => '当 A 很高时，过度模板化、千篇一律、只许按流程交差的环境会让你失去生命力；也可能因为追求质感而被反复打磨消耗。',
                'high_score_reading' => '你可能喜欢写作、设计、表达、构思、视觉/文字/概念创造，或把普通内容做出独特风格。',
                'low_score_safe_reading' => '低 A 不代表没有审美或不能创作；它只说明开放式表达与创意探索不是当前最自然的兴趣入口。',
                'work_activity_examples' => ['创造表达', '内容构思', '视觉或文字设计', '把抽象想法变成作品', '用新方式呈现信息'],
                'possible_drains' => ['高度标准化', '只能按模板执行', '质量被不断压缩', '表达被过度审查且没有空间'],
                'common_misread' => 'A 不是艺术天赋证明，也不等于适合所有创意职业。',
                'action_advice' => '把一个复杂主题改写成一页图文或 5 句话，观察你更享受构思、表达还是受众反馈。',
                'forbidden_expression' => '不得写成艺术天赋、创作能力、职业结论或作品质量保证。',
            ]),
            'S' => $this->dimensionSlot('S', '社会型', [
                'core_drive' => '你的燃料来自真实的人：理解、支持、解释、陪伴、协调，让别人更清楚或更能行动。',
                'positive_value' => 'S 让成果不只停留在自己手里，而是面向真实对象。它能把知识、判断和表达转化为支持他人的力量。',
                'real_world_cost' => '当 S 很高时，真实人的复杂反馈、情绪负担和长期陪伴会带来消耗；当 S 较低时，高互动助人场景未必自然给能量。',
                'high_score_reading' => '你可能喜欢倾听、辅导、解释、培训、协调、服务、理解需求，或看到别人因你的支持而更清楚。',
                'low_score_safe_reading' => '低 S 不代表冷漠，也不代表不能合作；它只说明高互动、持续助人或情绪密度较高的活动不是当前最自然的兴趣入口。',
                'work_activity_examples' => ['倾听真实需求', '解释和辅导', '协调协作', '支持别人做判断', '设计帮助别人理解的材料'],
                'possible_drains' => ['情绪边界模糊', '持续被索取', '大量低质量沟通', '真实反馈太复杂但缺少结构'],
                'common_misread' => 'S 不是善良程度，也不是咨询、教育或医疗资格证明。',
                'action_advice' => '找一个真实对象试讲、访谈或收集反馈，记录反馈让你有能量还是更消耗。',
                'forbidden_expression' => '不得写成同理心能力、心理咨询能力、教育资格、医疗/法律/职业指导资格或筛选能力。',
            ]),
            'E' => $this->dimensionSlot('E', '企业型', [
                'core_drive' => '你的燃料来自推动：影响他人、争取资源、设定目标、承担结果、把机会变成行动。',
                'positive_value' => 'E 让人愿意站出来推动事情、争取资源、面对竞争和把模糊机会变成可见结果。',
                'real_world_cost' => '当 E 很高时，缺少决策权、长期无影响力或目标含混的环境会消耗你；当 E 较低时，高曝光、高竞争、持续谈判和强商务压力可能消耗更快。',
                'high_score_reading' => '你可能喜欢主导推进、说服影响、商务拓展、目标达成、组织资源、承担结果。',
                'low_score_safe_reading' => '低 E 不代表不能创业、销售、管理或影响别人；它只说明强推动和高竞争不是当前最自然的兴趣入口。',
                'work_activity_examples' => ['推动目标', '争取资源', '公开表达', '商务拓展', '组织人和节奏'],
                'possible_drains' => ['持续竞争', '高压谈判', '强曝光', '只看短期指标', '每天都要争资源'],
                'common_misread' => 'E 不是领导能力或商业能力证明；低 E 也不是不能做管理。',
                'action_advice' => '读 3 条商务/增长/管理岗位描述，只圈出让你有兴趣的任务动词，区分你喜欢影响结果，还是只喜欢想法被看见。',
                'forbidden_expression' => '不得写成领导力、销售能力、创业结果、岗位结论或雇佣风险。',
            ]),
            'C' => $this->dimensionSlot('C', '常规型', [
                'core_drive' => '你的燃料来自秩序：分类、流程、记录、标准、质量校验和稳定交付。',
                'positive_value' => 'C 让事情不只被想出来，也能被保存、复用、交接和持续维护。它是复杂系统里的稳定支架。',
                'real_world_cost' => '当 C 很高时，混乱、随意变更和责任不清会消耗你；当 C 较低时，长期流程维护、重复记录和高度标准化可能让你感到生命力被压住。',
                'high_score_reading' => '你可能喜欢规划、整理、维护清单、做流程、校验质量、把杂乱信息排成稳定结构。',
                'low_score_safe_reading' => '低 C 不代表没有责任感或不能守规则；它只说明流程、重复维护和标准作业不是当前最自然的兴趣入口。',
                'work_activity_examples' => ['整理分类', '流程管理', '进度跟踪', '质量校验', '文档化和清单维护'],
                'possible_drains' => ['长期重复维护', '高度模板化', '只守流程不允许优化', '细节责任过多但缺少意义'],
                'common_misread' => 'C 不是尽责性人格分数，也不是责任心能力判断。',
                'action_advice' => '尝试把一个混乱任务整理成 5 步流程，观察结构化本身是给你能量，还是只是必要工具。',
                'forbidden_expression' => '不得写成责任感、执行力、纪律性、会计/行政能力或职业资格。',
            ]),
        ];
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
            'user_visible_boundary' => '这是职业兴趣线索，不是能力、人格身份、雇佣判断或岗位结论。',
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'approved_for_staging',
            'fallback_behavior' => 'omit_module',
            'content_status' => 'authored',
            'frontend_fallback_allowed' => false,
        ], $content);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function authoredPairContent(): array
    {
        return [
            'I_A' => [
                'pair_label' => '理性的创造者',
                'short_label' => '理解之后再表达',
                'chemistry' => '你的创造力不是无根的灵感，而是建立在理解、证据和结构之上。你最享受的时刻，往往是把一个混乱、晦涩、难懂的系统，转化为一个优雅、清楚、别人能理解的模型、文字、图或方案。',
                'positive_value' => '这组组合能让复杂问题变得可理解、可表达、可传播。你不只是想研究，也想让答案有形式、有质感。',
                'real_world_cost' => '如果工作只要快速产出而不允许深入，你会觉得表达空洞；如果工作只允许研究而没有表达出口，你会觉得成果被困住。',
                'common_misread' => '别人可能以为你只是创意型，或者只是研究型。其实你更像在用理解支撑表达。',
                'activities_to_validate' => ['把一篇复杂文章或报告改写成 5 句话', '设计一个小图或结构标题'],
            ],
            'I_S' => [
                'pair_label' => '深度的助人者',
                'short_label' => '先理解根源，再支持别人',
                'chemistry' => '你不太喜欢用空洞口号安慰人。真正想帮助别人时，你更倾向先研究问题的根源，再用结构化、可信的方式提供支持。你希望有用不是情绪热闹，而是让对方真的更清楚。',
                'positive_value' => '这组组合能把助人建立在理解和证据之上。你可能擅长澄清问题，而不是只给泛泛建议。',
                'real_world_cost' => '如果真实人的情绪和复杂需求过多，你可能被 S 消耗；如果帮助只停留在分析，没有真实反馈，S 又会得不到满足。',
                'common_misread' => '别人可能以为你冷静到不关心人，其实你可能只是想先把问题看清楚再帮。',
                'activities_to_validate' => ['找一个真实困扰', '写 5 个澄清问题，而不是直接给建议'],
            ],
            'A_S' => [
                'pair_label' => '共情的表达者',
                'short_label' => '让表达真正被人接住',
                'chemistry' => '你的表达不是只为了好看。你更在意它能不能触动、解释、启发或支持别人。对你来说，一个作品、材料或方案如果没人因此被照亮，它就少了一层意义。',
                'positive_value' => '这组组合能让表达更有人味，也让助人更有形式。你可能擅长把感受、知识或复杂体验翻译给真实的人。',
                'real_world_cost' => '如果受众反馈混乱或情绪很重，你可能被消耗；如果工作只追求漂亮形式、没有真实对象，也会缺少意义。',
                'common_misread' => '别人可能以为你只是情绪化或创意化，其实你可能非常在意表达能否产生真实帮助。',
                'activities_to_validate' => ['做一段小讲解或一页材料', '给真实对象看，记录对方是否更清楚'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function authoredPairSlot(string $pairKey, array $content): array
    {
        return array_merge($this->pairSlotBase($pairKey), [
            'content_version' => 'riasec_pair_blend_copy_slots_v1',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'approved_for_staging',
            'evidence_level' => 'expert_reviewed',
            'content_status' => 'authored',
        ], $content);
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
            'user_visible_boundary' => '这是兴趣组合解释，不是人格标签、能力证明或职业结论。',
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ];
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
            'forbidden_claims' => [
                '140q_accuracy_claim',
                '60q_override',
                'raw_score_delta',
                'job_fit',
                'ability_or_skill_inference',
            ],
            'required_boundaries' => $this->requiredBoundaries(),
            'user_visible_boundary' => '140Q 是工作日常情境线索，不改写 60Q，不比较 raw score，也不输出岗位结论。',
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
            'user_visible_boundary' => '质量状态只限制本次结果的阅读强度，不评价用户，也不改变分数或 Holland Code。',
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
            'user_visible_boundary' => '跨表单摘要只说明兴趣线索强调不同；不比较分数，不改写结果，也不形成职业结论。',
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
            'user_visible_boundary' => '愿望只校准探索问题，不覆盖 measured Holland Code，不改变 RIASEC 分数，也不形成职业结论。',
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
            'user_visible_boundary' => '不认同结果只影响探索路径，不修改 measured Holland Code、RIASEC 分数、报告快照、分享或 PDF。',
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

        if (count($parts) !== 2) {
            return strtoupper(trim(is_string($pair) ? $pair : implode('_', $parts)));
        }

        $order = array_flip(self::DIMENSIONS);
        usort($parts, fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode('_', $parts);
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
