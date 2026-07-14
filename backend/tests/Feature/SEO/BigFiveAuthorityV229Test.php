<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class BigFiveAuthorityV229Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-article-growth-change-29');
    }

    public function test_final_package_has_exact_batch_29_bilingual_inventory(): void
    {
        $package = $this->readJson('final-package.json');
        $this->assertCount(10, $package['assets']);
        $this->assertCount(5, array_filter($package['assets'], fn (array $asset): bool => $asset['locale'] === 'en'));
        $this->assertCount(5, array_filter($package['assets'], fn (array $asset): bool => $asset['locale'] === 'zh-CN'));
        $this->assertSame([29], array_values(array_unique(array_column($package['assets'], 'batch'))));
        $this->assertCount(5, array_unique(array_column($package['assets'], 'unique_intent_key')));
    }

    public function test_every_asset_has_complete_structure_and_change_boundary(): void
    {
        $package = $this->readJson('final-package.json');
        $required = ['direct_answer', 'evidence', 'nuance_counterexample', 'concrete_scenario', 'practical_framework', 'limitation', 'visible_sources', 'method_product_boundary', 'internal_links'];
        foreach ($package['assets'] as $asset) {
            $this->assertSame($required, array_column($asset['sections'], 'key'));
            $this->assertCount(2, $asset['source_mapping']);
            $this->assertCount(3, $asset['internal_link_targets']);
            $boundary = strtolower(collect($asset['sections'])->firstWhere('key', 'method_product_boundary')['body_md']);
            $terms = $asset['locale'] === 'en' ? ['group-level', 'personal prediction', 'score', 'unknown'] : ['群体', '个人预测', '分数', 'unknown'];
            foreach ($terms as $term) {
                $this->assertStringContainsString($term, $boundary);
            }
            $this->assertSame('pending_manual_review', $asset['review_status']);
            $this->assertNull($asset['reviewer']);
            $this->assertNull($asset['author']);
            $this->assertNull($asset['published_at']);
            $this->assertFalse($asset['cms_write_executed']);
            $this->assertFalse($asset['publish_state_change']);
            $this->assertFalse($asset['indexability_change']);
        }
    }

    public function test_review_chain_is_preserved(): void
    {
        $this->assertCount(10, $this->readJson('raw-drafts.json')['assets']);
        $this->assertCount(10, $this->readJson('skeptical-review.json')['reviews']);
        $this->assertCount(10, $this->readJson('repaired-drafts.json')['assets']);
        $this->assertCount(10, $this->readJson('source-mapping.json')['mappings']);
    }

    public function test_qa_proves_group_evidence_scope_and_zero_release_mutations(): void
    {
        $qa = $this->readJson('qa_report.json');
        $this->assertSame('PASS_PENDING_MANUAL_REVIEW', $qa['status']);
        $this->assertSame(['locked_themes' => 5, 'article_assets' => 10, 'en_assets' => 5, 'zh_cn_assets' => 5], $qa['counts']);
        $this->assertTrue($qa['checks']['consumes_only_pr21_batch_29']);
        $this->assertTrue($qa['checks']['group_evidence_not_personal_prediction']);
        $this->assertTrue($qa['checks']['behavior_tracking_not_score_optimization']);
        $this->assertTrue($qa['checks']['causality_and_guaranteed_change_prohibited']);
        $this->assertTrue($qa['checks']['private_result_links_excluded']);
        $this->assertSame(0, $qa['checks']['cms_writes']);
        $this->assertSame(0, $qa['checks']['published_assets']);
        $this->assertSame(0, $qa['checks']['indexability_changes']);
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packagePath.'/'.$file);
        $this->assertNotFalse($contents, "Unable to read {$file}");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
