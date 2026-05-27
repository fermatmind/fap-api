<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesCmsDraftUpdate01Test extends TestCase
{
    #[Test]
    public function content_pages_cms_draft_update_report_exists_with_closed_publication_boundaries(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-cms-draft-update-01.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-cms-draft-update-01.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-CMS-DRAFT-UPDATE-01', $generated['task'] ?? null);

        $targetPages = $generated['target_pages'] ?? [];
        sort($targetPages);

        $this->assertSame([
            'brand',
            'careers',
            'charter',
            'foundation',
            'policies',
        ], $targetPages);

        $this->assertSame('planned_public_benefit_shareholding', $generated['foundation_fact_state'] ?? null);
        $this->assertTrue($generated['approval_phrase_verified'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_sitemap_llms_exposure'] ?? false);
        $this->assertTrue($generated['no_footer_nav_exposure'] ?? false);
        $this->assertTrue($generated['no_public_runtime_exposure'] ?? false);
        $this->assertNotEmpty($generated['final_decision'] ?? null);
        $this->assertNotEmpty($generated['next_task'] ?? null);
    }
}
