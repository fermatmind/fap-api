<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecActivityExplorerService
{
    private const SCHEMA_VERSION = 'riasec.activity_explorer.v0.1';

    private const CONTENT_VERSION = 'activity_task_examples_v1.zh-CN';

    private const SOURCE_STATUS = 'content_example_not_registry_match';

    private const SOURCE_NAME = 'FermatTest RIASEC Activity Task Examples v1';

    private const ACTIVITY_TASK_ASSET_PATH = 'content_assets/riasec/activity_task_examples_v1.zh-CN.jsonl';

    private const ACTIVITY_TASK_SOURCE_STATUSES = [
        'content_example_not_registry_match',
        'commercial_expansion_candidate_not_runtime_imported',
    ];

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
        $activities = $this->fileBackedActivitiesForCode($code, $locale);

        if ($activities === []) {
            return [
                'status' => 'not_available_for_code_v1',
                'reason' => 'activity_task_examples_not_available',
                'activities' => [],
                'occupation_examples' => [],
            ];
        }

        return [
            'status' => 'available',
            'code' => $code,
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::SOURCE_NAME,
            'activity_chain' => array_values(array_map(
                static fn (array $activity): string => (string) ($activity['activity_key'] ?? ''),
                $activities,
            )),
            'activities' => $activities,
            'occupation_examples' => [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fileBackedActivitiesForCode(string $code, string $locale): array
    {
        $dimensions = $this->dimensionsForCode($code);
        if ($dimensions === []) {
            return [];
        }

        $rows = $this->loadActivityTaskAssetRows();
        if ($rows === []) {
            return [];
        }

        $selected = [];
        $seen = [];
        foreach ($dimensions as $dimension) {
            $perDimension = 0;
            foreach ($rows as $row) {
                if ($perDimension >= 3) {
                    break;
                }

                if (isset($seen[$row['activity_key']]) || ! in_array($dimension, $row['dimensions'], true)) {
                    continue;
                }

                $seen[$row['activity_key']] = true;
                $selected[] = $this->normalizeFileBackedActivity($row, $locale);
                $perDimension++;
            }
        }

        return $selected;
    }

    /**
     * @return list<array{activity_key:string,dimensions:list<string>,activity_label:string,task_examples:list<string>,low_risk_validation:string,action_duration_options:array<string,string>}>
     */
    private function loadActivityTaskAssetRows(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $path = base_path(self::ACTIVITY_TASK_ASSET_PATH);
        if (! is_file($path) || ! is_readable($path)) {
            return $cache = [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $cache = [];
            }

            if (! is_array($decoded) || ! $this->isValidActivityTaskRow($decoded)) {
                return $cache = [];
            }

            $rows[] = [
                'activity_key' => (string) $decoded['activity_key'],
                'dimensions' => array_values(array_map('strval', (array) $decoded['dimensions'])),
                'activity_label' => (string) $decoded['activity_label'],
                'task_examples' => array_values(array_map('strval', (array) $decoded['task_examples'])),
                'low_risk_validation' => (string) $decoded['low_risk_validation'],
                'action_duration_options' => array_map('strval', (array) $decoded['action_duration_options']),
            ];
        }

        return $cache = $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function isValidActivityTaskRow(array $row): bool
    {
        if (($row['schema_version'] ?? null) !== 'riasec.activity_task_example.v1') {
            return false;
        }

        if (($row['asset_version'] ?? null) !== 'riasec_activity_task_examples_v1.zh-CN') {
            return false;
        }

        if (($row['frontend_fallback_allowed'] ?? true) !== false || ($row['not_a_recommendation'] ?? false) !== true) {
            return false;
        }

        if (! in_array(($row['source_status'] ?? null), self::ACTIVITY_TASK_SOURCE_STATUSES, true)) {
            return false;
        }

        if (($row['activity_key'] ?? '') === '' || ! is_array($row['dimensions'] ?? null) || ! is_array($row['task_examples'] ?? null)) {
            return false;
        }

        foreach ((array) $row['dimensions'] as $dimension) {
            if (! isset(self::DIMENSION_ACTIVITY_FAMILIES[(string) $dimension])) {
                return false;
            }
        }

        return count((array) $row['task_examples']) >= 3
            && is_string($row['activity_label'] ?? null)
            && is_string($row['low_risk_validation'] ?? null)
            && is_array($row['action_duration_options'] ?? null);
    }

    /**
     * @param  array{activity_key:string,dimensions:list<string>,activity_label:string,task_examples:list<string>,low_risk_validation:string,action_duration_options:array<string,string>}  $row
     * @return array<string,mixed>
     */
    private function normalizeFileBackedActivity(array $row, string $locale): array
    {
        $nextExperiments = array_values(array_filter([
            $row['action_duration_options']['15min'] ?? null,
            $row['action_duration_options']['30min'] ?? null,
            $row['low_risk_validation'],
        ]));

        return [
            'activity_key' => $row['activity_key'],
            'riasec_dimensions' => $row['dimensions'],
            'activity_label' => $locale === 'zh-CN' ? $row['activity_label'] : $row['activity_key'],
            'activity_user_copy' => $row['low_risk_validation'],
            'content_version' => self::CONTENT_VERSION,
            'evidence_level' => 'backend_authoritative_activity_task_asset',
            'source_status' => self::SOURCE_STATUS,
            'source_name' => self::SOURCE_NAME,
            'task_examples' => $row['task_examples'],
            'occupation_examples' => [],
            'next_experiments' => $nextExperiments,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function codeActivities(string $code, string $locale): array
    {
        return match ($code) {
            'IAS' => $this->iasActivities($locale),
            'RCE' => $this->rceActivities($locale),
            'EAS' => $this->easActivities($locale),
            'CRI' => $this->criActivities($locale),
            'SIC' => $this->sicActivities($locale),
            'ERC' => $this->ercActivities($locale),
            'AIR' => $this->airActivities($locale),
            'CSE' => $this->cseActivities($locale),
            default => [],
        };
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

        return $this->normalizeActivities($activities, $locale);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rceActivities(string $locale): array
    {
        return $this->normalizeActivities([
            [
                'activity_key' => 'operate_reliable_processes',
                'riasec_dimensions' => ['R', 'C'],
                'activity_label' => ['en' => 'Operate reliable processes', 'zh-CN' => '稳定执行具体流程'],
                'activity_user_copy' => [
                    'en' => 'You may like concrete work where steps, tools, and quality checks matter.',
                    'zh-CN' => '你可能喜欢有明确步骤、工具和质量检查的具体工作。',
                ],
                'task_examples' => [
                    '按标准流程完成设备、物料或现场检查。',
                    '记录异常并按优先级处理。',
                    '把重复任务整理成可复用清单。',
                ],
                'occupation_examples' => [
                    ['name' => '运营执行 / 现场支持', 'common_tasks' => ['执行流程', '记录异常', '协调交付']],
                    ['name' => '质检助理', 'common_tasks' => ['检查样品', '记录结果', '跟进修正']],
                    ['name' => '实验室技术支持', 'common_tasks' => ['准备材料', '维护设备', '记录数据']],
                ],
                'next_experiments' => [
                    '为一个重复任务写出操作清单。',
                    '观察一个现场流程并记录 3 个风险点。',
                    '做一次小型质量检查并复盘遗漏。',
                ],
            ],
            [
                'activity_key' => 'improve_hands_on_workflow',
                'riasec_dimensions' => ['R', 'C', 'E'],
                'activity_label' => ['en' => 'Improve hands-on workflow', 'zh-CN' => '改进可落地的工作流程'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy making practical work smoother, clearer, and easier to coordinate.',
                    'zh-CN' => '你可能喜欢把实际工作变得更顺、更清楚、更容易协作。',
                ],
                'task_examples' => [
                    '找出流程中最容易卡住的一步。',
                    '调整物料、工具或交接方式。',
                    '把改进建议写成可执行步骤。',
                ],
                'occupation_examples' => [
                    ['name' => '流程改善助理', 'common_tasks' => ['观察流程', '整理问题', '提出调整步骤']],
                    ['name' => '供应链运营支持', 'common_tasks' => ['跟进物料', '维护记录', '协调异常']],
                    ['name' => '项目执行协调', 'common_tasks' => ['追踪进度', '整理风险', '推动交付']],
                ],
                'next_experiments' => [
                    '把一个低效流程画成步骤图。',
                    '为一次活动设计物料检查表。',
                    '跟进一个小任务直到完成并记录阻塞。',
                ],
            ],
        ], $locale);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function easActivities(string $locale): array
    {
        return $this->normalizeActivities([
            [
                'activity_key' => 'present_and_mobilize_ideas',
                'riasec_dimensions' => ['E', 'A'],
                'activity_label' => ['en' => 'Present and mobilize ideas', 'zh-CN' => '表达并推动想法'],
                'activity_user_copy' => [
                    'en' => 'You may like turning ideas into messages that move people to act.',
                    'zh-CN' => '你可能喜欢把想法变成能让别人理解并行动的信息。',
                ],
                'task_examples' => [
                    '为一个主题设计表达角度。',
                    '向不同对象说明同一个方案。',
                    '根据反馈调整措辞和呈现方式。',
                ],
                'occupation_examples' => [
                    ['name' => '品牌传播助理', 'common_tasks' => ['整理卖点', '撰写材料', '跟进反馈']],
                    ['name' => '活动策划支持', 'common_tasks' => ['设计主题', '协调对象', '复盘效果']],
                    ['name' => '内容运营', 'common_tasks' => ['策划选题', '编辑内容', '观察反馈']],
                ],
                'next_experiments' => [
                    '把一个想法写成 1 分钟说明。',
                    '为同一主题做两个不同受众版本。',
                    '收集 5 条反馈并改写表达。',
                ],
            ],
            [
                'activity_key' => 'shape_audience_experience',
                'riasec_dimensions' => ['A', 'S', 'E'],
                'activity_label' => ['en' => 'Shape audience experience', 'zh-CN' => '设计受众体验'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy designing moments that help people feel engaged and clear.',
                    'zh-CN' => '你可能喜欢设计让人更投入、更清楚的体验过程。',
                ],
                'task_examples' => [
                    '设计一次活动的关键触点。',
                    '把受众反应转化成改进清单。',
                    '协调内容、节奏和现场执行。',
                ],
                'occupation_examples' => [
                    ['name' => '用户活动运营', 'common_tasks' => ['设计流程', '协调现场', '整理反馈']],
                    ['name' => '学习体验助理', 'common_tasks' => ['设计材料', '观察参与', '迭代流程']],
                    ['name' => '社群项目支持', 'common_tasks' => ['组织活动', '回应成员', '复盘内容']],
                ],
                'next_experiments' => [
                    '为一个小活动设计开始、进行、结束三步。',
                    '观察一次讲解中的参与变化。',
                    '把一次体验复盘成改进清单。',
                ],
            ],
        ], $locale);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function criActivities(string $locale): array
    {
        return $this->normalizeActivities([
            [
                'activity_key' => 'audit_tangible_systems',
                'riasec_dimensions' => ['C', 'R'],
                'activity_label' => ['en' => 'Audit tangible systems', 'zh-CN' => '检查具体系统'],
                'activity_user_copy' => [
                    'en' => 'You may like checking whether real systems match expected standards.',
                    'zh-CN' => '你可能喜欢确认真实系统是否符合标准和记录。',
                ],
                'task_examples' => [
                    '核对设备、库存或样本记录。',
                    '发现偏差并保留证据。',
                    '把异常整理成可追踪问题。',
                ],
                'occupation_examples' => [
                    ['name' => '质量体系助理', 'common_tasks' => ['核对记录', '整理偏差', '跟进修正']],
                    ['name' => '数据采集 / 现场调查', 'common_tasks' => ['采集样本', '记录环境', '整理表格']],
                    ['name' => '仓储运营支持', 'common_tasks' => ['盘点物料', '核对单据', '标注异常']],
                ],
                'next_experiments' => [
                    '做一次库存或资料核对。',
                    '为一个检查任务设计记录表。',
                    '把发现的问题按原因归类。',
                ],
            ],
            [
                'activity_key' => 'investigate_process_failures',
                'riasec_dimensions' => ['I', 'C', 'R'],
                'activity_label' => ['en' => 'Investigate process failures', 'zh-CN' => '调查流程失误'],
                'activity_user_copy' => [
                    'en' => 'You may be drawn to finding why a concrete process failed.',
                    'zh-CN' => '你可能会被“具体流程为什么出错”这类问题吸引。',
                ],
                'task_examples' => [
                    '回看记录找出异常发生位置。',
                    '比较可能原因并保留证据。',
                    '写出预防再次发生的步骤。',
                ],
                'occupation_examples' => [
                    ['name' => '运营分析支持', 'common_tasks' => ['查看记录', '归纳异常', '准备复盘']],
                    ['name' => '安全 / 合规助理', 'common_tasks' => ['核查流程', '整理证据', '提醒边界']],
                    ['name' => '实验流程支持', 'common_tasks' => ['记录步骤', '排查误差', '维护样本']],
                ],
                'next_experiments' => [
                    '复盘一次小失误并写出 3 个原因。',
                    '把一个流程的风险点标在步骤图上。',
                    '设计一个避免遗漏的检查清单。',
                ],
            ],
        ], $locale);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sicActivities(string $locale): array
    {
        return $this->normalizeActivities([
            [
                'activity_key' => 'clarify_people_needs_with_evidence',
                'riasec_dimensions' => ['S', 'I'],
                'activity_label' => ['en' => 'Clarify people needs with evidence', 'zh-CN' => '用证据澄清人的需求'],
                'activity_user_copy' => [
                    'en' => 'You may like helping people by first understanding what is really happening.',
                    'zh-CN' => '你可能喜欢先弄清真实情况，再帮助别人处理问题。',
                ],
                'task_examples' => [
                    '访谈对象并记录关键事实。',
                    '把需求、情绪和限制条件分开。',
                    '整理可验证的支持方案。',
                ],
                'occupation_examples' => [
                    ['name' => '用户研究助理', 'common_tasks' => ['访谈用户', '整理证据', '输出洞察']],
                    ['name' => '学习支持助理', 'common_tasks' => ['了解困难', '整理资料', '跟进反馈']],
                    ['name' => '服务运营支持', 'common_tasks' => ['记录诉求', '分类问题', '协调处理']],
                ],
                'next_experiments' => [
                    '写 5 个澄清需求的问题。',
                    '把一次反馈分成事实、感受和限制。',
                    '设计一个低风险验证步骤。',
                ],
            ],
            [
                'activity_key' => 'organize_support_resources',
                'riasec_dimensions' => ['S', 'C'],
                'activity_label' => ['en' => 'Organize support resources', 'zh-CN' => '整理支持资源'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy making help easier to find, follow, and repeat.',
                    'zh-CN' => '你可能喜欢把帮助变得更容易找到、执行和复用。',
                ],
                'task_examples' => [
                    '把常见问题整理成清单。',
                    '维护支持材料和处理记录。',
                    '根据反馈优化步骤说明。',
                ],
                'occupation_examples' => [
                    ['name' => '客户支持运营', 'common_tasks' => ['整理问题', '维护知识库', '跟进处理']],
                    ['name' => '教育项目助理', 'common_tasks' => ['整理材料', '通知对象', '记录反馈']],
                    ['name' => '公益项目协调', 'common_tasks' => ['整理资源', '联系对象', '跟踪进展']],
                ],
                'next_experiments' => [
                    '为一个常见问题写处理流程。',
                    '整理一组支持资源并标注适用对象。',
                    '把 10 条反馈归为 3 类。',
                ],
            ],
        ], $locale);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ercActivities(string $locale): array
    {
        return $this->normalizeActivities([
            [
                'activity_key' => 'coordinate_operational_delivery',
                'riasec_dimensions' => ['E', 'R', 'C'],
                'activity_label' => ['en' => 'Coordinate operational delivery', 'zh-CN' => '协调实际交付'],
                'activity_user_copy' => [
                    'en' => 'You may like keeping practical work moving across people, resources, and constraints.',
                    'zh-CN' => '你可能喜欢在人员、资源和限制之间推动实际交付。',
                ],
                'task_examples' => [
                    '拆解交付步骤和负责人。',
                    '跟踪物料、时间和现场限制。',
                    '推动阻塞项得到处理。',
                ],
                'occupation_examples' => [
                    ['name' => '项目运营助理', 'common_tasks' => ['拆解任务', '追踪进度', '协调资源']],
                    ['name' => '活动执行统筹', 'common_tasks' => ['安排现场', '确认物料', '处理异常']],
                    ['name' => '门店 / 区域运营支持', 'common_tasks' => ['巡检执行', '整理问题', '跟进改善']],
                ],
                'next_experiments' => [
                    '为一次小交付写责任清单。',
                    '记录一个任务的阻塞和处理路径。',
                    '协调两个人完成一个具体任务。',
                ],
            ],
            [
                'activity_key' => 'negotiate_practical_constraints',
                'riasec_dimensions' => ['E', 'C'],
                'activity_label' => ['en' => 'Negotiate practical constraints', 'zh-CN' => '协调现实限制'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy making decisions when resources, rules, and timing all matter.',
                    'zh-CN' => '你可能喜欢在资源、规则和时间都有限的情况下推进判断。',
                ],
                'task_examples' => [
                    '比较不同执行方案的代价。',
                    '与相关方确认可接受条件。',
                    '把决定记录成清楚的执行边界。',
                ],
                'occupation_examples' => [
                    ['name' => '商务运营支持', 'common_tasks' => ['整理条件', '跟进沟通', '记录约定']],
                    ['name' => '采购协调助理', 'common_tasks' => ['比较方案', '确认条件', '维护记录']],
                    ['name' => '排期 / 资源协调', 'common_tasks' => ['整理需求', '安排资源', '处理冲突']],
                ],
                'next_experiments' => [
                    '把一个选择的资源限制列成表。',
                    '模拟一次条件确认对话。',
                    '写一份简短执行边界说明。',
                ],
            ],
        ], $locale);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function airActivities(string $locale): array
    {
        return $this->normalizeActivities([
            [
                'activity_key' => 'prototype_expressive_solutions',
                'riasec_dimensions' => ['A', 'I', 'R'],
                'activity_label' => ['en' => 'Prototype expressive solutions', 'zh-CN' => '把想法做成原型'],
                'activity_user_copy' => [
                    'en' => 'You may like testing an idea by making something people can see or use.',
                    'zh-CN' => '你可能喜欢把想法做成别人看得见、用得上的原型。',
                ],
                'task_examples' => [
                    '把抽象概念做成草图、模型或样张。',
                    '观察别人如何理解原型。',
                    '根据证据改动结构或表现形式。',
                ],
                'occupation_examples' => [
                    ['name' => '产品原型助理', 'common_tasks' => ['制作草图', '整理反馈', '迭代结构']],
                    ['name' => '信息设计支持', 'common_tasks' => ['设计图示', '核对信息', '优化表达']],
                    ['name' => '展陈 / 体验设计助理', 'common_tasks' => ['制作样张', '测试动线', '记录观察']],
                ],
                'next_experiments' => [
                    '为一个想法做低保真原型。',
                    '让 2 个人试用并记录卡点。',
                    '把反馈改成下一版结构。',
                ],
            ],
            [
                'activity_key' => 'test_creative_materials',
                'riasec_dimensions' => ['A', 'I'],
                'activity_label' => ['en' => 'Test creative materials', 'zh-CN' => '验证创作材料'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy checking whether a creative output really communicates what it should.',
                    'zh-CN' => '你可能喜欢验证一个创作输出是否真的传达了该传达的内容。',
                ],
                'task_examples' => [
                    '收集读者或用户理解反馈。',
                    '比较不同版本的表达效果。',
                    '用证据改写标题、结构或视觉重点。',
                ],
                'occupation_examples' => [
                    ['name' => '内容测试 / 编辑支持', 'common_tasks' => ['比较版本', '整理反馈', '修改结构']],
                    ['name' => '用户体验研究助理', 'common_tasks' => ['观察理解', '记录问题', '提出调整']],
                    ['name' => '创意策略助理', 'common_tasks' => ['整理洞察', '测试表达', '准备说明']],
                ],
                'next_experiments' => [
                    '做两个版本的说明材料。',
                    '记录别人理解错在哪里。',
                    '用反馈改写一个标题。',
                ],
            ],
        ], $locale);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cseActivities(string $locale): array
    {
        return $this->normalizeActivities([
            [
                'activity_key' => 'facilitate_structured_action',
                'riasec_dimensions' => ['C', 'S', 'E'],
                'activity_label' => ['en' => 'Facilitate structured action', 'zh-CN' => '组织清晰行动'],
                'activity_user_copy' => [
                    'en' => 'You may like helping groups move from discussion to clear next steps.',
                    'zh-CN' => '你可能喜欢帮助一群人从讨论进入清楚的下一步。',
                ],
                'task_examples' => [
                    '把讨论整理成决定、负责人和时间点。',
                    '提醒边界、风险和未确认事项。',
                    '跟进下一步是否被执行。',
                ],
                'occupation_examples' => [
                    ['name' => '项目助理 / PMO 支持', 'common_tasks' => ['整理会议', '追踪任务', '提醒风险']],
                    ['name' => '培训运营支持', 'common_tasks' => ['组织学员', '维护流程', '收集反馈']],
                    ['name' => '客户成功运营', 'common_tasks' => ['整理需求', '协调动作', '复盘结果']],
                ],
                'next_experiments' => [
                    '把一次讨论整理成行动清单。',
                    '为一个小组任务建立跟进表。',
                    '练习在会议后写 5 行纪要。',
                ],
            ],
            [
                'activity_key' => 'maintain_service_workflows',
                'riasec_dimensions' => ['C', 'S'],
                'activity_label' => ['en' => 'Maintain service workflows', 'zh-CN' => '维护服务流程'],
                'activity_user_copy' => [
                    'en' => 'You may enjoy keeping people-facing processes clear, consistent, and responsive.',
                    'zh-CN' => '你可能喜欢让面向人的流程保持清楚、稳定、能回应问题。',
                ],
                'task_examples' => [
                    '维护服务记录和处理进度。',
                    '把重复问题整理成标准步骤。',
                    '根据实际反馈调整流程说明。',
                ],
                'occupation_examples' => [
                    ['name' => '服务流程运营', 'common_tasks' => ['维护记录', '整理问题', '优化步骤']],
                    ['name' => '行政 / 学务支持', 'common_tasks' => ['处理申请', '整理材料', '跟进反馈']],
                    ['name' => '社群运营支持', 'common_tasks' => ['维护规则', '回应问题', '整理活动']],
                ],
                'next_experiments' => [
                    '为一个服务流程写标准步骤。',
                    '把 10 个问题整理成 FAQ。',
                    '检查一次流程说明是否能被新用户执行。',
                ],
            ],
        ], $locale);
    }

    /**
     * @param  list<array<string,mixed>>  $activities
     * @return list<array<string,mixed>>
     */
    private function normalizeActivities(array $activities, string $locale): array
    {
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
