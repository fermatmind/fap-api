<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class BigFiveAuthorityV225Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-article-result-reading-25');
    }

    public function test_final_package_has_exact_batch_25_bilingual_inventory(): void
    {
        $package = $this->readJson('final-package.json');
        $this->assertCount(10, $package['assets']);
        $this->assertCount(5, array_filter($package['assets'], fn (array $asset): bool => $asset['locale'] === 'en'));
        $this->assertCount(5, array_filter($package['assets'], fn (array $asset): bool => $asset['locale'] === 'zh-CN'));
        $this->assertSame([25], array_values(array_unique(array_column($package['assets'], 'batch'))));
        $this->assertCount(5, array_unique(array_column($package['assets'], 'unique_intent_key')));
    }

    public function test_every_asset_is_evidence_mapped_and_fail_closed(): void
    {
        $package = $this->readJson('final-package.json');
        $required = ['direct_answer', 'evidence', 'nuance_counterexample', 'concrete_scenario', 'practical_framework', 'limitation', 'visible_sources', 'method_product_boundary', 'internal_links'];
        foreach ($package['assets'] as $asset) {
            $this->assertSame($required, array_column($asset['sections'], 'key'));
            $this->assertCount(3, $asset['source_mapping']);
            $this->assertCount(3, $asset['internal_link_targets']);
            $this->assertSame('pending_manual_review', $asset['review_status']);
            $this->assertNull($asset['reviewer']);
            $this->assertNull($asset['author']);
            $this->assertNull($asset['published_at']);
            $this->assertFalse($asset['cms_write_executed']);
            $this->assertFalse($asset['publish_state_change']);
            $this->assertFalse($asset['indexability_change']);
        }
    }

    public function test_raw_review_repair_final_and_source_artifacts_are_preserved(): void
    {
        $this->assertCount(10, $this->readJson('raw-drafts.json')['assets']);
        $this->assertCount(10, $this->readJson('skeptical-review.json')['reviews']);
        $this->assertCount(10, $this->readJson('repaired-drafts.json')['assets']);
        $this->assertCount(10, $this->readJson('source-mapping.json')['mappings']);
    }

    public function test_qa_proves_result_reading_scope_and_zero_release_mutations(): void
    {
        $qa = $this->readJson('qa_report.json');
        $this->assertSame('PASS_PENDING_MANUAL_REVIEW', $qa['status']);
        $this->assertSame(['locked_themes' => 5, 'article_assets' => 10, 'en_assets' => 5, 'zh_cn_assets' => 5], $qa['counts']);
        $this->assertTrue($qa['checks']['consumes_only_pr21_batch_25']);
        $this->assertTrue($qa['checks']['exact_locked_slug_locale_pairs']);
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
