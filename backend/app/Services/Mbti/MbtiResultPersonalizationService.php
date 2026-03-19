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
    private const TARGET_SECTIONS = ['overview', 'growth', 'relationships', 'career'];

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
        'boundary' => '边界提示',
        'identity' => '身份层',
        'scene' => '场景应用',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_BLOCK_LABELS_EN = [
        'type_skeleton' => 'Type skeleton',
        'axis_strength' => 'Strength layer',
        'boundary' => 'Boundary note',
        'identity' => 'Identity layer',
        'scene' => 'Scene application',
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
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TEMPLATES = [
        'overview' => '放到日常场景里，这条主轴通常会表现成：{{scene_side_hint}}。这会决定别人首先从哪里理解你。',
        'growth' => '把它放进成长情境时，更有效的做法不是否定这条主轴，而是让它在{{axis_label}}上多带一个反向校正动作：{{scene_side_hint}}。',
        'relationships' => '放到关系里，这条主轴通常会变成一种相处节奏：{{scene_side_hint}}。如果对方没有读懂这一点，就容易把你的方式误解成距离感、迟疑或控制感。',
        'career' => '放到工作里，这条主轴更像你的默认操作系统：{{scene_side_hint}}。它会直接影响你更适配的岗位节奏与协作环境。',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCENE_TEMPLATES_EN = [
        'overview' => 'In everyday situations, this lead axis often shows up as {{scene_side_hint}}. That shapes where other people begin to understand you.',
        'growth' => 'In growth work, the best move is not to reject this axis but to add one opposite-side correction on {{axis_label}}: {{scene_side_hint}}.',
        'relationships' => 'In relationships, this axis often turns into a rhythm: {{scene_side_hint}}. If the other person misses that pattern, they may misread your style as distance, hesitation, or control.',
        'career' => 'At work, this axis behaves like your default operating system: {{scene_side_hint}}. It directly affects the pace and collaboration environment that fit you best.',
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
        $sectionVariants = $this->buildSectionVariants(
            $axisVector,
            $identity,
            $dominantAxes,
            $dynamicDoc,
            $locale
        );

        $variantKeys = [];
        foreach ($sectionVariants as $sectionKey => $variant) {
            $variantKeys[$sectionKey] = (string) ($variant['variant_key'] ?? '');
        }

        return [
            'schema_version' => 'mbti.personalization.phase1.v1',
            'locale' => $locale,
            'type_code' => $typeCode,
            'identity' => $identity,
            'axis_vector' => $axisVector,
            'axis_bands' => $axisBands,
            'boundary_flags' => $boundaryFlags,
            'dominant_axes' => $dominantAxes,
            'variant_keys' => $variantKeys,
            'sections' => $sectionVariants,
            'pack_id' => trim((string) ($context['pack_id'] ?? data_get($reportPayload, 'versions.content_pack_id', ''))),
            'engine_version' => trim((string) ($context['engine_version'] ?? data_get($reportPayload, 'versions.engine', ''))),
            'content_package_dir' => trim((string) ($context['dir_version'] ?? data_get($reportPayload, 'versions.dir_version', ''))),
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
            if ($sectionKey === '' || ! is_array($sectionMeta[$sectionKey] ?? null)) {
                continue;
            }

            $dynamic = $sectionMeta[$sectionKey];
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
     * @param  list<array<string, mixed>>  $dominantAxes
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, mixed>>
     */
    private function buildSectionVariants(
        array $axisVector,
        string $identity,
        array $dominantAxes,
        array $doc,
        string $locale
    ): array {
        $primaryAxis = is_array($dominantAxes[0] ?? null) ? $dominantAxes[0] : null;
        $boundaryAxes = array_values(array_map(
            static fn (string $axisCode): string => $axisCode,
            array_keys(array_filter($axisVector, static fn (array $node): bool => (string) ($node['band'] ?? '') === 'boundary'))
        ));

        $sectionVariants = [];

        foreach (self::TARGET_SECTIONS as $sectionKey) {
            if (! is_array($primaryAxis)) {
                continue;
            }

            $blocks = [];
            $selectedBlocks = [];
            $band = (string) ($primaryAxis['band'] ?? 'clear');
            $axisCode = (string) ($primaryAxis['axis'] ?? 'EI');
            $side = (string) ($primaryAxis['side'] ?? 'E');

            $axisStrengthText = $this->resolveAxisStrengthText($doc, $sectionKey, $band, $locale, $primaryAxis);
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

            $sceneText = $this->resolveSceneText($doc, $sectionKey, $locale, $primaryAxis);
            if ($sceneText !== '') {
                $blockId = sprintf('%s.scene.%s.%s', $sectionKey, $axisCode, $side);
                $selectedBlocks[] = $blockId;
                $blocks[] = [
                    'id' => $blockId,
                    'kind' => 'scene',
                    'label' => $this->blockLabel('scene', $doc, $locale),
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

            $boundaryAxis = $boundaryAxes[0] ?? null;
            if (is_string($boundaryAxis) && $boundaryAxis !== '') {
                $boundaryText = $this->resolveBoundaryText($doc, $boundaryAxis, $locale);
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
                'primary_axis' => $primaryAxis,
                'boundary_axes' => $boundaryAxes,
                'selected_blocks' => $selectedBlocks,
                'blocks' => $blocks,
            ];
        }

        return $sectionVariants;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $axis
     */
    private function resolveAxisStrengthText(array $doc, string $sectionKey, string $band, string $locale, array $axis): string
    {
        $template = $this->resolveTemplate(
            data_get($doc, "axis_strength_templates.{$sectionKey}.{$band}"),
            $locale,
            self::DEFAULT_AXIS_STRENGTH_TEMPLATES["{$sectionKey}.{$band}"] ?? ($locale === 'zh-CN' ? '' : (self::DEFAULT_AXIS_STRENGTH_TEMPLATES_EN["{$sectionKey}.{$band}"] ?? ''))
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

        if ($packId !== '' && $dirVersion !== '') {
            $found = $this->packsIndex->find($packId, $dirVersion);
            if (($found['ok'] ?? false) === true) {
                $item = is_array($found['item'] ?? null) ? $found['item'] : [];
                $manifestPath = trim((string) ($item['manifest_path'] ?? ''));
                if ($manifestPath !== '' && is_file($manifestPath)) {
                    $manifest = json_decode((string) file_get_contents($manifestPath), true);
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
