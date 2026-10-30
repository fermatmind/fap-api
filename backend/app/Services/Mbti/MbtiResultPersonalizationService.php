<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Services\AI\ControlledGenerationRuntime;
use App\Services\AI\ControlledNarrativeLayerService;
use App\Services\Comparative\VersionedComparativeNormingLayerService;
use App\Services\Content\ContentPacksIndex;
use App\Services\Content\ContentPackV2Resolver;
use App\Services\Content\CulturalCalibrationLayerService;
use App\Support\Mbti\MbtiAxisStrengthBand;

final class MbtiResultPersonalizationService
{
    /**
     * @var list<string>
     */
    private const AXIS_ORDER = ['EI', 'SN', 'TF', 'JP', 'AT'];

    private const INTERPRETATION_COPY_VERSION = 'mbti.interpretation_copy.20261030.v1';

    /**
     * @var list<string>
     */
    private const DOMINANT_AXIS_ORDER = ['EI', 'SN', 'TF', 'JP'];

    /**
     * @var list<string>
     */
    private const TARGET_SECTIONS = [
        'overview',
        'trait_overview',
        'traits.why_this_type',
        'traits.close_call_axes',
        'traits.adjacent_type_contrast',
        'traits.decision_style',
        'career.summary',
        'career.collaboration_fit',
        'career.work_environment',
        'career.work_experiments',
        'career.advantages',
        'career.weaknesses',
        'career.preferred_roles',
        'career.next_step',
        'career.upgrade_suggestions',
        'growth.summary',
        'growth.stability_confidence',
        'growth.next_actions',
        'growth.weekly_experiments',
        'growth.strengths',
        'growth.weaknesses',
        'growth.stress_recovery',
        'growth.watchouts',
        'growth.motivators',
        'growth.drainers',
        'relationships.summary',
        'relationships.strengths',
        'relationships.weaknesses',
        'relationships.communication_style',
        'relationships.try_this_week',
        'relationships.rel_advantages',
        'relationships.rel_risks',
    ];

    /**
     * @var array<string, string>
     */
    private const SECTION_SCENE_MAP = [
        'overview' => 'overview',
        'trait_overview' => 'overview',
        'traits.why_this_type' => 'explainability',
        'traits.close_call_axes' => 'explainability',
        'traits.adjacent_type_contrast' => 'explainability',
        'traits.decision_style' => 'decision',
        'career.summary' => 'work',
        'career.collaboration_fit' => 'communication',
        'career.work_environment' => 'work',
        'career.work_experiments' => 'work',
        'career.advantages' => 'work',
        'career.weaknesses' => 'work',
        'career.preferred_roles' => 'work',
        'career.next_step' => 'decision',
        'career.upgrade_suggestions' => 'work',
        'growth.summary' => 'growth',
        'growth.stability_confidence' => 'stability',
        'growth.next_actions' => 'growth',
        'growth.weekly_experiments' => 'growth',
        'growth.strengths' => 'growth',
        'growth.weaknesses' => 'growth',
        'growth.stress_recovery' => 'stress_recovery',
        'growth.watchouts' => 'stress_recovery',
        'growth.motivators' => 'growth',
        'growth.drainers' => 'stress_recovery',
        'relationships.summary' => 'relationships',
        'relationships.strengths' => 'relationships',
        'relationships.weaknesses' => 'relationships',
        'relationships.communication_style' => 'communication',
        'relationships.try_this_week' => 'communication',
        'relationships.rel_advantages' => 'communication',
        'relationships.rel_risks' => 'decision',
    ];

