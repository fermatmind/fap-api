<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti03aClaimLintGateTest extends TestCase
{
    #[Test]
    public function claim_lint_gate_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-03a-claim-lint-gate.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-03a-claim-lint-gate.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-03A', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-03B', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function gate_is_required_before_search_digital_pr_content_and_review(): void
    {
        foreach (['search_channel_planning', 'digital_pr_wave_2', 'content_internal_link_wave_1', 'growth_experiment_review'] as $gate) {
            $this->assertContains($gate, $this->artifact()['required_before'] ?? []);
        }
    }

    #[Test]
    public function required_mbti_surfaces_are_covered(): void
    {
        foreach (['mbti_research_salary_turnover_report', 'mbti_test_page_metadata_faq_jsonld', 'mbti_result_report_paywall_copy', 'mbti_topic_article_snippets', 'internal_link_anchor_text', 'digital_pr_pitch_copy_if_later_used', 'career_job_fit_adjacent_mbti_wording'] as $surface) {
            $this->assertContains($surface, $this->artifact()['surfaces'] ?? []);
        }
    }

    #[Test]
    public function forbidden_claims_and_allowed_bounded_language_are_locked(): void
    {
        $artifact = $this->artifact();
        foreach (['MBTI决定收入', 'MBTI预测离职', '薪资保证', '个人离职预测', '招聘适配', '职业成功预测', '精准职业推荐', '最适合职业', 'AI 职业规划', '岗位胜任力', '诊断', '确诊', '治疗', '治愈'] as $claim) {
            $this->assertContains($claim, $artifact['forbidden_claims'] ?? []);
        }
        foreach (['模型化指数', '聚合层面', '方向性趋势', '非诊断', '结果仅供参考', '职业方向参考', '工作方式倾向', '探索建议', 'discussion resource', 'modeled signal', 'aggregate trend', 'not hiring advice', 'not salary guarantee', 'not individual prediction'] as $language) {
            $this->assertContains($language, $artifact['allowed_bounded_language'] ?? []);
        }
    }

    #[Test]
    public function states_severity_and_gate_rules_are_explicit(): void
    {
        $artifact = $this->artifact();
        foreach (['safe', 'needs_review', 'blocked'] as $state) {
            $this->assertContains($state, $artifact['states'] ?? []);
        }
        foreach (['P0', 'P1', 'P2', 'P3'] as $severity) {
            $this->assertArrayHasKey($severity, $artifact['severity_mapping'] ?? []);
        }
        foreach (['claim_lint_flags_or_blocks_must_not_auto_rewrite', 'claim_unsafe_urls_cannot_enter_search_channel_planning', 'claim_unsafe_pitch_copy_cannot_enter_digital_pr_wave_2', 'claim_unsafe_content_internal_link_candidates_cannot_enter_wave_1', 'no_precise_recommender_hiring_salary_or_career_success_overclaims_for_mbti_big_five_riasec_career_graph'] as $rule) {
            $this->assertContains($rule, $artifact['gate_rules'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prevent_runtime_scan_rewrite_and_mutation(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_auto_rewrite_and_next_task(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-03a-claim-lint-gate.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-03a-claim-lint-gate.v1.json')));
        foreach (['must not auto-rewrite', 'does not scan production content', 'does not mutate cms', 'does not modify fap-web', 'does not send digital pr', 'seo-growth-mbti-03b'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-03a-claim-lint-gate.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
