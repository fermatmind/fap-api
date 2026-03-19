<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Services\Content\ContentPacksIndex;

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
        'traits.decision_style',
        'career.summary',
        'career.advantages',
        'career.weaknesses',
        'career.preferred_roles',
        'career.upgrade_suggestions',
        'growth.summary',
        'growth.strengths',
        'growth.weaknesses',
        'growth.stress_recovery',
        'growth.motivators',
        'growth.drainers',
        'relationships.summary',
        'relationships.strengths',
        'relationships.weaknesses',
        'relationships.communication_style',
        'relationships.rel_advantages',
        'relationships.rel_risks',
    ];

    /**
     * @var array<string, string>
     */
    private const SECTION_SCENE_MAP = [
        'overview' => 'overview',
        'trait_overview' => 'overview',
        'traits.decision_style' => 'decision',
        'career.summary' => 'work',
        'career.advantages' => 'work',
        'career.weaknesses' => 'work',
        'career.preferred_roles' => 'work',
        'career.upgrade_suggestions' => 'work',
        'growth.summary' => 'growth',
        'growth.strengths' => 'growth',
        'growth.weaknesses' => 'growth',
        'growth.stress_recovery' => 'stress_recovery',
        'growth.motivators' => 'growth',
        'growth.drainers' => 'stress_recovery',
        'relationships.summary' => 'relationships',
        'relationships.strengths' => 'relationships',
        'relationships.weaknesses' => 'relationships',
        'relationships.communication_style' => 'communication',
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
        'decision' => '决策场景',
        'stress_recovery' => '压力恢复场景',
        'communication' => '沟通协作场景',
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
        'decision' => 'Decision scene',
        'stress_recovery' => 'Stress recovery scene',
        'communication' => 'Communication scene',
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

    public function __construct(
        private readonly ContentPacksIndex $packsIndex,
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
        $sectionVariants = $this->buildSectionVariants(
            $axisVector,
            $identity,
            $sceneFingerprint,
            $dynamicDoc,
            $locale
        );

        $variantKeys = [];
        foreach ($sectionVariants as $sectionKey => $variant) {
            $variantKeys[$sectionKey] = (string) ($variant['variant_key'] ?? '');
        }

        return [
            'schema_version' => 'mbti.personalization.phase4a.v1',
            'locale' => $locale,
            'type_code' => $typeCode,
            'identity' => $identity,
            'axis_vector' => $axisVector,
            'axis_bands' => $axisBands,
            'boundary_flags' => $boundaryFlags,
            'dominant_axes' => $dominantAxes,
            'scene_fingerprint' => $sceneFingerprint,
            'work_style_keys' => array_values((array) data_get($sceneFingerprint, 'work.style_keys', [])),
            'relationship_style_keys' => array_values((array) data_get($sceneFingerprint, 'relationships.style_keys', [])),
            'decision_style_keys' => array_values((array) data_get($sceneFingerprint, 'decision.style_keys', [])),
            'stress_recovery_keys' => array_values((array) data_get($sceneFingerprint, 'stress_recovery.style_keys', [])),
            'communication_style_keys' => array_values((array) data_get($sceneFingerprint, 'communication.style_keys', [])),
            'variant_keys' => $variantKeys,
            'sections' => $sectionVariants,
            'pack_id' => trim((string) ($context['pack_id'] ?? data_get($reportPayload, 'versions.content_pack_id', ''))),
            'engine_version' => trim((string) ($context['engine_version'] ?? data_get($reportPayload, 'versions.engine', ''))),
            'content_package_dir' => trim((string) ($context['dir_version'] ?? data_get($reportPayload, 'versions.dir_version', ''))),
            'dynamic_sections_version' => trim((string) ($dynamicDoc['version'] ?? '')),
        ];
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
                'boundary_axes' => array_values((array) ($dynamic['boundary_axes'] ?? [])),
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
     * @param  array<string, array<string, mixed>>  $sceneFingerprint
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, mixed>>
     */
    private function buildSectionVariants(
        array $axisVector,
        string $identity,
        array $sceneFingerprint,
        array $doc,
        string $locale
    ): array {
        $sectionVariants = [];

        foreach (self::TARGET_SECTIONS as $sectionKey) {
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

            $sceneText = $this->resolveSceneText($doc, $templateGroup, $locale, $primaryAxis);
            if ($sceneText !== '') {
                $sceneBlockKind = $this->sceneBlockKind($sceneKey);
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
                'selected_blocks' => $selectedBlocks,
                'blocks' => $blocks,
            ];
        }

        return $sectionVariants;
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

    private function sceneBlockKind(string $sceneKey): string
    {
        return match ($sceneKey) {
            'decision' => 'decision',
            'stress_recovery' => 'stress_recovery',
            'communication' => 'communication',
            default => 'scene',
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