    /**
     * @var list<string>
     */
    private const SCENE_FINGERPRINT_ORDER = [
        'work',
        'relationships',
        'growth',
        'decision',
        'stress_recovery',
        'communication',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const SCENE_AXIS_PRIORITY = [
        'overview' => ['EI', 'SN', 'TF', 'JP'],
        'explainability' => ['EI', 'SN', 'TF', 'JP'],
        'stability' => ['EI', 'SN', 'TF', 'JP'],
        'work' => ['EI', 'JP', 'TF', 'SN'],
        'relationships' => ['TF', 'EI', 'JP', 'SN'],
        'growth' => ['EI', 'SN', 'TF', 'JP'],
        'decision' => ['TF', 'JP', 'SN', 'EI'],
        'stress_recovery' => ['JP', 'EI', 'TF', 'SN'],
        'communication' => ['EI', 'TF', 'SN', 'JP'],
    ];

    /**
     * @var array<string, string>
     */
    private const SCENE_ANCHORS = [
        'overview' => 'overview',
        'explainability' => 'overview',
        'stability' => 'growth',
        'work' => 'career',
        'relationships' => 'relationships',
        'growth' => 'growth',
        'decision' => 'overview',
        'stress_recovery' => 'growth',
        'communication' => 'relationships',
    ];

    /**
     * @var list<string>
     */
    private const TONE_TARGET_SECTIONS = [
        'traits.why_this_type',
        'growth.stability_confidence',
        'traits.adjacent_type_contrast',
        'growth.next_actions',
        'growth.watchouts',
        'career.next_step',
        'relationships.try_this_week',
    ];

    /**
     * @var list<string>
     */
    private const TONE_MODES = [
        'supportive',
        'direct',
        'reflective',
        'stabilizing',
        'low_pressure',
    ];

    /**
     * @var array<string, array{label:array<string,string>, sides:array<string,string>}>
     */
    private const AXIS_COPY = [
        'EI' => [
            'label' => ['zh-CN' => '能量方向', 'en' => 'energy direction'],
            'sides' => ['E' => '外倾', 'I' => '内倾', 'E:en' => 'Extraversion', 'I:en' => 'Introversion'],
        ],
        'SN' => [
            'label' => ['zh-CN' => '信息偏好', 'en' => 'information preference'],
            'sides' => ['S' => '实感', 'N' => '直觉', 'S:en' => 'Sensing', 'N:en' => 'Intuition'],
        ],
        'TF' => [
            'label' => ['zh-CN' => '决策偏好', 'en' => 'decision style'],
            'sides' => ['T' => '思考', 'F' => '情感', 'T:en' => 'Thinking', 'F:en' => 'Feeling'],
        ],
        'JP' => [
            'label' => ['zh-CN' => '生活方式', 'en' => 'lifestyle'],
            'sides' => ['J' => '判断', 'P' => '感知', 'J:en' => 'Judging', 'P:en' => 'Perceiving'],
        ],
        'AT' => [
            'label' => ['zh-CN' => '身份层', 'en' => 'identity layer'],
            'sides' => ['A' => '果断', 'T' => '敏感', 'A:en' => 'Assertive', 'T:en' => 'Turbulent'],
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BLOCK_LABELS = [
        'type_skeleton' => '类型骨架',
        'axis_strength' => '强度层',
        'boundary' => '边界深解释',
        'identity' => '身份层',
        'scene' => '场景应用',
        'work_style' => '工作风格桥接',
        'role_fit' => '角色匹配桥接',
        'collaboration_fit' => '协作匹配桥接',
        'work_env' => '工作环境桥接',
        'career_next_step' => '职业下一步桥接',
        'next_action' => '下一步动作',
        'weekly_experiment' => '本周实验',
        'relationship_practice' => '本周关系练习',
        'work_experiment' => '工作实验',
        'watchout' => '风险提醒',
        'action_experiment' => '行动实验',
        'action_reset' => '动作重置',
        'action_resistance_break' => '阻力拆解',
        'action_momentum_start' => '起步动量',
        'action_bridge_step' => '桥接动作',
        'action_low_pressure_step' => '低压动作',
        'watchout_overextension' => '过度拉伸提醒',
        'watchout_people_pleasing' => '讨好风险提醒',
        'watchout_overcontrol' => '过度控制提醒',
        'watchout_energy_leak' => '能量泄漏提醒',
        'watchout_avoidant_delay' => '回避延迟提醒',
        'watchout_recovery_gap' => '恢复断档提醒',
        'work_scene_execution' => '执行场景',
        'work_scene_collaboration' => '协作场景',
        'work_scene_pacing' => '节奏场景',
        'work_scene_role_fit' => '角色匹配场景',
        'work_scene_transition' => '过渡场景',
        'work_scene_promotion_readiness' => '晋升准备场景',
        'work_scene_team_friction' => '团队摩擦场景',
        'work_scene_focus_recovery' => '注意力恢复场景',
        'relationship_bridge' => '关系桥接',
        'relationship_boundary_negotiation' => '边界协商',
        'relationship_misread_repair' => '误读修复',
        'relationship_conflict_recovery' => '冲突恢复',
        'relationship_try_this_week' => '本周关系尝试',
        'relationship_low_intensity_reconnect' => '低强度重新连接',
        'recovery_reset' => '恢复重置',
        'recovery_reentry' => '恢复重入',
        'revisit_resume' => '复访继续',
        'adaptive_retry' => '自适应重试',
        'adaptive_reduce_pressure' => '减压自适应',
        'decision' => '决策场景',
        'stress_recovery' => '压力恢复场景',
        'communication' => '沟通协作场景',
        'why_this_type' => '为什么是这个类型',
        'misunderstanding_fix' => '误读修正',
        'borderline_axis' => '边界轴解释',
        'adjacent_type_contrast' => '相邻类型对照',
        'stability_explanation' => '稳定性解释',
        'stability_reframe' => '稳定性重释',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BLOCK_LABELS_EN = [
        'type_skeleton' => 'Type skeleton',
        'axis_strength' => 'Strength layer',
        'boundary' => 'Boundary deepening',
        'identity' => 'Identity layer',
        'scene' => 'Scene application',
        'work_style' => 'Work-style bridge',
        'role_fit' => 'Role-fit bridge',
        'collaboration_fit' => 'Collaboration-fit bridge',
        'work_env' => 'Work-environment bridge',
        'career_next_step' => 'Career next-step bridge',
        'next_action' => 'Next action',
        'weekly_experiment' => 'Weekly experiment',
        'relationship_practice' => 'Relationship practice',
        'work_experiment' => 'Work experiment',
        'watchout' => 'Watchout',
        'action_experiment' => 'Action experiment',
        'action_reset' => 'Action reset',
        'action_resistance_break' => 'Resistance break',
        'action_momentum_start' => 'Momentum start',
        'action_bridge_step' => 'Bridge step',
        'action_low_pressure_step' => 'Low-pressure step',
        'watchout_overextension' => 'Overextension watchout',
        'watchout_people_pleasing' => 'People-pleasing watchout',
        'watchout_overcontrol' => 'Overcontrol watchout',
        'watchout_energy_leak' => 'Energy leak watchout',
        'watchout_avoidant_delay' => 'Avoidant delay watchout',
        'watchout_recovery_gap' => 'Recovery gap watchout',
        'work_scene_execution' => 'Execution scene',
        'work_scene_collaboration' => 'Collaboration scene',
        'work_scene_pacing' => 'Pacing scene',
        'work_scene_role_fit' => 'Role-fit scene',
        'work_scene_transition' => 'Transition scene',
        'work_scene_promotion_readiness' => 'Promotion-readiness scene',
        'work_scene_team_friction' => 'Team-friction scene',
        'work_scene_focus_recovery' => 'Focus-recovery scene',
        'relationship_bridge' => 'Relationship bridge',
        'relationship_boundary_negotiation' => 'Boundary negotiation',
        'relationship_misread_repair' => 'Misread repair',
        'relationship_conflict_recovery' => 'Conflict recovery',
        'relationship_try_this_week' => 'This-week relationship try',
        'relationship_low_intensity_reconnect' => 'Low-intensity reconnect',
        'recovery_reset' => 'Recovery reset',
        'recovery_reentry' => 'Recovery re-entry',
        'revisit_resume' => 'Revisit resume',
        'adaptive_retry' => 'Adaptive retry',
        'adaptive_reduce_pressure' => 'Adaptive pressure reduction',
        'decision' => 'Decision scene',
        'stress_recovery' => 'Stress recovery scene',
        'communication' => 'Communication scene',
        'why_this_type' => 'Why-this-type explanation',
        'misunderstanding_fix' => 'Misread correction',
        'borderline_axis' => 'Borderline-axis explanation',
        'adjacent_type_contrast' => 'Adjacent-type contrast',
        'stability_explanation' => 'Stability explanation',
        'stability_reframe' => 'Stability reframe',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TITLES = [
        'work' => '你的工作模式',
        'relationships' => '你的关系模式',
        'growth' => '你的成长模式',
        'decision' => '你的决策模式',
        'stress_recovery' => '你的压力恢复模式',
        'communication' => '你的沟通模式',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TITLES_EN = [
        'work' => 'Your work pattern',
        'relationships' => 'Your relationship pattern',
        'growth' => 'Your growth pattern',
        'decision' => 'Your decision pattern',
        'stress_recovery' => 'Your stress pattern',
        'communication' => 'Your communication pattern',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BAND_LABELS = [
        'boundary' => '边界带',
        'clear' => '清晰偏好',
        'strong' => '强偏好',
        'very_strong' => '极强偏好',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BAND_LABELS_EN = [
        'boundary' => 'boundary band',
        'clear' => 'clear preference',
        'strong' => 'strong preference',
        'very_strong' => 'very strong preference',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_AXIS_STRENGTH_TEMPLATES = [
        'overview.boundary' => '在{{axis_label}}上，本次回答的方向接近中点。可以用一个符合{{side_label}}描述的实际例子和一个反例核对，暂时保留两侧解释。',
        'overview.clear' => '在{{axis_label}}上，本次回答更偏向{{side_label}}。这描述当前方向，不证明多数第一反应或长期稳定性。',
        'overview.strong' => '在{{axis_label}}上，本次回答呈现较强的{{side_label}}方向。可以回看一个实际情境，核对这个线索是否符合，也记录反例。',
        'overview.very_strong' => '在{{axis_label}}上，本次{{side_label}}方向信号较强；不能据此判断行为一直一致，或他人会怎样看你。',
        'growth.boundary' => '本次回答的方向接近中点。成长练习可以先比较两侧做法：在一个低风险情境中试一个小调整，记录实际反馈，再决定是否继续。',
        'growth.clear' => '成长练习可以从一个符合{{side_label}}偏好的小动作开始，再尝试一个对侧做法，用实际反馈判断是否有用。',
        'growth.strong' => '本次{{side_label}}方向较强。可以尝试增加一个对侧检查点，但是否需要校正应由具体情境和反馈决定。',
        'growth.very_strong' => '本次{{side_label}}方向信号较强。若某个具体问题需要其他视角，可以加入一个对侧检查点，再用反馈判断是否有帮助。',
        'relationships.boundary' => '本次回答的方向接近中点，不能据此确定你在不同关系中的表达方式。可以比较两次具体互动，并询问对方怎样理解你的表达。',
        'relationships.clear' => '本次回答更偏向{{side_label}}。在人际中，可以核对这个线索是否符合一次具体互动，并询问对方的实际感受。',
        'relationships.strong' => '本次{{side_label}}方向较强。可以核对它是否影响相处方式；不能据此推断关系优势或误解一定来自这一侧。',
        'relationships.very_strong' => '本次{{side_label}}方向信号较强，不代表关系风格长期稳定。请用具体互动核对符合和不符合的部分。',
        'career.boundary' => '在工作里，这条轴更像弹性档位而不是固定齿轮；不同任务会把你拉向不同侧，因此环境匹配比职位名称更重要。',
        'career.clear' => '在工作里，{{side_label}}已经是你较稳定的默认操作方式。它会影响你更顺手的工作节奏、协作方式和反馈偏好。',
        'career.strong' => '在工作里，{{side_label}}已经很鲜明。你通常不是“什么环境都行”，而是会在某类节奏里明显更快进入高质量输出。',
        'career.very_strong' => '在工作里，你的{{side_label}}偏好非常强。这会让你在适配环境里效率极高，但也会放大与不匹配环境之间的摩擦感。',
        'work.boundary' => '本次回答的方向接近中点。可以比较两个具体工作任务，记录任务要求、协作条件和自己的实际做法，核对是否出现不同选择。',
        'work.clear' => '本次回答更偏向{{side_label}}。请核对它是否影响启动任务、协作或接收反馈，而不要把它当作固定工作方式。',
        'work.strong' => '本次{{side_label}}方向较强。可以试着调整一个工作条件，并观察结果；方向得分不证明输出或摩擦会怎样变化。',
        'work.very_strong' => '本次{{side_label}}方向信号较强，不证明工作速度、风格一致性或环境适配结果。请用具体任务验证。',
        'decision.boundary' => '本次回答的方向接近中点。做决定时，可以把两侧相关的信息和判断标准分别列出，再依据证据与后果取舍，不预设自己会自动切换。',
        'decision.clear' => '本次回答更偏向{{side_label}}。可以回看一次具体选择，核对实际使用的信息与判断标准，再考虑是否需要补充其他视角。',
        'decision.strong' => '本次回答呈现较强的{{side_label}}方向。做决定时，可以核对自己实际采用的信息和判断标准，再检查是否遗漏其他相关视角。',
        'decision.very_strong' => '本次{{side_label}}方向信号较强，不表示判断更快、更稳或更准确。重要决定仍需证据、后果和对侧视角。',
        'stress_recovery.boundary' => '本次回答的方向接近中点，不能说明压力下或恢复时会采用哪种方式。可以记录一次具体经历中的负荷、实际做法和有帮助的支持。',
        'stress_recovery.clear' => '本次回答更偏向{{side_label}}，不等于更稳定的应对方式。面对压力时，可以先核对当前负荷、实际反应和可用支持，再选择一个负担较小的调整。',
        'stress_recovery.strong' => '本次{{side_label}}方向较强，但它不能说明你如何应对压力。可以回看一次具体经历，记录压力来源、实际做法及有帮助的支持。',
        'stress_recovery.very_strong' => '本次{{side_label}}方向较强，不代表应对速度或恢复能力。请先核对压力来源、可用支持和实际有效的恢复方式。',
        'communication.boundary' => '本次回答的方向接近中点。可以回看面向不同对象的两次对话，核对表达是否确有变化，以及对方实际怎样理解。',
        'communication.clear' => '本次回答更偏向{{side_label}}。可以核对一次具体对话中的开场、表达和回应，再根据对方反馈考虑是否调整。',
        'communication.strong' => '本次{{side_label}}方向较强。可以用一次具体对话核对它是否符合你的表达方式，并询问对方实际怎样理解，而不预设误读来自这条轴。',
        'communication.very_strong' => '本次{{side_label}}方向信号较强，不证明沟通方式长期一致或别人会误读你。请用具体对话核对。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_AXIS_STRENGTH_TEMPLATES_EN = [
        'overview.boundary' => 'On {{axis_label}}, this response set is near the midpoint. Check one actual example that fits {{side_label}} and one counterexample, keeping both directions open.',
        'overview.clear' => 'On {{axis_label}}, this response set leans toward {{side_label}}. This describes the current direction, not most first reactions or long-term stability.',
        'overview.strong' => 'On {{axis_label}}, this response set shows a stronger {{side_label}} direction. Review one actual situation to check whether this clue fits, and record a counterexample too.',
        'overview.very_strong' => 'On {{axis_label}}, this response set has a stronger {{side_label}} signal; it does not establish consistent behavior or how others see you.',
        'growth.boundary' => 'This response set is near the midpoint. For growth, compare approaches from both sides: try one small adjustment in a low-risk situation, record actual feedback and decide whether to continue.',
        'growth.clear' => 'For growth, test one small action consistent with {{side_label}} and one opposite-side approach, then use actual feedback to judge whether they help.',
        'growth.strong' => 'This response set has a stronger {{side_label}} direction. Try an opposite-side checkpoint if useful; the situation and feedback determine whether correction is needed.',
        'growth.very_strong' => 'This response set has a stronger {{side_label}} signal. If a specific problem calls for another perspective, try an opposite-side checkpoint and use feedback to judge whether it helps.',
        'relationships.boundary' => 'This response set is near the midpoint and does not establish how you express yourself across relationships. Compare two specific interactions and ask how the other person understood your expression.',
        'relationships.clear' => 'This response set leans toward {{side_label}}. In relationships, check whether this clue fits one specific interaction and ask about the other person\'s actual experience.',
        'relationships.strong' => 'This response set has a stronger {{side_label}} direction. Check whether it affects interaction; it does not establish relationship strengths or the cause of misunderstandings.',
        'relationships.very_strong' => 'A stronger {{side_label}} signal in this response set does not establish a stable relationship style. Check both fitting and non-fitting examples from actual interactions.',
        'career.boundary' => 'At work, this axis behaves more like a flexible gear than a fixed setting. Different tasks can pull you toward different sides, so environment fit matters more than job labels.',
        'career.clear' => 'At work, {{side_label}} is already a stable operating mode. It shapes the pace, collaboration pattern, and feedback style that feel most natural to you.',
        'career.strong' => 'At work, {{side_label}} is already strong. You are not equally effective everywhere; some environments let you enter high-quality output much faster.',
        'career.very_strong' => 'At work, your {{side_label}} preference is very strong. That can make you exceptionally effective in a good-fit environment and noticeably strained in a bad-fit one.',
        'work.boundary' => 'This response set is near the midpoint. Compare two specific work tasks and record their demands, collaboration conditions and what you actually did, checking whether your choices differed.',
        'work.clear' => 'This response set leans toward {{side_label}}. Check whether it affects starting tasks, collaboration or feedback rather than treating it as a fixed operating mode.',
        'work.strong' => 'This response set has a stronger {{side_label}} direction. Test one work condition and observe the result; the score does not establish how output or friction will change.',
        'work.very_strong' => 'A stronger {{side_label}} signal does not establish work speed, style consistency or environment fit. Check it against concrete tasks.',
        'decision.boundary' => 'This response set is near the midpoint. When deciding, list the information and criteria associated with both sides, then weigh the evidence and consequences without assuming you automatically switch between approaches.',
        'decision.clear' => 'This response set leans toward {{side_label}}. Review one specific choice, check the information and criteria you actually used, and consider whether another perspective is needed.',
        'decision.strong' => 'This response set shows a stronger {{side_label}} direction. When deciding, review the information and criteria you actually used, then check whether other relevant perspectives were missed.',
        'decision.very_strong' => 'A stronger {{side_label}} signal does not mean faster, steadier or more accurate judgment. Important decisions still need evidence, consequences and an opposite-side perspective.',
        'stress_recovery.boundary' => 'This response set is near the midpoint and does not establish how you respond under pressure or during recovery. Record the demands, what you actually did and any helpful support in one specific experience.',
        'stress_recovery.clear' => 'This response set leans toward {{side_label}}; that does not establish a steadier coping approach. Under pressure, first check current demands, actual responses and available support, then choose a lower-burden adjustment.',
        'stress_recovery.strong' => 'This response set has a stronger {{side_label}} direction, but it does not establish how you cope with pressure. Review one specific experience and record the pressure source, what you actually did and any helpful support.',
        'stress_recovery.very_strong' => 'A stronger {{side_label}} direction does not establish coping speed or recovery ability. Check the pressure source, available support and recovery approaches that actually help.',
        'communication.boundary' => 'This response set is near the midpoint. Review two conversations with different people, checking whether your expression actually changed and how each person understood it.',
        'communication.clear' => 'This response set leans toward {{side_label}}. Review the opening, expression and responses in one specific conversation, then use the other person\'s feedback to consider an adjustment.',
        'communication.strong' => 'This response set has a stronger {{side_label}} direction. Check it against one specific conversation and ask how the other person understood you, without assuming this axis caused a misunderstanding.',
        'communication.very_strong' => 'A stronger {{side_label}} signal does not establish a consistently fixed communication style or that others misread you. Check it against actual conversations.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TEMPLATES = [
        'overview' => '日常观察中，可以核对这个线索是否符合：{{scene_side_hint}}。用一次具体经历和一个反例比较，不预设别人会怎样理解你。',
        'growth' => '成长练习可以围绕{{axis_label}}尝试一种做法：{{scene_side_hint}}。只在具体问题需要时补充另一侧视角，再用反馈判断是否有用。',
        'relationships' => '关系中，可以把这个线索作为讨论起点：{{scene_side_hint}}。询问对方实际怎样理解，不由偏好预判误解或相处结果。',
        'career' => '放到工作里，这条主轴更像你的默认操作系统：{{scene_side_hint}}。它会直接影响你更适配的岗位节奏与协作环境。',
        'work' => '在这个情境中，可以核对一个假设：{{scene_side_hint}}。再用实际任务、条件和反馈判断是否符合；这不预测岗位适配或表现。',
        'decision' => '做决定时，可以核对这个线索：{{scene_side_hint}}。回看一次具体选择中实际用了哪些信息，以及哪些视角还需要补充。',
        'stress_recovery' => '面对压力时，可以把这个线索作为一个待核对的尝试：{{scene_side_hint}}。结合当前负荷与可用支持判断是否适合，并观察实际反馈。',
        'communication' => '沟通时，可以用一次具体对话核对这个线索：{{scene_side_hint}}。再根据对方的回应决定是否调整表达。',
        'work_style' => '在这个情境中，可以核对一个假设：{{scene_side_hint}}。再用实际任务、条件和反馈判断是否符合；这不预测岗位适配或表现。',
        'role_fit' => '在这个情境中，可以核对一个假设：{{scene_side_hint}}。再用实际任务、条件和反馈判断是否符合；这不预测岗位适配或表现。',
        'collaboration_fit' => '团队协作时，可以观察这个线索是否符合：{{scene_side_hint}}。结合分工、对齐或意见不同时的实际经历，也向合作方询问他们需要怎样的配合。',
        'work_env' => '了解工作环境时，可以把这个线索变成一个问题：{{scene_side_hint}}。询问具体的节奏、边界和反馈安排，再与实际任务及现实限制核对。',
        'career_next_step' => '职业下一步可以围绕这个线索做一个可逆验证：{{scene_side_hint}}。先核对任务、技能和现实条件，再决定是否继续。',
        'next_action' => '下一步可以从这个线索选一个小尝试：{{scene_side_hint}}。把它缩成一周内可重复、可撤回的动作，记录能否实际完成及得到的反馈。',
        'weekly_experiment' => '本周可以围绕这个线索设计一次低成本实验：{{scene_side_hint}}。只改变一个做法，记录当时条件、结果与反例，不预设它一定更有效。',
        'relationship_practice' => '本周关系练习可以从这个线索提出一个小调整：{{scene_side_hint}}。在双方愿意的低风险对话中试一次表达或倾听调整，再询问对方是否觉得有帮助。',
        'work_experiment' => '工作实验可以围绕这个线索尝试一次可逆调整：{{scene_side_hint}}。尽量保持任务相近，只改变一个环境、协作或节奏条件，再根据记录决定保留、调整或停止。',
        'watchout' => '风险提醒可以先核对一个假设：{{scene_side_hint}}。记录具体情境与实际反应；若没有相应问题，不必把它当作你的高压默认模式。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TEMPLATES_EN = [
        'overview' => 'In everyday observation, check whether this clue fits: {{scene_side_hint}}. Compare one actual example with a counterexample, without assuming how others understand you.',
        'growth' => 'For growth, try an approach related to {{axis_label}}: {{scene_side_hint}}. Add an opposite-side perspective if the specific problem calls for it, then use feedback to judge whether it helps.',
        'relationships' => 'In relationships, use this clue as a starting point for discussion: {{scene_side_hint}}. Ask how the other person actually understands you rather than predicting misunderstandings or outcomes from a preference.',
        'career' => 'At work, this axis behaves like your default operating system: {{scene_side_hint}}. It directly affects the pace and collaboration environment that fit you best.',
        'work' => 'In this situation, check a hypothesis: {{scene_side_hint}}. Use actual tasks, conditions and feedback to judge whether it fits; this does not predict role fit or performance.',
        'decision' => 'When making a decision, check this clue: {{scene_side_hint}}. Review which information you actually used in one specific choice and which perspectives still need attention.',
        'stress_recovery' => 'Under pressure, treat this clue as a possible trial to check: {{scene_side_hint}}. Consider current demands and available support when deciding whether it fits, and observe actual feedback.',
        'communication' => 'In communication, check this clue against one specific conversation: {{scene_side_hint}}. Use the other person\'s response to decide whether to adjust your expression.',
        'work_style' => 'In this situation, check a hypothesis: {{scene_side_hint}}. Use actual tasks, conditions and feedback to judge whether it fits; this does not predict role fit or performance.',
        'role_fit' => 'In this situation, check a hypothesis: {{scene_side_hint}}. Use actual tasks, conditions and feedback to judge whether it fits; this does not predict role fit or performance.',
        'collaboration_fit' => 'In teamwork, observe whether this clue fits: {{scene_side_hint}}. Check actual experiences of dividing work, aligning or disagreeing, and ask collaborators what cooperation they need.',
        'work_env' => 'When exploring a work environment, turn this clue into a question: {{scene_side_hint}}. Ask about specific pacing, boundaries and feedback arrangements, then compare them with actual tasks and practical constraints.',
        'career_next_step' => 'For your next career step, use this clue for a reversible trial: {{scene_side_hint}}. Check tasks, skills and practical conditions before deciding whether to continue.',
        'next_action' => 'For your next step, choose a small trial based on this clue: {{scene_side_hint}}. Make it repeatable within one week and reversible, and record whether you could carry it out and what feedback followed.',
        'weekly_experiment' => 'This week, design a low-cost experiment around this clue: {{scene_side_hint}}. Change one practice and record the conditions, outcome and counterexamples without assuming it will work better.',
        'relationship_practice' => 'For this week’s relationship practice, use this clue to suggest a small adjustment: {{scene_side_hint}}. Try a change in expression or listening during a mutually agreed, low-stakes conversation, then ask whether the other person found it helpful.',
        'work_experiment' => 'For a work experiment, try a reversible adjustment around this clue: {{scene_side_hint}}. Keep the task similar where possible, change one environmental, collaboration or pacing condition, then use the record to decide whether to keep, adjust or stop it.',
        'watchout' => 'For a watchout, first check a hypothesis: {{scene_side_hint}}. Record the situation and actual response; if the problem is absent, do not treat it as your default pattern under pressure.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES = [
        'stable' => '本次核心轴的方向分离相对清晰，但不证明跨情境或长期稳定。把“{{growth_hint}}”作为一个待验证线索，在一周内重复一个可逆的小动作；记录情境、行动、反馈及反例，再决定保留、调整或停止。',
        'mixed' => '本次部分轴的方向较清晰，另一些更接近中点，仍需保留不确定性。只选一个与“{{growth_hint}}”相关的具体情境，试一个一周内可重复、可撤回的小动作；记下前后条件和实际反馈，不用结果证明固定人格。',
        'context_sensitive' => '本次有多条轴接近中点，当前代码只是整理结果的一种方式。先核对“{{growth_hint}}”及其反例，再选一个一周内可重复的可逆尝试；记录当时的条件、是否实际完成和得到的反馈，允许保留多个解释。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES_EN = [
        'stable' => 'The core directions are more separated in this response set, without establishing stability across contexts or time. Treat “{{growth_hint}}” as one hypothesis and repeat a small reversible action during one week. Record the situation, action, feedback and counterexamples, then decide whether to keep, adjust or stop it.',
        'mixed' => 'Some directions are clearer in this response set while others are nearer the midpoint, so uncertainty remains. Choose one concrete situation related to “{{growth_hint}}” and try a small reversible action that can be repeated during one week. Record the conditions and actual feedback rather than using the outcome to prove a fixed personality.',
        'context_sensitive' => 'Several axes are near the midpoint in this response set; the current code is one way to organize the result. Check “{{growth_hint}}” and a counterexample, then choose a reversible trial that can be repeated during one week. Record the conditions, whether the action happened and the feedback received, keeping multiple explanations open.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_FINGERPRINT_TEMPLATES = [
        'work' => '可以核对一个情境假设：你是否先用{{primary_hint}}，再用{{support_hint}}？记录符合与不符合的例子，当前分数不能确定固定顺序。{{identity_clause}} {{boundary_clause}}',
        'relationships' => '可以核对一个情境假设：你是否先用{{primary_hint}}，再用{{support_hint}}？记录符合与不符合的例子，当前分数不能确定固定顺序。{{identity_clause}} {{boundary_clause}}',
        'growth' => '可以核对一个情境假设：你是否先用{{primary_hint}}，再用{{support_hint}}？记录符合与不符合的例子，当前分数不能确定固定顺序。{{identity_clause}} {{boundary_clause}}',
        'decision' => '可以核对一个情境假设：你是否先用{{primary_hint}}，再用{{support_hint}}？记录符合与不符合的例子，当前分数不能确定固定顺序。{{identity_clause}} {{boundary_clause}}',
        'stress_recovery' => '可以核对一个情境假设：你是否先用{{primary_hint}}，再用{{support_hint}}？记录符合与不符合的例子，当前分数不能确定固定顺序。{{identity_clause}} {{boundary_clause}}',
        'communication' => '可以核对一个情境假设：你是否先用{{primary_hint}}，再用{{support_hint}}？记录符合与不符合的例子，当前分数不能确定固定顺序。{{identity_clause}} {{boundary_clause}}',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_FINGERPRINT_TEMPLATES_EN = [
        'work' => 'Check a situational hypothesis: do you start with {{primary_hint}} and then use {{support_hint}}? Record fitting and non-fitting examples; the current scores do not establish a fixed sequence. {{identity_clause}} {{boundary_clause}}',
        'relationships' => 'Check a situational hypothesis: do you start with {{primary_hint}} and then use {{support_hint}}? Record fitting and non-fitting examples; the current scores do not establish a fixed sequence. {{identity_clause}} {{boundary_clause}}',
        'growth' => 'Check a situational hypothesis: do you start with {{primary_hint}} and then use {{support_hint}}? Record fitting and non-fitting examples; the current scores do not establish a fixed sequence. {{identity_clause}} {{boundary_clause}}',
        'decision' => 'Check a situational hypothesis: do you start with {{primary_hint}} and then use {{support_hint}}? Record fitting and non-fitting examples; the current scores do not establish a fixed sequence. {{identity_clause}} {{boundary_clause}}',
        'stress_recovery' => 'Check a situational hypothesis: do you start with {{primary_hint}} and then use {{support_hint}}? Record fitting and non-fitting examples; the current scores do not establish a fixed sequence. {{identity_clause}} {{boundary_clause}}',
        'communication' => 'Check a situational hypothesis: do you start with {{primary_hint}} and then use {{support_hint}}? Record fitting and non-fitting examples; the current scores do not establish a fixed sequence. {{identity_clause}} {{boundary_clause}}',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BOUNDARY_NARRATIVE_TEMPLATES = [
        'overview' => '在{{axis_label}}上，本次回答接近中点。可以比较{{side_label}}和{{opposite_side_label}}在具体情境中的例子；小分差不能证明你会自动切换，也不能解释他人如何理解你。',
        'relationships' => '在{{axis_label}}上，本次回答接近中点。可以比较{{side_label}}和{{opposite_side_label}}在具体情境中的例子；小分差不能证明你会自动切换，也不能解释他人如何理解你。',
        'growth' => '在{{axis_label}}上，本次回答接近中点。可以比较{{side_label}}和{{opposite_side_label}}在具体情境中的例子；小分差不能证明你会自动切换，也不能解释他人如何理解你。',
        'work' => '在{{axis_label}}上，本次回答接近中点。可以比较{{side_label}}和{{opposite_side_label}}在具体情境中的例子；小分差不能证明你会自动切换，也不能解释他人如何理解你。',
        'decision' => '在{{axis_label}}上，本次回答接近中点。可以比较{{side_label}}和{{opposite_side_label}}在具体情境中的例子；小分差不能证明你会自动切换，也不能解释他人如何理解你。',
        'stress_recovery' => '在{{axis_label}}上，本次回答接近中点。可以比较{{side_label}}和{{opposite_side_label}}在具体情境中的例子；小分差不能证明你会自动切换，也不能解释他人如何理解你。',
        'communication' => '在{{axis_label}}上，本次回答接近中点。可以比较{{side_label}}和{{opposite_side_label}}在具体情境中的例子；小分差不能证明你会自动切换，也不能解释他人如何理解你。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BOUNDARY_NARRATIVE_TEMPLATES_EN = [
        'overview' => 'On {{axis_label}}, this response set is near the midpoint. Compare examples of {{side_label}} and {{opposite_side_label}} in specific situations; a small gap does not establish automatic switching or explain how others understand you.',
        'relationships' => 'On {{axis_label}}, this response set is near the midpoint. Compare examples of {{side_label}} and {{opposite_side_label}} in specific situations; a small gap does not establish automatic switching or explain how others understand you.',
        'growth' => 'On {{axis_label}}, this response set is near the midpoint. Compare examples of {{side_label}} and {{opposite_side_label}} in specific situations; a small gap does not establish automatic switching or explain how others understand you.',
        'work' => 'On {{axis_label}}, this response set is near the midpoint. Compare examples of {{side_label}} and {{opposite_side_label}} in specific situations; a small gap does not establish automatic switching or explain how others understand you.',
        'decision' => 'On {{axis_label}}, this response set is near the midpoint. Compare examples of {{side_label}} and {{opposite_side_label}} in specific situations; a small gap does not establish automatic switching or explain how others understand you.',
        'stress_recovery' => 'On {{axis_label}}, this response set is near the midpoint. Compare examples of {{side_label}} and {{opposite_side_label}} in specific situations; a small gap does not establish automatic switching or explain how others understand you.',
        'communication' => 'On {{axis_label}}, this response set is near the midpoint. Compare examples of {{side_label}} and {{opposite_side_label}} in specific situations; a small gap does not establish automatic switching or explain how others understand you.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_HINTS = [
        'EI:E' => '你更容易先把能量投向外部互动、讨论与现场反馈',
        'EI:I' => '你更容易先在内部整理，再挑选更精准的表达时机',
        'SN:S' => '你更容易先抓住事实、细节和可验证的信息',
        'SN:N' => '你更容易先抓住趋势、隐含线索和整体意义',
        'TF:T' => '你更容易先按逻辑、标准和可比性来判断',
        'TF:F' => '你更容易先按感受、关系和价值影响来判断',
        'JP:J' => '你更容易先建立结构、节奏和明确的推进顺序',
        'JP:P' => '你更容易先保留弹性、边试边调，再决定最后定版',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_HINTS_EN = [
        'EI:E' => 'you usually send energy outward first through interaction, discussion, and live feedback',
        'EI:I' => 'you usually organize internally first and choose a more precise moment to speak',
        'SN:S' => 'you usually anchor first on facts, details, and verifiable information',
        'SN:N' => 'you usually lock onto patterns, signals, and larger meaning first',
        'TF:T' => 'you usually judge first through logic, standards, and comparability',
        'TF:F' => 'you usually judge first through feeling, relationship impact, and values',
        'JP:J' => 'you usually build structure, pace, and a defined order of execution first',
        'JP:P' => 'you usually keep flexibility first, test while moving, and commit later',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BOUNDARY_TEMPLATES = [
        'EI' => 'E / I接近中点，表示本次回答两侧分离较小；不能据此确定社交方式会怎样随情境变化。',
        'SN' => 'S / N接近中点，表示本次方向仍不清晰。可分别核对关注事实与关注模式的经历，不推断切换能力。',
        'TF' => 'T / F接近中点，表示本次判断偏好的分离较小；思考和情感偏好不等于理性与情绪水平。',
        'JP' => 'J / P接近中点，表示本次结构与弹性方向的分离较小。请结合具体安排任务的经历核对。',
        'AT' => 'A / T接近中点，表示这项FermatMind附加扩展的方向分离较小；不代表情绪稳定或紧张程度。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BOUNDARY_TEMPLATES_EN = [
        'EI' => 'An E / I score near the midpoint means the two directions were less separated in this response set; it does not establish how social behavior changes across contexts.',
        'SN' => 'An S / N score near the midpoint leaves the current direction less clear. Compare examples of attending to facts and patterns; it does not establish switching ability.',
        'TF' => 'A T / F score near the midpoint shows less separation in this response set; Thinking and Feeling preferences are not levels of rationality or emotion.',
        'JP' => 'A J / P score near the midpoint shows less separation between the reported directions. Check it against concrete experiences of organizing tasks.',
        'AT' => 'An A / T score near the midpoint shows less separation in this additional FermatMind extension; it is not a measure of emotional stability or tension.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_IDENTITY_TEMPLATES = [
        'A' => 'A是FermatMind附加扩展中的一个方向。请核对自己面对评价和反馈的具体经历，不由此推断恢复速度、抗压或工作能力。',
        'T' => 'T是FermatMind附加扩展中的一个方向。请核对自我质疑和反馈敏感的具体经历，不由此推断自我觉察、能力或健康水平。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_IDENTITY_TEMPLATES_EN = [
        'A' => 'A is one direction in FermatMind’s additional extension. Check it against actual experiences of evaluation and feedback; it does not establish recovery speed, coping ability or work ability.',
        'T' => 'T is one direction in FermatMind’s additional extension. Check examples of self-questioning and feedback sensitivity; it does not establish self-awareness, ability or health.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES = [
        'stable' => '本次核心轴的方向分离相对清晰；这不证明跨情境或长期稳定。请同时核对符合和不符合的实际例子。',
        'mixed' => '本次部分轴的方向较清晰，另一些更接近中点。保留不确定性，不把当前代码当作固定人格。',
        'context_sensitive' => '本次有多条轴接近中点。当前代码用于整理结果，不证明稳定偏好；可以比较相邻方向并记录反例。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES_EN = [
        'stable' => 'The core directions are more separated in this response set; this does not establish stability across contexts or over time. Check both fitting and non-fitting examples.',
        'mixed' => 'Some directions are clearer in this response set while others sit closer to the midpoint. Preserve uncertainty rather than treating the current code as a fixed personality.',
        'context_sensitive' => 'Several axes are near the midpoint in this response set. The current code organizes the result rather than proving a stable preference; compare neighboring directions and record counterexamples.',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const DEFAULT_MISUNDERSTANDING_FIX_TEMPLATES = [
        'why_this_type' => [
            'boundary' => '不同的自我描述可能与情境、已学会的行为或措辞有关。请比较{{dominant_side_label}}及另一侧的具体例子；这份结果不证明你最后总会回到同一判断方式。{{identity_clause}}',
            'clear' => '不同的自我描述可能与情境、已学会的行为或措辞有关。请比较{{dominant_side_label}}及另一侧的具体例子；这份结果不证明你最后总会回到同一判断方式。{{identity_clause}}',
            'strong' => '不同的自我描述可能与情境、已学会的行为或措辞有关。请比较{{dominant_side_label}}及另一侧的具体例子；这份结果不证明你最后总会回到同一判断方式。{{identity_clause}}',
            'very_strong' => '不同的自我描述可能与情境、已学会的行为或措辞有关。请比较{{dominant_side_label}}及另一侧的具体例子；这份结果不证明你最后总会回到同一判断方式。{{identity_clause}}',
        ],
        'adjacent_type_contrast' => [
            'boundary' => '{{neighbor_type}}只是一个比较入口，不是别人一定会把你看成的类型。请比较{{side_label}}与{{opposite_side_label}}的具体例子，不从外在相似推断内在判断。{{identity_clause}}',
            'clear' => '{{neighbor_type}}只是一个比较入口，不是别人一定会把你看成的类型。请比较{{side_label}}与{{opposite_side_label}}的具体例子，不从外在相似推断内在判断。{{identity_clause}}',
            'strong' => '{{neighbor_type}}只是一个比较入口，不是别人一定会把你看成的类型。请比较{{side_label}}与{{opposite_side_label}}的具体例子，不从外在相似推断内在判断。{{identity_clause}}',
            'very_strong' => '{{neighbor_type}}只是一个比较入口，不是别人一定会把你看成的类型。请比较{{side_label}}与{{opposite_side_label}}的具体例子，不从外在相似推断内在判断。{{identity_clause}}',
        ],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const DEFAULT_MISUNDERSTANDING_FIX_TEMPLATES_EN = [
        'why_this_type' => [
            'boundary' => 'A different self-description can reflect context, learned behavior or wording. Compare concrete examples of {{dominant_side_label}} and the opposite approach; this result does not establish that your judgment always returns to the same side. {{identity_clause}}',
            'clear' => 'A different self-description can reflect context, learned behavior or wording. Compare concrete examples of {{dominant_side_label}} and the opposite approach; this result does not establish that your judgment always returns to the same side. {{identity_clause}}',
            'strong' => 'A different self-description can reflect context, learned behavior or wording. Compare concrete examples of {{dominant_side_label}} and the opposite approach; this result does not establish that your judgment always returns to the same side. {{identity_clause}}',
            'very_strong' => 'A different self-description can reflect context, learned behavior or wording. Compare concrete examples of {{dominant_side_label}} and the opposite approach; this result does not establish that your judgment always returns to the same side. {{identity_clause}}',
        ],
        'adjacent_type_contrast' => [
            'boundary' => '{{neighbor_type}} is a comparison prompt, not a type that others necessarily see in you. Compare concrete examples of {{side_label}} and {{opposite_side_label}} rather than inferring inner judgment from outward resemblance. {{identity_clause}}',
            'clear' => '{{neighbor_type}} is a comparison prompt, not a type that others necessarily see in you. Compare concrete examples of {{side_label}} and {{opposite_side_label}} rather than inferring inner judgment from outward resemblance. {{identity_clause}}',
            'strong' => '{{neighbor_type}} is a comparison prompt, not a type that others necessarily see in you. Compare concrete examples of {{side_label}} and {{opposite_side_label}} rather than inferring inner judgment from outward resemblance. {{identity_clause}}',
            'very_strong' => '{{neighbor_type}} is a comparison prompt, not a type that others necessarily see in you. Compare concrete examples of {{side_label}} and {{opposite_side_label}} rather than inferring inner judgment from outward resemblance. {{identity_clause}}',
        ],
    ];

    private const DEFAULT_CLOSE_CALL_AXIS_TEMPLATE = '在{{axis_label}}上，本次所选方向{{side_label}}为{{percent}}%，距50%中点{{delta}}个百分点。这个数值不是与另一侧的分差，也不证明稳定偏好或情境切换。';

    private const DEFAULT_CLOSE_CALL_AXIS_TEMPLATE_EN = 'On {{axis_label}}, the selected {{side_label}} direction is {{percent}}%, {{delta}} percentage points from the 50% midpoint. This is not the gap from the opposite side and does not establish a stable preference or switching across contexts.';

    private const DEFAULT_ADJACENT_TYPE_CONTRAST_TEMPLATE = '{{neighbor_type}}是跨过{{axis_label}}当前归类方向后可比较的标签。请核对{{side_label}}和{{opposite_side_label}}的具体例子；相似感不证明其中一个标签就是固定的你。';

    private const DEFAULT_ADJACENT_TYPE_CONTRAST_TEMPLATE_EN = '{{neighbor_type}} is a comparison label reached by crossing the current classification direction on {{axis_label}}. Check concrete examples of {{side_label}} and {{opposite_side_label}}; resemblance does not establish either label as a fixed identity.';

    /**
     * @var array<string, string>
     */
    private const DEFAULT_STABILITY_EXPLANATION_TEMPLATES = [
        'stable' => '本次核心轴的方向分离相对清晰；这不证明跨情境或长期稳定。请同时核对符合和不符合的实际例子。',
        'mixed' => '本次部分轴的方向较清晰，另一些更接近中点。保留不确定性，不把当前代码当作固定人格。',
        'context_sensitive' => '本次有多条轴接近中点。当前代码用于整理结果，不证明稳定偏好；可以比较相邻方向并记录反例。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_STABILITY_EXPLANATION_TEMPLATES_EN = [
        'stable' => 'The core directions are more separated in this response set; this does not establish stability across contexts or over time. Check both fitting and non-fitting examples.',
        'mixed' => 'Some directions are clearer in this response set while others sit closer to the midpoint. Preserve uncertainty rather than treating the current code as a fixed personality.',
        'context_sensitive' => 'Several axes are near the midpoint in this response set. The current code organizes the result rather than proving a stable preference; compare neighboring directions and record counterexamples.',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const DEFAULT_STABILITY_REFRAME_TEMPLATES = [
        'stable' => [
            'zh-CN' => '本次核心轴的方向分离相对清晰；这不证明跨情境或长期稳定。请同时核对符合和不符合的实际例子。',
            'en' => 'The core directions are more separated in this response set; this does not establish stability across contexts or over time. Check both fitting and non-fitting examples.',
        ],
        'mixed' => [
            'zh-CN' => '本次部分轴的方向较清晰，另一些更接近中点。保留不确定性，不把当前代码当作固定人格。',
            'en' => 'Some directions are clearer in this response set while others sit closer to the midpoint. Preserve uncertainty rather than treating the current code as a fixed personality.',
        ],
        'context_sensitive' => [
            'zh-CN' => '本次有多条轴接近中点。当前代码用于整理结果，不证明稳定偏好；可以比较相邻方向并记录反例。',
            'en' => 'Several axes are near the midpoint in this response set. The current code organizes the result rather than proving a stable preference; compare neighboring directions and record counterexamples.',
        ],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const DEFAULT_STABILITY_REFRAME_TEMPLATES_EN = [
        'stable' => [
            'zh-CN' => '本次核心轴的方向分离相对清晰；这不证明跨情境或长期稳定。请同时核对符合和不符合的实际例子。',
            'en' => 'The core directions are more separated in this response set; this does not establish stability across contexts or over time. Check both fitting and non-fitting examples.',
        ],
        'mixed' => [
            'zh-CN' => '本次部分轴的方向较清晰，另一些更接近中点。保留不确定性，不把当前代码当作固定人格。',
            'en' => 'Some directions are clearer in this response set while others sit closer to the midpoint. Preserve uncertainty rather than treating the current code as a fixed personality.',
        ],
        'context_sensitive' => [
            'zh-CN' => '本次有多条轴接近中点。当前代码用于整理结果，不证明稳定偏好；可以比较相邻方向并记录反例。',
            'en' => 'Several axes are near the midpoint in this response set. The current code organizes the result rather than proving a stable preference; compare neighboring directions and record counterexamples.',
        ],
    ];

    public function __construct(
        private readonly ContentPacksIndex $packsIndex,
        private readonly MbtiUserStateOrchestrationService $userStateOrchestrationService,
        private readonly MbtiActionJourneyContractService $actionJourneyContractService,
        private readonly MbtiLongitudinalMemoryService $longitudinalMemoryService,
        private readonly MbtiAdaptiveSelectionService $adaptiveSelectionService,
        private readonly MbtiBigFiveSynthesisService $bigFiveSynthesisService,
        private readonly MbtiWorkingLifeConsolidationService $workingLifeConsolidationService,
        private readonly MbtiIntraTypeProfileService $intraTypeProfileService,
        private readonly MbtiPrivacyConsentContractService $privacyConsentContractService,
        private readonly VersionedComparativeNormingLayerService $comparativeNormingLayerService,
        private readonly ControlledGenerationRuntime $controlledGenerationRuntime,
        private readonly ControlledNarrativeLayerService $controlledNarrativeLayerService,
        private readonly CulturalCalibrationLayerService $culturalCalibrationLayerService,
        private readonly MbtiReadModelContractService $readModelContractService,
        private readonly ContentPackV2Resolver $contentPackV2Resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $reportPayload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildForReportPayload(array $reportPayload, array $context = []): array
    {
        $typeCode = $this->extractTypeCode($reportPayload, $context);
        if ($typeCode === '') {
            return [];
        }

        $locale = $this->normalizeLocale((string) ($context['locale'] ?? data_get($reportPayload, 'locale', '')));
        $axisVector = $this->buildAxisVector($reportPayload, $locale);
        if ($axisVector === []) {
            return [];
        }

        $identity = $this->resolveIdentity($typeCode, $axisVector);
        $axisBands = [];
        $boundaryFlags = [];

        foreach ($axisVector as $axisCode => $node) {
            $band = (string) ($node['band'] ?? 'clear');
            $axisBands[$axisCode] = $band;
            $boundaryFlags[$axisCode] = $band === 'boundary';
        }

        $dominantAxes = $this->resolveDominantAxes($axisVector);
        $dynamicDoc = $this->loadDynamicSectionsDoc($context, $locale);
        $sceneFingerprint = $this->buildSceneFingerprint(
            $axisVector,
            $identity,
            $dynamicDoc,
            $locale
        );
        $explainabilityAuthority = $this->buildExplainabilityAuthority(
            $typeCode,
            $identity,
            $axisVector,
            $dominantAxes,
            $reportPayload,
            $dynamicDoc,
            $locale
        );
        $sectionVariants = $this->buildSectionVariants(
            $axisVector,
            $identity,
            $sceneFingerprint,
            $explainabilityAuthority,
            [],
            $dynamicDoc,
            $locale
        );
        $careerBridgeAuthority = $this->buildCareerBridgeAuthority(
            $typeCode,
            $identity,
            $sceneFingerprint,
            $sectionVariants
        );
        $actionAuthority = $this->buildActionAuthority(
            $identity,
            $sceneFingerprint,
            $sectionVariants,
            $explainabilityAuthority,
            $dynamicDoc,
            $locale
        );
        $sectionVariants = $this->buildSectionVariants(
            $axisVector,
            $identity,
            $sceneFingerprint,
            $explainabilityAuthority,
            $actionAuthority,
            $dynamicDoc,
            $locale
        );

        $variantKeys = [];
        foreach ($sectionVariants as $sectionKey => $variant) {
            $variantKeys[$sectionKey] = (string) ($variant['variant_key'] ?? '');
        }

        $personalization = $this->userStateOrchestrationService->withBaseline([
            'schema_version' => 'mbti.personalization.phase9e.v1',
            'interpretation_copy_version' => self::INTERPRETATION_COPY_VERSION,
            'locale' => $locale,
            'type_code' => $typeCode,
            'identity' => $identity,
            'axis_vector' => $axisVector,
            'axis_bands' => $axisBands,
            'boundary_flags' => $boundaryFlags,
            'dominant_axes' => $dominantAxes,
            'scene_fingerprint' => $sceneFingerprint,
            'explainability_summary' => $explainabilityAuthority['explainability_summary'],
            'close_call_axes' => $explainabilityAuthority['close_call_axes'],
            'neighbor_type_keys' => $explainabilityAuthority['neighbor_type_keys'],
            'contrast_keys' => $explainabilityAuthority['contrast_keys'],
            'confidence_or_stability_keys' => $explainabilityAuthority['confidence_or_stability_keys'],
            'work_style_keys' => array_values((array) data_get($sceneFingerprint, 'work.style_keys', [])),
            'relationship_style_keys' => array_values((array) data_get($sceneFingerprint, 'relationships.style_keys', [])),
            'decision_style_keys' => array_values((array) data_get($sceneFingerprint, 'decision.style_keys', [])),
            'stress_recovery_keys' => array_values((array) data_get($sceneFingerprint, 'stress_recovery.style_keys', [])),
            'communication_style_keys' => array_values((array) data_get($sceneFingerprint, 'communication.style_keys', [])),
            'work_style_summary' => $careerBridgeAuthority['work_style_summary'],
            'role_fit_keys' => $careerBridgeAuthority['role_fit_keys'],
            'collaboration_fit_keys' => $careerBridgeAuthority['collaboration_fit_keys'],
            'work_env_preference_keys' => $careerBridgeAuthority['work_env_preference_keys'],
            'career_next_step_keys' => $careerBridgeAuthority['career_next_step_keys'],
            'action_plan_summary' => $actionAuthority['action_plan_summary'],
            'weekly_action_keys' => $actionAuthority['weekly_action_keys'],
            'relationship_action_keys' => $actionAuthority['relationship_action_keys'],
            'work_experiment_keys' => $actionAuthority['work_experiment_keys'],
            'watchout_keys' => $actionAuthority['watchout_keys'],
            'recommended_read_candidates' => $this->extractRecommendationCandidates($reportPayload),
            'variant_keys' => $variantKeys,
            'sections' => $sectionVariants,
            'pack_id' => trim((string) ($context['pack_id'] ?? data_get($reportPayload, 'versions.content_pack_id', ''))),
            'engine_version' => trim((string) ($context['engine_version'] ?? data_get($reportPayload, 'versions.engine', ''))),
            'content_package_dir' => trim((string) ($context['dir_version'] ?? data_get($reportPayload, 'versions.dir_version', ''))),
            'dynamic_sections_version' => trim((string) ($dynamicDoc['version'] ?? '')),
        ], (bool) ($context['has_unlock'] ?? false));

        $personalization = $this->bigFiveSynthesisService->attach($personalization, [
            'org_id' => (int) ($context['org_id'] ?? 0),
            'user_id' => $context['user_id'] ?? null,
            'anon_id' => $context['anon_id'] ?? null,
            'attempt_id' => $context['attempt_id'] ?? null,
            'locale' => $locale,
        ]);
        $personalization = $this->workingLifeConsolidationService->attach($personalization);
        $personalization = $this->actionJourneyContractService->attach($personalization);
        $personalization = $this->intraTypeProfileService->attach($personalization);
        $personalization = $this->longitudinalMemoryService->attach($personalization, $context);
        $personalization = $this->adaptiveSelectionService->attach($personalization);
        $personalization = $this->attachToneLayer($personalization, $context, $dynamicDoc, $locale);
        $personalization = $this->attachCtaBundle($personalization, $context, $locale);

        $personalization = $this->privacyConsentContractService->attachContract($personalization, [
            'region' => trim((string) ($context['region'] ?? config('regions.default_region', 'CN_MAINLAND'))),
            'locale' => $locale,
        ]);
        $personalization['comparative_v1'] = $this->comparativeNormingLayerService->buildForMbti(
            $personalization,
            $reportPayload,
            [
                'locale' => $locale,
                'region' => trim((string) ($context['region'] ?? config('regions.default_region', 'CN_MAINLAND'))),
                'norm_version' => trim((string) ($context['norm_version'] ?? data_get($reportPayload, 'norms.version_id', ''))),
            ]
        );

        $personalization['narrative_runtime_contract_v1'] = $this->controlledGenerationRuntime->buildContract(
            'mbti.report',
            'MBTI',
            $locale,
            $personalization,
            [
                'type_code' => $typeCode,
                'identity' => $identity,
                'engine_version' => trim((string) ($context['engine_version'] ?? data_get($reportPayload, 'versions.engine', ''))),
                'schema_version' => 'mbti.personalization.phase9e.v1',
                'dynamic_sections_version' => trim((string) ($dynamicDoc['version'] ?? '')),
                'user_id' => $context['user_id'] ?? null,
                'anon_id' => $context['anon_id'] ?? null,
                'attempt_id' => $context['attempt_id'] ?? null,
            ]
        );
        $personalization['controlled_narrative_v1'] = $this->controlledNarrativeLayerService->buildFromRuntimeContract(
            is_array($personalization['narrative_runtime_contract_v1'] ?? null)
                ? $personalization['narrative_runtime_contract_v1']
                : []
        );
        $personalization['cultural_calibration_v1'] = $this->culturalCalibrationLayerService->buildForMbti(
            $personalization,
            [
                'locale' => $locale,
                'region' => trim((string) ($context['region'] ?? config('regions.default_region', 'CN_MAINLAND'))),
                'pack_id' => trim((string) ($context['pack_id'] ?? data_get($reportPayload, 'versions.content_pack_id', ''))),
                'dir_version' => trim((string) ($context['dir_version'] ?? data_get($reportPayload, 'versions.dir_version', ''))),
            ]
        );

        return $this->readModelContractService->attachContract($personalization);
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function attachCtaBundleForExistingPersonalization(array $personalization, array $context = []): array
    {
        if ($personalization === []) {
            return [];
        }

        $locale = $this->normalizeLocale((string) ($context['locale'] ?? ($personalization['locale'] ?? '')));
        $resolvedContext = [
            'pack_id' => trim((string) ($context['pack_id'] ?? ($personalization['pack_id'] ?? ''))),
            'dir_version' => trim((string) ($context['dir_version'] ?? ($personalization['content_package_dir'] ?? ''))),
            'locale' => $locale,
        ];
        $dynamicDoc = $this->loadDynamicSectionsDoc($resolvedContext, $locale);
        $personalization = $this->refreshInterpretationCopy($personalization, $dynamicDoc, $locale);
        $personalization = $this->attachToneLayer($personalization, $resolvedContext, $dynamicDoc, $locale);

        return $this->attachCtaBundle($personalization, $resolvedContext, $locale);
    }

    /** Refresh display copy from saved scores without rewriting the stored report or user state. */
    private function refreshInterpretationCopy(array $personalization, ?array $doc = null, ?string $locale = null): array
    {
        if (array_key_exists('interpretation_copy_version', $personalization)
            || ($personalization['schema_version'] ?? null) !== 'mbti.personalization.phase9e.v1') {
            return $personalization;
        }
        $axes = $personalization['axis_vector'] ?? null;
        if (! is_array($axes) || ! is_array($personalization['sections'] ?? null)
            || array_diff(self::AXIS_ORDER, array_keys($axes)) !== []) {
            return $personalization;
        }
        foreach (self::AXIS_ORDER as $code) {
            if (! is_array($axes[$code]) || ! is_numeric($axes[$code]['pct'] ?? null)
                || ! is_numeric($axes[$code]['delta'] ?? null) || ! is_string($axes[$code]['side'] ?? null)
                || ! is_string($axes[$code]['band'] ?? null) || ! is_string($axes[$code]['axis_label'] ?? null)
                || ! is_string($axes[$code]['side_label'] ?? null)) {
                return $personalization;
            }
        }
        $validAxis = static function (mixed $axis) use ($axes): bool {
            if (! is_array($axis) || ! isset($axes[$axis['axis'] ?? ''])) {
                return false;
            }
            $source = $axes[$axis['axis']];
            foreach (['pct', 'delta', 'side', 'band', 'axis_label', 'side_label'] as $field) {
                if (! array_key_exists($field, $axis) || $axis[$field] !== $source[$field]) {
                    return false;
                }
            }

            return true;
        };
        if (array_diff(self::TARGET_SECTIONS, array_keys($personalization['sections'])) !== []) {
            return $personalization;
        }
        foreach (self::TARGET_SECTIONS as $key) {
            $section = $personalization['sections'][$key];
            if (! is_array($section) || ! $validAxis($section['primary_axis'] ?? null)
                || ! is_array($section['blocks'] ?? null)
                || (isset($section['support_axis']) && ! $validAxis($section['support_axis']))) {
                return $personalization;
            }
            foreach ($section['blocks'] as $block) {
                if (! is_array($block) || ! is_string($block['id'] ?? null)
                    || ! is_string($block['kind'] ?? null) || ! is_string($block['text'] ?? null)) {
                    return $personalization;
                }
            }
        }
        $scenes = $personalization['scene_fingerprint'] ?? null;
        if (! is_array($scenes) || array_diff(self::SCENE_FINGERPRINT_ORDER, array_keys($scenes)) !== []) {
            return $personalization;
        }
        foreach (self::SCENE_FINGERPRINT_ORDER as $key) {
            $scene = $scenes[$key];
            if (! is_array($scene) || ! $validAxis($scene['primary_axis'] ?? null)
                || (isset($scene['support_axis']) && ! $validAxis($scene['support_axis']))
                || ! is_string($scene['summary'] ?? null)) {
                return $personalization;
            }
        }
        foreach (['work_style_summary', 'action_plan_summary', 'explainability_summary'] as $key) {
            if (! is_string($personalization[$key] ?? null)) {
                return $personalization;
            }
        }
        $locale = $this->normalizeLocale($locale ?? (string) ($personalization['locale'] ?? ''));
        $doc ??= $this->loadDynamicSectionsDoc([
            'pack_id' => (string) ($personalization['pack_id'] ?? ''),
            'dir_version' => (string) ($personalization['content_package_dir'] ?? ''),
        ], $locale);
        if ($locale === 'zh-CN' && $doc === []) {
            return $personalization;
        }
        $identity = (string) ($personalization['identity'] ?? '');
        $closeCalls = $this->resolveCloseCallAxes($axes, $locale);
        $personalization['close_call_axes'] = $closeCalls;
        foreach (self::SCENE_FINGERPRINT_ORDER as $key) {
            $scene = $scenes[$key];
            $text = $this->resolveSceneFingerprintText($doc, (string) $key, $locale, $axes,
                $scene['primary_axis'], is_array($scene['support_axis'] ?? null) ? $scene['support_axis'] : null,
                $identity, $this->normalizeStringList($scene['boundary_axes'] ?? []));
            $scene['summary'] = $text;
            $scenes[$key] = $scene;
        }
        $personalization['scene_fingerprint'] = $scenes;
        if (is_string($personalization['work_style_summary'] ?? null) && is_string(data_get($scenes, 'work.summary'))) {
            $personalization['work_style_summary'] = data_get($scenes, 'work.summary');
        }
        $bucket = $this->resolveToneStabilityBucket($personalization);
        $personalization['action_plan_summary'] = $this->buildActionPlanSummary($scenes, $bucket, $doc, $locale);
        $summary = $this->resolveTemplate(data_get($doc, 'explainability_summary_templates.'.$bucket), $locale,
            $locale === 'zh-CN' ? (self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES[$bucket] ?? self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES['mixed'])
                : (self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES_EN[$bucket] ?? self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES_EN['mixed']));
        $personalization['explainability_summary'] = $this->renderTemplate($summary, [
            'close_axis_label' => (string) ($closeCalls[0]['axis_label'] ?? ''),
            'identity_clause' => $this->shortIdentityClause($identity, $locale),
        ]);
        foreach ($personalization['sections'] as $key => &$section) {
            if (! is_array($section) || ! is_array($section['primary_axis'] ?? null) || ! is_array($section['blocks'] ?? null)) {
                continue;
            }
            $primary = $section['primary_axis'];
            $sceneKey = (string) ($section['scene_key'] ?? self::SECTION_SCENE_MAP[$key] ?? 'overview');
            $tone = (string) ($section['tone_mode'] ?? '');
            $section['close_call_axes'] = array_values(array_filter((array) ($section['close_call_axes'] ?? []),
                fn ($axis): bool => is_array($axis) && $this->isExplainabilityCloseCall($axis)));
            $kept = [];
            foreach ($section['blocks'] as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $kind = (string) ($block['kind'] ?? '');
                $text = '';
                if ($key === 'traits.close_call_axes' && $kind === 'borderline_axis') {
                    $id = (string) ($block['id'] ?? '');
                    $matches = array_values(array_filter($closeCalls, static fn (array $axis): bool => str_contains($id, '.borderline_axis.'.$axis['axis'].'.')));
                    if ($matches === []) {
                        continue;
                    }
                    $text = $this->resolveCloseCallAxisText($doc, $locale, $matches[0]);
                } elseif ($kind === 'axis_strength') {
                    $text = $this->resolveAxisStrengthText($doc, $this->templateGroupForSection((string) $key, $sceneKey), (string) ($primary['band'] ?? 'clear'), $locale, $primary);
                } elseif ($kind === 'identity') {
                    $text = $this->resolveIdentityText($doc, $identity, $locale);
                } elseif ($kind === 'boundary') {
                    $axis = (string) (($section['boundary_axes'] ?? [])[0] ?? '');
                    if (isset($axes[$axis])) {
                        $text = $this->resolveBoundaryNarrativeText($doc, $sceneKey, $axis, $locale, $axes, $primary);
                    }
                } elseif ($kind === $this->sceneBlockKind($sceneKey, (string) $key)) {
                    $text = $this->resolveSceneText($doc, $this->sceneTemplateKeyForSection((string) $key, $sceneKey), $locale, $primary, (string) $key, $tone);
                }
                $toned = $this->resolveToneAdjustedText($doc, $locale, (string) $key, $kind, $tone, $section, $personalization);
                if ($toned !== '') {
                    $text = $toned;
                }
                if ($text !== '') {
                    $block['text'] = $text;
                }
                $kept[] = $block;
            }
            $section['blocks'] = $kept;
            if ($key === 'traits.close_call_axes') {
                $ids = array_column($kept, 'id');
                $section['selected_blocks'] = array_values(array_filter((array) ($section['selected_blocks'] ?? []), static fn ($id): bool => in_array($id, $ids, true)));
            }
        }
        unset($section);
        $personalization['interpretation_copy_version'] = self::INTERPRETATION_COPY_VERSION;

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $dynamicDoc
     * @return array<string, mixed>
     */
    private function attachToneLayer(array $personalization, array $context, array $dynamicDoc, string $locale): array
    {
        if ($personalization === []) {
            return [];
        }

        $doc = $dynamicDoc !== [] ? $dynamicDoc : $this->loadDynamicSectionsDoc($context, $locale);
        $toneProfile = $this->buildToneProfile($personalization, $doc, $locale);
        if ($toneProfile === []) {
            return $personalization;
        }

        $personalization['tone_profile_v1'] = $toneProfile;
        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];
        if ($sections === []) {
            return $personalization;
        }

        $sectionToneModes = is_array($toneProfile['section_tone_modes'] ?? null) ? $toneProfile['section_tone_modes'] : [];
        $sectionToneReasons = is_array($toneProfile['section_tone_reasons'] ?? null) ? $toneProfile['section_tone_reasons'] : [];

        foreach (self::TONE_TARGET_SECTIONS as $sectionKey) {
            if (! is_array($sections[$sectionKey] ?? null)) {
                continue;
            }

            $toneMode = trim((string) ($sectionToneModes[$sectionKey] ?? ''));
            if ($toneMode === '') {
                continue;
            }

            $sections[$sectionKey] = $this->applyToneToSection(
                $sectionKey,
                $sections[$sectionKey],
                $toneMode,
                trim((string) ($sectionToneReasons[$sectionKey] ?? '')),
                $personalization,
                $doc,
                $locale
            );
        }

        $personalization['sections'] = $sections;

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyToneToSection(
        string $sectionKey,
        array $section,
        string $toneMode,
        string $toneReason,
        array $personalization,
        array $doc,
        string $locale
    ): array {
        $section['tone_mode'] = $toneMode;
        $section['tone_reason'] = $toneReason;
        $section['phrasing_mode'] = $toneMode;
        $section['tone_anchor_keys'] = $this->toneAnchorKeys($sectionKey, $toneMode, $personalization, $section);

        $blocks = [];
        foreach (array_values(array_filter((array) ($section['blocks'] ?? []), 'is_array')) as $block) {
            $kind = trim((string) ($block['kind'] ?? ''));
            if ($kind === '') {
                $blocks[] = $block;

                continue;
            }

            $toneText = $this->resolveToneAdjustedText(
                $doc,
                $locale,
                $sectionKey,
                $kind,
                $toneMode,
                $section,
                $personalization
            );

            if ($toneText !== '') {
                $block['text'] = $toneText;
            }

            $block['tone_mode'] = $toneMode;
            $blocks[] = $block;
        }

        $section['blocks'] = $blocks;

        return $section;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function buildToneProfile(array $personalization, array $doc, string $locale): array
    {
        $defaultToneMode = $this->resolveDefaultToneMode($personalization);
        if ($defaultToneMode === '') {
            return [];
        }

        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];
        $sectionToneModes = [];
        $sectionToneReasons = [];

        foreach (self::TONE_TARGET_SECTIONS as $sectionKey) {
            if (! is_array($sections[$sectionKey] ?? null)) {
                continue;
            }

            [$mode, $reason] = $this->resolveSectionToneMode($sectionKey, $personalization, $defaultToneMode);
            $sectionToneModes[$sectionKey] = $mode;
            $sectionToneReasons[$sectionKey] = $reason;
        }

        $toneEvidence = [
            'intent_cluster' => trim((string) data_get($personalization, 'user_state.current_intent_cluster', 'default')),
            'memory_state' => trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_state', 'fresh')),
            'adaptive_state' => trim((string) data_get($personalization, 'adaptive_selection_v1.selection_rewrite_reason', 'baseline')),
            'identity' => trim((string) ($personalization['identity'] ?? '')),
            'stability_bucket' => $this->resolveToneStabilityBucket($personalization),
            'action_completion_tendency' => trim((string) data_get($personalization, 'user_state.action_completion_tendency', 'idle')),
            'boundary_axes' => array_values(array_unique(array_filter(array_merge(
                $this->normalizeStringList(data_get($personalization, 'sections.traits.why_this_type.boundary_axes', [])),
                $this->normalizeStringList(data_get($personalization, 'sections.growth.stability_confidence.boundary_axes', []))
            )))),
        ];

        $toneReason = $this->resolveTopLevelToneReason($defaultToneMode, $toneEvidence);
        $toneAnchorKeys = [];
        foreach ($sectionToneModes as $sectionKey => $mode) {
            $toneAnchorKeys[] = sprintf('%s:%s', $sectionKey, $mode);
        }

        return [
            'version' => 'mbti.tone_profile.v1',
            'tone_contract_version' => 'mbti.tone_profile.v1',
            'tone_fingerprint' => hash('sha256', json_encode([
                'default' => $defaultToneMode,
                'sections' => $sectionToneModes,
                'evidence' => $toneEvidence,
                'doc_version' => trim((string) ($doc['version'] ?? '')),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'tone_scope' => 'mbti.result_sections',
            'default_tone_mode' => $defaultToneMode,
            'section_tone_modes' => $sectionToneModes,
            'section_tone_reasons' => $sectionToneReasons,
            'tone_reason' => $toneReason,
            'tone_evidence' => $toneEvidence,
            'phrasing_mode' => $defaultToneMode,
            'tone_softness_mode' => $defaultToneMode === 'low_pressure' ? 'low_pressure' : 'guided',
            'tone_anchor_keys' => $toneAnchorKeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function resolveDefaultToneMode(array $personalization): string
    {
        $adaptiveReason = trim((string) data_get($personalization, 'adaptive_selection_v1.selection_rewrite_reason', ''));
        $memoryState = trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_state', ''));
        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', 'default'));
        $completionTendency = trim((string) data_get($personalization, 'user_state.action_completion_tendency', 'idle'));
        $stabilityBucket = $this->resolveToneStabilityBucket($personalization);

        return match (true) {
            str_contains($adaptiveReason, 'reduce_pressure')
                || in_array($completionTendency, ['idle', 'warming_up'], true) => 'low_pressure',
            $adaptiveReason !== '' || $stabilityBucket === 'context_sensitive' => 'stabilizing',
            in_array($memoryState, ['resume_ready', 'resumed', 'revisit'], true) => 'reflective',
            in_array($intentCluster, ['career_move', 'action_activation'], true) => 'direct',
            default => 'supportive',
        };
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array{0:string,1:string}
     */
    private function resolveSectionToneMode(string $sectionKey, array $personalization, string $defaultToneMode): array
    {
        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', 'default'));
        $memoryState = trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_state', ''));
        $adaptiveReason = trim((string) data_get($personalization, 'adaptive_selection_v1.selection_rewrite_reason', ''));
        $stabilityBucket = $this->resolveToneStabilityBucket($personalization);
        $completionTendency = trim((string) data_get($personalization, 'user_state.action_completion_tendency', 'idle'));

        return match ($sectionKey) {
            'traits.why_this_type', 'traits.adjacent_type_contrast' => match (true) {
                in_array($memoryState, ['resume_ready', 'resumed', 'revisit'], true)
                    || in_array($intentCluster, ['clarify_type', 'deep_reading'], true) => ['reflective', 'resume_or_clarity'],
                $adaptiveReason !== '' => ['stabilizing', 'adaptive_guardrail'],
                default => ['supportive', 'same_type_explainability'],
            },
            'growth.stability_confidence' => match (true) {
                $adaptiveReason !== '' || $stabilityBucket === 'context_sensitive' => ['stabilizing', 'stability_guardrail'],
                in_array($memoryState, ['resume_ready', 'resumed', 'revisit'], true) => ['reflective', 'memory_resume'],
                $intentCluster === 'career_move' => ['direct', 'career_translation'],
                default => ['supportive', 'baseline_stability'],
            },
            'growth.next_actions' => match (true) {
                str_contains($adaptiveReason, 'reduce_pressure')
                    || in_array($completionTendency, ['idle', 'warming_up'], true) => ['low_pressure', 'action_pressure_buffer'],
                in_array($memoryState, ['resume_ready', 'resumed', 'revisit'], true) => ['reflective', 'resume_action_loop'],
                in_array($intentCluster, ['career_move', 'action_activation'], true) => ['direct', 'activation_path'],
                default => [$defaultToneMode, 'default_action_mode'],
            },
            'growth.watchouts' => match (true) {
                $adaptiveReason !== '' || $stabilityBucket === 'context_sensitive' => ['stabilizing', 'watchout_stability_guardrail'],
                in_array($intentCluster, ['career_move', 'action_activation'], true) => ['direct', 'watchout_activation'],
                default => ['supportive', 'watchout_baseline'],
            },
            'career.next_step' => match (true) {
                in_array($memoryState, ['resume_ready', 'resumed', 'revisit'], true) => ['reflective', 'career_resume'],
                $intentCluster === 'career_move' => ['direct', 'career_move'],
                default => ['supportive', 'career_support'],
            },
            'relationships.try_this_week' => match (true) {
                in_array($memoryState, ['resume_ready', 'resumed', 'revisit'], true) => ['reflective', 'relationship_resume'],
                $adaptiveReason !== '' || $stabilityBucket === 'context_sensitive' => ['stabilizing', 'relationship_stabilize'],
                default => ['supportive', 'relationship_bridge'],
            },
            default => [$defaultToneMode, 'default_tone'],
        };
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function resolveToneStabilityBucket(array $personalization): string
    {
        $contrastKey = trim((string) data_get($personalization, 'contrast_keys.growth.stability_confidence', ''));
        if (preg_match('/stability\.([a-z_]+)/', $contrastKey, $matches) === 1) {
            return trim((string) ($matches[1] ?? 'mixed'));
        }

        return 'mixed';
    }

    /**
     * @param  array<string, mixed>  $toneEvidence
     */
    private function resolveTopLevelToneReason(string $defaultToneMode, array $toneEvidence): string
    {
        return match ($defaultToneMode) {
            'low_pressure' => 'action_pressure_buffer',
            'stabilizing' => 'adaptive_or_context_sensitive',
            'reflective' => 'revisit_resume_context',
            'direct' => 'intent_driven_activation',
            default => trim((string) ($toneEvidence['intent_cluster'] ?? 'supportive_default')),
        };
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $personalization
     * @return list<string>
     */
    private function toneAnchorKeys(string $sectionKey, string $toneMode, array $personalization, array $section): array
    {
        $anchors = [
            sprintf('tone.%s', $toneMode),
            sprintf('section.%s', $sectionKey),
        ];

        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));
        if ($intentCluster !== '') {
            $anchors[] = sprintf('intent.%s', $intentCluster);
        }

        $memoryState = trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_state', ''));
        if ($memoryState !== '') {
            $anchors[] = sprintf('memory.%s', $memoryState);
        }

        $adaptiveReason = trim((string) data_get($personalization, 'adaptive_selection_v1.selection_rewrite_reason', ''));
        if ($adaptiveReason !== '') {
            $anchors[] = sprintf('adaptive.%s', $adaptiveReason);
        }

        foreach ($this->normalizeStringList($section['boundary_axes'] ?? []) as $axisCode) {
            $anchors[] = sprintf('boundary.%s', strtolower($axisCode));
        }

        return array_values(array_unique(array_filter($anchors)));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function attachCtaBundle(array $personalization, array $context, string $locale): array
    {
        $commercialSpec = $this->loadCommercialSpecDoc($context, $locale);
        if ($commercialSpec === []) {
            return $personalization;
        }

        $bundle = $this->buildCtaBundle($personalization, $commercialSpec);
        if ($bundle === []) {
            return $personalization;
        }

        $personalization['cta_bundle_v1'] = $bundle;
        $orchestration = is_array($personalization['orchestration'] ?? null) ? $personalization['orchestration'] : [];
        $orchestration['cta_bundle_key'] = trim((string) ($bundle['bundle_key'] ?? ''));
        $orchestration['cta_entry_reason'] = trim((string) ($bundle['entry_reason'] ?? ''));
        $orchestration['cta_softness_mode'] = trim((string) ($bundle['softness_mode'] ?? ''));
        $personalization['orchestration'] = $orchestration;

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $commercialSpec
     * @return array<string, mixed>
     */
    private function buildCtaBundle(array $personalization, array $commercialSpec): array
    {
        $bundles = is_array($commercialSpec['cta_bundles'] ?? null) ? $commercialSpec['cta_bundles'] : [];
        if ($bundles === []) {
            return [];
        }

        $bundleKey = $this->resolveCtaBundleKey($personalization);
        $bundle = is_array($bundles[$bundleKey] ?? null) ? $bundles[$bundleKey] : null;
        if ($bundle === null) {
            $bundleKey = trim((string) ($commercialSpec['cta_bundle_default'] ?? 'cta_bundle_growth'));
            $bundle = is_array($bundles[$bundleKey] ?? null) ? $bundles[$bundleKey] : null;
        }

        if ($bundle === null) {
            return [];
        }

        $entryMap = [];
        foreach (array_values(array_filter((array) ($bundle['entries'] ?? []), 'is_array')) as $entry) {
            $ctaKey = trim((string) ($entry['cta_key'] ?? ''));
            if ($ctaKey !== '') {
                $entryMap[$ctaKey] = $entry;
            }
        }

        $orderedEntries = [];
        foreach ($this->normalizeStringList(data_get($personalization, 'orchestration.cta_priority_keys', [])) as $ctaKey) {
            if (isset($entryMap[$ctaKey])) {
                $orderedEntries[] = $entryMap[$ctaKey];
                unset($entryMap[$ctaKey]);
            }
        }

        foreach ($entryMap as $entry) {
            $orderedEntries[] = $entry;
        }

        return [
            'version' => 'mbti.cta_bundle.v1',
            'bundle_key' => $bundleKey,
            'cta_intent' => trim((string) ($bundle['cta_intent'] ?? '')),
            'softness_mode' => trim((string) ($bundle['softness_mode'] ?? '')),
            'entry_reason' => trim((string) ($bundle['entry_reason'] ?? '')),
            'entries' => $orderedEntries,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function resolveCtaBundleKey(array $personalization): string
    {
        $currentIntentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));
        $memoryState = trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_state', ''));
        $adaptiveReason = trim((string) data_get($personalization, 'adaptive_selection_v1.selection_rewrite_reason', ''));
        $hasUnlock = (bool) data_get($personalization, 'user_state.has_unlock', false);
        $primaryFocusKey = trim((string) data_get($personalization, 'orchestration.primary_focus_key', ''));

        if ($adaptiveReason !== '') {
            return 'cta_bundle_adaptive_next_best';
        }

        if ($memoryState === 'resume_ready') {
            return 'cta_bundle_revisit';
        }

        if ($currentIntentCluster === 'career_move' || str_starts_with($primaryFocusKey, 'career.')) {
            return 'cta_bundle_career';
        }

        if ($currentIntentCluster === 'relationship_tuning' || str_starts_with($primaryFocusKey, 'relationships.')) {
            return 'cta_bundle_relationship';
        }

        if (! $hasUnlock && in_array($currentIntentCluster, ['clarify_type', 'deep_reading'], true)) {
            return 'cta_bundle_unlock_deepening';
        }

        if (! $hasUnlock && $currentIntentCluster === 'action_activation') {
            return 'cta_bundle_low_pressure';
        }

        if ((bool) data_get($personalization, 'user_state.has_share', false)) {
            return 'cta_bundle_share_forward';
        }

        return 'cta_bundle_growth';
    }

    /**
     * @param  array<string, mixed>  $reportPayload
     * @return list<array{
     *   key:string,
     *   type:string,
     *   title:string,
     *   priority:int,
     *   tags:list<string>,
     *   url:string
     * }>
     */
    private function extractRecommendationCandidates(array $reportPayload): array
    {
        $reads = is_array($reportPayload['recommended_reads'] ?? null) ? $reportPayload['recommended_reads'] : [];
        $candidates = [];

        foreach ($reads as $index => $read) {
            if (! is_array($read)) {
                continue;
            }

            $key = $this->normalizeRecommendationCandidateKey($read, (int) $index);
            if ($key === '') {
                continue;
            }

            $tags = array_values(array_unique(array_filter(array_map(
                static fn (mixed $tag): string => trim((string) $tag),
                is_array($read['tags'] ?? null) ? $read['tags'] : []
            ))));

            $candidates[] = [
                'key' => $key,
                'type' => trim((string) ($read['type'] ?? '')),
                'title' => trim((string) ($read['title'] ?? '')),
                'priority' => is_numeric($read['priority'] ?? null) ? (int) round((float) $read['priority']) : 0,
                'tags' => $tags,
                'url' => trim((string) ($read['url'] ?? $read['canonical_url'] ?? '')),
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $read
     */
    private function normalizeRecommendationCandidateKey(array $read, int $index): string
    {
        foreach ([
            trim((string) ($read['id'] ?? '')),
            trim((string) ($read['canonical_id'] ?? '')),
            trim((string) ($read['canonical_url'] ?? '')),
            trim((string) ($read['url'] ?? '')),
            trim((string) ($read['title'] ?? '')),
        ] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return sprintf('recommended-read-%d', $index + 1);
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ))));
    }

    /**
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function applyToProjection(array $projection, array $personalization): array
    {
        if ($personalization === []) {
            return $projection;
        }

        $personalization = $this->refreshInterpretationCopy($personalization);

        $projection['_meta'] = is_array($projection['_meta'] ?? null) ? $projection['_meta'] : [];
        $projection['_meta']['personalization'] = $personalization;

        $sections = is_array($projection['sections'] ?? null) ? $projection['sections'] : [];
        if ($sections === []) {
            return $projection;
        }

        $sectionMeta = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionKey = strtolower(trim((string) ($section['key'] ?? '')));
            $dynamic = $this->resolveProjectionSectionMeta($sectionKey, $sectionMeta);
            if ($sectionKey === '' || ! is_array($dynamic)) {
                continue;
            }
            $body = trim((string) ($section['body_md'] ?? $section['body'] ?? ''));
            $payload = is_array($section['payload'] ?? null) ? $section['payload'] : [];
            $blocks = [];

            if ($body !== '') {
                $blocks[] = [
                    'id' => sprintf('%s.type_skeleton', $sectionKey),
                    'kind' => 'type_skeleton',
                    'label' => $this->blockLabelForLocale('type_skeleton', $personalization),
                    'text' => $body,
                ];
            }

            foreach ((array) ($dynamic['blocks'] ?? []) as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $blocks[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'kind' => (string) ($block['kind'] ?? 'axis_strength'),
                    'label' => (string) ($block['label'] ?? $this->blockLabelForLocale((string) ($block['kind'] ?? 'axis_strength'), $personalization)),
                    'text' => (string) ($block['text'] ?? ''),
                    'contrast_key' => trim((string) ($block['contrast_key'] ?? '')),
                ];
            }

            if ($blocks !== []) {
                $visibleBlocks = array_values(array_filter($blocks, static function (array $block): bool {
                    return trim((string) ($block['text'] ?? '')) !== '';
                }));
                $selectedBlockIds = array_values(array_filter(array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    (array) ($dynamic['selected_blocks'] ?? [])
                )));

                if ($visibleBlocks !== [] && $selectedBlockIds !== []) {
                    $blockMap = [];
                    foreach ($visibleBlocks as $block) {
                        $blockId = trim((string) ($block['id'] ?? ''));
                        if ($blockId !== '') {
                            $blockMap[$blockId] = $block;
                        }
                    }

                    $orderedVisibleBlocks = [];
                    foreach ($visibleBlocks as $block) {
                        if (trim((string) ($block['kind'] ?? '')) === 'type_skeleton') {
                            $orderedVisibleBlocks[] = $block;
                        }
                    }
                    foreach ($selectedBlockIds as $blockId) {
                        if (isset($blockMap[$blockId])) {
                            $orderedVisibleBlocks[] = $blockMap[$blockId];
                        }
                    }

                    $orderedVisibleBlocks = array_values(array_filter($orderedVisibleBlocks, static function (array $block): bool {
                        return trim((string) ($block['id'] ?? '')) !== '';
                    }));

                    if (count($orderedVisibleBlocks) > count(array_filter($visibleBlocks, static function (array $block): bool {
                        return trim((string) ($block['kind'] ?? '')) === 'type_skeleton';
                    }))) {
                        $visibleBlocks = $orderedVisibleBlocks;
                    }
                }

                $payload['blocks'] = $visibleBlocks;
            }

            $payload['personalization'] = [
                'variant_key' => (string) ($dynamic['variant_key'] ?? ''),
                'section_selection_key' => trim((string) data_get($personalization, 'section_selection_keys.'.$sectionKey, '')),
                'selected_blocks' => array_values((array) ($dynamic['selected_blocks'] ?? [])),
                'primary_axis' => is_array($dynamic['primary_axis'] ?? null) ? $dynamic['primary_axis'] : null,
                'scene_key' => (string) ($dynamic['scene_key'] ?? ''),
                'style_key' => (string) ($dynamic['style_key'] ?? ''),
                'action_key' => (string) ($dynamic['action_key'] ?? ''),
                'contrast_key' => (string) ($dynamic['contrast_key'] ?? ''),
                'boundary_axes' => array_values((array) ($dynamic['boundary_axes'] ?? [])),
                'close_call_axes' => array_values((array) ($dynamic['close_call_axes'] ?? [])),
                'neighbor_type_keys' => array_values((array) ($dynamic['neighbor_type_keys'] ?? [])),
                'profile_seed_key' => trim((string) ($personalization['profile_seed_key'] ?? '')),
                'selection_fingerprint' => trim((string) ($personalization['selection_fingerprint'] ?? '')),
                'memory_fingerprint' => trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_fingerprint', '')),
                'memory_state' => trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_state', '')),
                'memory_rewrite_reason' => trim((string) data_get($personalization, 'longitudinal_memory_v1.memory_rewrite_reason', '')),
                'adaptive_contract_version' => trim((string) data_get($personalization, 'adaptive_selection_v1.adaptive_contract_version', '')),
                'adaptive_fingerprint' => trim((string) data_get($personalization, 'adaptive_selection_v1.adaptive_fingerprint', '')),
                'selection_rewrite_reason' => trim((string) data_get($personalization, 'adaptive_selection_v1.selection_rewrite_reason', '')),
                'next_best_action_key' => trim((string) data_get($personalization, 'adaptive_selection_v1.next_best_action_v1.key', '')),
                'next_best_action_reason' => trim((string) data_get($personalization, 'adaptive_selection_v1.next_best_action_v1.reason', '')),
                'tone_mode' => trim((string) ($dynamic['tone_mode'] ?? data_get($personalization, 'tone_profile_v1.section_tone_modes.'.$sectionKey, ''))),
                'tone_reason' => trim((string) ($dynamic['tone_reason'] ?? data_get($personalization, 'tone_profile_v1.section_tone_reasons.'.$sectionKey, ''))),
                'phrasing_mode' => trim((string) ($dynamic['phrasing_mode'] ?? data_get($personalization, 'tone_profile_v1.phrasing_mode', ''))),
                'tone_anchor_keys' => array_values((array) ($dynamic['tone_anchor_keys'] ?? [])),
            ];

            $section['payload'] = $payload;
            $section['_meta'] = array_merge(
                is_array($section['_meta'] ?? null) ? $section['_meta'] : [],
                ['variant_key' => (string) ($dynamic['variant_key'] ?? '')]
            );
            $sections[$index] = $section;
        }

        $projection['sections'] = $this->applyPromotedEnglishResultAuthority($sections, $personalization);

        return $projection;
    }

    /**
     * Apply only the activated, database-backed W1 English result authority to
     * private result/report projections. Public share projections do not call
     * applyToProjection and therefore remain excluded by their safe surface
     * contract.
     *
     * @param  list<array<string,mixed>>  $sections
     * @param  array<string,mixed>  $personalization
     * @return list<array<string,mixed>>
     */
    private function applyPromotedEnglishResultAuthority(array $sections, array $personalization): array
    {
        if ($this->normalizeLocale((string) ($personalization['locale'] ?? '')) !== 'en') {
            return $sections;
        }
        $authority = $this->contentPackV2Resolver->resolveActiveMbtiResultAuthority();
        if (! is_array($authority) || ($authority['schema_version'] ?? null) !== 'mbti_result_promotion.v2') {
            return $sections;
        }
        $assets = [];
        foreach ((array) ($authority['rows'] ?? []) as $row) {
            $asset = is_array($row) && is_array($row['asset'] ?? null) ? $row['asset'] : null;
            $field = is_array($asset) ? trim((string) ($asset['authority_field'] ?? '')) : '';
            if ($asset !== null && str_starts_with($field, 'sections.')) {
                $assets[substr($field, strlen('sections.'))] = $asset;
            }
        }
        if ($assets === []) {
            return $sections;
        }
        $templateContext = $this->promotedResultTemplateContext($personalization);
        foreach ($sections as $index => $section) {
            $key = strtolower(trim((string) ($section['key'] ?? '')));
            $asset = $assets[$key] ?? null;
            $content = is_array($asset) && is_array($asset['content'] ?? null) ? $asset['content'] : null;
            if ($key === '' || $content === null) {
                continue;
            }
            $body = array_values(array_filter(array_map(
                fn (mixed $line): string => $this->renderTemplate((string) $line, $templateContext),
                (array) ($content['body_template'] ?? []),
            ), static fn (string $line): bool => $line !== ''));
            if ($body === [] || implode(' ', $body) === '' || str_contains(implode(' ', $body), '{{')) {
                continue;
            }
            $payload = is_array($section['payload'] ?? null) ? $section['payload'] : [];
            $payload['summary'] = $this->renderTemplate((string) ($content['summary_template'] ?? ''), $templateContext);
            $payload['reflection_prompts'] = array_values(array_map(
                fn (mixed $prompt): string => $this->renderTemplate((string) $prompt, $templateContext),
                (array) ($content['reflection_prompts'] ?? []),
            ));
            $section['title'] = $this->renderTemplate((string) ($content['title'] ?? ($section['title'] ?? '')), $templateContext);
            $section['body_md'] = implode("\n\n", $body);
            $section['payload'] = $payload;
            $section['_meta'] = array_merge(is_array($section['_meta'] ?? null) ? $section['_meta'] : [], [
                'authority_source' => 'content_promotion_w1_mbti_results_v2',
                'authority_row_id' => (string) ($asset['row_id'] ?? ''),
            ]);
            $sections[$index] = $section;
        }

        return $sections;
    }

    /** @param array<string,mixed> $personalization @return array<string,string> */
    private function promotedResultTemplateContext(array $personalization): array
    {
        $identity = is_array($personalization['identity'] ?? null) ? $personalization['identity'] : [];
        $typeCode = trim((string) ($personalization['type_code'] ?? 'MBTI result'));

        return [
            'type_code' => $typeCode,
            'identity_variant' => trim((string) ($identity['variant_code'] ?? 'identity pattern')),
            'axis_label' => 'preference axis', 'side_label' => 'the reported side', 'opposite_side_label' => 'the opposite side', 'delta' => 'a measured',
            'neighbor_type' => 'a nearby pattern', 'adjacent_axis_label' => 'a nearby preference axis', 'adjacent_side_label' => 'the reported side', 'adjacent_opposite_side_label' => 'the opposite side',
        ];
    }

    /**
     * @param  array<string, mixed>  $reportPayload
     * @param  array<string, mixed>  $context
     */
    private function extractTypeCode(array $reportPayload, array $context): string
    {
        $candidates = [
            $context['type_code'] ?? null,
            data_get($reportPayload, 'profile.type_code'),
            $reportPayload['type_code'] ?? null,
            data_get($reportPayload, 'identity_card.type_code'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = strtoupper(trim((string) $candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $reportPayload
     * @return array<string, array<string, mixed>>
     */
    private function buildAxisVector(array $reportPayload, string $locale): array
    {
        $scores = is_array($reportPayload['scores'] ?? null) ? $reportPayload['scores'] : [];
        $axisStates = is_array($reportPayload['axis_states'] ?? null) ? $reportPayload['axis_states'] : [];
        $out = [];

        foreach (self::AXIS_ORDER as $axisCode) {
            $node = is_array($scores[$axisCode] ?? null) ? $scores[$axisCode] : [];
            $pct = is_numeric($node['pct'] ?? null) ? (int) round((float) $node['pct']) : null;
            $delta = is_numeric($node['delta'] ?? null)
                ? (int) round(abs((float) $node['delta']))
                : ($pct !== null ? (int) round(abs($pct - 50)) : null);
            $side = strtoupper(trim((string) ($node['side'] ?? '')));
            $state = trim((string) ($node['state'] ?? ($axisStates[$axisCode] ?? '')));

            if ($pct === null || $delta === null || $side === '') {
                continue;
            }

            $band = $this->resolveBand($delta, $state);
            $out[$axisCode] = [
                'axis' => $axisCode,
                'axis_label' => $this->axisLabel($axisCode, $locale),
                'side' => $side,
                'side_label' => $this->sideLabel($axisCode, $side, $locale),
                'pct' => $pct,
                'delta' => $delta,
                'state' => $state !== '' ? $state : $band,
                'band' => $band,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     */
    private function resolveIdentity(string $typeCode, array $axisVector): string
    {
        if (preg_match('/-(A|T)$/', $typeCode, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        $atSide = strtoupper(trim((string) ($axisVector['AT']['side'] ?? '')));
        if ($atSide === 'A' || $atSide === 'T') {
            return $atSide;
        }

        return '';
    }

    private function resolveBand(int $delta, string $state): string
    {
        return MbtiAxisStrengthBand::fromDeltaAndState($delta, $state);
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @return list<array<string, mixed>>
     */
    private function resolveDominantAxes(array $axisVector): array
    {
        $axes = [];

        foreach (self::DOMINANT_AXIS_ORDER as $axisCode) {
            if (! is_array($axisVector[$axisCode] ?? null)) {
                continue;
            }

            $axes[] = $axisVector[$axisCode];
        }

        usort($axes, static function (array $left, array $right): int {
            return ((int) ($right['delta'] ?? 0)) <=> ((int) ($left['delta'] ?? 0));
        });

        return array_values($axes);
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, mixed>>
     */
    private function buildSceneFingerprint(
        array $axisVector,
        string $identity,
        array $doc,
        string $locale
    ): array {
        $fingerprint = [];

        foreach (self::SCENE_FINGERPRINT_ORDER as $sceneKey) {
            $primaryAxis = $this->resolveScenePrimaryAxis($sceneKey, $axisVector);
            if (! is_array($primaryAxis)) {
                continue;
            }

            $supportAxis = $this->resolveSceneSupportAxis($sceneKey, $axisVector, (string) ($primaryAxis['axis'] ?? ''));
            $boundaryAxes = $this->resolveSceneBoundaryAxes($sceneKey, $axisVector);
            $styleKeys = $this->buildSceneStyleKeys($sceneKey, $primaryAxis, $supportAxis, $identity, $boundaryAxes);
            $fingerprint[$sceneKey] = [
                'scene' => $sceneKey,
                'title' => $this->sceneTitle($sceneKey, $doc, $locale),
                'summary' => $this->resolveSceneFingerprintText(
                    $doc,
                    $sceneKey,
                    $locale,
                    $axisVector,
                    $primaryAxis,
                    $supportAxis,
                    $identity,
                    $boundaryAxes
                ),
                'style_key' => $styleKeys[0] ?? '',
                'style_keys' => $styleKeys,
                'chapter_anchor' => self::SCENE_ANCHORS[$sceneKey] ?? 'overview',
                'primary_axis' => $primaryAxis,
                'support_axis' => $supportAxis,
                'boundary_axes' => $boundaryAxes,
            ];
        }

        return $fingerprint;
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @param  list<array<string, mixed>>  $dominantAxes
     * @param  array<string, mixed>  $reportPayload
     * @param  array<string, mixed>  $doc
     * @return array{
     *   explainability_summary:string,
     *   close_call_axes:list<array<string,mixed>>,
     *   dominant_axis:array<string,mixed>|null,
     *   neighbor_type_keys:list<string>,
     *   contrast_keys:array<string,string>,
     *   confidence_or_stability_keys:list<string>,
     *   stability_bucket:string
     * }
     */
    private function buildExplainabilityAuthority(
        string $typeCode,
        string $identity,
        array $axisVector,
        array $dominantAxes,
        array $reportPayload,
        array $doc,
        string $locale
    ): array {
        $closeCallAxes = $this->resolveCloseCallAxes($axisVector, $locale);
        $rankedExplainabilityAxes = $this->resolveExplainabilityAxes($axisVector, $locale);
        $neighborTypeKeys = $this->resolveNeighborTypeKeys($typeCode, $rankedExplainabilityAxes);
        $stabilityBucket = $this->resolveStabilityBucket($reportPayload, $axisVector, $closeCallAxes, $dominantAxes);
        $dominantAxis = is_array($dominantAxes[0] ?? null) ? $dominantAxes[0] : $this->resolveScenePrimaryAxis('overview', $axisVector);
        $closeAxis = is_array($closeCallAxes[0] ?? null) ? $closeCallAxes[0] : $dominantAxis;
        $dominantAxisCode = trim((string) ($dominantAxis['axis'] ?? ''));
        $dominantSide = trim((string) ($dominantAxis['side'] ?? ''));
        $closeAxisCode = trim((string) ($closeAxis['axis'] ?? ''));
        $closeAxisLabel = trim((string) ($closeAxis['axis_label'] ?? ''));

        $summaryTemplate = $this->resolveTemplate(
            data_get($doc, 'explainability_summary_templates.'.$stabilityBucket),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES[$stabilityBucket] ?? self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES['mixed'])
                : (self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES_EN[$stabilityBucket] ?? self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES_EN['mixed'])
        );

        $contrastKeys = [
            'traits.why_this_type' => sprintf(
                'traits.why_this_type:dominant.%s.%s.%s',
                $dominantAxisCode !== '' ? $dominantAxisCode : 'axis',
                $dominantSide !== '' ? $dominantSide : 'side',
                trim((string) ($dominantAxis['band'] ?? 'clear'))
            ),
            'traits.close_call_axes' => sprintf(
                'traits.close_call_axes:close.%s',
                $closeCallAxes === []
                    ? 'none'
                    : implode('-', array_values(array_filter(array_map(
                        static fn (array $axis): string => trim((string) ($axis['axis'] ?? '')),
                        $closeCallAxes
                    ))))
            ),
            'traits.adjacent_type_contrast' => sprintf(
                'traits.adjacent_type_contrast:neighbor.%s',
                $neighborTypeKeys === [] ? 'none' : implode('-', $neighborTypeKeys)
            ),
            'growth.stability_confidence' => sprintf(
                'growth.stability_confidence:stability.%s',
                $stabilityBucket
            ),
        ];

        $confidenceOrStabilityKeys = [sprintf('stability.bucket.%s', $stabilityBucket)];
        foreach ($closeCallAxes as $axis) {
            $axisCode = trim((string) ($axis['axis'] ?? ''));
            if ($axisCode !== '') {
                $confidenceOrStabilityKeys[] = sprintf('stability.close_call.%s', $axisCode);
            }
        }
        if ($identity !== '') {
            $confidenceOrStabilityKeys[] = sprintf('stability.identity.%s', $identity);
        }

        return [
            'explainability_summary' => $this->renderTemplate($summaryTemplate, [
                'dominant_axis_label' => trim((string) ($dominantAxis['axis_label'] ?? '')),
                'dominant_side_label' => trim((string) ($dominantAxis['side_label'] ?? '')),
                'close_axis_label' => $closeAxisLabel !== '' ? $closeAxisLabel : ($locale === 'zh-CN' ? '最接近边界的那条轴' : 'the closest-call axis'),
            ]),
            'close_call_axes' => $closeCallAxes,
            'dominant_axis' => is_array($dominantAxis) ? $dominantAxis : null,
            'neighbor_type_keys' => $neighborTypeKeys,
            'contrast_keys' => $contrastKeys,
            'confidence_or_stability_keys' => array_values(array_unique(array_filter($confidenceOrStabilityKeys))),
            'stability_bucket' => $stabilityBucket,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, mixed>>
     */
    private function buildSectionVariants(
        array $axisVector,
        string $identity,
        array $sceneFingerprint,
        array $explainabilityAuthority,
        array $actionAuthority,
        array $doc,
        string $locale
    ): array {
        $sectionVariants = [];

        foreach (self::TARGET_SECTIONS as $sectionKey) {
            if (in_array($sectionKey, [
                'traits.why_this_type',
                'traits.close_call_axes',
                'traits.adjacent_type_contrast',
                'growth.stability_confidence',
            ], true)) {
                $sectionVariants[$sectionKey] = $this->buildExplainabilitySectionVariant(
                    $sectionKey,
                    $axisVector,
                    $identity,
                    $sceneFingerprint,
                    $explainabilityAuthority,
                    $doc,
                    $locale
                );

                continue;
            }

            if (in_array($sectionKey, [
                'growth.next_actions',
                'growth.weekly_experiments',
                'relationships.try_this_week',
                'career.work_experiments',
                'career.next_step',
                'growth.watchouts',
            ], true)) {
                $sectionVariants[$sectionKey] = $this->buildActionSectionVariant(
                    $sectionKey,
                    $axisVector,
                    $identity,
                    $sceneFingerprint,
                    $actionAuthority,
                    $doc,
                    $locale,
                    ''
                );

                continue;
            }

            $sceneKey = self::SECTION_SCENE_MAP[$sectionKey] ?? 'overview';
            $primaryAxis = is_array(data_get($sceneFingerprint, $sceneKey.'.primary_axis'))
                ? data_get($sceneFingerprint, $sceneKey.'.primary_axis')
                : $this->resolveScenePrimaryAxis($sceneKey, $axisVector);

            if (! is_array($primaryAxis)) {
                continue;
            }

            $supportAxis = is_array(data_get($sceneFingerprint, $sceneKey.'.support_axis'))
                ? data_get($sceneFingerprint, $sceneKey.'.support_axis')
                : $this->resolveSceneSupportAxis($sceneKey, $axisVector, (string) ($primaryAxis['axis'] ?? ''));
            $boundaryAxes = array_values((array) data_get($sceneFingerprint, $sceneKey.'.boundary_axes', []));
            $boundaryAxis = $boundaryAxes[0] ?? null;
            $axisCode = (string) ($primaryAxis['axis'] ?? 'EI');
            $side = (string) ($primaryAxis['side'] ?? 'E');
            $band = (string) ($primaryAxis['band'] ?? 'clear');
            $styleKey = (string) data_get($sceneFingerprint, $sceneKey.'.style_key', '');
            $templateGroup = $this->templateGroupForSection($sectionKey, $sceneKey);
            $sceneTemplateKey = $this->sceneTemplateKeyForSection($sectionKey, $sceneKey);

            $blocks = [];
            $selectedBlocks = [];

            $axisStrengthText = $this->resolveAxisStrengthText($doc, $templateGroup, $band, $locale, $primaryAxis);
            if ($axisStrengthText !== '') {
                $blockId = sprintf('%s.axis_strength.%s.%s.%s', $sectionKey, $axisCode, $side, $band);
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'axis_strength',
                    'label' => $this->blockLabel('axis_strength', $doc, $locale),
                    'text' => $axisStrengthText,
                ];
            }

            $sceneText = $this->resolveSceneText($doc, $sceneTemplateKey, $locale, $primaryAxis);
            if ($sceneText !== '') {
                $sceneBlockKind = $this->sceneBlockKind($sceneKey, $sectionKey);
                $blockId = sprintf('%s.%s.%s.%s', $sectionKey, $sceneBlockKind, $axisCode, $side);
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => $sceneBlockKind,
                    'label' => $this->blockLabel($sceneBlockKind, $doc, $locale),
                    'text' => $sceneText,
                ];
            }

            if ($identity !== '') {
                $identityText = $this->resolveIdentityText($doc, $identity, $locale);
                if ($identityText !== '') {
                    $blockId = sprintf('%s.identity.%s', $sectionKey, strtolower($identity));
                    $selectedBlocks[] = $blockId;
                    $blocks[] = [
                        'id' => $blockId,
                        'kind' => 'identity',
                        'label' => $this->blockLabel('identity', $doc, $locale),
                        'text' => $identityText,
                    ];
                }
            }

            if (is_string($boundaryAxis) && $boundaryAxis !== '') {
                $boundaryText = $this->resolveBoundaryNarrativeText(
                    $doc,
                    $sceneKey,
                    $boundaryAxis,
                    $locale,
                    $axisVector,
                    $primaryAxis
                );
                if ($boundaryText !== '') {
                    $blockId = sprintf('%s.boundary.%s', $sectionKey, $boundaryAxis);
                    $selectedBlocks[] = $blockId;
                    $blocks[] = [
                        'id' => $blockId,
                        'kind' => 'boundary',
                        'label' => $this->blockLabel('boundary', $doc, $locale),
                        'text' => $boundaryText,
                    ];
                }
            }

            $variantParts = [
                $sectionKey,
                sprintf('%s.%s.%s', $axisCode, $side, $band),
                $identity !== '' ? sprintf('identity.%s', $identity) : 'identity.none',
                is_string($boundaryAxis) && $boundaryAxis !== '' ? sprintf('boundary.%s', $boundaryAxis) : 'boundary.none',
            ];

            $sectionVariants[$sectionKey] = [
                'variant_key' => implode(':', $variantParts),
                'style_key' => $styleKey,
                'scene_key' => $sceneKey,
                'primary_axis' => $primaryAxis,
                'support_axis' => $supportAxis,
                'boundary_axes' => $boundaryAxes,
                'action_key' => '',
                'contrast_key' => '',
                'close_call_axes' => [],
                'neighbor_type_keys' => [],
                'selected_blocks' => $selectedBlocks,
                'blocks' => $blocks,
            ];
        }

        return $sectionVariants;
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, mixed>  $explainabilityAuthority
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function buildExplainabilitySectionVariant(
        string $sectionKey,
        array $axisVector,
        string $identity,
        array $sceneFingerprint,
        array $explainabilityAuthority,
        array $doc,
        string $locale
    ): array {
        $dominantAxis = is_array($explainabilityAuthority['dominant_axis'] ?? null)
            ? $explainabilityAuthority['dominant_axis']
            : $this->resolveScenePrimaryAxis('overview', $axisVector);
        $closeCallAxes = array_values(array_filter(
            (array) ($explainabilityAuthority['close_call_axes'] ?? []),
            static fn (mixed $value): bool => is_array($value)
        ));
        $primaryCloseAxis = is_array($closeCallAxes[0] ?? null) ? $closeCallAxes[0] : $dominantAxis;
        $secondaryCloseAxis = is_array($closeCallAxes[1] ?? null) ? $closeCallAxes[1] : null;
        $neighborTypeKeys = array_values((array) ($explainabilityAuthority['neighbor_type_keys'] ?? []));
        $contrastKey = trim((string) data_get($explainabilityAuthority, 'contrast_keys.'.$sectionKey, ''));
        $stabilityBucket = trim((string) ($explainabilityAuthority['stability_bucket'] ?? 'mixed'));

        $sceneKey = $sectionKey === 'growth.stability_confidence' ? 'stability' : 'explainability';
        $primaryAxis = $sectionKey === 'growth.stability_confidence'
            ? $dominantAxis
            : ($sectionKey === 'traits.why_this_type' ? $dominantAxis : $primaryCloseAxis);
        $supportAxis = match ($sectionKey) {
            'traits.why_this_type' => $primaryCloseAxis,
            'traits.close_call_axes' => $secondaryCloseAxis,
            'traits.adjacent_type_contrast' => $secondaryCloseAxis ?? $dominantAxis,
            'growth.stability_confidence' => $primaryCloseAxis,
            default => null,
        };
        $boundaryAxes = array_values(array_filter(array_map(
            static fn (array $axis): string => trim((string) ($axis['axis'] ?? '')),
            $closeCallAxes
        )));

        if (! is_array($primaryAxis)) {
            return [
                'variant_key' => $sectionKey.':unresolved',
                'style_key' => '',
                'scene_key' => $sceneKey,
                'primary_axis' => null,
                'support_axis' => null,
                'boundary_axes' => $boundaryAxes,
                'contrast_key' => $contrastKey,
                'close_call_axes' => $closeCallAxes,
                'neighbor_type_keys' => $neighborTypeKeys,
                'selected_blocks' => [],
                'blocks' => [],
            ];
        }

        $identityText = $identity !== '' ? $this->resolveIdentityText($doc, $identity, $locale) : '';
        $blocks = [];
        $selectedBlocks = [];
        $variantKey = '';

        if ($sectionKey === 'traits.why_this_type') {
            $axisStrengthText = $this->resolveAxisStrengthText($doc, 'overview', (string) ($primaryAxis['band'] ?? 'clear'), $locale, $primaryAxis);
            if ($axisStrengthText !== '') {
                $blockId = sprintf('%s.axis_strength.%s.%s.%s', $sectionKey, $primaryAxis['axis'] ?? 'axis', $primaryAxis['side'] ?? 'side', $primaryAxis['band'] ?? 'clear');
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'axis_strength',
                    'label' => $this->blockLabel('axis_strength', $doc, $locale),
                    'text' => $axisStrengthText,
                ];
            }

            $whyText = $this->resolveWhyThisTypeText($doc, $locale, $primaryAxis, $supportAxis, $identity, $closeCallAxes);
            if ($whyText !== '') {
                $blockId = sprintf('%s.why_this_type.%s', $sectionKey, strtolower((string) ($primaryAxis['axis'] ?? 'axis')));
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'why_this_type',
                    'label' => $this->blockLabel('why_this_type', $doc, $locale),
                    'text' => $whyText,
                    'contrast_key' => $contrastKey,
                ];
            }

            $misunderstandingFixText = $this->resolveMisunderstandingFixText(
                $doc,
                $locale,
                $sectionKey,
                (string) ($primaryAxis['band'] ?? 'clear'),
                $primaryAxis,
                $supportAxis,
                $identity,
                $closeCallAxes,
                $neighborTypeKeys
            );
            if ($misunderstandingFixText !== '') {
                $blockId = sprintf('%s.misunderstanding_fix.%s', $sectionKey, strtolower((string) ($primaryAxis['axis'] ?? 'axis')));
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'misunderstanding_fix',
                    'label' => $this->blockLabel('misunderstanding_fix', $doc, $locale),
                    'text' => $misunderstandingFixText,
                    'contrast_key' => $contrastKey,
                ];
            }

            if ($identityText !== '') {
                $blockId = sprintf('%s.identity.%s', $sectionKey, strtolower($identity));
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'identity',
                    'label' => $this->blockLabel('identity', $doc, $locale),
                    'text' => $identityText,
                ];
            }

            $variantKey = implode(':', [
                $sectionKey,
                sprintf('%s.%s.%s', $primaryAxis['axis'] ?? 'axis', $primaryAxis['side'] ?? 'side', $primaryAxis['band'] ?? 'clear'),
                $identity !== '' ? sprintf('identity.%s', $identity) : 'identity.none',
                $boundaryAxes !== [] ? sprintf('boundary.%s', $boundaryAxes[0]) : 'boundary.none',
            ]);
        } elseif ($sectionKey === 'traits.close_call_axes') {
            foreach ($closeCallAxes as $index => $closeAxis) {
                $closeAxisCode = trim((string) ($closeAxis['axis'] ?? ''));
                if ($closeAxisCode === '') {
                    continue;
                }

                $text = $this->resolveCloseCallAxisText($doc, $locale, $closeAxis);
                if ($text === '') {
                    continue;
                }

                $blockId = sprintf('%s.borderline_axis.%s.%d', $sectionKey, $closeAxisCode, $index + 1);
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'borderline_axis',
                    'label' => $this->blockLabel('borderline_axis', $doc, $locale),
                    'text' => $text,
                    'contrast_key' => $contrastKey,
                ];
            }

            if ($boundaryAxes !== []) {
                $boundaryNarrative = $this->resolveBoundaryNarrativeText(
                    $doc,
                    'overview',
                    $boundaryAxes[0],
                    $locale,
                    $axisVector,
                    $primaryAxis
                );
                if ($boundaryNarrative !== '') {
                    $blockId = sprintf('%s.boundary.%s', $sectionKey, $boundaryAxes[0]);
                    $selectedBlocks[] = $blockId;
                    $blocks[] = [
                        'id' => $blockId,
                        'kind' => 'boundary',
                        'label' => $this->blockLabel('boundary', $doc, $locale),
                        'text' => $boundaryNarrative,
                    ];
                }
            }

            $variantKey = implode(':', [
                $sectionKey,
                sprintf('%s.%s.%s', $primaryAxis['axis'] ?? 'axis', $primaryAxis['side'] ?? 'side', $primaryAxis['band'] ?? 'clear'),
                $identity !== '' ? sprintf('identity.%s', $identity) : 'identity.none',
                $boundaryAxes !== [] ? sprintf('boundary.%s', $boundaryAxes[0]) : 'boundary.none',
            ]);
        } elseif ($sectionKey === 'traits.adjacent_type_contrast') {
            $contrastText = $this->resolveAdjacentTypeContrastText(
                $doc,
                $locale,
                $primaryAxis,
                $neighborTypeKeys,
                $closeCallAxes
            );
            if ($contrastText !== '') {
                $blockId = sprintf('%s.adjacent_type_contrast.%s', $sectionKey, strtolower((string) ($primaryAxis['axis'] ?? 'axis')));
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'adjacent_type_contrast',
                    'label' => $this->blockLabel('adjacent_type_contrast', $doc, $locale),
                    'text' => $contrastText,
                    'contrast_key' => $contrastKey,
                ];
            }

            $misunderstandingFixText = $this->resolveMisunderstandingFixText(
                $doc,
                $locale,
                $sectionKey,
                (string) ($primaryAxis['band'] ?? 'clear'),
                $primaryAxis,
                $supportAxis,
                $identity,
                $closeCallAxes,
                $neighborTypeKeys
            );
            if ($misunderstandingFixText !== '') {
                $blockId = sprintf('%s.misunderstanding_fix.%s', $sectionKey, strtolower((string) ($neighborTypeKeys[0] ?? ($primaryAxis['axis'] ?? 'axis'))));
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'misunderstanding_fix',
                    'label' => $this->blockLabel('misunderstanding_fix', $doc, $locale),
                    'text' => $misunderstandingFixText,
                    'contrast_key' => $contrastKey,
                ];
            }

            if ($identityText !== '') {
                $blockId = sprintf('%s.identity.%s', $sectionKey, strtolower($identity));
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'identity',
                    'label' => $this->blockLabel('identity', $doc, $locale),
                    'text' => $identityText,
                ];
            }

            $variantKey = implode(':', [
                $sectionKey,
                sprintf('%s.%s.%s', $primaryAxis['axis'] ?? 'axis', $primaryAxis['side'] ?? 'side', $primaryAxis['band'] ?? 'clear'),
                $identity !== '' ? sprintf('identity.%s', $identity) : 'identity.none',
                sprintf('neighbor.%s', $neighborTypeKeys[0] ?? 'none'),
            ]);
        } elseif ($sectionKey === 'growth.stability_confidence') {
            $stabilityText = $this->resolveStabilityExplanationText(
                $doc,
                $locale,
                $stabilityBucket,
                $primaryCloseAxis,
                $identity
            );
            if ($stabilityText !== '') {
                $blockId = sprintf('%s.stability.%s', $sectionKey, $stabilityBucket);
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'stability_explanation',
                    'label' => $this->blockLabel('stability_explanation', $doc, $locale),
                    'text' => $stabilityText,
                    'contrast_key' => $contrastKey,
                ];
            }

            $stabilityReframeText = $this->resolveStabilityReframeText(
                $doc,
                $locale,
                $stabilityBucket,
                $primaryCloseAxis ?? $primaryAxis,
                $identity
            );
            if ($stabilityReframeText !== '') {
                $blockId = sprintf('%s.stability_reframe.%s', $sectionKey, $stabilityBucket);
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'stability_reframe',
                    'label' => $this->blockLabel('stability_reframe', $doc, $locale),
                    'text' => $stabilityReframeText,
                    'contrast_key' => $contrastKey,
                ];
            }

            $stressRecoveryText = $this->resolveSceneText($doc, 'stress_recovery', $locale, $primaryCloseAxis ?? $primaryAxis);
            if ($stressRecoveryText !== '') {
                $blockId = sprintf('%s.stress_recovery.%s', $sectionKey, $primaryCloseAxis['axis'] ?? $primaryAxis['axis'] ?? 'axis');
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'stress_recovery',
                    'label' => $this->blockLabel('stress_recovery', $doc, $locale),
                    'text' => $stressRecoveryText,
                ];
            }

            if ($boundaryAxes !== []) {
                $boundaryNarrative = $this->resolveBoundaryNarrativeText(
                    $doc,
                    'growth',
                    $boundaryAxes[0],
                    $locale,
                    $axisVector,
                    $primaryCloseAxis ?? $primaryAxis
                );
                if ($boundaryNarrative !== '') {
                    $blockId = sprintf('%s.boundary.%s', $sectionKey, $boundaryAxes[0]);
                    $selectedBlocks[] = $blockId;
                    $blocks[] = [
                        'id' => $blockId,
                        'kind' => 'boundary',
                        'label' => $this->blockLabel('boundary', $doc, $locale),
                        'text' => $boundaryNarrative,
                    ];
                }
            }

            $variantKey = implode(':', [
                $sectionKey,
                sprintf('stability.%s', $stabilityBucket),
                $identity !== '' ? sprintf('identity.%s', $identity) : 'identity.none',
                $boundaryAxes !== [] ? sprintf('boundary.%s', $boundaryAxes[0]) : 'boundary.none',
            ]);
        }

        return [
            'variant_key' => $variantKey !== '' ? $variantKey : $sectionKey.':unresolved',
            'style_key' => $contrastKey,
            'scene_key' => $sceneKey,
            'primary_axis' => $primaryAxis,
            'support_axis' => $supportAxis,
            'boundary_axes' => $boundaryAxes,
            'action_key' => '',
            'contrast_key' => $contrastKey,
            'close_call_axes' => $closeCallAxes,
            'neighbor_type_keys' => $neighborTypeKeys,
            'selected_blocks' => $selectedBlocks,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, array<string, mixed>>  $sectionVariants
     * @return array{
     *   work_style_summary:string,
     *   role_fit_keys:list<string>,
     *   collaboration_fit_keys:list<string>,
     *   work_env_preference_keys:list<string>,
     *   career_next_step_keys:list<string>
     * }
     */
    private function buildCareerBridgeAuthority(
        string $typeCode,
        string $identity,
        array $sceneFingerprint,
        array $sectionVariants
    ): array {
        $workStyleKeys = array_values((array) data_get($sceneFingerprint, 'work.style_keys', []));
        $communicationStyleKeys = array_values((array) data_get($sceneFingerprint, 'communication.style_keys', []));
        $decisionStyleKeys = array_values((array) data_get($sceneFingerprint, 'decision.style_keys', []));
        $roleFitKeys = $this->remapStyleKeys($workStyleKeys, 'role_fit');
        $roleCluster = $this->resolveRoleCluster($typeCode);
        if ($roleCluster !== '') {
            array_unshift($roleFitKeys, sprintf('role_fit.role.%s', $roleCluster));
        }

        $workEnvPreferenceKeys = $this->remapStyleKeys($workStyleKeys, 'work_env');
        $workPrimaryAxis = data_get($sceneFingerprint, 'work.primary_axis');
        if (is_array($workPrimaryAxis)) {
            $primaryAxisCode = trim((string) ($workPrimaryAxis['axis'] ?? ''));
            $primarySide = trim((string) ($workPrimaryAxis['side'] ?? ''));
            if ($primaryAxisCode === 'EI') {
                $workEnvPreferenceKeys[] = sprintf(
                    'work_env.preference.%s',
                    $primarySide === 'E' ? 'high_collaboration' : 'deep_focus'
                );
            }

            if ($primaryAxisCode === 'JP') {
                $workEnvPreferenceKeys[] = sprintf(
                    'work_env.preference.%s',
                    $primarySide === 'J' ? 'structured_rhythm' : 'adaptive_rhythm'
                );
            }
        }

        $workBoundaryAxis = trim((string) data_get($sceneFingerprint, 'work.boundary_axes.0', ''));
        if ($workBoundaryAxis !== '') {
            $workEnvPreferenceKeys[] = sprintf('work_env.boundary.%s', $workBoundaryAxis);
        }

        $collaborationFitKeys = $this->remapStyleKeys($communicationStyleKeys, 'collaboration_fit');
        foreach ((array) data_get($sceneFingerprint, 'decision.boundary_axes', []) as $axisCode) {
            $axisCode = trim((string) $axisCode);
            if ($axisCode === '') {
                continue;
            }

            $collaborationFitKeys[] = sprintf('collaboration_fit.decision_boundary.%s', $axisCode);
        }

        $careerNextStepKeys = $this->remapStyleKeys($decisionStyleKeys, 'career_next_step');
        $careerNextStepKeys[] = sprintf(
            'career_next_step.theme.%s',
            $this->resolveCareerNextStepTheme($sceneFingerprint, $sectionVariants)
        );
        if ($identity !== '') {
            $careerNextStepKeys[] = sprintf('career_next_step.identity.%s', $identity);
        }

        return [
            'work_style_summary' => trim((string) data_get($sceneFingerprint, 'work.summary', '')),
            'role_fit_keys' => array_values(array_unique(array_filter($roleFitKeys))),
            'collaboration_fit_keys' => array_values(array_unique(array_filter($collaborationFitKeys))),
            'work_env_preference_keys' => array_values(array_unique(array_filter($workEnvPreferenceKeys))),
            'career_next_step_keys' => array_values(array_unique(array_filter($careerNextStepKeys))),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, array<string, mixed>>  $sectionVariants
     * @param  array<string, mixed>  $explainabilityAuthority
     * @param  array<string, mixed>  $doc
     * @return array{
     *   action_plan_summary:string,
     *   weekly_action_keys:list<string>,
     *   relationship_action_keys:list<string>,
     *   work_experiment_keys:list<string>,
     *   watchout_keys:list<string>
     * }
     */
    private function buildActionAuthority(
        string $identity,
        array $sceneFingerprint,
        array $sectionVariants,
        array $explainabilityAuthority,
        array $doc,
        string $locale
    ): array {
        $growthActionKeys = $this->remapStyleKeys((array) data_get($sceneFingerprint, 'growth.style_keys', []), 'weekly_action');
        $relationshipActionKeys = $this->remapStyleKeys((array) data_get($sceneFingerprint, 'communication.style_keys', []), 'relationship_action');
        $workExperimentKeys = $this->remapStyleKeys((array) data_get($sceneFingerprint, 'work.style_keys', []), 'work_experiment');
        $careerNextStepKeys = $this->remapStyleKeys((array) data_get($sceneFingerprint, 'decision.style_keys', []), 'career_next_step');
        $watchoutKeys = $this->remapStyleKeys((array) data_get($sceneFingerprint, 'stress_recovery.style_keys', []), 'watchout');
        $actionTheme = $this->resolveActionTheme($sceneFingerprint, $sectionVariants, $explainabilityAuthority);
        $careerNextStepTheme = $this->resolveCareerNextStepTheme($sceneFingerprint, $sectionVariants);
        $stabilityBucket = trim((string) ($explainabilityAuthority['stability_bucket'] ?? 'mixed'));

        $growthActionKeys[] = sprintf('weekly_action.theme.%s', $actionTheme);
        $relationshipActionKeys[] = sprintf('relationship_action.theme.%s', $actionTheme);
        $workExperimentKeys[] = sprintf('work_experiment.theme.%s', $actionTheme);
        $careerNextStepKeys[] = sprintf('career_next_step.theme.%s', $careerNextStepTheme);
        $watchoutKeys[] = sprintf('watchout.stability.%s', $stabilityBucket !== '' ? $stabilityBucket : 'mixed');

        if ($identity !== '') {
            $growthActionKeys[] = sprintf('weekly_action.identity.%s', $identity);
            $relationshipActionKeys[] = sprintf('relationship_action.identity.%s', $identity);
            $workExperimentKeys[] = sprintf('work_experiment.identity.%s', $identity);
            $careerNextStepKeys[] = sprintf('career_next_step.identity.%s', $identity);
            $watchoutKeys[] = sprintf('watchout.identity.%s', $identity);
        }

        foreach ((array) data_get($sceneFingerprint, 'communication.boundary_axes', []) as $axisCode) {
            $axisCode = trim((string) $axisCode);
            if ($axisCode !== '') {
                $relationshipActionKeys[] = sprintf('relationship_action.boundary.%s', $axisCode);
            }
        }

        foreach ((array) data_get($sceneFingerprint, 'work.boundary_axes', []) as $axisCode) {
            $axisCode = trim((string) $axisCode);
            if ($axisCode !== '') {
                $workExperimentKeys[] = sprintf('work_experiment.boundary.%s', $axisCode);
            }
        }

        foreach ((array) ($explainabilityAuthority['close_call_axes'] ?? []) as $axis) {
            if (! is_array($axis)) {
                continue;
            }

            $axisCode = trim((string) ($axis['axis'] ?? ''));
            if ($axisCode !== '') {
                $watchoutKeys[] = sprintf('watchout.close_call.%s', $axisCode);
            }
        }

        return [
            'action_plan_summary' => $this->buildActionPlanSummary($sceneFingerprint, $stabilityBucket, $doc, $locale),
            'action_theme' => $actionTheme,
            'stability_bucket' => $stabilityBucket !== '' ? $stabilityBucket : 'mixed',
            'close_call_axes' => array_values((array) data_get($explainabilityAuthority, 'close_call_axes', [])),
            'weekly_action_keys' => array_values(array_unique(array_filter($growthActionKeys))),
            'relationship_action_keys' => array_values(array_unique(array_filter($relationshipActionKeys))),
            'work_experiment_keys' => array_values(array_unique(array_filter($workExperimentKeys))),
            'career_next_step_keys' => array_values(array_unique(array_filter($careerNextStepKeys))),
            'watchout_keys' => array_values(array_unique(array_filter($watchoutKeys))),
        ];
    }

    /**
     * @param  list<string>  $styleKeys
     * @return list<string>
     */
    private function remapStyleKeys(array $styleKeys, string $prefix): array
    {
        $keys = [];

        foreach ($styleKeys as $styleKey) {
            $normalized = trim((string) $styleKey);
            if ($normalized === '') {
                continue;
            }

            $parts = explode('.', $normalized);
            array_shift($parts);
            $keys[] = implode('.', array_filter(array_merge([$prefix], $parts)));
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function resolveRoleCluster(string $typeCode): string
    {
        $baseType = strtoupper((string) preg_replace('/-(A|T)$/', '', trim($typeCode)));
        if (strlen($baseType) < 4) {
            return '';
        }

        $cluster = substr($baseType, 1, 2);

        return in_array($cluster, ['SJ', 'SP', 'NF', 'NT'], true) ? $cluster : '';
    }

    /**
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, array<string, mixed>>  $sectionVariants
     */
    private function resolveCareerNextStepTheme(array $sceneFingerprint, array $sectionVariants): string
    {
        $focusAxis = trim((string) (
            data_get($sectionVariants, 'career.next_step.boundary_axes.0')
            ?? data_get($sceneFingerprint, 'decision.boundary_axes.0')
            ?? data_get($sceneFingerprint, 'work.boundary_axes.0')
            ?? ''
        ));

        return match ($focusAxis) {
            'JP' => 'make_rhythm_visible',
            'TF' => 'clarify_decision_criteria',
            'EI' => 'protect_energy_lanes',
            'SN' => 'turn_insight_into_evidence',
            default => 'repeat_high_fit_experiment',
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, array<string, mixed>>  $sectionVariants
     * @param  array<string, mixed>  $explainabilityAuthority
     */
    private function resolveActionTheme(
        array $sceneFingerprint,
        array $sectionVariants,
        array $explainabilityAuthority
    ): string {
        $focusAxis = trim((string) (
            data_get($sectionVariants, 'growth.stability_confidence.boundary_axes.0')
            ?? data_get($sectionVariants, 'career.next_step.boundary_axes.0')
            ?? data_get($sceneFingerprint, 'growth.boundary_axes.0')
            ?? data_get($sceneFingerprint, 'communication.boundary_axes.0')
            ?? data_get($sceneFingerprint, 'work.boundary_axes.0')
            ?? data_get($explainabilityAuthority, 'close_call_axes.0.axis')
            ?? ''
        ));

        return match ($focusAxis) {
            'JP' => 'make_rhythm_visible',
            'TF' => 'name_decision_rule',
            'EI' => 'protect_energy_lane',
            'SN' => 'turn_pattern_into_proof',
            default => 'repeat_high_fit_experiment',
        };
    }

    /**
     * @param  array<string, mixed>  $primaryAxis
     * @param  array<string, mixed>|null  $supportAxis
     */
    private function resolveActionSupportKind(
        string $sectionKey,
        string $actionTheme,
        string $stabilityBucket,
        array $primaryAxis,
        string $boundaryAxis,
        ?array $supportAxis,
        string $identity,
        string $intentCluster
    ): string {
        $axisCode = strtoupper(trim((string) ($primaryAxis['axis'] ?? '')));
        $band = strtolower(trim((string) ($primaryAxis['band'] ?? '')));
        $theme = trim($actionTheme);
        $supportAxisCode = strtoupper(trim((string) ($supportAxis['axis'] ?? '')));

        return match ($sectionKey) {
            'growth.next_actions' => match (true) {
                $intentCluster === 'career_move' => 'action_bridge_step',
                $intentCluster === 'clarify_type' => 'action_experiment',
                $theme === 'make_rhythm_visible' => 'action_momentum_start',
                $theme === 'clarify_decision_criteria' => 'action_bridge_step',
                $theme === 'protect_energy_lane' => 'action_low_pressure_step',
                $theme === 'turn_pattern_into_proof' => 'action_experiment',
                $stabilityBucket === 'context_sensitive' => 'action_reset',
                $boundaryAxis !== '' => 'action_low_pressure_step',
                default => 'action_resistance_break',
            },
            'growth.watchouts' => match (true) {
                $stabilityBucket === 'context_sensitive' || $band === 'boundary' => 'watchout_recovery_gap',
                $theme === 'protect_energy_lane' => 'watchout_energy_leak',
                $theme === 'name_decision_rule' || $identity === 'T' => 'watchout_overcontrol',
                $axisCode === 'EI' || $supportAxisCode === 'EI' => 'watchout_people_pleasing',
                default => 'watchout_overextension',
            },
            'career.next_step' => match (true) {
                $intentCluster === 'career_move' => 'work_scene_role_fit',
                $intentCluster === 'clarify_type' => 'work_scene_transition',
                $axisCode === 'JP' || $boundaryAxis === 'JP' => 'work_scene_pacing',
                $axisCode === 'TF' || $boundaryAxis === 'TF' => 'work_scene_role_fit',
                $axisCode === 'EI' || $supportAxisCode === 'EI' => 'work_scene_collaboration',
                $axisCode === 'SN' || $supportAxisCode === 'SN' => 'work_scene_execution',
                default => 'work_scene_transition',
            },
            'career.work_experiments' => match (true) {
                $intentCluster === 'career_move' => 'work_scene_team_friction',
                $intentCluster === 'clarify_type' => 'work_scene_pacing',
                $theme === 'make_rhythm_visible' => 'work_scene_execution',
                $theme === 'clarify_decision_criteria' => 'work_scene_pacing',
                $theme === 'protect_energy_lane' => 'work_scene_focus_recovery',
                $theme === 'turn_pattern_into_proof' => 'work_scene_team_friction',
                $boundaryAxis === 'JP' => 'work_scene_transition',
                default => 'work_scene_collaboration',
            },
            'relationships.try_this_week' => match (true) {
                $intentCluster === 'relationship_tuning' => 'relationship_boundary_negotiation',
                $theme === 'clarify_decision_criteria' || $axisCode === 'TF' || $boundaryAxis === 'TF' => 'relationship_boundary_negotiation',
                $theme === 'protect_energy_lane' || $axisCode === 'EI' => 'relationship_low_intensity_reconnect',
                $stabilityBucket === 'context_sensitive' => 'relationship_misread_repair',
                $stabilityBucket === 'mixed' => 'relationship_conflict_recovery',
                default => 'relationship_bridge',
            },
            default => 'revisit_resume',
        };
    }

    /**
     * @return list<string>
     */
    private function actionSupportKindsForSection(string $sectionKey, string $supportKind): array
    {
        return match ($sectionKey) {
            'growth.next_actions' => array_values(array_unique(array_filter([
                $supportKind,
                'action_experiment',
                'action_bridge_step',
                'action_momentum_start',
                'action_low_pressure_step',
                'action_reset',
                'action_resistance_break',
            ]))),
            'growth.watchouts' => array_values(array_unique(array_filter([
                $supportKind,
                'watchout_overextension',
                'watchout_people_pleasing',
                'watchout_overcontrol',
                'watchout_energy_leak',
                'watchout_avoidant_delay',
                'watchout_recovery_gap',
            ]))),
            'career.next_step' => array_values(array_unique(array_filter([
                $supportKind,
                'work_scene_role_fit',
                'work_scene_transition',
                'work_scene_execution',
                'work_scene_collaboration',
                'work_scene_pacing',
                'work_scene_promotion_readiness',
                'work_scene_team_friction',
                'work_scene_focus_recovery',
            ]))),
            'career.work_experiments' => array_values(array_unique(array_filter([
                $supportKind,
                'work_scene_execution',
                'work_scene_collaboration',
                'work_scene_pacing',
                'work_scene_role_fit',
                'work_scene_transition',
                'work_scene_promotion_readiness',
                'work_scene_team_friction',
                'work_scene_focus_recovery',
            ]))),
            'relationships.try_this_week' => array_values(array_unique(array_filter([
                $supportKind,
                'relationship_bridge',
                'relationship_boundary_negotiation',
                'relationship_misread_repair',
                'relationship_conflict_recovery',
                'relationship_try_this_week',
                'relationship_low_intensity_reconnect',
            ]))),
            default => [$supportKind],
        };
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $sceneFingerprint
     * @param  array<string, mixed>|null  $supportAxis
     */
    private function resolveActionSupportText(
        array $doc,
        string $sectionKey,
        string $supportKind,
        string $locale,
        array $sceneFingerprint,
        array $primaryAxis,
        ?array $supportAxis,
        string $identity,
        string $actionTheme,
        string $stabilityBucket,
        string $boundaryAxis,
        string $toneMode = ''
    ): string {
        $templateNode = $this->resolveToneTemplateNode($doc, $sectionKey, $supportKind, $toneMode);
        if ($templateNode === null) {
            $templateNode = data_get($doc, 'action_support_templates.'.$sectionKey.'.'.$supportKind);
        }
        if ($templateNode === null) {
            $templateNode = $doc['action_support_templates'][$sectionKey][$supportKind] ?? null;
        }

        $template = $this->resolveTemplate($templateNode, $locale, '');
        if ($template === '') {
            return '';
        }

        $primaryHint = $this->sceneSummaryHint($this->sceneHintText($doc, $locale, $primaryAxis), $locale);
        $supportHint = is_array($supportAxis)
            ? $this->sceneSummaryHint($this->sceneHintText($doc, $locale, $supportAxis), $locale)
            : $primaryHint;
        $actionHint = $this->actionThemeLabel($actionTheme, $locale);
        $identityClause = $this->shortIdentityClause($identity, $locale);
        $boundaryClause = '';

        return $this->renderTemplate($template, [
            'scene_side_hint' => $primaryHint,
            'support_hint' => $supportHint,
            'action_hint' => $actionHint,
            'work_hint' => $supportHint !== '' ? $supportHint : $primaryHint,
            'relationship_hint' => $supportHint !== '' ? $supportHint : $primaryHint,
            'recovery_hint' => $supportHint !== '' ? $supportHint : $primaryHint,
            'watchout_hint' => $supportHint !== '' ? $supportHint : $primaryHint,
            'identity_clause' => $identityClause,
            'boundary_clause' => $boundaryClause,
            'stability_bucket' => $stabilityBucket,
        ]);
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $doc
     */
    private function resolveToneAdjustedText(
        array $doc,
        string $locale,
        string $sectionKey,
        string $kind,
        string $toneMode,
        array $section,
        array $personalization
    ): string {
        $primaryAxis = is_array($section['primary_axis'] ?? null) ? $section['primary_axis'] : [];
        $supportAxis = is_array($section['support_axis'] ?? null) ? $section['support_axis'] : null;
        $closeCallAxes = array_values(array_filter((array) ($section['close_call_axes'] ?? []), 'is_array'));
        $neighborTypeKeys = $this->normalizeStringList($section['neighbor_type_keys'] ?? []);
        $boundaryAxes = $this->normalizeStringList($section['boundary_axes'] ?? []);
        $identity = trim((string) ($personalization['identity'] ?? ''));
        $stabilityBucket = $this->resolveToneStabilityBucket($personalization);
        $actionTheme = $this->extractThemeFromActionKey(trim((string) ($section['action_key'] ?? '')));
        $axisVector = is_array($personalization['axis_vector'] ?? null) ? $personalization['axis_vector'] : [];

        return match ($sectionKey) {
            'traits.why_this_type' => match ($kind) {
                'why_this_type' => $this->resolveWhyThisTypeText(
                    $doc,
                    $locale,
                    $primaryAxis,
                    $supportAxis,
                    $identity,
                    $closeCallAxes,
                    $toneMode
                ),
                'misunderstanding_fix' => $this->resolveMisunderstandingFixText(
                    $doc,
                    $locale,
                    $sectionKey,
                    (string) ($primaryAxis['band'] ?? 'clear'),
                    $primaryAxis,
                    $supportAxis,
                    $identity,
                    $closeCallAxes,
                    $neighborTypeKeys,
                    $toneMode
                ),
                default => '',
            },
            'traits.adjacent_type_contrast' => match ($kind) {
                'adjacent_type_contrast' => $this->resolveAdjacentTypeContrastText(
                    $doc,
                    $locale,
                    $primaryAxis,
                    $neighborTypeKeys,
                    $closeCallAxes,
                    $toneMode
                ),
                'misunderstanding_fix' => $this->resolveMisunderstandingFixText(
                    $doc,
                    $locale,
                    $sectionKey,
                    (string) ($primaryAxis['band'] ?? 'clear'),
                    $primaryAxis,
                    $supportAxis,
                    $identity,
                    $closeCallAxes,
                    $neighborTypeKeys,
                    $toneMode
                ),
                default => '',
            },
            'growth.stability_confidence' => match ($kind) {
                'stability_explanation' => $this->resolveStabilityExplanationText(
                    $doc,
                    $locale,
                    $stabilityBucket,
                    $closeCallAxes[0] ?? $primaryAxis,
                    $identity,
                    $toneMode
                ),
                'stability_reframe' => $this->resolveStabilityReframeText(
                    $doc,
                    $locale,
                    $stabilityBucket,
                    $closeCallAxes[0] ?? $primaryAxis,
                    $identity,
                    $toneMode
                ),
                'stress_recovery' => $this->resolveSceneText(
                    $doc,
                    'stress_recovery',
                    $locale,
                    $closeCallAxes[0] ?? $primaryAxis,
                    $sectionKey,
                    $toneMode
                ),
                default => '',
            },
            'growth.next_actions',
            'growth.watchouts',
            'career.next_step',
            'relationships.try_this_week' => match (true) {
                in_array($kind, ['next_action', 'watchout', 'career_next_step', 'relationship_practice'], true) => $this->resolveSceneText($doc, $kind, $locale, $primaryAxis, $sectionKey, $toneMode),
                true => $this->resolveActionSupportText(
                    $doc,
                    $sectionKey,
                    $kind,
                    $locale,
                    is_array($personalization['scene_fingerprint'] ?? null) ? $personalization['scene_fingerprint'] : [],
                    $primaryAxis,
                    is_array($supportAxis) ? $supportAxis : null,
                    $identity,
                    $actionTheme,
                    $stabilityBucket,
                    $boundaryAxes[0] ?? '',
                    $toneMode
                ),
            },
            default => '',
        };
    }

    private function extractThemeFromActionKey(string $actionKey): string
    {
        if ($actionKey === '') {
            return '';
        }

        if (preg_match('/\.theme\.([a-z0-9_]+)/i', $actionKey, $matches) === 1) {
            return strtolower(trim((string) ($matches[1] ?? '')));
        }

        return '';
    }

    private function actionThemeLabel(string $actionTheme, string $locale): string
    {
        return match ($actionTheme) {
            'make_rhythm_visible' => $locale === 'zh-CN' ? '先把节奏显性化' : 'make the rhythm visible first',
            'clarify_decision_criteria' => $locale === 'zh-CN' ? '先把判断标准说清楚' : 'clarify the decision criteria first',
            'protect_energy_lane' => $locale === 'zh-CN' ? '先守住能量边界' : 'protect the energy lane first',
            'turn_pattern_into_proof' => $locale === 'zh-CN' ? '先把模式转成证据' : 'turn the pattern into evidence first',
            'repeat_high_fit_experiment' => $locale === 'zh-CN' ? '先重复高匹配动作' : 'repeat the high-fit move first',
            default => $locale === 'zh-CN' ? '先做一个更小的动作' : 'start with a smaller move',
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @return list<array<string, mixed>>
     */
    private function resolveExplainabilityAxes(array $axisVector, string $locale): array
    {
        $axes = [];

        foreach (self::AXIS_ORDER as $axisCode) {
            if (! is_array($axisVector[$axisCode] ?? null)) {
                continue;
            }

            $axis = $axisVector[$axisCode];
            $side = trim((string) ($axis['side'] ?? ''));
            $oppositeSide = $this->oppositeSide($axisCode, $side);
            $axes[] = [
                ...$axis,
                'opposite_side' => $oppositeSide,
                'opposite_side_label' => $this->sideLabel($axisCode, $oppositeSide, $locale),
                'boundary' => $this->isExplainabilityCloseCall($axis),
            ];
        }

        usort($axes, static function (array $left, array $right): int {
            $leftBoundary = ($left['boundary'] ?? false) === true ? 0 : 1;
            $rightBoundary = ($right['boundary'] ?? false) === true ? 0 : 1;
            if ($leftBoundary !== $rightBoundary) {
                return $leftBoundary <=> $rightBoundary;
            }

            return ((int) ($left['delta'] ?? 0)) <=> ((int) ($right['delta'] ?? 0));
        });

        return array_values($axes);
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @return list<array<string, mixed>>
     */
    private function resolveCloseCallAxes(array $axisVector, string $locale): array
    {
        return array_slice(array_values(array_filter(
            $this->resolveExplainabilityAxes($axisVector, $locale),
            fn (array $axis): bool => $this->isExplainabilityCloseCall($axis),
        )), 0, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rankedAxes
     * @return list<string>
     */
    private function resolveNeighborTypeKeys(string $typeCode, array $rankedAxes): array
    {
        $baseType = strtoupper((string) preg_replace('/-(A|T)$/', '', trim($typeCode)));
        if (strlen($baseType) !== 4) {
            return [];
        }

        $axisIndexMap = ['EI' => 0, 'SN' => 1, 'TF' => 2, 'JP' => 3];
        $neighbors = [];
        $nonAtAxes = array_values(array_filter(
            $rankedAxes,
            static fn (array $axis): bool => trim((string) ($axis['axis'] ?? '')) !== 'AT'
        ));

        foreach ($nonAtAxes as $index => $axis) {
            $axisCode = trim((string) ($axis['axis'] ?? ''));
            if (! array_key_exists($axisCode, $axisIndexMap)) {
                continue;
            }

            if ($index > 0 && ! $this->isExplainabilityCloseCall($axis)) {
                continue;
            }

            $chars = str_split($baseType);
            $charIndex = $axisIndexMap[$axisCode];
            $current = $chars[$charIndex] ?? '';
            $flipped = $this->oppositeSide($axisCode, $current);
            if ($flipped === '') {
                continue;
            }

            $chars[$charIndex] = $flipped;
            $neighbors[] = implode('', $chars);

            if (count($neighbors) >= 2) {
                break;
            }
        }

        return array_values(array_unique(array_filter($neighbors)));
    }

    /**
     * @param  list<array<string, mixed>>  $closeCallAxes
     * @param  list<array<string, mixed>>  $dominantAxes
     */
    private function resolveStabilityBucket(
        array $reportPayload,
        array $axisVector,
        array $closeCallAxes,
        array $dominantAxes
    ): string {
        $clarity = strtolower(trim((string) (
            data_get($reportPayload, 'pci.overall.clarity')
            ?? data_get($reportPayload, 'result_json.pci.overall.clarity')
            ?? data_get($reportPayload, 'result_json.axis_scores_json.pci.overall.clarity')
            ?? ''
        )));

        if (in_array($clarity, ['high', 'very_high', 'stable'], true)) {
            return 'stable';
        }

        if (in_array($clarity, ['low', 'very_low', 'context_sensitive'], true)) {
            return 'context_sensitive';
        }

        $eligibleCloseCalls = array_values(array_filter(
            $closeCallAxes,
            fn (array $axis): bool => $this->isExplainabilityCloseCall($axis)
        ));

        if (count($eligibleCloseCalls) >= 2) {
            return 'context_sensitive';
        }

        $dominantAxis = is_array($dominantAxes[0] ?? null) ? $dominantAxes[0] : $this->resolveScenePrimaryAxis('overview', $axisVector);
        if (
            count($eligibleCloseCalls) === 0
            && is_array($dominantAxis)
            && in_array((string) ($dominantAxis['band'] ?? ''), ['strong', 'very_strong'], true)
        ) {
            return 'stable';
        }

        return 'mixed';
    }

    /**
     * @param  array<string, mixed>  $axis
     */
    private function isExplainabilityCloseCall(array $axis): bool
    {
        if ((bool) ($axis['boundary'] ?? false) === true) {
            return true;
        }

        if ((string) ($axis['band'] ?? '') === 'boundary') {
            return true;
        }

        return (int) ($axis['delta'] ?? 99) < 12;
    }

    /**
     * @param  list<array<string, mixed>>  $closeCallAxes
     */
    private function resolveWhyThisTypeText(
        array $doc,
        string $locale,
        array $primaryAxis,
        ?array $supportAxis,
        string $identity,
        array $closeCallAxes,
        string $toneMode = ''
    ): string {
        if ((int) ($primaryAxis['pct'] ?? -1) === 50) {
            return $locale === 'zh-CN'
                ? '本次核心轴持平时，代码只沿用预设归类规则；不能把该方向解释为稳定主导偏好。请比较两侧和相邻类型的具体例子。'
                : 'When the core axis is tied in this response set, the code follows the preset classification rule; it does not establish a stable dominant preference. Compare examples from both sides and neighboring types.';
        }

        $band = trim((string) ($primaryAxis['band'] ?? 'clear'));
        $template = $this->resolveTemplate(
            $this->resolveToneTemplateNode($doc, 'traits.why_this_type', 'why_this_type', $toneMode)
                ?? data_get($doc, 'why_this_type_templates.'.$band),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES['mixed'])
                : (self::DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES_EN['mixed'])
        );

        $closeAxis = is_array($closeCallAxes[0] ?? null) ? $closeCallAxes[0] : $supportAxis;

        return $this->renderTemplate($template, [
            'dominant_axis_label' => trim((string) ($primaryAxis['axis_label'] ?? '')),
            'dominant_side_label' => trim((string) ($primaryAxis['side_label'] ?? '')),
            'close_axis_label' => trim((string) ($closeAxis['axis_label'] ?? ($locale === 'zh-CN' ? '最接近边界的那条轴' : 'the closest-call axis'))),
            'identity_clause' => $this->shortIdentityClause($identity, $locale),
        ]);
    }

    private function resolveCloseCallAxisText(array $doc, string $locale, array $axis): string
    {
        if ((int) ($axis['pct'] ?? -1) === 50) {
            return $locale === 'zh-CN'
                ? '在'.trim((string) ($axis['axis_label'] ?? '')).'上，本次两侧持平。代码沿用预设归类规则，不证明偏好方向；请同时比较两侧的具体经历。'
                : 'On '.trim((string) ($axis['axis_label'] ?? '')).', the two directions are tied in this response set. The code follows the preset classification rule, not an established preference; compare examples from both sides.';
        }

        $template = $this->resolveTemplate(
            data_get($doc, 'close_call_axis_templates.default'),
            $locale,
            $locale === 'zh-CN' ? self::DEFAULT_CLOSE_CALL_AXIS_TEMPLATE : self::DEFAULT_CLOSE_CALL_AXIS_TEMPLATE_EN
        );

        return $this->renderTemplate($template, [
            'axis_label' => trim((string) ($axis['axis_label'] ?? '')),
            'side_label' => trim((string) ($axis['side_label'] ?? '')),
            'opposite_side_label' => trim((string) ($axis['opposite_side_label'] ?? '')),
            'delta' => (string) ($axis['delta'] ?? ''),
            'percent' => (string) ($axis['pct'] ?? ''),
        ]);
    }

    /**
     * @param  list<string>  $neighborTypeKeys
     * @param  list<array<string, mixed>>  $closeCallAxes
     */
    private function resolveAdjacentTypeContrastText(
        array $doc,
        string $locale,
        array $primaryAxis,
        array $neighborTypeKeys,
        array $closeCallAxes,
        string $toneMode = ''
    ): string {
        if ($closeCallAxes === []) {
            return $locale === 'zh-CN'
                ? '本次没有达到现有近分条件的轴。相邻标签仍可用于比较，但不能据此声称你接近中点或经常表现成另一类型。'
                : 'No axis meets the existing close-call condition in this response set. Neighboring labels can still support comparison, but this does not imply a midpoint score or that you often behave like another type.';
        }

        $template = $this->resolveTemplate(
            $this->resolveToneTemplateNode($doc, 'traits.adjacent_type_contrast', 'adjacent_type_contrast', $toneMode)
                ?? data_get($doc, 'adjacent_type_contrast_templates.default'),
            $locale,
            $locale === 'zh-CN' ? self::DEFAULT_ADJACENT_TYPE_CONTRAST_TEMPLATE : self::DEFAULT_ADJACENT_TYPE_CONTRAST_TEMPLATE_EN
        );

        $axis = is_array($closeCallAxes[0] ?? null) ? $closeCallAxes[0] : $primaryAxis;

        return $this->renderTemplate($template, [
            'neighbor_type' => trim((string) ($neighborTypeKeys[0] ?? ($locale === 'zh-CN' ? '相邻类型' : 'a nearby type'))),
            'axis_label' => trim((string) ($axis['axis_label'] ?? '')),
            'side_label' => trim((string) ($axis['side_label'] ?? '')),
            'opposite_side_label' => trim((string) ($axis['opposite_side_label'] ?? '')),
        ]);
    }

    /**
     * @param  list<string>  $neighborTypeKeys
     * @param  list<array<string, mixed>>  $closeCallAxes
     */
    private function resolveMisunderstandingFixText(
        array $doc,
        string $locale,
        string $sectionKey,
        string $band,
        array $primaryAxis,
        ?array $supportAxis,
        string $identity,
        array $closeCallAxes,
        array $neighborTypeKeys,
        string $toneMode = ''
    ): string {
        $templateKey = $sectionKey === 'traits.adjacent_type_contrast'
            ? 'adjacent_type_contrast'
            : 'why_this_type';

        $template = $this->resolveTemplate(
            $this->resolveToneTemplateNode($doc, $sectionKey, 'misunderstanding_fix', $toneMode)
                ?? data_get($doc, 'misunderstanding_fix_templates.'.$templateKey.'.'.trim($band)),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_MISUNDERSTANDING_FIX_TEMPLATES[$templateKey][$band] ?? self::DEFAULT_MISUNDERSTANDING_FIX_TEMPLATES[$templateKey]['clear'])
                : (self::DEFAULT_MISUNDERSTANDING_FIX_TEMPLATES_EN[$templateKey][$band] ?? self::DEFAULT_MISUNDERSTANDING_FIX_TEMPLATES_EN[$templateKey]['clear'])
        );

        $closeAxis = is_array($closeCallAxes[0] ?? null) ? $closeCallAxes[0] : $supportAxis;

        return $this->renderTemplate($template, [
            'dominant_axis_label' => trim((string) ($primaryAxis['axis_label'] ?? '')),
            'dominant_side_label' => trim((string) ($primaryAxis['side_label'] ?? '')),
            'axis_label' => trim((string) ($closeAxis['axis_label'] ?? ($primaryAxis['axis_label'] ?? ''))),
            'side_label' => trim((string) ($closeAxis['side_label'] ?? ($primaryAxis['side_label'] ?? ''))),
            'opposite_side_label' => trim((string) ($closeAxis['opposite_side_label'] ?? ($primaryAxis['opposite_side_label'] ?? ''))),
            'close_axis_label' => trim((string) ($closeAxis['axis_label'] ?? ($locale === 'zh-CN' ? '最接近边界的那条轴' : 'the closest-call axis'))),
            'neighbor_type' => trim((string) ($neighborTypeKeys[0] ?? ($locale === 'zh-CN' ? '相邻类型' : 'a nearby type'))),
            'identity_clause' => $this->shortIdentityClause($identity, $locale),
        ]);
    }

    private function resolveStabilityExplanationText(
        array $doc,
        string $locale,
        string $bucket,
        ?array $closeAxis,
        string $identity,
        string $toneMode = ''
    ): string {
        $template = $this->resolveTemplate(
            $this->resolveToneTemplateNode($doc, 'growth.stability_confidence', 'stability_explanation', $toneMode)
                ?? data_get($doc, 'stability_explanation_templates.'.$bucket),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_STABILITY_EXPLANATION_TEMPLATES[$bucket] ?? self::DEFAULT_STABILITY_EXPLANATION_TEMPLATES['mixed'])
                : (self::DEFAULT_STABILITY_EXPLANATION_TEMPLATES_EN[$bucket] ?? self::DEFAULT_STABILITY_EXPLANATION_TEMPLATES_EN['mixed'])
        );

        return $this->renderTemplate($template, [
            'close_axis_label' => trim((string) ($closeAxis['axis_label'] ?? ($locale === 'zh-CN' ? '最接近边界的那条轴' : 'the closest-call axis'))),
            'identity_clause' => $this->shortIdentityClause($identity, $locale),
        ]);
    }

    private function resolveStabilityReframeText(
        array $doc,
        string $locale,
        string $bucket,
        ?array $closeAxis,
        string $identity,
        string $toneMode = ''
    ): string {
        $template = $this->resolveTemplate(
            $this->resolveToneTemplateNode($doc, 'growth.stability_confidence', 'stability_reframe', $toneMode)
                ?? data_get($doc, 'stability_reframe_templates.'.$bucket),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_STABILITY_REFRAME_TEMPLATES[$bucket][$locale] ?? self::DEFAULT_STABILITY_REFRAME_TEMPLATES['mixed'][$locale])
                : (self::DEFAULT_STABILITY_REFRAME_TEMPLATES_EN[$bucket][$locale] ?? self::DEFAULT_STABILITY_REFRAME_TEMPLATES_EN['mixed'][$locale])
        );

        return $this->renderTemplate($template, [
            'close_axis_label' => trim((string) ($closeAxis['axis_label'] ?? ($locale === 'zh-CN' ? '最接近边界的那条轴' : 'the closest-call axis'))),
            'identity_clause' => $this->shortIdentityClause($identity, $locale),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, mixed>  $doc
     */
    private function buildActionPlanSummary(
        array $sceneFingerprint,
        string $stabilityBucket,
        array $doc,
        string $locale
    ): string {
        $template = $this->resolveTemplate(
            data_get($doc, 'action_plan_summary_templates.'.$stabilityBucket),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES[$stabilityBucket] ?? self::DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES['mixed'])
                : (self::DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES_EN[$stabilityBucket] ?? self::DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES_EN['mixed'])
        );

        $growthHint = $this->sceneSummaryHint(
            $this->sceneHintText($doc, $locale, (array) data_get($sceneFingerprint, 'growth.primary_axis', [])),
            $locale
        );
        $relationshipHint = $this->sceneSummaryHint(
            $this->sceneHintText($doc, $locale, (array) data_get($sceneFingerprint, 'communication.primary_axis', [])),
            $locale
        );
        $workHint = $this->sceneSummaryHint(
            $this->sceneHintText($doc, $locale, (array) data_get($sceneFingerprint, 'work.primary_axis', [])),
            $locale
        );

        return $this->renderTemplate($template, [
            'growth_hint' => $growthHint !== '' ? $growthHint : ($locale === 'zh-CN' ? '把成长动作拆小一点' : 'make growth moves smaller'),
            'relationship_hint' => $relationshipHint !== '' ? $relationshipHint : ($locale === 'zh-CN' ? '把沟通节奏说得更明白' : 'make your communication rhythm clearer'),
            'work_hint' => $workHint !== '' ? $workHint : ($locale === 'zh-CN' ? '先做一个可逆的小实验' : 'start with one reversible experiment'),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, mixed>  $actionAuthority
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function buildActionSectionVariant(
        string $sectionKey,
        array $axisVector,
        string $identity,
        array $sceneFingerprint,
        array $actionAuthority,
        array $doc,
        string $locale,
        string $intentCluster
    ): array {
        $sceneKey = self::SECTION_SCENE_MAP[$sectionKey] ?? 'growth';
        $primaryAxis = is_array(data_get($sceneFingerprint, $sceneKey.'.primary_axis'))
            ? data_get($sceneFingerprint, $sceneKey.'.primary_axis')
            : $this->resolveScenePrimaryAxis($sceneKey, $axisVector);

        if (! is_array($primaryAxis)) {
            return [
                'variant_key' => $sectionKey.':unresolved',
                'style_key' => '',
                'scene_key' => $sceneKey,
                'primary_axis' => null,
                'support_axis' => null,
                'boundary_axes' => [],
                'action_key' => '',
                'contrast_key' => '',
                'close_call_axes' => [],
                'neighbor_type_keys' => [],
                'selected_blocks' => [],
                'blocks' => [],
            ];
        }

        $supportAxis = is_array(data_get($sceneFingerprint, $sceneKey.'.support_axis'))
            ? data_get($sceneFingerprint, $sceneKey.'.support_axis')
            : $this->resolveSceneSupportAxis($sceneKey, $axisVector, (string) ($primaryAxis['axis'] ?? ''));
        $boundaryAxes = array_values((array) data_get($sceneFingerprint, $sceneKey.'.boundary_axes', []));
        $boundaryAxis = $boundaryAxes[0] ?? null;
        $axisCode = (string) ($primaryAxis['axis'] ?? 'EI');
        $side = (string) ($primaryAxis['side'] ?? 'E');
        $band = (string) ($primaryAxis['band'] ?? 'clear');
        $styleKey = (string) data_get($sceneFingerprint, $sceneKey.'.style_key', '');
        $templateGroup = $this->templateGroupForSection($sectionKey, $sceneKey);
        $sceneTemplateKey = $this->sceneTemplateKeyForSection($sectionKey, $sceneKey);
        $sceneBlockKind = $this->sceneBlockKind($sceneKey, $sectionKey);
        $actionKey = $this->resolveActionKeyForSection($sectionKey, $actionAuthority);
        $actionTheme = $this->extractThemeFromActionKey($actionKey);
        $stabilityBucket = trim((string) ($actionAuthority['stability_bucket'] ?? 'mixed'));
        $supportKind = $this->resolveActionSupportKind(
            $sectionKey,
            $actionTheme,
            $stabilityBucket,
            $primaryAxis,
            is_string($boundaryAxis) ? $boundaryAxis : '',
            $supportAxis,
            $identity,
            $intentCluster
        );

        $blocks = [];
        $selectedBlocks = [];

        $axisStrengthText = $this->resolveAxisStrengthText($doc, $templateGroup, $band, $locale, $primaryAxis);
        if ($axisStrengthText !== '') {
            $blockId = sprintf('%s.axis_strength.%s.%s.%s', $sectionKey, $axisCode, $side, $band);
            $selectedBlocks[] = $blockId;
            $blocks[] = [
                'id' => $blockId,
                'kind' => 'axis_strength',
                'label' => $this->blockLabel('axis_strength', $doc, $locale),
                'text' => $axisStrengthText,
            ];
        }

        $sceneText = $this->resolveSceneText($doc, $sceneTemplateKey, $locale, $primaryAxis);
        if ($sceneText !== '') {
            $blockId = sprintf('%s.%s.%s.%s', $sectionKey, $sceneBlockKind, $axisCode, $side);
            $selectedBlocks[] = $blockId;
            $blocks[] = [
                'id' => $blockId,
                'kind' => $sceneBlockKind,
                'label' => $this->blockLabel($sceneBlockKind, $doc, $locale),
                'text' => $sceneText,
            ];
        }

        if ($identity !== '') {
            $identityText = $this->resolveIdentityText($doc, $identity, $locale);
            if ($identityText !== '') {
                $blockId = sprintf('%s.identity.%s', $sectionKey, strtolower($identity));
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'identity',
                    'label' => $this->blockLabel('identity', $doc, $locale),
                    'text' => $identityText,
                ];
            }
        }

        if (is_string($boundaryAxis) && $boundaryAxis !== '') {
            $boundaryText = $this->resolveBoundaryNarrativeText(
                $doc,
                $sceneKey,
                $boundaryAxis,
                $locale,
                $axisVector,
                $primaryAxis
            );
            if ($boundaryText !== '') {
                $blockId = sprintf('%s.boundary.%s', $sectionKey, $boundaryAxis);
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'boundary',
                    'label' => $this->blockLabel('boundary', $doc, $locale),
                    'text' => $boundaryText,
                ];
            }
        }

        foreach ($this->actionSupportKindsForSection($sectionKey, $supportKind) as $supportKindCandidate) {
            $supportText = $this->resolveActionSupportText(
                $doc,
                $sectionKey,
                $supportKindCandidate,
                $locale,
                $sceneFingerprint,
                $primaryAxis,
                $supportAxis,
                $identity,
                $actionTheme,
                $stabilityBucket,
                is_string($boundaryAxis) ? $boundaryAxis : ''
            );
            if ($supportText === '') {
                continue;
            }

            $blockId = sprintf(
                '%s.%s.%s.%s.%s',
                $sectionKey,
                $supportKindCandidate,
                $axisCode,
                $side,
                $this->normalizeActionKeyForVariant($actionTheme !== '' ? $actionTheme : $supportKindCandidate)
            );
            $selectedBlocks[] = $blockId;
            $blocks[] = [
                'id' => $blockId,
                'kind' => $supportKindCandidate,
                'label' => $this->blockLabel($supportKindCandidate, $doc, $locale),
                'text' => $supportText,
            ];
        }

        return [
            'variant_key' => implode(':', [
                $sectionKey,
                sprintf('%s.%s.%s', $axisCode, $side, $band),
                $identity !== '' ? sprintf('identity.%s', $identity) : 'identity.none',
                $actionKey !== '' ? sprintf('action.%s', $this->normalizeActionKeyForVariant($actionKey)) : 'action.none',
                is_string($boundaryAxis) && $boundaryAxis !== '' ? sprintf('boundary.%s', $boundaryAxis) : 'boundary.none',
            ]),
            'style_key' => $styleKey,
            'scene_key' => $sceneKey,
            'primary_axis' => $primaryAxis,
            'support_axis' => $supportAxis,
            'boundary_axes' => $boundaryAxes,
            'action_key' => $actionKey,
            'contrast_key' => '',
            'close_call_axes' => [],
            'neighbor_type_keys' => [],
            'selected_blocks' => $selectedBlocks,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $actionAuthority
     */
    private function resolveActionKeyForSection(string $sectionKey, array $actionAuthority): string
    {
        $keys = match ($sectionKey) {
            'growth.next_actions', 'growth.weekly_experiments' => (array) ($actionAuthority['weekly_action_keys'] ?? []),
            'career.next_step' => (array) ($actionAuthority['career_next_step_keys'] ?? []),
            'relationships.try_this_week' => (array) ($actionAuthority['relationship_action_keys'] ?? []),
            'career.work_experiments' => (array) ($actionAuthority['work_experiment_keys'] ?? []),
            'growth.watchouts' => (array) ($actionAuthority['watchout_keys'] ?? []),
            default => [],
        };

        foreach ($keys as $key) {
            $normalized = trim((string) $key);
            if (str_contains($normalized, '.theme.') || str_contains($normalized, '.stability.')) {
                return $normalized;
            }
        }

        return trim((string) ($keys[0] ?? ''));
    }

    private function normalizeActionKeyForVariant(string $actionKey): string
    {
        $normalized = strtolower(trim($actionKey));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @return array<string, mixed>|null
     */
    private function resolveScenePrimaryAxis(string $sceneKey, array $axisVector): ?array
    {
        foreach (self::SCENE_AXIS_PRIORITY[$sceneKey] ?? self::DOMINANT_AXIS_ORDER as $axisCode) {
            if (is_array($axisVector[$axisCode] ?? null)) {
                return $axisVector[$axisCode];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @return array<string, mixed>|null
     */
    private function resolveSceneSupportAxis(string $sceneKey, array $axisVector, string $primaryAxisCode): ?array
    {
        foreach (self::SCENE_AXIS_PRIORITY[$sceneKey] ?? self::DOMINANT_AXIS_ORDER as $axisCode) {
            if ($axisCode === $primaryAxisCode) {
                continue;
            }

            if (is_array($axisVector[$axisCode] ?? null)) {
                return $axisVector[$axisCode];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $axisVector
     * @return list<string>
     */
    private function resolveSceneBoundaryAxes(string $sceneKey, array $axisVector): array
    {
        $ordered = [];
        $priority = self::SCENE_AXIS_PRIORITY[$sceneKey] ?? self::DOMINANT_AXIS_ORDER;

        foreach ($priority as $axisCode) {
            if ((string) data_get($axisVector, $axisCode.'.band') !== 'boundary') {
                continue;
            }

            $ordered[] = $axisCode;
        }

        return array_values(array_unique($ordered));
    }

    /**
     * @param  array<string, mixed>|null  $primaryAxis
     * @param  array<string, mixed>|null  $supportAxis
     * @param  list<string>  $boundaryAxes
     * @return list<string>
     */
    private function buildSceneStyleKeys(
        string $sceneKey,
        ?array $primaryAxis,
        ?array $supportAxis,
        string $identity,
        array $boundaryAxes
    ): array {
        $keys = [];

        if (is_array($primaryAxis)) {
            $keys[] = sprintf(
                '%s.primary.%s.%s.%s',
                $sceneKey,
                (string) ($primaryAxis['axis'] ?? ''),
                (string) ($primaryAxis['side'] ?? ''),
                (string) ($primaryAxis['band'] ?? 'clear')
            );
        }

        if (is_array($supportAxis)) {
            $keys[] = sprintf(
                '%s.support.%s.%s.%s',
                $sceneKey,
                (string) ($supportAxis['axis'] ?? ''),
                (string) ($supportAxis['side'] ?? ''),
                (string) ($supportAxis['band'] ?? 'clear')
            );
        }

        if ($identity !== '') {
            $keys[] = sprintf('%s.identity.%s', $sceneKey, $identity);
        }

        foreach ($boundaryAxes as $axisCode) {
            $keys[] = sprintf('%s.boundary.%s', $sceneKey, $axisCode);
        }

        return array_values(array_filter($keys));
    }

    private function templateGroupForSection(string $sectionKey, string $sceneKey): string
    {
        return match ($sceneKey) {
            'work' => 'work',
            'relationships' => 'relationships',
            'growth' => 'growth',
            'decision' => 'decision',
            'stress_recovery' => 'stress_recovery',
            'communication' => 'communication',
            default => $sectionKey === 'trait_overview' ? 'overview' : 'overview',
        };
    }

    private function sceneTemplateKeyForSection(string $sectionKey, string $sceneKey): string
    {
        return match ($sectionKey) {
            'career.summary' => 'work_style',
            'career.preferred_roles' => 'role_fit',
            'career.collaboration_fit' => 'collaboration_fit',
            'career.work_environment' => 'work_env',
            'career.work_experiments' => 'work_experiment',
            'career.next_step' => 'career_next_step',
            'growth.next_actions' => 'next_action',
            'growth.weekly_experiments' => 'weekly_experiment',
            'relationships.try_this_week' => 'relationship_practice',
            'growth.watchouts' => 'watchout',
            default => $this->templateGroupForSection($sectionKey, $sceneKey),
        };
    }

    private function sceneBlockKind(string $sceneKey, string $sectionKey): string
    {
        return match ($sectionKey) {
            'career.summary' => 'work_style',
            'career.preferred_roles' => 'role_fit',
            'career.collaboration_fit' => 'collaboration_fit',
            'career.work_environment' => 'work_env',
            'career.work_experiments' => 'work_experiment',
            'career.next_step' => 'career_next_step',
            'growth.next_actions' => 'next_action',
            'growth.weekly_experiments' => 'weekly_experiment',
            'relationships.try_this_week' => 'relationship_practice',
            'growth.watchouts' => 'watchout',
            default => match ($sceneKey) {
                'decision' => 'decision',
                'stress_recovery' => 'stress_recovery',
                'communication' => 'communication',
                default => 'scene',
            },
        };
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function sceneTitle(string $sceneKey, array $doc, string $locale): string
    {
        return $this->resolveTemplate(
            data_get($doc, 'scene_titles.'.$sceneKey),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_SCENE_TITLES[$sceneKey] ?? $sceneKey)
                : (self::DEFAULT_SCENE_TITLES_EN[$sceneKey] ?? $sceneKey)
        );
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $primaryAxis
     * @param  array<string, mixed>|null  $supportAxis
     * @param  list<string>  $boundaryAxes
     */
    private function resolveSceneFingerprintText(
        array $doc,
        string $sceneKey,
        string $locale,
        array $axisVector,
        array $primaryAxis,
        ?array $supportAxis,
        string $identity,
        array $boundaryAxes
    ): string {
        $template = $this->resolveTemplate(
            data_get($doc, 'scene_fingerprint_templates.'.$sceneKey),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_SCENE_FINGERPRINT_TEMPLATES[$sceneKey] ?? '')
                : (self::DEFAULT_SCENE_FINGERPRINT_TEMPLATES_EN[$sceneKey] ?? '')
        );

        $primaryHint = $this->sceneSummaryHint($this->sceneHintText($doc, $locale, $primaryAxis), $locale);
        $supportHint = is_array($supportAxis)
            ? $this->sceneSummaryHint($this->sceneHintText($doc, $locale, $supportAxis), $locale)
            : ($locale === 'zh-CN' ? '另一侧提供的校正' : 'an opposite-side correction');
        $identityClause = $this->shortIdentityClause($identity, $locale);
        $boundaryClause = '';

        $boundaryAxis = $boundaryAxes[0] ?? null;
        if (is_string($boundaryAxis) && $boundaryAxis !== '') {
            $boundaryClause = $this->resolveBoundaryNarrativeText(
                $doc,
                $sceneKey,
                $boundaryAxis,
                $locale,
                $axisVector,
                $primaryAxis,
                true
            );
        }

        return $this->renderTemplate($template, [
            'primary_hint' => $primaryHint,
            'support_hint' => $supportHint,
            'identity_clause' => $identityClause,
            'boundary_clause' => $boundaryClause,
        ]);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, array<string, mixed>>  $axisVector
     * @param  array<string, mixed>  $primaryAxis
     */
    private function resolveBoundaryNarrativeText(
        array $doc,
        string $sceneKey,
        string $axisCode,
        string $locale,
        array $axisVector,
        array $primaryAxis,
        bool $compact = false
    ): string {
        $template = $this->resolveTemplate(
            data_get($doc, 'boundary_narrative_templates.'.$sceneKey),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_BOUNDARY_NARRATIVE_TEMPLATES[$sceneKey] ?? '')
                : (self::DEFAULT_BOUNDARY_NARRATIVE_TEMPLATES_EN[$sceneKey] ?? '')
        );

        $boundaryAxis = is_array($axisVector[$axisCode] ?? null)
            ? $axisVector[$axisCode]
            : $primaryAxis;
        $side = (string) ($boundaryAxis['side'] ?? '');
        $opposite = $this->oppositeSide($axisCode, $side);

        $text = $this->renderTemplate($template, [
            'axis_label' => $this->axisLabel($axisCode, $locale),
            'side_label' => $this->sideLabel($axisCode, $side, $locale),
            'opposite_side_label' => $this->sideLabel($axisCode, $opposite, $locale),
        ]);

        if (! $compact) {
            return $text;
        }

        $sentences = preg_split('/(?<=[。.!?])\s+/u', $text) ?: [$text];

        return trim((string) ($sentences[0] ?? $text));
    }

    /**
     * @param  array<string, array<string, mixed>>  $sectionMeta
     * @return array<string, mixed>|null
     */
    private function resolveProjectionSectionMeta(string $sectionKey, array $sectionMeta): ?array
    {
        if (is_array($sectionMeta[$sectionKey] ?? null)) {
            return $sectionMeta[$sectionKey];
        }

        $groupKey = strtolower(trim((string) strtok($sectionKey, '.')));
        if ($groupKey !== '' && is_array($sectionMeta[$groupKey] ?? null)) {
            return $sectionMeta[$groupKey];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $axis
     */
    private function sceneHintText(array $doc, string $locale, array $axis): string
    {
        return $this->resolveTemplate(
            data_get($doc, 'scene_hints.'.($axis['axis'] ?? '').'.'.($axis['side'] ?? '')),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_SCENE_HINTS[(string) ($axis['axis'] ?? '').':'.(string) ($axis['side'] ?? '')] ?? '')
                : (self::DEFAULT_SCENE_HINTS_EN[(string) ($axis['axis'] ?? '').':'.(string) ($axis['side'] ?? '')] ?? '')
        );
    }

    private function shortIdentityClause(string $identity, string $locale): string
    {
        if ($identity === 'A') {
            return $locale === 'zh-CN'
                ? 'A是FermatMind附加扩展中的一个方向。请核对自己面对评价和反馈的具体经历，不由此推断恢复速度、抗压或工作能力。'
                : 'A is one direction in FermatMind’s additional extension. Check it against actual experiences of evaluation and feedback; it does not establish recovery speed, coping ability or work ability.';
        }

        if ($identity === 'T') {
            return $locale === 'zh-CN'
                ? 'T是FermatMind附加扩展中的一个方向。请核对自我质疑和反馈敏感的具体经历，不由此推断自我觉察、能力或健康水平。'
                : 'T is one direction in FermatMind’s additional extension. Check examples of self-questioning and feedback sensitivity; it does not establish self-awareness, ability or health.';
        }

        return '';
    }

    private function sceneSummaryHint(string $hint, string $locale): string
    {
        $normalized = trim($hint);
        if ($normalized === '') {
            return '';
        }

        if ($locale === 'zh-CN') {
            $normalized = preg_replace('/^你更容易先/u', '', $normalized) ?? $normalized;
            $normalized = preg_replace('/^你通常会先/u', '', $normalized) ?? $normalized;

            return trim($normalized);
        }

        $normalized = preg_replace('/^you usually /i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^you first /i', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function oppositeSide(string $axisCode, string $side): string
    {
        return match ($axisCode.':'.strtoupper($side)) {
            'EI:E' => 'I',
            'EI:I' => 'E',
            'SN:S' => 'N',
            'SN:N' => 'S',
            'TF:T' => 'F',
            'TF:F' => 'T',
            'JP:J' => 'P',
            'JP:P' => 'J',
            'AT:A' => 'T',
            'AT:T' => 'A',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $axis
     */
    private function resolveAxisStrengthText(array $doc, string $sectionKey, string $band, string $locale, array $axis): string
    {
        if ((int) ($axis['pct'] ?? -1) === 50) {
            return $locale === 'zh-CN'
                ? '本次两侧持平，未形成清晰方向。代码沿用预设归类规则，不代表稳定偏好。'
                : 'The two directions are tied in this response set. The code follows the preset classification rule rather than an established stable preference.';
        }

        $fallbackTemplate = $locale === 'zh-CN'
            ? (self::DEFAULT_AXIS_STRENGTH_TEMPLATES["{$sectionKey}.{$band}"] ?? '')
            : (self::DEFAULT_AXIS_STRENGTH_TEMPLATES_EN["{$sectionKey}.{$band}"] ?? '');

        $template = $this->resolveTemplate(
            data_get($doc, "axis_strength_templates.{$sectionKey}.{$band}"),
            $locale,
            $fallbackTemplate
        );

        return $this->renderTemplate($template, [
            'axis_label' => (string) ($axis['axis_label'] ?? ''),
            'side_label' => (string) ($axis['side_label'] ?? ''),
            'percent' => (string) ($axis['pct'] ?? ''),
            'delta' => (string) ($axis['delta'] ?? ''),
            'band_label' => $this->bandLabel((string) ($axis['band'] ?? $band), $doc, $locale),
        ]);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $axis
     */
    private function resolveSceneText(
        array $doc,
        string $sectionKey,
        string $locale,
        array $axis,
        string $toneSectionKey = '',
        string $toneMode = ''
    ): string {
        $sceneHint = $this->resolveTemplate(
            data_get($doc, 'scene_hints.'.($axis['axis'] ?? '').'.'.($axis['side'] ?? '')),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_SCENE_HINTS[(string) ($axis['axis'] ?? '').':'.(string) ($axis['side'] ?? '')] ?? '')
                : (self::DEFAULT_SCENE_HINTS_EN[(string) ($axis['axis'] ?? '').':'.(string) ($axis['side'] ?? '')] ?? '')
        );

        $template = $this->resolveTemplate(
            ($toneSectionKey !== '' ? $this->resolveToneTemplateNode($doc, $toneSectionKey, $sectionKey, $toneMode) : null)
                ?? data_get($doc, 'scene_templates.'.$sectionKey),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_SCENE_TEMPLATES[$sectionKey] ?? '')
                : (self::DEFAULT_SCENE_TEMPLATES_EN[$sectionKey] ?? '')
        );

        return $this->renderTemplate($template, [
            'axis_label' => (string) ($axis['axis_label'] ?? ''),
            'side_label' => (string) ($axis['side_label'] ?? ''),
            'scene_side_hint' => $sceneHint,
        ]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function resolveBoundaryText(array $doc, string $axisCode, string $locale): string
    {
        return $this->resolveTemplate(
            data_get($doc, 'boundary_templates.'.$axisCode),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_BOUNDARY_TEMPLATES[$axisCode] ?? '')
                : (self::DEFAULT_BOUNDARY_TEMPLATES_EN[$axisCode] ?? '')
        );
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function resolveIdentityText(array $doc, string $identity, string $locale): string
    {
        return $this->resolveTemplate(
            data_get($doc, 'identity_templates.'.$identity),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_IDENTITY_TEMPLATES[$identity] ?? '')
                : (self::DEFAULT_IDENTITY_TEMPLATES_EN[$identity] ?? '')
        );
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $personalization
     */
    private function blockLabel(string $kind, array $doc, string $locale): string
    {
        return $this->resolveTemplate(
            data_get($doc, 'labels.block_kinds.'.$kind),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_BLOCK_LABELS[$kind] ?? $kind)
                : (self::DEFAULT_BLOCK_LABELS_EN[$kind] ?? $kind)
        );
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function blockLabelForLocale(string $kind, array $personalization): string
    {
        $locale = $this->normalizeLocale((string) data_get($personalization, 'locale', 'zh-CN'));

        return $locale === 'zh-CN'
            ? (self::DEFAULT_BLOCK_LABELS[$kind] ?? $kind)
            : (self::DEFAULT_BLOCK_LABELS_EN[$kind] ?? $kind);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function bandLabel(string $band, array $doc, string $locale): string
    {
        return $this->resolveTemplate(
            data_get($doc, 'labels.band.'.$band),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_BAND_LABELS[$band] ?? $band)
                : (self::DEFAULT_BAND_LABELS_EN[$band] ?? $band)
        );
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function resolveToneTemplateNode(array $doc, string $sectionKey, string $blockKind, string $toneMode): mixed
    {
        $normalizedTone = strtolower(trim($toneMode));
        if ($normalizedTone === '') {
            return null;
        }

        $toneTemplates = is_array($doc['tone_templates'] ?? null) ? $doc['tone_templates'] : [];
        $sectionNode = is_array($toneTemplates[$sectionKey] ?? null) ? $toneTemplates[$sectionKey] : [];
        $toneNode = is_array($sectionNode[$normalizedTone] ?? null) ? $sectionNode[$normalizedTone] : [];

        return $toneNode[$blockKind] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function loadDynamicSectionsDoc(array $context, string $locale): array
    {
        $packId = trim((string) ($context['pack_id'] ?? ''));
        $dirVersion = trim((string) ($context['dir_version'] ?? ''));
        $requestedLocale = $this->normalizeLocale($locale);

        if ($packId !== '' && $dirVersion !== '') {
            $found = $this->packsIndex->find($packId, $dirVersion);
            if (($found['ok'] ?? false) === true) {
                $item = is_array($found['item'] ?? null) ? $found['item'] : [];
                $manifestPath = trim((string) ($item['manifest_path'] ?? ''));
                if ($manifestPath !== '' && is_file($manifestPath)) {
                    $manifest = json_decode((string) file_get_contents($manifestPath), true);
                    $manifestLocale = $this->normalizeLocale((string) ($item['locale'] ?? (is_array($manifest) ? ($manifest['locale'] ?? '') : '')));
                    if ($manifestLocale !== '' && $requestedLocale !== '' && $manifestLocale !== $requestedLocale) {
                        return [];
                    }
                    $baseDir = dirname($manifestPath);
                    $doc = $this->loadDynamicDocFromBaseDir($baseDir, is_array($manifest) ? $manifest : []);
                    if ($doc !== []) {
                        return $doc;
                    }
                }
            }
        }

        return $locale === 'zh-CN'
            ? []
            : [];
    }

    private function loadCommercialSpecDoc(array $context, string $locale): array
    {
        $packId = trim((string) ($context['pack_id'] ?? ''));
        $dirVersion = trim((string) ($context['dir_version'] ?? ''));
        $requestedLocale = $this->normalizeLocale($locale);

        if ($packId !== '' && $dirVersion !== '') {
            $found = $this->packsIndex->find($packId, $dirVersion);
            if (($found['ok'] ?? false) === true) {
                $item = is_array($found['item'] ?? null) ? $found['item'] : [];
                $manifestPath = trim((string) ($item['manifest_path'] ?? ''));
                if ($manifestPath !== '' && is_file($manifestPath)) {
                    $manifest = json_decode((string) file_get_contents($manifestPath), true);
                    $manifestLocale = $this->normalizeLocale((string) ($item['locale'] ?? (is_array($manifest) ? ($manifest['locale'] ?? '') : '')));
                    if ($manifestLocale !== '' && $requestedLocale !== '' && $manifestLocale !== $requestedLocale) {
                        return [];
                    }

                    $baseDir = dirname($manifestPath);
                    $path = $baseDir.DIRECTORY_SEPARATOR.'commercial_spec.json';
                    if (is_file($path)) {
                        $json = json_decode((string) file_get_contents($path), true);
                        if (is_array($json)) {
                            return $json;
                        }
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function loadDynamicDocFromBaseDir(string $baseDir, array $manifest): array
    {
        $candidatePaths = [];
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
        $dynamicAssets = $assets['dynamic_sections'] ?? null;

        if (is_array($dynamicAssets)) {
            foreach ($dynamicAssets as $path) {
                if (! is_string($path) || trim($path) === '') {
                    continue;
                }

                $candidatePaths[] = $baseDir.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
            }
        }

        $candidatePaths[] = $baseDir.DIRECTORY_SEPARATOR.'report_dynamic_sections.json';

        foreach (array_values(array_unique($candidatePaths)) as $path) {
            if (! is_file($path)) {
                continue;
            }

            $json = json_decode((string) file_get_contents($path), true);
            if (is_array($json)) {
                return $json;
            }
        }

        return [];
    }

    private function resolveTemplate(mixed $value, string $locale, string $fallback): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            $exact = trim((string) ($value[$locale] ?? ''));
            if ($exact !== '') {
                return $exact;
            }

            $short = strtolower(strtok($locale, '-'));
            $shortValue = trim((string) ($value[$short] ?? ''));
            if ($shortValue !== '') {
                return $shortValue;
            }
        }

        return trim($fallback);
    }

    /**
     * @param  array<string, string>  $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        $rendered = $template;

        foreach ($context as $key => $value) {
            $rendered = str_replace('{{'.$key.'}}', trim((string) $value), $rendered);
        }

        return trim(preg_replace('/\s+/', ' ', $rendered) ?? $rendered);
    }

    private function axisLabel(string $axisCode, string $locale): string
    {
        $node = self::AXIS_COPY[$axisCode]['label'] ?? null;
        if (! is_array($node)) {
            return $axisCode;
        }

        return $node[$locale] ?? $node['en'] ?? $axisCode;
    }

    private function sideLabel(string $axisCode, string $side, string $locale): string
    {
        $node = self::AXIS_COPY[$axisCode]['sides'] ?? null;
        if (! is_array($node)) {
            return $side;
        }

        if ($locale === 'zh-CN') {
            return $node[$side] ?? $side;
        }

        return $node[$side.':en'] ?? $side;
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = str_replace('_', '-', trim($locale));
        if ($normalized === '') {
            return 'zh-CN';
        }

        return strtolower($normalized) === 'zh-cn' ? 'zh-CN' : 'en';
    }
}
