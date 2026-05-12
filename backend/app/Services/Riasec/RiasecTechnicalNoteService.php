<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use Carbon\CarbonImmutable;

final class RiasecTechnicalNoteService
{
    public const SCHEMA_VERSION = 'riasec.technical_note.v1';

    public const TECHNICAL_NOTE_VERSION = 'riasec_technical_note.v0.1';

    public const METHOD_BOUNDARY_VERSION = 'riasec.method_boundary.v0.1';

    public function __construct(
        private readonly RiasecMeasurementContract $measurementContract,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        $standard = $this->measurementContract->forFormCode('riasec_60', 60);
        $enhanced = $this->measurementContract->forFormCode('riasec_140', 140);

        return [
            'technical_note_v1' => [
                'schema_version' => self::SCHEMA_VERSION,
                'scale_code' => 'RIASEC',
                'technical_note_version' => self::TECHNICAL_NOTE_VERSION,
                'measurement_contract_version' => RiasecMeasurementContract::SCHEMA_VERSION,
                'method_boundary_version' => self::METHOD_BOUNDARY_VERSION,
                'sections' => $this->sections(),
                'method_boundaries' => $this->methodBoundaries(),
                'form_contracts' => [
                    'riasec_60' => [
                        'form_code' => 'riasec_60',
                        'question_count' => 60,
                        'score_space_version' => data_get($standard, 'form.score_space_version'),
                        'normalization_method' => data_get($standard, 'scoring.normalization_method'),
                        'quality_rule_status' => data_get($standard, 'quality.quality_rule_status'),
                        'low_quality_strength' => data_get($standard, 'quality.low_quality_strength'),
                        'cross_form_comparable' => false,
                        'raw_score_delta_allowed' => false,
                    ],
                    'riasec_140' => [
                        'form_code' => 'riasec_140',
                        'question_count' => 140,
                        'score_space_version' => data_get($enhanced, 'form.score_space_version'),
                        'normalization_method' => data_get($enhanced, 'scoring.normalization_method'),
                        'quality_rule_status' => data_get($enhanced, 'quality.quality_rule_status'),
                        'low_quality_strength' => data_get($enhanced, 'quality.low_quality_strength'),
                        'cross_form_comparable' => false,
                        'raw_score_delta_allowed' => false,
                    ],
                ],
                'data_status_summary' => [
                    'currently_operational' => [
                        '60q_scoring_v1',
                        'projection_v2_minimal',
                        'score_space_version',
                        'compare_policy',
                        'snapshot_bound_report',
                    ],
                    'partial' => [
                        'activity_explorer_v0_1',
                        'pdf_share_history_snapshot_surfaces',
                    ],
                    'not_claimed' => [
                        'ability',
                        'personality',
                        'values',
                        'career_success_probability',
                        'job_fit',
                        'hiring_screening_suitability',
                        'career_registry_match',
                        'cross_form_raw_score_delta',
                    ],
                ],
                'disclaimers' => $this->disclaimers(),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function sections(): array
    {
        return [
            [
                'section_key' => 'test_goal',
                'title' => '测试目标',
                'body' => 'RIASEC 当前用于呈现职业兴趣线索，帮助用户理解自己更容易被哪些工作活动吸引。',
                'data_status' => 'currently_operational',
            ],
            [
                'section_key' => 'measurement_boundary',
                'title' => '测量边界',
                'body' => 'RIASEC 测职业兴趣，不测能力、人格、价值观、雇佣筛选结论或长期职业结果。',
                'data_status' => 'currently_operational',
            ],
            [
                'section_key' => 'score_space_boundary',
                'title' => '分数空间',
                'body' => '60Q 与 140Q 属于同一 RIASEC scale，但使用不同 score_space_version，不默认比较 raw score delta。',
                'data_status' => 'currently_operational',
            ],
            [
                'section_key' => 'riasec_140_context',
                'title' => '140Q 边界',
                'body' => '140Q 可表达更具体的工作日常情境化兴趣线索，但不能被写成更准确答案。',
                'data_status' => 'currently_operational',
            ],
            [
                'section_key' => 'quality_boundary',
                'title' => '质量边界',
                'body' => '60Q 当前只声明最小答题完成规则，不输出强 low_quality 判断。',
                'data_status' => 'currently_operational',
            ],
            [
                'section_key' => 'snapshot_boundary',
                'title' => '报告快照',
                'body' => '正式报告必须绑定生成时的 snapshot，后续 share、PDF、history 应读取同一份可审计结果。',
                'data_status' => 'currently_operational',
            ],
            [
                'section_key' => 'career_examples_boundary',
                'title' => '职业例子边界',
                'body' => '没有 reviewed registry source 时，职业例子只能作为 content_example_not_registry_match 展示，不是职业匹配。',
                'data_status' => 'partial',
            ],
            [
                'section_key' => 'feedback_boundary',
                'title' => '反馈边界',
                'body' => 'Exploration feedback 只能作为后续探索覆盖层，不能改写 measured_holland_code 或 RIASEC 分数。',
                'data_status' => 'planned',
            ],
        ];
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function methodBoundaries(): array
    {
        return [
            'career_interest_only' => [
                'label' => '职业兴趣信号',
                'copy' => 'RIASEC 输出的是职业兴趣证据，不是能力、人格或价值观结论。',
                'evidence_level' => 'runtime_contract',
                'content_maturity' => 'v0.1',
            ],
            'not_career_recommendation' => [
                'label' => '非职业推荐器',
                'copy' => '结果页不能输出职业推荐、岗位适配评分、职业排名或长期职业结果判断。',
                'evidence_level' => 'method_boundary',
                'content_maturity' => 'v0.1',
            ],
            'same_scale_not_same_score_space' => [
                'label' => '同一 scale，不同分数空间',
                'copy' => 'riasec_60 与 riasec_140 同属 RIASEC，但 raw score space 不同，跨 form raw delta 默认关闭。',
                'evidence_level' => 'measurement_contract',
                'content_maturity' => 'v0.1',
            ],
            'riasec_140_contextual_not_more_accurate' => [
                'label' => '140Q 情境化边界',
                'copy' => '140Q 只能称为工作日常情境化兴趣线索，不能称为更准确答案。',
                'evidence_level' => 'method_boundary',
                'content_maturity' => 'v0.1',
            ],
            'content_examples_not_registry_match' => [
                'label' => 'Examples only',
                'copy' => '没有 reviewed registry source 时，occupation examples 必须标注 content_example_not_registry_match。',
                'evidence_level' => 'content_boundary',
                'content_maturity' => 'v0.1',
            ],
            'feedback_no_score_mutation' => [
                'label' => '反馈不改写测量结果',
                'copy' => '用户反馈只进入探索层，不会覆盖 measured_holland_code、维度分数或正式报告快照。',
                'evidence_level' => 'method_boundary',
                'content_maturity' => 'v0.1',
            ],
            'snapshot_bound_report' => [
                'label' => '正式报告快照绑定',
                'copy' => '正式报告、share、PDF、history 必须以 snapshot-bound report 为展示依据。',
                'evidence_level' => 'runtime_contract',
                'content_maturity' => 'v0.1',
            ],
            'quality_boundary_60q_minimal' => [
                'label' => '60Q 质量边界',
                'copy' => '60Q 当前没有强 low_quality rule，只能声明 minimal_answer_completion_only。',
                'evidence_level' => 'measurement_contract',
                'content_maturity' => 'v0.1',
            ],
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function disclaimers(): array
    {
        return [
            [
                'key' => 'not_ability_or_personality',
                'label' => '非能力/人格测量',
                'copy' => 'RIASEC 不测能力、人格、价值观或长期职业结果。',
            ],
            [
                'key' => 'not_hiring_screening',
                'label' => '非招聘筛选用途',
                'copy' => 'RIASEC 不用于招聘、晋升、淘汰或雇佣筛选。',
            ],
            [
                'key' => 'no_cross_form_raw_delta',
                'label' => '不比较跨 form raw delta',
                'copy' => '60Q 与 140Q 不默认比较 raw score delta。',
            ],
            [
                'key' => 'riasec_140_not_more_accurate',
                'label' => '140Q 非更准确声明',
                'copy' => '140Q 只能作为更具体的情境化兴趣线索。',
            ],
            [
                'key' => 'examples_not_matches',
                'label' => '职业例子不是匹配',
                'copy' => '职业例子是内容示例，不是职业数据库匹配或岗位推荐。',
            ],
            [
                'key' => 'feedback_overlay_boundary',
                'label' => '反馈层边界',
                'copy' => '反馈不会修改 measured_holland_code、分数或正式报告快照。',
            ],
        ];
    }
}
