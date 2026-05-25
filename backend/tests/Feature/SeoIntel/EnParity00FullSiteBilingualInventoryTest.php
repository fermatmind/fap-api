<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity00FullSiteBilingualInventoryTest extends TestCase
{
    #[Test]
    public function generated_inventory_artifact_lands_expected_read_only_baseline(): void
    {
        $path = base_path('docs/seo/generated/en-parity-00-full-site-bilingual-inventory.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('en-parity-00-full-site-bilingual-inventory.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-00', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_commit_performed'] ?? true));

        $this->assertSame(180, $payload['key_counts']['url_discovered'] ?? null);
        $this->assertSame(180, $payload['key_counts']['scanned_url_count'] ?? null);
        $this->assertSame(73, $payload['key_counts']['en_url_count'] ?? null);
        $this->assertSame(106, $payload['key_counts']['zh_url_count'] ?? null);
        $this->assertSame(1, $payload['key_counts']['unknown_url_count'] ?? null);

        $this->assertContains('https://fermatmind.com/en/about', $payload['hard_404_urls'] ?? []);
        $this->assertContains('https://fermatmind.com/en/support', $payload['hard_404_urls'] ?? []);
        $this->assertContains('https://fermatmind.com/en/help/about', $payload['soft_404_urls'] ?? []);
        $this->assertContains('https://fermatmind.com/zh/support', $payload['soft_404_urls'] ?? []);

        $this->assertSame(9, $payload['key_counts']['article_en_count'] ?? null);
        $this->assertSame(19, $payload['key_counts']['article_zh_count'] ?? null);
        $this->assertSame(10, $payload['key_counts']['missing_english_article_count'] ?? null);
        $this->assertContains('https://fermatmind.com/zh/articles/mbti-basics', $payload['missing_english_article_counterparts'] ?? []);

        $this->assertSame(0, $payload['key_counts']['career_guide_detail_en_count'] ?? null);
        $this->assertSame(20, $payload['key_counts']['career_guide_detail_zh_count'] ?? null);
        $this->assertSame(20, $payload['key_counts']['missing_english_career_guide_count'] ?? null);
        $this->assertContains('https://fermatmind.com/zh/career/guides/salary-negotiation-framework', $payload['missing_english_career_guide_counterparts'] ?? []);

        $this->assertSame(
            'included_as_p0_carryover_from_seo_growth_mbti_action_01d_scan_00',
            $payload['mbti_research_404_carryover']['status'] ?? null
        );
        $this->assertContains(
            'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report',
            $payload['mbti_research_404_carryover']['urls'] ?? []
        );

        $gapIds = array_column($payload['authority_evidence_gaps'] ?? [], 'id');
        $this->assertContains('chrome_batch_visual_gap', $gapIds);
        $this->assertContains('backend_url_truth_coverage_gap', $gapIds);
        $this->assertContains('internal_link_graph_local_mysql_gap', $gapIds);

        $this->assertSame('EN-PARITY-01 URL Truth / hard 404 / soft 404 / canonical baseline', $payload['next_task'] ?? null);
    }
}
