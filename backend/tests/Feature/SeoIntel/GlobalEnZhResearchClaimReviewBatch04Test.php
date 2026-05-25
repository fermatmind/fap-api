<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhResearchClaimReviewBatch04Test extends TestCase
{
    #[Test]
    public function research_claim_review_package_blocks_schema_publish_and_overclaims(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-research-claim-review-batch-04.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-research-claim-review-batch-04.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-research-claim-review-batch-04.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-RESEARCH-CLAIM-REVIEW-BATCH-04', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-research-claim-review-batch-04.import.v1', $package['schema_version'] ?? null);
        $this->assertSame('research_claim_review_only', $package['package_type'] ?? null);

        $items = $package['items'] ?? [];
        $this->assertCount(2, $items);
        $this->assertContains('mbti-salary-turnover-report', array_column($items, 'source_key'));
        $this->assertContains('research-report-catalog', array_column($items, 'source_key'));

        foreach ($items as $item) {
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertTrue($item['claim_review_required'] ?? false);
            $this->assertFalse($item['dataset_schema_eligible'] ?? true);
            $this->assertFalse($item['article_schema_eligible'] ?? true);
            $this->assertFalse($item['sitemap_eligible'] ?? true);
            $this->assertFalse($item['llms_eligible'] ?? true);
            $this->assertFalse($item['search_channel_eligible'] ?? true);
            $this->assertFalse($item['digital_pr_eligible'] ?? true);
            $this->assertFalse($item['pseo_eligible'] ?? true);
            $this->assertNotEmpty($item['visible_grounding_requirements'] ?? []);
            $this->assertNotEmpty($item['required_disclaimer_caveat'] ?? '');
            $this->assertContains($item['publish_state'] ?? null, ['deferred_claim_review', 'deferred_missing_authority']);
        }

        $mbtiItem = array_values(array_filter($items, fn (array $item): bool => ($item['source_key'] ?? null) === 'mbti-salary-turnover-report'))[0];
        $this->assertSame('deferred_claim_review', $mbtiItem['publish_state'] ?? null);
        $this->assertContains('MBTI predicts individual salary', $mbtiItem['forbidden_claim_risks'] ?? []);
        $this->assertContains('MBTI predicts individual turnover', $mbtiItem['forbidden_claim_risks'] ?? []);

        $this->assertSame(0, $generated['dataset_schema_eligible_count'] ?? null);
        $this->assertSame(0, $generated['article_schema_eligible_count'] ?? null);
        $this->assertSame(0, $generated['sitemap_eligible_count'] ?? null);
        $this->assertSame(0, $generated['llms_eligible_count'] ?? null);
        $this->assertSame(0, $generated['search_channel_eligible_count'] ?? null);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-CAREER-CONTENT-BATCH-05', $generated['next_task'] ?? null);
    }
}
