<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Services\AI\ControlledGenerationRuntime;
use App\Services\AI\ControlledNarrativeLayerService;
use App\Services\Comparative\VersionedComparativeNormingLayerService;
use App\Services\Content\ContentPacksIndex;
use App\Services\Content\CulturalCalibrationLayerService;

final class MbtiResultPersonalizationService
{
    /**
     * @var list<string>
     */
    private const AXIS_ORDER = ['EI', 'SN', 'TF', 'JP', 'AT'];

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
        'decision' => '决策场景',
        'stress_recovery' => '压力恢复场景',
        'communication' => '沟通协作场景',
        'why_this_type' => '为什么是这个类型',
        'borderline_axis' => '边界轴解释',
        'adjacent_type_contrast' => '相邻类型对照',
        'stability_explanation' => '稳定性解释',
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
        'decision' => 'Decision scene',
        'stress_recovery' => 'Stress recovery scene',
        'communication' => 'Communication scene',
        'why_this_type' => 'Why-this-type explanation',
        'borderline_axis' => 'Borderline-axis explanation',
        'adjacent_type_contrast' => 'Adjacent-type contrast',
        'stability_explanation' => 'Stability explanation',
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
        'overview.boundary' => '在{{axis_label}}上，你现在更接近均衡区间。{{side_label}}仍然是主方向，但另一侧会在不同场景里频繁参与，所以这不是“绝对单向”的结果。',
        'overview.clear' => '在{{axis_label}}上，你已经呈现出稳定的{{side_label}}倾向；它会解释你多数第一反应，但不会压扁另一侧的可用性。',
        'overview.strong' => '在{{axis_label}}上，你的{{side_label}}偏好已经很鲜明。你通常不会先停在中间，而会自然把注意力和行动拉向这一侧。',
        'overview.very_strong' => '在{{axis_label}}上，你呈现出非常鲜明的{{side_label}}偏好。这会让你的风格高度一致，也让别人更容易快速感受到你的主导方式。',
        'growth.boundary' => '成长上，最有效的动作不是把自己推向极端，而是学会识别什么时候该让另一侧补位。',
        'growth.clear' => '成长上，你更适合先放大这条已经清晰的{{side_label}}优势，再为它补一条低成本的对侧校正动作。',
        'growth.strong' => '成长上，你不缺方向感，缺的是校正机制。因为{{side_label}}已经很强，真正有价值的是给它加上稳定的对侧检查点。',
        'growth.very_strong' => '成长上，你最需要防的不是“不够像自己”，而是把{{side_label}}一路推到底。越强的偏好，越需要可重复的反向校正。',
        'relationships.boundary' => '在人际里，这条轴接近边界，意味着你不会一直用同一种方式靠近别人；不同关系会唤起你不同侧的表达。',
        'relationships.clear' => '在人际里，{{side_label}}已经是你更常见的默认方式。别人感受到你的节奏时，通常会先接收到这一侧。',
        'relationships.strong' => '在人际里，{{side_label}}已经很鲜明。它会带来明显优势，也会让误读更容易围绕这一侧发生。',
        'relationships.very_strong' => '在人际里，你的{{side_label}}风格非常稳定。好处是边界清楚、识别度高，代价是别人更容易把你的主导方式当作全部的你。',
        'career.boundary' => '在工作里，这条轴更像弹性档位而不是固定齿轮；不同任务会把你拉向不同侧，因此环境匹配比职位名称更重要。',
        'career.clear' => '在工作里，{{side_label}}已经是你较稳定的默认操作方式。它会影响你更顺手的工作节奏、协作方式和反馈偏好。',
        'career.strong' => '在工作里，{{side_label}}已经很鲜明。你通常不是“什么环境都行”，而是会在某类节奏里明显更快进入高质量输出。',
        'career.very_strong' => '在工作里，你的{{side_label}}偏好非常强。这会让你在适配环境里效率极高，但也会放大与不匹配环境之间的摩擦感。',
        'work.boundary' => '在工作场景里，这条轴靠近中线，意味着你会根据任务、人和节奏切换挡位；真正关键的是你何时切换，以及团队是否读得懂这种切换。',
        'work.clear' => '在工作场景里，{{side_label}}已经是你较稳定的默认工作方式。它会影响你启动任务、协作和接收反馈的第一反应。',
        'work.strong' => '在工作场景里，{{side_label}}已经很鲜明。匹配环境会放大你的效率，不匹配环境也会更快放大摩擦。',
        'work.very_strong' => '在工作场景里，你的{{side_label}}风格极其稳定。优势是输出速度和风格识别度都很高，代价是环境不合拍时不适感也会非常明显。',
        'decision.boundary' => '做决定时，这条轴靠近中线，所以你不是单一路径地下判断，而是会在两套入口之间切换。',
        'decision.clear' => '做决定时，{{side_label}}已经是你更常见的默认入口，但另一侧仍然会在复核和收尾阶段发挥作用。',
        'decision.strong' => '做决定时，{{side_label}}已经很鲜明。你往往会先沿着这一侧推进，再决定是否需要另一侧补位。',
        'decision.very_strong' => '做决定时，你的{{side_label}}倾向非常强。这会让判断更快更稳，也会让别人更容易把你理解成“只会这样判断的人”。',
        'stress_recovery.boundary' => '在压力与恢复上，这条轴靠近中线，意味着你在过载时和恢复时可能会切到不同挡位。',
        'stress_recovery.clear' => '在压力与恢复上，{{side_label}}已经是你更稳定的应对入口，但恢复阶段通常还需要另一侧来重新平衡。',
        'stress_recovery.strong' => '在压力与恢复上，{{side_label}}已经很鲜明。你容易先用这一侧救火，所以更需要一条固定的恢复回路。',
        'stress_recovery.very_strong' => '在压力与恢复上，你的{{side_label}}风格非常强。优势是应对快，风险是过度依赖同一套自救方式。',
        'communication.boundary' => '在沟通里，这条轴靠近中线，所以你不是只会一种表达方式；你会根据对象、氛围和目标来切换。',
        'communication.clear' => '在沟通里，{{side_label}}已经是你更常见的起手方式，但当场景变化时，另一侧仍会迅速进场补位。',
        'communication.strong' => '在沟通里，{{side_label}}已经很鲜明。别人通常会先感受到这一侧，因此误读也往往从这里开始。',
        'communication.very_strong' => '在沟通里，你的{{side_label}}风格非常稳定。优势是辨识度高，风险是别人容易把你的表达方式误读成你的全部意图。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_AXIS_STRENGTH_TEMPLATES_EN = [
        'overview.boundary' => 'On {{axis_label}}, you currently sit close to the middle. {{side_label}} still leads, but the opposite side stays active in different situations, so this is not a single-direction reading.',
        'overview.clear' => 'On {{axis_label}}, you already show a stable {{side_label}} preference. It explains many first reactions without flattening the opposite side completely.',
        'overview.strong' => 'On {{axis_label}}, your {{side_label}} preference is already very visible. You usually do not pause in the middle; attention and action naturally move toward this side.',
        'overview.very_strong' => 'On {{axis_label}}, your {{side_label}} preference is extremely visible. That makes your style highly consistent and easy for others to notice quickly.',
        'growth.boundary' => 'For growth, the most useful move is not to force yourself toward an extreme, but to notice when the opposite side should come in as support.',
        'growth.clear' => 'For growth, you are better off building on this already-clear {{side_label}} strength and pairing it with one low-cost correction from the opposite side.',
        'growth.strong' => 'For growth, you do not lack direction; you need calibration. Because {{side_label}} is already strong, the real leverage comes from a repeatable opposite-side check.',
        'growth.very_strong' => 'For growth, the main risk is not being less yourself; it is running {{side_label}} too far without a stable correction loop.',
        'relationships.boundary' => 'In relationships, this axis sits near the boundary. You do not approach people in only one way, and different relationships can pull out different sides of you.',
        'relationships.clear' => 'In relationships, {{side_label}} is already your more common default. People usually feel this side first when they experience your rhythm.',
        'relationships.strong' => 'In relationships, {{side_label}} is already quite strong. It creates obvious strengths, but it also makes misunderstandings cluster around that same side.',
        'relationships.very_strong' => 'In relationships, your {{side_label}} style is extremely stable. The upside is clarity and recognizability; the downside is that others may mistake your dominant style for the whole of you.',
        'career.boundary' => 'At work, this axis behaves more like a flexible gear than a fixed setting. Different tasks can pull you toward different sides, so environment fit matters more than job labels.',
        'career.clear' => 'At work, {{side_label}} is already a stable operating mode. It shapes the pace, collaboration pattern, and feedback style that feel most natural to you.',
        'career.strong' => 'At work, {{side_label}} is already strong. You are not equally effective everywhere; some environments let you enter high-quality output much faster.',
        'career.very_strong' => 'At work, your {{side_label}} preference is very strong. That can make you exceptionally effective in a good-fit environment and noticeably strained in a bad-fit one.',
        'work.boundary' => 'At work, this axis sits close to the middle, which means you shift gears depending on task, people, and pace. The real question is when you switch and whether the team can read that switch.',
        'work.clear' => 'At work, {{side_label}} is already your more stable operating mode. It shapes how you start, collaborate, and take feedback.',
        'work.strong' => 'At work, {{side_label}} is already strong. Fit environments amplify your output, while poor-fit environments amplify friction faster.',
        'work.very_strong' => 'At work, your {{side_label}} style is extremely stable. The upside is speed and recognizability; the downside is that mismatch becomes much more obvious.',
        'decision.boundary' => 'In decisions, this axis sits close to the middle, so you do not judge through one path only. You switch between two entry points.',
        'decision.clear' => 'In decisions, {{side_label}} is already your more common first entry point, while the opposite side still comes in during review and closure.',
        'decision.strong' => 'In decisions, {{side_label}} is already strong. You often move forward through this side first, then decide whether the opposite side needs to step in.',
        'decision.very_strong' => 'In decisions, your {{side_label}} preference is very strong. That makes your judgment faster and more stable, but also easier for others to oversimplify.',
        'stress_recovery.boundary' => 'In stress and recovery, this axis sits close to the middle. Your overload mode and your recovery mode may not use the same gear.',
        'stress_recovery.clear' => 'In stress and recovery, {{side_label}} is already your steadier coping entry point, but recovery often still needs the opposite side to rebalance.',
        'stress_recovery.strong' => 'In stress and recovery, {{side_label}} is already strong. You naturally use this side to contain overload, so you need a deliberate reset loop.',
        'stress_recovery.very_strong' => 'In stress and recovery, your {{side_label}} style is very strong. The upside is fast coping; the downside is over-relying on the same self-protection move.',
        'communication.boundary' => 'In communication, this axis sits close to the middle, so you do not express yourself in only one way. You switch with audience, atmosphere, and goal.',
        'communication.clear' => 'In communication, {{side_label}} is already your more common opening move, but the opposite side still comes in quickly when the context changes.',
        'communication.strong' => 'In communication, {{side_label}} is already strong. People usually feel this side first, so misunderstandings often begin there too.',
        'communication.very_strong' => 'In communication, your {{side_label}} style is extremely stable. The upside is recognizability; the downside is that people may mistake the style for the whole intention.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TEMPLATES = [
        'overview' => '放到日常场景里，这条主轴通常会表现成：{{scene_side_hint}}。这会决定别人首先从哪里理解你。',
        'growth' => '把它放进成长情境时，更有效的做法不是否定这条主轴，而是让它在{{axis_label}}上多带一个反向校正动作：{{scene_side_hint}}。',
        'relationships' => '放到关系里，这条主轴通常会变成一种相处节奏：{{scene_side_hint}}。如果对方没有读懂这一点，就容易把你的方式误解成距离感、迟疑或控制感。',
        'career' => '放到工作里，这条主轴更像你的默认操作系统：{{scene_side_hint}}。它会直接影响你更适配的岗位节奏与协作环境。',
        'work' => '放到工作里，这条主轴更像你的默认操作系统：{{scene_side_hint}}。它会直接影响你更适配的岗位节奏与协作环境。',
        'decision' => '放到决策里，这条主轴会决定你先用哪一种入口缩小范围：{{scene_side_hint}}。',
        'stress_recovery' => '放到压力与恢复里，这条主轴通常会变成你最先启动的自救方式：{{scene_side_hint}}。',
        'communication' => '放到沟通里，这条主轴通常会变成你的起手表达方式：{{scene_side_hint}}。',
        'work_style' => '放到工作方式上，这条主轴会决定你默认怎么开工、怎么协作、怎么接收反馈：{{scene_side_hint}}。',
        'role_fit' => '放到岗位匹配上，这条主轴最容易让你在某类角色里快速进入状态：{{scene_side_hint}}。',
        'collaboration_fit' => '放到团队协作里，这条主轴会决定你更自然的对齐、配合与修正方式：{{scene_side_hint}}。',
        'work_env' => '放到工作环境里，这条主轴会决定你更需要哪类节奏、边界和反馈方式：{{scene_side_hint}}。',
        'career_next_step' => '放到职业下一步，这条主轴提示你先去试一个更贴近自己的动作：{{scene_side_hint}}。',
        'next_action' => '把它翻译成下一步动作，最值得先做的是：{{scene_side_hint}}。先让这个动作小到一周内能重复，而不是一次做成大的自我改造。',
        'weekly_experiment' => '放到这周可执行实验，这条主轴最适合变成一个低成本重复动作：{{scene_side_hint}}。目标不是证明你是谁，而是观察哪种做法让你更稳。',
        'relationship_practice' => '放到本周关系练习，这条主轴最适合变成一个可见的小动作：{{scene_side_hint}}。重点不是表现完美，而是让对方更容易读懂你的真实节奏。',
        'work_experiment' => '放到工作实验，这条主轴最适合先试一个可逆动作：{{scene_side_hint}}。先用小范围验证环境、协作或节奏是否更贴近你。',
        'watchout' => '放到风险提醒，这条主轴最容易在高压时把你推向一种默认反应：{{scene_side_hint}}。真正要防的不是做错一次，而是在没察觉的情况下反复复制同一种失衡。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TEMPLATES_EN = [
        'overview' => 'In everyday situations, this lead axis often shows up as {{scene_side_hint}}. That shapes where other people begin to understand you.',
        'growth' => 'In growth work, the best move is not to reject this axis but to add one opposite-side correction on {{axis_label}}: {{scene_side_hint}}.',
        'relationships' => 'In relationships, this axis often turns into a rhythm: {{scene_side_hint}}. If the other person misses that pattern, they may misread your style as distance, hesitation, or control.',
        'career' => 'At work, this axis behaves like your default operating system: {{scene_side_hint}}. It directly affects the pace and collaboration environment that fit you best.',
        'work' => 'At work, this axis behaves like your default operating system: {{scene_side_hint}}. It directly affects the pace and collaboration environment that fit you best.',
        'decision' => 'In decisions, this axis shapes which entry point you use first to narrow the field: {{scene_side_hint}}.',
        'stress_recovery' => 'In stress and recovery, this axis often becomes the first coping move you activate: {{scene_side_hint}}.',
        'communication' => 'In communication, this axis often becomes your opening expression style: {{scene_side_hint}}.',
        'work_style' => 'In work style, this axis shapes how you start, collaborate, and receive feedback by default: {{scene_side_hint}}.',
        'role_fit' => 'In role fit, this axis points to the kinds of roles where you enter high-quality work faster: {{scene_side_hint}}.',
        'collaboration_fit' => 'In collaboration, this axis shapes the alignment, pacing, and repair moves that feel most natural to you: {{scene_side_hint}}.',
        'work_env' => 'In work environment fit, this axis shapes the pace, boundaries, and feedback conditions you need most: {{scene_side_hint}}.',
        'career_next_step' => 'For your next career move, this axis suggests the first experiment worth trying: {{scene_side_hint}}.',
        'next_action' => 'Turn this into a next step by starting with one move that fits your pattern: {{scene_side_hint}}. Keep it small enough to repeat within a week instead of turning it into a full self-overhaul.',
        'weekly_experiment' => 'As a weekly experiment, this axis is best translated into one low-cost repeatable move: {{scene_side_hint}}. The goal is not to prove who you are, but to see which move makes you steadier.',
        'relationship_practice' => 'As a relationship practice for this week, this axis is best turned into one visible small move: {{scene_side_hint}}. The point is not perfect performance, but making your rhythm easier for the other person to read.',
        'work_experiment' => 'As a work experiment, this axis is best tested through one reversible move first: {{scene_side_hint}}. Use a small-scope trial to see whether the environment, collaboration, or pace fits you better.',
        'watchout' => 'As a watchout, this axis is most likely to push you into one default reaction under pressure: {{scene_side_hint}}. The real risk is not one bad move; it is repeating the same imbalance without noticing.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES = [
        'stable' => '接下来最值得做的，不是继续解释自己，而是把{{growth_hint}}、{{relationship_hint}}和{{work_hint}}分别变成一个小动作。因为你的结果整体较稳定，重点是把高匹配动作重复出来。',
        'mixed' => '接下来最值得做的，是把{{growth_hint}}、{{relationship_hint}}和{{work_hint}}变成低成本可重复的动作。你的主类型已清楚，但局部会随情境切换，所以动作要小、可见、可复盘。',
        'context_sensitive' => '接下来最值得做的，是用更小的动作去验证自己在不同情境里的切换。先从{{growth_hint}}、{{relationship_hint}}和{{work_hint}}各做一个一周内能重复的版本，不要一次想定终局。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_ACTION_PLAN_SUMMARY_TEMPLATES_EN = [
        'stable' => 'The next valuable move is not more self-explanation, but turning {{growth_hint}}, {{relationship_hint}}, and {{work_hint}} into small actions. Because your result is relatively stable, the goal is to repeat the moves that already fit.',
        'mixed' => 'The next valuable move is to turn {{growth_hint}}, {{relationship_hint}}, and {{work_hint}} into low-cost repeatable actions. Your core type is clear, but some parts still shift by context, so the move should stay small, visible, and reviewable.',
        'context_sensitive' => 'The next valuable move is to use smaller actions to test your switching pattern across contexts. Start by making one one-week version of {{growth_hint}}, {{relationship_hint}}, and {{work_hint}} instead of trying to decide the whole answer at once.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_FINGERPRINT_TEMPLATES = [
        'work' => '在工作里，你通常先用{{primary_hint}}开局，再用{{support_hint}}把节奏拉回可执行。{{identity_clause}} {{boundary_clause}}',
        'relationships' => '在关系里，你常先以{{primary_hint}}让别人感受到你，再通过{{support_hint}}决定要靠近、回应还是设边界。{{identity_clause}} {{boundary_clause}}',
        'growth' => '成长上，你的高杠杆点通常来自{{primary_hint}}；当你再补上{{support_hint}}时，进步会更稳定。{{identity_clause}} {{boundary_clause}}',
        'decision' => '做决定时，你通常先靠{{primary_hint}}缩小范围，再用{{support_hint}}确认是否值得推进。{{identity_clause}} {{boundary_clause}}',
        'stress_recovery' => '压力升高时，你容易先滑向{{primary_hint}}来求快或求稳；恢复阶段则更需要{{support_hint}}把你拉回可用区。{{identity_clause}} {{boundary_clause}}',
        'communication' => '沟通里，你通常先以{{primary_hint}}发起，再用{{support_hint}}修正对齐。{{identity_clause}} {{boundary_clause}}',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_FINGERPRINT_TEMPLATES_EN = [
        'work' => 'At work, you usually start through {{primary_hint}}, then use {{support_hint}} to bring the rhythm back into something executable. {{identity_clause}} {{boundary_clause}}',
        'relationships' => 'In relationships, people often feel you first through {{primary_hint}}, and then through {{support_hint}} you decide how to approach, respond, or set boundaries. {{identity_clause}} {{boundary_clause}}',
        'growth' => 'In growth work, your biggest leverage often comes from {{primary_hint}}; when you add {{support_hint}}, progress becomes more repeatable. {{identity_clause}} {{boundary_clause}}',
        'decision' => 'In decisions, you usually narrow the field through {{primary_hint}} first, then use {{support_hint}} to decide whether it is worth moving forward. {{identity_clause}} {{boundary_clause}}',
        'stress_recovery' => 'When pressure rises, you tend to slide toward {{primary_hint}} first to cope quickly or stay stable; recovery then needs {{support_hint}} to bring you back into a usable range. {{identity_clause}} {{boundary_clause}}',
        'communication' => 'In communication, you usually open through {{primary_hint}} and then use {{support_hint}} to recalibrate alignment. {{identity_clause}} {{boundary_clause}}',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BOUNDARY_NARRATIVE_TEMPLATES = [
        'overview' => '{{axis_label}}靠近中线时，你不是“有点像两边”，而是会在不同情境下切换入口。熟悉场景里你可能先用{{side_label}}，压力或高风险情境里又会迅速调动{{opposite_side_label}}。别人如果只看到其中一面，就容易把你误读成忽冷忽热、摇摆或前后不一。',
        'relationships' => '在人际里，{{axis_label}}靠近中线意味着你不会永远只走{{side_label}}这一条路。你可能先用{{side_label}}靠近，遇到压力或误解时又改用{{opposite_side_label}}保护自己。别人如果只看到其中一段，就会误判你到底是在拉近、保持距离，还是突然变得难以捉摸。',
        'growth' => '成长上，{{axis_label}}靠近中线意味着你真正要学的不是选边站，而是识别什么时候该让{{side_label}}先开路，什么时候该让{{opposite_side_label}}接手收尾。会切换并不可怕，不知道自己在什么时候切换才会让你觉得“我怎么又变了”。',
        'work' => '在工作里，{{axis_label}}靠近中线会让你在任务、协作和压力场景下切换齿轮。你有时像{{side_label}}，有时又会突然拉出{{opposite_side_label}}来修正，所以团队很容易只记住其中一面。真正有价值的是让别人知道：你在什么信号下会切换，以及切换后需要怎样的协作方式。',
        'decision' => '做决定时，{{axis_label}}靠近中线意味着你并不是摇摆不定，而是在两套判断入口之间来回校准。你可能先用{{side_label}}开路，再用{{opposite_side_label}}复核；场景一变，顺序也会反过来。别人会以为你前后矛盾，其实你是在同时守住速度与准确度、标准与关系，或结构与弹性。',
        'stress_recovery' => '压力上来时，{{axis_label}}靠近中线意味着你可能先滑向{{side_label}}来保住当下，再在恢复阶段调回{{opposite_side_label}}重新平衡。它的难点不是“你为什么不稳定”，而是如果你没意识到自己在切换，就会把这种来回误读成状态失控。',
        'communication' => '沟通里，{{axis_label}}靠近中线意味着你不会永远只用一种表达方式。你可能先用{{side_label}}出手，但一旦对方反馈变化，又迅速调动{{opposite_side_label}}补位。别人如果只看到前半段，常会误读你的真实意图；而你自己则会觉得“我明明已经说清楚了”。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BOUNDARY_NARRATIVE_TEMPLATES_EN = [
        'overview' => 'When {{axis_label}} sits near the middle, you are not simply “a bit of both.” You switch entry points across situations. In familiar settings you may lead with {{side_label}}, while pressure or high-stakes contexts quickly bring in {{opposite_side_label}}. If people only see one side, they can misread you as inconsistent or hard to pin down.',
        'relationships' => 'In relationships, a near-boundary {{axis_label}} means you do not move through connection in only one way. You may open with {{side_label}}, then shift into {{opposite_side_label}} when stress or misunderstanding rises. If someone only sees one segment of that pattern, they may misread whether you are moving closer, taking distance, or protecting yourself.',
        'growth' => 'For growth, a near-boundary {{axis_label}} means the real task is not picking one side forever. It is learning when {{side_label}} should open the move and when {{opposite_side_label}} should finish it. Switching is not the problem; not knowing when you switch is what creates the feeling that you are changing unpredictably.',
        'work' => 'At work, a near-boundary {{axis_label}} makes you shift gears across tasks, collaboration, and pressure. Sometimes you read as {{side_label}}, then suddenly pull in {{opposite_side_label}} to correct course. Teams often remember only one side. The useful move is to make the switch legible: what triggers it, and what collaboration pattern helps once it happens.',
        'decision' => 'In decisions, a near-boundary {{axis_label}} does not mean you are indecisive. It means you recalibrate between two judgment entry points. You may start with {{side_label}} and then use {{opposite_side_label}} to verify, or reverse that order when the context changes. People may call it inconsistency when it is really a dual-track calibration.',
        'stress_recovery' => 'Under stress, a near-boundary {{axis_label}} means you may slide toward {{side_label}} to contain the moment, then return through {{opposite_side_label}} during recovery. The hard part is not instability; it is failing to notice the shift and then misreading it as being out of control.',
        'communication' => 'In communication, a near-boundary {{axis_label}} means you do not express yourself in only one register. You may open with {{side_label}}, then rapidly bring in {{opposite_side_label}} as feedback changes. If people only catch the first half, they can misunderstand your intent; if you miss the shift yourself, it can feel like you already made yourself clear.',
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
        'EI' => 'E / I 这一轴离中线较近，所以你更像“会因场景切换能量入口的人”，而不是永远固定在单一社交档位。',
        'SN' => 'S / N 这一轴离中线较近，所以你既会看具体事实，也会快速跳到模式与意义；关键在于任务当下需要哪一种入口。',
        'TF' => 'T / F 这一轴离中线较近，所以你并不是单纯“理性”或“感性”，而是会在标准与关系之间反复校准。',
        'JP' => 'J / P 这一轴离中线较近，所以你既需要一定结构，也需要一定弹性；节奏感比绝对规则更重要。',
        'AT' => 'A / T 这一轴离中线较近，所以你的稳定感和敏感度都在参与结果，不适合把自己读成单一的“稳”或“紧”。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BOUNDARY_TEMPLATES_EN = [
        'EI' => 'Your E / I axis sits close to the middle, so you are better described as someone whose energy entry point changes with context than someone locked into one social mode.',
        'SN' => 'Your S / N axis sits close to the middle, so you can work from concrete facts and from patterns; the key is which entry point the task demands now.',
        'TF' => 'Your T / F axis sits close to the middle, so you are not purely rational or purely emotional; you keep recalibrating between standards and relationships.',
        'JP' => 'Your J / P axis sits close to the middle, so you need both structure and flexibility. Rhythm matters more than rigid rules.',
        'AT' => 'Your A / T axis sits close to the middle, so both steadiness and sensitivity are active in the result. It is not useful to read yourself as purely calm or purely tense.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_IDENTITY_TEMPLATES = [
        'A' => 'A 身份层会让你在当前类型骨架上更容易保持稳定推进、少被短期波动牵着走。',
        'T' => 'T 身份层会让你在当前类型骨架上更容易放大细节波动与结果质量，因此同一类型也会表现出更高的自我校准和压力感知。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_IDENTITY_TEMPLATES_EN = [
        'A' => 'The A identity layer makes this type skeleton feel steadier in execution and less reactive to short-term fluctuation.',
        'T' => 'The T identity layer makes this type skeleton more sensitive to quality shifts and detail-level variance, so the same type can feel more self-calibrating and pressure-aware.',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES = [
        'stable' => '你的结果主要由{{dominant_axis_label}}上的{{dominant_side_label}}偏好拉开差距，因此主类型较稳定。真正最接近边界的是{{close_axis_label}}，它会决定你在哪些场景下更像相邻类型。',
        'mixed' => '你的结果已经有清晰主轴，但仍保留几条会随场景切换的近边界轴。{{dominant_axis_label}}上的{{dominant_side_label}}解释了“你为什么是这个类型”，而{{close_axis_label}}解释了“你为什么又不像刻板印象里的那种类型”。',
        'context_sensitive' => '你的结果不是模糊，而是有几条非常接近边界的轴在一起工作。{{dominant_axis_label}}上的{{dominant_side_label}}仍然决定了主类型，但{{close_axis_label}}会显著影响你在不同情境里看起来像哪一类人。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_EXPLAINABILITY_SUMMARY_TEMPLATES_EN = [
        'stable' => 'Your result is mainly separated by a stronger {{dominant_side_label}} preference on {{dominant_axis_label}}, so the core type is comparatively stable. The closest call is {{close_axis_label}}, which explains where you can still resemble a nearby type.',
        'mixed' => 'Your result already has a clear structural axis, but a few near-boundary axes still change shape with context. {{dominant_axis_label}} and {{dominant_side_label}} explain why this type won, while {{close_axis_label}} explains why you do not always feel like the stereotype of it.',
        'context_sensitive' => 'Your result is not vague; it is context-sensitive because several axes sit close to the middle at once. {{dominant_axis_label}} and {{dominant_side_label}} still decide the main type, but {{close_axis_label}} strongly affects which nearby type you can resemble in different situations.',
    ];

    private const DEFAULT_CLOSE_CALL_AXIS_TEMPLATE = '在{{axis_label}}上，你最后仍偏向{{side_label}}，但和另一侧只拉开了{{delta}}个点差。也就是说，这条轴更像“近身拉扯”而不是绝对定型：熟悉情境下你常会先用{{side_label}}，而高压、误解或角色变化时，{{opposite_side_label}}会很快进场补位。';

    private const DEFAULT_CLOSE_CALL_AXIS_TEMPLATE_EN = 'On {{axis_label}}, you still end up leaning toward {{side_label}}, but the margin is only {{delta}} points. This axis behaves more like a live tension than a fixed identity: in familiar situations you may start with {{side_label}}, while pressure, misunderstanding, or role shifts can quickly pull in {{opposite_side_label}} as a correction.';

    private const DEFAULT_ADJACENT_TYPE_CONTRAST_TEMPLATE = '如果只看最接近边界的部分，别人最容易把你看成{{neighbor_type}}。原因不是你“测错了”，而是{{axis_label}}离中线太近，导致你在外显风格上经常会借用{{opposite_side_label}}那一面的节奏。真正区分你们的，不是表面像不像，而是你最终仍会回到{{side_label}}来做主判断和收尾。';

    private const DEFAULT_ADJACENT_TYPE_CONTRAST_TEMPLATE_EN = 'If someone only notices the closest-call part of your profile, they are most likely to read you as {{neighbor_type}}. That does not mean the result is wrong; it means {{axis_label}} sits close enough to the middle that your surface style often borrows the rhythm of {{opposite_side_label}}. The real difference is that your final judgment and closure still return to {{side_label}}.';

    /**
     * @var array<string, string>
     */
    private const DEFAULT_STABILITY_EXPLANATION_TEMPLATES = [
        'stable' => '这一份结果整体比较稳定。主类型不会因为普通情境波动就频繁改写，真正需要留意的，是在{{close_axis_label}}上什么时候需要主动给另一侧留一点空间。',
        'mixed' => '这一份结果整体属于“主类型明确，但局部会随场景切换”。你不是不稳定，而是有几条轴会根据任务、人际和压力负荷切换表达入口。',
        'context_sensitive' => '这一份结果最需要读成“情境敏感型稳定”，而不是简单稳定或简单摇摆。主类型仍然成立，但近边界轴太多时，你在不同场景里会更明显地表现出不同的切换模式。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_STABILITY_EXPLANATION_TEMPLATES_EN = [
        'stable' => 'This result is comparatively stable overall. The core type should not rewrite itself under ordinary context shifts; the real thing to watch is when {{close_axis_label}} needs deliberate room for the opposite side.',
        'mixed' => 'This result is best read as a clear type with local context shifts. You are not unstable; a few axes simply change entry points across task, relationship, and pressure conditions.',
        'context_sensitive' => 'This result is best read as context-sensitive stability rather than simple stability or simple wavering. The main type still holds, but several near-boundary axes make your switching pattern more visible across situations.',
    ];

    public function __construct(
        private readonly ContentPacksIndex $packsIndex,
        private readonly MbtiUserStateOrchestrationService $userStateOrchestrationService,
        private readonly MbtiActionJourneyContractService $actionJourneyContractService,
        private readonly MbtiBigFiveSynthesisService $bigFiveSynthesisService,
        private readonly MbtiWorkingLifeConsolidationService $workingLifeConsolidationService,
        private readonly MbtiPrivacyConsentContractService $privacyConsentContractService,
        private readonly VersionedComparativeNormingLayerService $comparativeNormingLayerService,
        private readonly ControlledGenerationRuntime $controlledGenerationRuntime,
        private readonly ControlledNarrativeLayerService $controlledNarrativeLayerService,
        private readonly CulturalCalibrationLayerService $culturalCalibrationLayerService,
        private readonly MbtiReadModelContractService $readModelContractService,
    ) {
    }

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
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function applyToProjection(array $projection, array $personalization): array
    {
        if ($personalization === []) {
            return $projection;
        }

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
                $payload['blocks'] = array_values(array_filter($blocks, static function (array $block): bool {
                    return trim((string) ($block['text'] ?? '')) !== '';
                }));
            }

            $payload['personalization'] = [
                'variant_key' => (string) ($dynamic['variant_key'] ?? ''),
                'selected_blocks' => array_values((array) ($dynamic['selected_blocks'] ?? [])),
                'primary_axis' => is_array($dynamic['primary_axis'] ?? null) ? $dynamic['primary_axis'] : null,
                'scene_key' => (string) ($dynamic['scene_key'] ?? ''),
                'style_key' => (string) ($dynamic['style_key'] ?? ''),
                'action_key' => (string) ($dynamic['action_key'] ?? ''),
                'contrast_key' => (string) ($dynamic['contrast_key'] ?? ''),
                'boundary_axes' => array_values((array) ($dynamic['boundary_axes'] ?? [])),
                'close_call_axes' => array_values((array) ($dynamic['close_call_axes'] ?? [])),
                'neighbor_type_keys' => array_values((array) ($dynamic['neighbor_type_keys'] ?? [])),
            ];

            $section['payload'] = $payload;
            $section['_meta'] = array_merge(
                is_array($section['_meta'] ?? null) ? $section['_meta'] : [],
                ['variant_key' => (string) ($dynamic['variant_key'] ?? '')]
            );
            $sections[$index] = $section;
        }

        $projection['sections'] = $sections;

        return $projection;
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
        if ($delta < 12) {
            return 'boundary';
        }

        if ($delta >= 40 || str_contains(strtolower($state), 'very')) {
            return 'very_strong';
        }

        if ($delta >= 25 || strtolower($state) === 'strong') {
            return 'strong';
        }

        return 'clear';
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
                'growth.watchouts',
            ], true)) {
                $sectionVariants[$sectionKey] = $this->buildActionSectionVariant(
                    $sectionKey,
                    $axisVector,
                    $identity,
                    $sceneFingerprint,
                    $actionAuthority,
                    $doc,
                    $locale
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
        $watchoutKeys = $this->remapStyleKeys((array) data_get($sceneFingerprint, 'stress_recovery.style_keys', []), 'watchout');
        $actionTheme = $this->resolveActionTheme($sceneFingerprint, $sectionVariants, $explainabilityAuthority);
        $stabilityBucket = trim((string) ($explainabilityAuthority['stability_bucket'] ?? 'mixed'));

        $growthActionKeys[] = sprintf('weekly_action.theme.%s', $actionTheme);
        $relationshipActionKeys[] = sprintf('relationship_action.theme.%s', $actionTheme);
        $workExperimentKeys[] = sprintf('work_experiment.theme.%s', $actionTheme);
        $watchoutKeys[] = sprintf('watchout.stability.%s', $stabilityBucket !== '' ? $stabilityBucket : 'mixed');

        if ($identity !== '') {
            $growthActionKeys[] = sprintf('weekly_action.identity.%s', $identity);
            $relationshipActionKeys[] = sprintf('relationship_action.identity.%s', $identity);
            $workExperimentKeys[] = sprintf('work_experiment.identity.%s', $identity);
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
            'weekly_action_keys' => array_values(array_unique(array_filter($growthActionKeys))),
            'relationship_action_keys' => array_values(array_unique(array_filter($relationshipActionKeys))),
            'work_experiment_keys' => array_values(array_unique(array_filter($workExperimentKeys))),
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
        return array_slice($this->resolveExplainabilityAxes($axisVector, $locale), 0, 2);
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
        array $closeCallAxes
    ): string {
        $band = trim((string) ($primaryAxis['band'] ?? 'clear'));
        $template = $this->resolveTemplate(
            data_get($doc, 'why_this_type_templates.'.$band),
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
        array $closeCallAxes
    ): string {
        $template = $this->resolveTemplate(
            data_get($doc, 'adjacent_type_contrast_templates.default'),
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

    private function resolveStabilityExplanationText(
        array $doc,
        string $locale,
        string $bucket,
        ?array $closeAxis,
        string $identity
    ): string {
        $template = $this->resolveTemplate(
            data_get($doc, 'stability_explanation_templates.'.$bucket),
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
        string $locale
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
                ? 'A 身份层让这个场景里的你更稳、更不容易被短期波动带偏。'
                : 'The A identity layer makes this scene feel steadier and less reactive to short-term fluctuation.';
        }

        if ($identity === 'T') {
            return $locale === 'zh-CN'
                ? 'T 身份层会放大你对反馈、细节和结果波动的感知。'
                : 'The T identity layer heightens your sensitivity to feedback, detail, and outcome variance.';
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
    private function resolveSceneText(array $doc, string $sectionKey, string $locale, array $axis): string
    {
        $sceneHint = $this->resolveTemplate(
            data_get($doc, 'scene_hints.'.($axis['axis'] ?? '').'.'.($axis['side'] ?? '')),
            $locale,
            $locale === 'zh-CN'
                ? (self::DEFAULT_SCENE_HINTS[(string) ($axis['axis'] ?? '').':'.(string) ($axis['side'] ?? '')] ?? '')
                : (self::DEFAULT_SCENE_HINTS_EN[(string) ($axis['axis'] ?? '').':'.(string) ($axis['side'] ?? '')] ?? '')
        );

        $template = $this->resolveTemplate(
            data_get($doc, 'scene_templates.'.$sectionKey),
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
