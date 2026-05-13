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
