<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use InvalidArgumentException;

final class MbtiPersonalityVariantSectionStructureService
{
    /**
     * @return list<string>
     */
    public function requiredSectionKeys(): array
    {
        return [
            'letters_intro',
            'overview',
            'trait_overview',
            'relationships.summary',
            'career.summary',
            'career.preferred_roles',
            'growth.strengths',
            'growth.weaknesses',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedRuntimeTypeCodes(): array
    {
        $codes = [];

        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            $codes[] = $typeCode.'-A';
            $codes[] = $typeCode.'-T';
        }

        sort($codes);

        return $codes;
    }

    /**
     * @return array{
     *   section_key:string,
     *   render_variant:string,
     *   body_md:?string,
     *   body_html:null,
     *   payload_json:array<string,mixed>,
     *   sort_order:int,
     *   is_enabled:bool
     * }
     */
    public function build(string $runtimeTypeCode, string $locale, ?string $typeName, string $sectionKey): array
    {
        $runtimeTypeCode = strtoupper(trim($runtimeTypeCode));
        $locale = $this->normalizeLocale($locale);
        $typeName = $this->normalizeTypeName($typeName);

        if (! in_array($runtimeTypeCode, $this->supportedRuntimeTypeCodes(), true)) {
            throw new InvalidArgumentException('Unsupported MBTI runtime type code for personality section structure.');
        }

        if (! in_array($sectionKey, $this->requiredSectionKeys(), true)) {
            throw new InvalidArgumentException('Unsupported MBTI personality structure section key.');
        }

        $definition = MbtiCanonicalSectionRegistry::definition($sectionKey);
        $typeLabel = $this->typeLabel($runtimeTypeCode, $typeName);
        $body = $this->body($sectionKey, $runtimeTypeCode, $typeLabel, $locale);

        return [
            'section_key' => $sectionKey,
            'render_variant' => (string) $definition['render_variant'],
            'body_md' => $body,
            'body_html' => null,
            'payload_json' => $this->payload($sectionKey, $runtimeTypeCode, $typeLabel, $locale),
            'sort_order' => (int) $definition['sort_order'],
            'is_enabled' => true,
        ];
    }

    private function body(string $sectionKey, string $runtimeTypeCode, string $typeLabel, string $locale): ?string
    {
        $baseType = substr($runtimeTypeCode, 0, 4);
        $variant = substr($runtimeTypeCode, -1);

        if ($locale === 'zh-CN') {
            return match ($sectionKey) {
                'overview' => "{$typeLabel} 用来描述 {$baseType} 核心倾向与 {$variant} 型状态的组合。阅读时可以先看整体画像，再对照常见特征、关系模式和职业适配，而不是把类型当成固定标签。",
                'relationships.summary' => "{$typeLabel} 的关系模式可以从表达节奏、冲突处理、边界感和支持方式来理解。适合把这里当作亲密关系、朋友相处和团队沟通的快速入口。",
                'career.summary' => "{$typeLabel} 的职业判断应优先看任务偏好、协作方式、反馈需求和长期动机。这里用于连接人格类型页、职业推荐页和后续具体岗位解释。",
                default => null,
            };
        }

        return match ($sectionKey) {
            'overview' => "{$typeLabel} describes the combination of the {$baseType} core pattern and the {$variant} variant state. Use this page as a practical map for traits, relationships, career fit, strengths, and blind spots rather than as a fixed label.",
            'relationships.summary' => "{$typeLabel} relationship patterns are easiest to read through communication pace, conflict repair, boundaries, and support needs. Use this section as the entry point for close relationships, friendship, and team dynamics.",
            'career.summary' => "{$typeLabel} career fit should be read through task preference, collaboration style, feedback needs, and long-term motivation. This section connects the type profile with career recommendations and role-level exploration.",
            default => null,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(string $sectionKey, string $runtimeTypeCode, string $typeLabel, string $locale): array
    {
        return match ($sectionKey) {
            'letters_intro' => $this->lettersIntroPayload($runtimeTypeCode, $typeLabel, $locale),
            'trait_overview' => $this->traitOverviewPayload($runtimeTypeCode, $typeLabel, $locale),
            'career.preferred_roles' => $this->preferredRolesPayload($runtimeTypeCode, $typeLabel, $locale),
            'growth.strengths' => ['items' => $this->strengthItems($runtimeTypeCode, $typeLabel, $locale)],
            'growth.weaknesses' => ['items' => $this->weaknessItems($runtimeTypeCode, $typeLabel, $locale)],
            default => [
                'structure_contract' => 'mbti_personality_variant_page_structure.v1',
                'intent' => $this->intentForSection($sectionKey),
                'runtime_type_code' => $runtimeTypeCode,
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function lettersIntroPayload(string $runtimeTypeCode, string $typeLabel, string $locale): array
    {
        $letters = str_replace('-', '', $runtimeTypeCode);

        return [
            'headline' => $locale === 'zh-CN'
                ? "{$typeLabel} 可以拆成 5 个信号：4 个 MBTI 基础偏好，加上 A/T 状态差异。"
                : "{$typeLabel} can be read as five signals: four MBTI preferences plus the A/T variant state.",
            'letters' => array_map(
                fn (string $letter, int $index): array => $this->letterCopy($letter, $locale, $index === 4),
                str_split($letters),
                array_keys(str_split($letters))
            ),
            'structure_contract' => 'mbti_personality_variant_page_structure.v1',
            'intent' => 'what_this_type_is_and_at_difference',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function traitOverviewPayload(string $runtimeTypeCode, string $typeLabel, string $locale): array
    {
        return [
            'summary' => $locale === 'zh-CN'
                ? "{$typeLabel} 的常见特征来自这些维度的组合。A/T 不是新类型，而是同一类型在自信、压力感和稳定感上的状态差异。"
                : "{$typeLabel} traits come from the combination of these dimensions. A/T is not a separate type; it describes confidence, stress sensitivity, and stability within the same type.",
            'dimensions' => [
                $this->axisPayload('EI', $runtimeTypeCode, $locale),
                $this->axisPayload('SN', $runtimeTypeCode, $locale),
                $this->axisPayload('TF', $runtimeTypeCode, $locale),
                $this->axisPayload('JP', $runtimeTypeCode, $locale),
                $this->axisPayload('AT', $runtimeTypeCode, $locale),
            ],
            'structure_contract' => 'mbti_personality_variant_page_structure.v1',
            'intent' => 'common_traits_and_at_difference',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function preferredRolesPayload(string $runtimeTypeCode, string $typeLabel, string $locale): array
    {
        $baseTypeCode = substr($runtimeTypeCode, 0, 4);
        $isJudging = str_contains($baseTypeCode, 'J');
        $isThinking = str_contains($baseTypeCode, 'T');

        if ($locale === 'zh-CN') {
            return [
                'title' => "{$typeLabel} 的适合工作先看工作方式，而不是直接套岗位清单。",
                'intro' => '这些岗位簇是初筛方向，后续仍需要结合技能、行业、教育背景和真实经历判断。',
                'groups' => [
                    [
                        'group_title' => $isThinking ? '分析与系统型任务' : '沟通与支持型任务',
                        'description' => $isThinking ? '适合从问题拆解、系统优化、策略判断开始验证。' : '适合从人际理解、服务设计、内容表达和支持协作开始验证。',
                        'examples' => $isThinking ? ['分析研究', '产品策略', '系统规划'] : ['咨询支持', '内容策划', '用户研究'],
                    ],
                    [
                        'group_title' => $isJudging ? '结构化推进环境' : '探索型弹性环境',
                        'description' => $isJudging ? '更适合目标清晰、反馈稳定、责任边界明确的场景。' : '更适合允许探索、迭代和灵活调整的场景。',
                        'examples' => $isJudging ? ['项目管理', '运营规划', '流程优化'] : ['创意项目', '早期探索', '跨职能协作'],
                    ],
                ],
                'structure_contract' => 'mbti_personality_variant_page_structure.v1',
                'intent' => 'career_and_best_fit_work',
            ];
        }

        return [
            'title' => "{$typeLabel} work fit starts with work style, not a fixed job list.",
            'intro' => 'Use these role clusters as an initial screen, then validate against skills, industry context, education, and real experience.',
            'groups' => [
                [
                    'group_title' => $isThinking ? 'Analytical and systems work' : 'Communication and support work',
                    'description' => $isThinking ? 'Start with problem decomposition, system improvement, and strategic judgment.' : 'Start with interpersonal understanding, service design, content expression, and support work.',
                    'examples' => $isThinking ? ['Research analysis', 'Product strategy', 'Systems planning'] : ['Advisory support', 'Content planning', 'User research'],
                ],
                [
                    'group_title' => $isJudging ? 'Structured execution environments' : 'Exploratory flexible environments',
                    'description' => $isJudging ? 'Often fits clearer goals, stable feedback, and defined ownership.' : 'Often fits exploration, iteration, and flexible adjustment.',
                    'examples' => $isJudging ? ['Project management', 'Operations planning', 'Process improvement'] : ['Creative projects', 'Early exploration', 'Cross-functional work'],
                ],
            ],
            'structure_contract' => 'mbti_personality_variant_page_structure.v1',
            'intent' => 'career_and_best_fit_work',
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function strengthItems(string $runtimeTypeCode, string $typeLabel, string $locale): array
    {
        $baseTypeCode = substr($runtimeTypeCode, 0, 4);
        $isIntroverted = str_starts_with($baseTypeCode, 'I');
        $isIntuitive = str_contains($baseTypeCode, 'N');
        $isAssertive = str_ends_with($runtimeTypeCode, '-A');

        if ($locale === 'zh-CN') {
            return [
                ['title' => '稳定优势', 'body' => "{$typeLabel} 通常可以从".($isIntroverted ? '独立思考' : '外部协同').'中形成稳定输出。'],
                ['title' => '判断方式', 'body' => $isIntuitive ? '更容易从模式、可能性和长期方向中找到线索。' : '更容易从事实、经验和可验证细节中推进判断。'],
                ['title' => '状态倾向', 'body' => $isAssertive ? 'A 型通常更容易保持自我确认和节奏稳定。' : 'T 型通常更容易捕捉风险、反馈和需要修正的细节。'],
            ];
        }

        return [
            ['title' => 'Stable advantage', 'body' => "{$typeLabel} often creates reliable output through ".($isIntroverted ? 'independent reflection' : 'external collaboration').'.'],
            ['title' => 'Judgment pattern', 'body' => $isIntuitive ? 'Often notices patterns, possibilities, and long-range direction.' : 'Often advances through facts, experience, and verifiable detail.'],
            ['title' => 'Variant signal', 'body' => $isAssertive ? 'A variants often hold steadier self-confirmation and pace.' : 'T variants often notice risk, feedback, and details that need adjustment.'],
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function weaknessItems(string $runtimeTypeCode, string $typeLabel, string $locale): array
    {
        $baseTypeCode = substr($runtimeTypeCode, 0, 4);
        $isPerceiving = str_contains($baseTypeCode, 'P');
        $isFeeling = str_contains($baseTypeCode, 'F');
        $isTurbulent = str_ends_with($runtimeTypeCode, '-T');

        if ($locale === 'zh-CN') {
            return [
                ['title' => '过度使用风险', 'body' => "{$typeLabel} 的优势如果过度使用，可能变成决策惯性或沟通盲点。"],
                ['title' => '协作风险', 'body' => $isFeeling ? '需要避免只照顾氛围而推迟关键边界。' : '需要避免只强调效率而忽略关系修复。'],
                ['title' => '执行风险', 'body' => $isPerceiving ? '需要把探索转成明确的下一步。' : '需要为计划保留调整空间。'],
                ['title' => '状态风险', 'body' => $isTurbulent ? 'T 型需要留意自我怀疑和压力放大。' : 'A 型需要留意过早确定和低估反馈。'],
            ];
        }

        return [
            ['title' => 'Overuse risk', 'body' => "{$typeLabel} strengths can become decision habits or communication blind spots when overused."],
            ['title' => 'Collaboration risk', 'body' => $isFeeling ? 'Watch for delaying boundaries to preserve harmony.' : 'Watch for prioritizing efficiency while under-investing in repair.'],
            ['title' => 'Execution risk', 'body' => $isPerceiving ? 'Convert exploration into a clear next step.' : 'Leave room for revision inside the plan.'],
            ['title' => 'Variant risk', 'body' => $isTurbulent ? 'T variants should watch for self-doubt and amplified stress.' : 'A variants should watch for deciding too early or underweighting feedback.'],
        ];
    }

    /**
     * @return array{letter:string,title:string,description:string}
     */
    private function letterCopy(string $letter, string $locale, bool $isVariantLetter = false): array
    {
        $copy = [
            'zh-CN' => [
                'E' => ['外向倾向', '从外部互动、行动反馈和现场信息中获得能量。'],
                'I' => ['内向倾向', '更依赖独立思考、内部整理和低干扰环境。'],
                'S' => ['现实感知', '更重视事实、经验、细节和当前可验证的信息。'],
                'N' => ['直觉感知', '更关注模式、可能性、抽象关系和长期方向。'],
                'T' => ['逻辑判断', '更倾向用原则、结构和因果关系做决定。'],
                'F' => ['价值判断', '更倾向用价值、关系影响和人的感受做决定。'],
                'J' => ['计划判断', '更喜欢清晰计划、确定节奏和可控推进。'],
                'P' => ['灵活探索', '更喜欢保留选项、边走边看和适应变化。'],
                'A' => ['A 型状态', '更稳定自信，通常较少被短期反馈扰动。'],
                'T_VARIANT' => ['T 型状态', '更敏感自省，通常更关注风险、反馈和修正空间。'],
            ],
            'en' => [
                'E' => ['Extraversion signal', 'Draws energy from interaction, action feedback, and live context.'],
                'I' => ['Introversion signal', 'Relies more on independent reflection, internal processing, and lower-noise settings.'],
                'S' => ['Sensing signal', 'Prioritizes facts, experience, detail, and verifiable present information.'],
                'N' => ['Intuition signal', 'Tracks patterns, possibilities, abstract connections, and long-range direction.'],
                'T' => ['Thinking signal', 'Decides through principles, structure, and cause-effect logic.'],
                'F' => ['Feeling signal', 'Decides through values, relationship impact, and human context.'],
                'J' => ['Judging signal', 'Prefers plans, clear pace, and controlled follow-through.'],
                'P' => ['Perceiving signal', 'Prefers optionality, discovery, and adapting as new information appears.'],
                'A' => ['Assertive state', 'More steady in self-confidence and less disrupted by short-term feedback.'],
                'T_VARIANT' => ['Turbulent state', 'More self-monitoring and more attentive to risk, feedback, and room for adjustment.'],
            ],
        ];

        $key = $letter === 'T' && $isVariantLetter ? 'T_VARIANT' : $letter;
        $label = $copy[$locale][$key] ?? [$letter, ''];

        return ['letter' => $letter, 'title' => $label[0], 'description' => $label[1]];
    }

    /**
     * @return array<string,mixed>
     */
    private function axisPayload(string $axisId, string $runtimeTypeCode, string $locale): array
    {
        $axis = [
            'EI' => ['E', 'I'],
            'SN' => ['S', 'N'],
            'TF' => ['T', 'F'],
            'JP' => ['J', 'P'],
            'AT' => ['A', 'T'],
        ][$axisId];
        $baseTypeCode = substr($runtimeTypeCode, 0, 4);
        $side = $axisId === 'AT' ? substr($runtimeTypeCode, -1) : $this->matchedLetter($baseTypeCode, $axis);

        $labels = $locale === 'zh-CN'
            ? [
                'EI' => ['能量来源', '外向', '内向'],
                'SN' => ['信息获取', '现实感知', '直觉感知'],
                'TF' => ['决策方式', '逻辑判断', '价值判断'],
                'JP' => ['生活节奏', '计划判断', '灵活探索'],
                'AT' => ['A/T 状态', '稳定自信', '敏感自省'],
            ]
            : [
                'EI' => ['Energy orientation', 'Extraversion', 'Introversion'],
                'SN' => ['Information style', 'Sensing', 'Intuition'],
                'TF' => ['Decision style', 'Thinking', 'Feeling'],
                'JP' => ['Life rhythm', 'Judging', 'Perceiving'],
                'AT' => ['A/T state', 'Assertive', 'Turbulent'],
            ];

        return [
            'id' => $axisId,
            'code' => $axisId,
            'name' => $labels[$axisId][0],
            'label' => $labels[$axisId][0],
            'axis_left' => $labels[$axisId][1],
            'axis_right' => $labels[$axisId][2],
            'summary' => $this->axisSummary($axisId, $side, $locale),
            'description' => $this->axisDescription($axisId, $side, $locale),
            'source' => 'cms_structure',
            'side' => $side,
            'state' => 'authored_skeleton',
        ];
    }

    /**
     * @param  array{0:string,1:string}  $axis
     */
    private function matchedLetter(string $runtimeTypeCode, array $axis): string
    {
        return str_contains($runtimeTypeCode, $axis[0]) ? $axis[0] : $axis[1];
    }

    private function axisSummary(string $axisId, string $side, string $locale): string
    {
        $summary = [
            'zh-CN' => [
                'EI:E' => '更容易通过互动和外部反馈启动。',
                'EI:I' => '更容易通过独立整理和内部思考启动。',
                'SN:S' => '更重视事实、经验和具体信息。',
                'SN:N' => '更重视模式、可能性和抽象关系。',
                'TF:T' => '更倾向用逻辑结构和原则判断。',
                'TF:F' => '更倾向用价值影响和人的感受判断。',
                'JP:J' => '更偏好计划、确定性和清晰推进。',
                'JP:P' => '更偏好灵活、探索和开放选项。',
                'AT:A' => 'A 型更稳定自信，较少被短期反馈扰动。',
                'AT:T' => 'T 型更敏感自省，更关注风险和修正空间。',
            ],
            'en' => [
                'EI:E' => 'Starts more easily through interaction and external feedback.',
                'EI:I' => 'Starts more easily through independent processing and reflection.',
                'SN:S' => 'Prioritizes facts, experience, and concrete information.',
                'SN:N' => 'Prioritizes patterns, possibilities, and abstract relationships.',
                'TF:T' => 'Leans on logical structure and principles when deciding.',
                'TF:F' => 'Leans on values, impact, and human context when deciding.',
                'JP:J' => 'Prefers planning, certainty, and clear follow-through.',
                'JP:P' => 'Prefers flexibility, exploration, and open options.',
                'AT:A' => 'Assertive variants are steadier and less disrupted by short-term feedback.',
                'AT:T' => 'Turbulent variants are more self-monitoring and attentive to risk and adjustment.',
            ],
        ];

        return $summary[$locale][$axisId.':'.$side] ?? $axisId;
    }

    private function axisDescription(string $axisId, string $side, string $locale): string
    {
        if ($locale === 'zh-CN') {
            return $axisId === 'AT'
                ? 'A/T 只说明状态差异，不改变 4 字母基础类型。'
                : '这个维度影响信息处理、沟通节奏和行动偏好。';
        }

        return $axisId === 'AT'
            ? 'A/T describes state differences; it does not replace the four-letter base type.'
            : 'This dimension shapes information processing, communication pace, and action preference.';
    }

    private function intentForSection(string $sectionKey): string
    {
        return match ($sectionKey) {
            'relationships.summary' => 'love_and_relationships',
            'career.summary', 'career.preferred_roles' => 'career_and_best_fit_work',
            'growth.strengths', 'growth.weaknesses' => 'strengths_and_weaknesses',
            default => 'personality_detail_structure',
        };
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = trim($locale);

        if ($normalized === 'zh') {
            return 'zh-CN';
        }

        if (in_array($normalized, PersonalityProfile::SUPPORTED_LOCALES, true)) {
            return $normalized;
        }

        throw new InvalidArgumentException('Unsupported locale for MBTI personality section structure.');
    }

    private function normalizeTypeName(?string $typeName): ?string
    {
        $normalized = trim((string) $typeName);
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/[-－](?:A|T)$/i', '', $normalized) ?: $normalized;

        return trim($normalized) !== '' ? trim($normalized) : null;
    }

    private function typeLabel(string $runtimeTypeCode, ?string $typeName): string
    {
        return $typeName !== null ? $runtimeTypeCode.' '.$typeName : $runtimeTypeCode;
    }
}
