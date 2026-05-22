<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelChineseClaimLinterRuntimeContractTest extends TestCase
{
    #[Test]
    public function contract_file_and_generated_artifact_exist_and_parse(): void
    {
        $this->assertFileExists(base_path('docs/seo/chinese-claim-linter-runtime-contract.md'));

        $artifact = $this->artifact();

        $this->assertSame('chinese-claim-linter-runtime-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('CLAIM-LINT-01A', $artifact['task'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('CLAIM-LINT-01B', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function candidate_surfaces_and_states_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'article_title_excerpt_body',
            'research_report_title_body_methodology_disclaimer_faq_excerpt',
            'seo_metadata',
            'faq',
            'json_ld_text',
            'llms_ai_answer_surfaces',
            'cta_copy',
            'career_guide_copy',
            'career_job_copy',
            'career_recommendation_copy',
            'big_five_surfaces',
            'riasec_surfaces',
            'career_graph_surfaces',
            'iq_eq_surfaces',
            'mbti_surfaces',
            'test_detail_surfaces',
        ] as $surface) {
            $this->assertContains($surface, $artifact['candidate_input_surfaces'] ?? []);
        }

        $this->assertSame(['safe', 'needs_review', 'blocked'], $artifact['states'] ?? []);
    }

    #[Test]
    public function forbidden_and_bounded_chinese_phrases_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            '精准职业推荐',
            '最适合职业',
            'AI 职业规划',
            '岗位胜任力',
            '招聘适配',
            '职业成功率',
            '薪资保证',
            '个人离职预测',
            'MBTI决定收入',
            'MBTI预测离职',
            'Big Five职业精准匹配',
            'RIASEC推荐职业',
            '智商真实测量',
            '临床诊断',
            '诊断',
            '确诊',
            '治疗',
            '治愈',
            '心理疾病判断',
        ] as $phrase) {
            $this->assertContains($phrase, $artifact['forbidden_or_flagged_phrases'] ?? []);
        }

        foreach ([
            '职业方向参考',
            '兴趣信号',
            '工作方式倾向',
            '探索建议',
            '非诊断',
            '结果仅供参考',
            '自评筛查',
            '模型化指数',
            '聚合层面',
            '方向性趋势',
            'snapshot-based support',
            'evidence-backed explanation',
        ] as $phrase) {
            $this->assertContains($phrase, $artifact['allowed_bounded_phrases'] ?? []);
        }
    }

    #[Test]
    public function severity_mapping_and_claim_boundaries_block_overclaims(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('public_indexable_claim_unsafe_pages', $artifact['severity_mapping']['P0'] ?? null);
        $this->assertSame('high_risk_seo_metadata_faq_llms_ai_answer_json_ld_claim_risk', $artifact['severity_mapping']['P1'] ?? null);
        $this->assertSame('draft_article_body_caution_needed', $artifact['severity_mapping']['P2'] ?? null);
        $this->assertSame('informational_wording_drift_or_non_public_warning', $artifact['severity_mapping']['P3'] ?? null);

        foreach ([
            'mbti_determines_salary_allowed',
            'mbti_predicts_individual_turnover_allowed',
            'mbti_causes_income_allowed',
            'mbti_hiring_or_promotion_tool_allowed',
            'hiring_suitability_by_mbti_allowed',
            'best_fit_jobs_by_mbti_allowed',
            'precise_career_recommendation_allowed',
            'salary_guarantee_allowed',
            'individual_prediction_allowed',
            'clinical_or_diagnostic_framing_allowed',
            'exact_iq_or_real_intelligence_measurement_allowed',
            'big_five_precise_job_matching_allowed',
            'riasec_precise_job_matching_allowed',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact['claim_boundaries'][$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function safety_flags_keep_contract_non_mutating(): void
    {
        $artifact = $this->artifact();

        $this->assertTrue($artifact['runtime_requirements']['ci_readable_json'] ?? false);
        $this->assertTrue($artifact['runtime_requirements']['matched_rule_evidence_without_private_data'] ?? false);
        $this->assertTrue($artifact['runtime_requirements']['fail_closed_for_public_indexable_claim_unsafe'] ?? false);
        $this->assertTrue($artifact['runtime_requirements']['severity_mapping_required'] ?? false);

        foreach ([
            'runtime_enforcement_activated_in_this_pr',
            'cms_content_mutated_in_this_pr',
            'auto_rewrite_enabled',
            'auto_publish_enabled',
            'fap_web_modified',
            'search_channel_enqueue_enabled',
            'url_submission_enabled',
            'observation_queue_write_enabled',
            'seo_intel_write_enabled',
            'sitemap_changed',
            'llms_changed',
            'production_content_scan_enabled',
            'scheduler_enabled',
            'env_edited',
            'deployment_performed',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact['safety_flags'][$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_runtime_contract_without_activation(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/chinese-claim-linter-runtime-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/chinese-claim-linter-runtime-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'docs/generated/test only',
            'does not activate runtime enforcement',
            'must not scan production content by default',
            'safe',
            'needs_review',
            'blocked',
            'p0',
            'p1',
            'p2',
            'p3',
            '精准职业推荐',
            'mbti决定收入',
            'big five职业精准匹配',
            'riasec推荐职业',
            '职业方向参考',
            '模型化指数',
            'auto-rewrite content',
            'auto-publish content',
            'next task: `claim-lint-01b`',
            '"next_task": "claim-lint-01b"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('docs/seo/generated/chinese-claim-linter-runtime-contract.v1.json')), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
