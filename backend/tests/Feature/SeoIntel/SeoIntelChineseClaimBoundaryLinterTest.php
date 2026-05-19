<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelChineseClaimBoundaryLinterTest extends TestCase
{
    #[Test]
    public function artifact_locks_forbidden_chinese_claim_classes(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('chinese-claim-boundary-linter.v1', $artifact['version'] ?? null);
        $this->assertContains('PR-RESEARCH-00', $artifact['source_documents'] ?? []);

        foreach ([
            '诊断',
            '确诊',
            '治疗',
            '治愈',
            '真实智商',
            '权威认证',
            '精准推荐',
            '最适合职业',
            '岗位胜任力',
            '招聘适配',
            '职业成功率',
            'AI 职业规划',
            '心理疾病判断',
        ] as $claim) {
            $this->assertContains($claim, $artifact['forbidden_claim_classes'] ?? []);
        }
    }

    #[Test]
    public function artifact_lists_allowed_safer_wording_without_guarantees(): void
    {
        $wording = $this->artifact()['allowed_safer_wording'] ?? [];

        foreach ([
            '自评筛查',
            '非诊断',
            '结果仅供参考',
            '在线估测',
            '置信区间',
            '职业方向参考',
            '探索建议',
            '兴趣信号',
            '工作方式倾向',
            'snapshot-based support',
        ] as $phrase) {
            $this->assertContains($phrase, $wording);
        }
    }

    #[Test]
    public function claim_boundaries_do_not_expand_career_or_medical_claims(): void
    {
        $boundaries = $this->artifact()['claim_boundaries'] ?? [];

        foreach ([
            'riasec_full_recommendation_claims_allowed',
            'big_five_full_recommendation_claims_allowed',
            'career_decision_full_recommendation_claims_allowed',
            'medical_diagnosis_claims_allowed',
            'hiring_fit_claims_allowed',
            'exact_iq_claims_allowed',
            'guaranteed_career_outcome_claims_allowed',
        ] as $flag) {
            $this->assertFalse((bool) ($boundaries[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function unsafe_claims_block_search_and_publish_surfaces(): void
    {
        $blocked = $this->artifact()['blocked_page_eligibility_when_claim_unsafe'] ?? [];

        foreach ([
            'sitemap',
            'llms',
            'search_channel_queue',
            'baidu_push',
            'indexnow',
            'so360',
            'sogou',
            'shenma',
        ] as $surface) {
            $this->assertContains($surface, $blocked);
        }
    }

    #[Test]
    public function no_runtime_activation_or_content_mutation_happens_in_this_pr(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'runtime_linter_activated_in_this_pr',
            'frontend_runtime_copy_changed_in_this_pr',
            'cms_content_mutated_in_this_pr',
            'auto_publish_enabled_in_this_pr',
            'sitemap_changed_in_this_pr',
            'llms_changed_in_this_pr',
            'url_submission_performed',
            'production_write_execution',
            'scheduler_enabled_in_this_pr',
            'external_api_live_activation',
            'env_edit_in_this_pr',
            'metabase_deployed_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_boundaries_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/chinese-claim-boundary-linter.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/chinese-claim-boundary-linter.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'docs/spec/test only',
            'does not activate runtime linting',
            '精准推荐',
            '最适合职业',
            '职业成功率',
            '结果仅供参考',
            '职业方向参考',
            'riasec and big five career semantics remain shallow/partial assets',
            'must not auto-publish',
            'next task: content-ops-01',
            '"next_task": "content-ops-01"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/chinese-claim-boundary-linter.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
