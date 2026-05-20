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
        private readonly RiasecLifecycleCopyService $lifecycleCopy,
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
                'lifecycle_copy_v1' => $this->lifecycleCopy->lifecycleCopyContract(),
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
        $summaryByTitle = [];
        foreach ($this->lifecycleCopy->technicalNoteSummarySections() as $section) {
            $summaryByTitle[$section['title']] = $section['copy'];
        }

        $sections = [];
        $mapping = [
            '这个测试测什么' => [
                'section_key' => 'test_goal',
                'data_status' => 'currently_operational',
            ],
            '这个测试不测什么' => [
                'section_key' => 'measurement_boundary',
                'data_status' => 'currently_operational',
            ],
            '如何读分数' => [
                'section_key' => 'score_space_boundary',
                'data_status' => 'currently_operational',
            ],
            '60Q 和 140Q 的关系' => [
                'section_key' => 'riasec_140_context',
                'data_status' => 'currently_operational',
            ],
            '如何读职业例子' => [
                'section_key' => 'career_examples_boundary',
                'data_status' => 'partial',
            ],
            '反馈如何使用' => [
                'section_key' => 'feedback_boundary',
                'data_status' => 'planned',
            ],
        ];

        foreach ($mapping as $title => $meta) {
            $body = trim((string) ($summaryByTitle[$title] ?? ''));
            if ($body === '') {
                continue;
            }

            $sections[] = [
                'section_key' => $meta['section_key'],
                'title' => $title,
                'body' => $body,
                'data_status' => $meta['data_status'],
            ];
        }

        $sections[] = [
            'section_key' => 'quality_boundary',
            'title' => '质量边界',
            'body' => '60Q 当前只声明最小答题完成规则，不输出强 low_quality 判断。',
            'data_status' => 'currently_operational',
        ];
        $sections[] = [
            'section_key' => 'snapshot_boundary',
            'title' => '报告快照',
            'body' => '正式报告必须绑定生成时的 snapshot，后续 share、PDF、history 应读取同一份可审计结果。',
            'data_status' => 'currently_operational',
        ];

        return $sections;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function methodBoundaries(): array
    {
        $sourceByKey = [];
        foreach ($this->lifecycleCopy->professionalMethodBoundarySections() as $section) {
            $sourceByKey[$section['key']] = $section;
        }

        return [
            'career_interest_only' => [
                'label' => (string) ($sourceByKey['measurement_object']['title'] ?? '测量对象'),
                'copy' => (string) ($sourceByKey['measurement_object']['body'] ?? ''),
                'evidence_level' => 'runtime_contract',
                'content_maturity' => 'v0.1',
            ],
            'not_career_recommendation' => [
                'label' => (string) ($sourceByKey['examples']['title'] ?? '职业例子边界'),
                'copy' => (string) ($sourceByKey['examples']['body'] ?? ''),
                'evidence_level' => 'method_boundary',
                'content_maturity' => 'v0.1',
            ],
            'same_scale_not_same_score_space' => [
                'label' => (string) ($sourceByKey['score_space']['title'] ?? '分数空间'),
                'copy' => 'riasec_60 与 riasec_140 同属 RIASEC，但 raw score space 不同，不默认比较 raw score delta。',
                'evidence_level' => 'measurement_contract',
                'content_maturity' => 'v0.1',
            ],
            'riasec_140_contextual_not_more_accurate' => [
                'label' => (string) ($sourceByKey['forms']['title'] ?? '60Q 与 140Q'),
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
                'label' => (string) ($sourceByKey['feedback']['title'] ?? '反馈边界'),
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
