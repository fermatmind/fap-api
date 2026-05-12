<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecActivityExplorerService
{
    private const SCHEMA_VERSION = 'riasec.activity_explorer.v0.1';

    private const CONTENT_VERSION = 'career_activity_registry_v0.1';

    private const SOURCE_STATUS = 'content_example_not_registry_match';

    private const SOURCE_NAME = 'FermatTest Career Activity Registry v0.1';

    /**
     * @var array<string,array{dimension:string,label:array{en:string,zh-CN:string},core_drive:array{en:string,zh-CN:string},activity_families:list<string>}>
     */
    private const DIMENSION_ACTIVITY_FAMILIES = [
        'R' => [
            'dimension' => 'R',
            'label' => ['en' => 'Realistic', 'zh-CN' => '实作型'],
            'core_drive' => [
                'en' => 'Build, adjust, repair, and verify tangible outcomes.',
                'zh-CN' => '把事情做出来、调好、修好、落地。',
            ],
            'activity_families' => [
                'physical_implementation',
                'tools_and_equipment',
                'field_troubleshooting',
                'prototypes_and_tangible_outputs',
                'hands_on_systems',
            ],
        ],
        'I' => [
            'dimension' => 'I',
            'label' => ['en' => 'Investigative', 'zh-CN' => '研究型'],
            'core_drive' => [
                'en' => 'Clarify problems by finding causes, evidence, and structure.',
                'zh-CN' => '把问题看清楚，找出原因、证据和结构。',
            ],
            'activity_families' => [
                'analyze_complex_problems',
                'organize_evidence_materials',
                'model_systems',
                'test_hypotheses',
                'research_and_explain',
            ],
        ],
        'A' => [
            'dimension' => 'A',
            'label' => ['en' => 'Artistic', 'zh-CN' => '艺术型'],
            'core_drive' => [
                'en' => 'Shape ideas, information, or experiences into expressive form.',
                'zh-CN' => '把想法、信息或体验表达成有质感的形式。',
            ],
            'activity_families' => [
                'explain_complex_information',
                'create_original_output',
                'design_experiences',
                'shape_story_or_visuals',
                'transform_abstract_into_form',
            ],
        ],
        'S' => [
            'dimension' => 'S',
            'label' => ['en' => 'Social', 'zh-CN' => '社会型'],
            'core_drive' => [
                'en' => 'Help real people understand, feel steadier, and take action.',
                'zh-CN' => '让真实的人更清楚、更安心、更能行动。',
            ],
            'activity_families' => [
                'understand_real_needs',
                'support_decision_making',
                'teach_or_facilitate',
                'listen_and_clarify',
                'build_people_support',
            ],
        ],
        'E' => [
            'dimension' => 'E',
            'label' => ['en' => 'Enterprising', 'zh-CN' => '企业型'],
            'core_drive' => [
                'en' => 'Influence, move resources, and turn opportunities into outcomes.',
                'zh-CN' => '影响、推动、争取资源，把机会变成结果。',
            ],
            'activity_families' => [
                'persuade_and_influence',
                'lead_projects',
                'negotiate_resources',
                'business_growth',
                'make_decisions_under_uncertainty',
            ],
        ],
        'C' => [
            'dimension' => 'C',
            'label' => ['en' => 'Conventional', 'zh-CN' => '常规型'],
            'core_drive' => [
                'en' => 'Create order, reduce ambiguity, and keep systems running reliably.',
                'zh-CN' => '建立秩序、减少混乱、让系统稳定运行。',
            ],
            'activity_families' => [
                'organize_evidence_materials',
                'manage_records',
                'build_processes',
                'quality_check',
                'operational_follow_through',
            ],
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function build(?string $hollandCode, string $locale = 'zh-CN'): array
    {
        $normalizedLocale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $code = $this->normalizeCode($hollandCode);
        $dimensions = $this->dimensionsForCode($code);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'content_version' => self::CONTENT_VERSION,
            'status' => 'content_examples_only',
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::SOURCE_NAME,
            'locale' => $normalizedLocale,
            'holland_code' => $code,
            'boundary' => [
                'occupation_examples_label' => '内容示例，非职业数据库匹配',
                'occupation_examples_policy' => 'content_example_not_registry_match_without_reviewed_registry_source',
                'ranking_allowed' => false,
                'fit_score_allowed' => false,
                'success_prediction_allowed' => false,
                'qualification_judgment_allowed' => false,
                'registry_source_connected' => false,
            ],
            'dimension_activity_families' => $this->dimensionFamilies($dimensions, $normalizedLocale),
            'code_activity_pack' => $this->codeActivityPack($code, $normalizedLocale),
        ];
    }

    private function normalizeCode(?string $hollandCode): string
    {
        $code = strtoupper((string) preg_replace('/[^RIASEC]/i', '', (string) $hollandCode));

        return substr($code, 0, 3);
    }

    /**
     * @return list<string>
     */
    private function dimensionsForCode(string $code): array
    {
        $dimensions = [];
        foreach (str_split($code) as $dimension) {
            if (isset(self::DIMENSION_ACTIVITY_FAMILIES[$dimension]) && ! in_array($dimension, $dimensions, true)) {
                $dimensions[] = $dimension;
            }
        }

        return $dimensions;
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<array<string,mixed>>
     */
    private function dimensionFamilies(array $dimensions, string $locale): array
    {
        $rows = [];
        foreach ($dimensions as $dimension) {
            $source = self::DIMENSION_ACTIVITY_FAMILIES[$dimension] ?? null;
            if ($source === null) {
                continue;
            }

            $rows[] = [
                'dimension' => $dimension,
                'label' => $source['label'][$locale] ?? $source['label']['en'],
                'core_drive' => $source['core_drive'][$locale] ?? $source['core_drive']['en'],
                'activity_families' => $source['activity_families'],
                'evidence_level' => 'theory_based_content_mapping',
                'source_status' => self::SOURCE_STATUS,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function codeActivityPack(string $code, string $locale): array
    {
        if ($code !== 'IAS') {
            return [
                'status' => 'not_available_for_code_v0_1',
                'reason' => 'code_activity_pack_not_authored',
                'activities' => [],
                'occupation_examples' => [],
            ];
        }

        return [
            'status' => 'available',
            'code' => 'IAS',
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::SOURCE_NAME,
            'activity_chain' => [
                'analyze_complex_problems',
                'explain_complex_information',
                'support_decision_making',
            ],
            'activities' => $this->iasActivities($locale),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function iasActivities(string $locale): array
    {
        $activities = [
            [
                'activity_key' => 'analyze_complex_problems',
                'riasec_dimensions' => ['I'],
                'activity_label' => ['en' => 'Analyze complex problems', 'zh-CN' => '分析复杂问题'],
                'activity_user_copy' => [
                    'en' => 'You may be drawn to causes, evidence, and structure.',
                    'zh-CN' => '你会被“为什么会这样、证据在哪里、结构是什么”这类问题吸引。',
                ],
                'task_examples' => [
                    '把一个模糊问题拆成原因、变量和假设。',
                    '比较不同解释，找出最可能成立的一种。',
                    '阅读资料后写出自己的判断依据。',
                ],
                'occupation_examples' => [
                    ['name' => '行业研究助理', 'common_tasks' => ['拆解行业问题', '整理证据', '写出判断依据']],
                    ['name' => '产品分析 / 用户洞察助理', 'common_tasks' => ['分析反馈', '整理需求假设', '输出洞察']],
                    ['name' => '政策研究助理', 'common_tasks' => ['阅读资料', '比较解释', '形成研究摘要']],
                    ['name' => '研究型内容策划', 'common_tasks' => ['查证资料', '搭建内容结构', '解释复杂主题']],
                ],
                'next_experiments' => [
                    '写一个问题拆解表。',
                    '阅读一份行业报告并提炼 5 条证据。',
                    '对同一问题列出 2 种不同解释。',
                ],
            ],
            [
                'activity_key' => 'organize_evidence_materials',
                'riasec_dimensions' => ['I', 'C'],
                'activity_label' => ['en' => 'Organize evidence and materials', 'zh-CN' => '整理证据与资料'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy turning messy information into a structure that supports judgment.',
                    'zh-CN' => '你不只是想看资料，而是想把混乱信息整理成能支持判断的结构。',
                ],
                'task_examples' => [
                    '筛选资料，去掉无关信息。',
                    '把案例、评论或数据按主题归类。',
                    '提炼 5 条最关键证据。',
                ],
                'occupation_examples' => [
                    ['name' => '研究助理', 'common_tasks' => ['筛选资料', '归类证据', '制作摘要']],
                    ['name' => '资料分析 / 内容研究', 'common_tasks' => ['整理资料', '标注主题', '输出结构化笔记']],
                    ['name' => '知识管理助理', 'common_tasks' => ['维护知识库', '校对条目', '整理索引']],
                    ['name' => '运营分析支持', 'common_tasks' => ['整理运营数据', '归纳问题', '准备汇报材料']],
                ],
                'next_experiments' => [
                    '给一篇文章做证据索引。',
                    '整理 10 条资料成 3 类。',
                    '把一段杂乱讨论整理成一个决策表。',
                ],
            ],
            [
                'activity_key' => 'explain_complex_information',
                'riasec_dimensions' => ['A', 'I'],
                'activity_label' => ['en' => 'Explain complex information clearly', 'zh-CN' => '把复杂信息表达清楚'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy making difficult material clear and usable for others.',
                    'zh-CN' => '你想让难懂的东西变得清楚、有质感、能被别人接住。',
                ],
                'task_examples' => [
                    '把复杂概念改写成短文。',
                    '设计一张说明图或流程卡。',
                    '做一份面向非专业用户的说明材料。',
                ],
                'occupation_examples' => [
                    ['name' => '技术 / 科学传播', 'common_tasks' => ['解释概念', '核对事实', '制作说明材料']],
                    ['name' => '知识产品内容策划', 'common_tasks' => ['设计主题', '组织内容', '面向受众表达']],
                    ['name' => '说明文档 / 信息设计', 'common_tasks' => ['写说明文档', '设计流程卡', '优化信息层级']],
                    ['name' => '教育内容编辑', 'common_tasks' => ['改写材料', '设计练习', '校对内容']],
                ],
                'next_experiments' => [
                    '把一篇复杂文章改写成 5 句话。',
                    '做一页解释材料给别人看。',
                    '为一个专业概念写一个普通人能懂的类比。',
                ],
            ],
            [
                'activity_key' => 'understand_real_needs',
                'riasec_dimensions' => ['S', 'I'],
                'activity_label' => ['en' => 'Understand real needs', 'zh-CN' => '理解真实人的需求'],
                'activity_user_copy' => [
                    'en' => 'You may want to understand where real people get stuck.',
                    'zh-CN' => '你不只想研究抽象问题，也想知道真实的人为什么卡住。',
                ],
                'task_examples' => [
                    '访谈或观察真实用户。',
                    '拆解评论、反馈或投诉。',
                    '写出需求假设和验证问题。',
                ],
                'occupation_examples' => [
                    ['name' => '用户研究助理', 'common_tasks' => ['拆解用户反馈', '整理需求假设', '写出洞察报告']],
                    ['name' => '服务设计研究', 'common_tasks' => ['观察服务流程', '记录阻塞点', '整理改进机会']],
                    ['name' => '教育支持 / 学习辅导', 'common_tasks' => ['了解学习困难', '拆解问题', '提供学习材料']],
                    ['name' => '社区项目研究', 'common_tasks' => ['收集社区反馈', '归纳需求', '形成项目线索']],
                ],
                'next_experiments' => [
                    '拆解 3 条用户评论。',
                    '写 5 个访谈问题。',
                    '观察一个真实服务流程，记录用户可能卡住的地方。',
                ],
            ],
            [
                'activity_key' => 'design_learning_explanatory_materials',
                'riasec_dimensions' => ['A', 'S', 'I'],
                'activity_label' => ['en' => 'Design learning or explanatory materials', 'zh-CN' => '设计学习或说明材料'],
                'activity_user_copy' => [
                    'en' => 'You may like turning knowledge into material that helps others learn or act.',
                    'zh-CN' => '你可能喜欢把知识变成别人更容易学习、使用、行动的材料。',
                ],
                'task_examples' => [
                    '设计学习路径。',
                    '把步骤拆成教程。',
                    '根据反馈改写说明材料。',
                ],
                'occupation_examples' => [
                    ['name' => '教学设计 / 学习体验', 'common_tasks' => ['设计学习路径', '拆解步骤', '迭代材料']],
                    ['name' => '培训内容支持', 'common_tasks' => ['整理课程资料', '制作说明卡', '收集反馈']],
                    ['name' => '产品说明 / 用户教育', 'common_tasks' => ['编写教程', '设计引导材料', '验证理解效果']],
                    ['name' => '知识库内容设计', 'common_tasks' => ['组织条目', '维护说明', '优化检索结构']],
                ],
                'next_experiments' => [
                    '制作一张入门说明卡。',
                    '把一个步骤做成 5 分钟教程。',
                    '让一个真实对象按你的说明完成一个小任务。',
                ],
            ],
            [
                'activity_key' => 'support_decision_making',
                'riasec_dimensions' => ['S', 'I', 'A'],
                'activity_label' => ['en' => 'Support decision making', 'zh-CN' => '支持别人做判断'],
                'activity_user_copy' => [
                    'en' => 'You may like using questions, explanations, tools, or frames to help people choose more clearly.',
                    'zh-CN' => '你可能喜欢用提问、解释、工具或框架，让别人更清楚地选择。',
                ],
                'task_examples' => [
                    '把选择项整理成判断表。',
                    '用问题帮助别人澄清取舍。',
                    '设计一个低风险验证步骤。',
                ],
                'occupation_examples' => [
                    ['name' => '学习规划支持', 'common_tasks' => ['整理选择项', '设计学习路径', '提醒验证边界']],
                    ['name' => '产品顾问 / 方案支持', 'common_tasks' => ['理解需求', '解释方案', '整理取舍']],
                    ['name' => '研究咨询助理', 'common_tasks' => ['准备资料', '搭建框架', '形成说明材料']],
                    ['name' => '公益项目支持', 'common_tasks' => ['理解对象需求', '整理资源', '支持行动计划']],
                ],
                'next_experiments' => [
                    '为一个选择做利弊表。',
                    '设计 3 个澄清问题。',
                    '把一个大决定拆成一个可验证的小步骤。',
                ],
            ],
        ];

        return array_map(fn (array $activity): array => $this->normalizeActivity($activity, $locale), $activities);
    }

    /**
     * @param  array<string,mixed>  $activity
     * @return array<string,mixed>
     */
    private function normalizeActivity(array $activity, string $locale): array
    {
        $occupationExamples = [];
        foreach ((array) ($activity['occupation_examples'] ?? []) as $example) {
            if (! is_array($example)) {
                continue;
            }

            $occupationExamples[] = [
                'occupation_example' => (string) ($example['name'] ?? ''),
                'source_status' => self::SOURCE_STATUS,
                'source_name' => self::SOURCE_NAME,
                'display_label' => '内容示例，非职业数据库匹配',
                'common_tasks' => array_values(array_map('strval', (array) ($example['common_tasks'] ?? []))),
                'skills_to_check' => $this->skillsToCheck((string) ($activity['activity_key'] ?? '')),
                'education_boundary' => '可能需要相关课程、训练、项目经验或领域知识；具体要求会因地区、行业和组织不同而变化。',
                'skill_boundary' => '兴趣不等于能力，需要通过学习、作品、练习或真实项目验证相关技能。',
                'qualification_boundary' => '涉及专业资质、执业资格或监管领域时，必须遵守资格、证书、教育背景和当地法规。',
                'localization_note' => '职业名称、教育路径和资格要求会因国家、地区、行业和组织不同而变化。',
                'not_a_recommendation' => true,
            ];
        }

        return [
            'activity_key' => (string) ($activity['activity_key'] ?? ''),
            'riasec_dimensions' => array_values(array_map('strval', (array) ($activity['riasec_dimensions'] ?? []))),
            'activity_label' => (string) data_get($activity, 'activity_label.'.$locale, data_get($activity, 'activity_label.en', '')),
            'activity_user_copy' => (string) data_get($activity, 'activity_user_copy.'.$locale, data_get($activity, 'activity_user_copy.en', '')),
            'content_version' => self::CONTENT_VERSION,
            'evidence_level' => 'theory_based_content_mapping',
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::SOURCE_NAME,
            'task_examples' => array_values(array_map('strval', (array) ($activity['task_examples'] ?? []))),
            'occupation_examples' => $occupationExamples,
            'next_experiments' => array_values(array_map('strval', (array) ($activity['next_experiments'] ?? []))),
        ];
    }

    /**
     * @return list<string>
     */
    private function skillsToCheck(string $activityKey): array
    {
        return match ($activityKey) {
            'analyze_complex_problems' => ['资料检索', '逻辑判断', '结构化写作'],
            'organize_evidence_materials' => ['分类', '校对', '信息判断', '结构化表达'],
            'explain_complex_information' => ['写作', '信息设计', '事实核查', '受众理解'],
            'understand_real_needs' => ['访谈', '提问', '倾听', '分析反馈'],
            'design_learning_explanatory_materials' => ['教学设计', '内容结构', '反馈迭代'],
            'support_decision_making' => ['提问', '框架搭建', '边界意识'],
            default => ['学习验证', '真实任务练习', '反馈迭代'],
        };
    }
}
