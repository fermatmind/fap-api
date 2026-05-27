<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesHumanRevision01Test extends TestCase
{
    #[Test]
    public function content_page_human_revision_artifacts_exist_with_closed_gates(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-human-revision-01.v1.json');
        $revisionPackagePath = base_path('docs/seo/import-packages/global-en-zh-content-pages-human-revision-01.revision.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-human-revision-01.md'));
        $this->assertFileExists($generatedPath);
        $this->assertFileExists($revisionPackagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $revisionPackage = json_decode((string) file_get_contents($revisionPackagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-content-pages-human-revision-01.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-01', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-content-pages-human-revision-01.revision.v1', $revisionPackage['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-01', $revisionPackage['task'] ?? null);

        $targetPages = $generated['target_pages'] ?? [];
        sort($targetPages);

        $this->assertSame([
            'brand',
            'careers',
            'charter',
            'foundation',
            'policies',
        ], $targetPages);

        $this->assertIsArray($generated['per_page_revision_status'] ?? null);
        $this->assertCount(5, $generated['per_page_revision_status']);
        $this->assertIsArray($revisionPackage['items'] ?? null);
        $this->assertCount(5, $revisionPackage['items']);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_sitemap_llms_exposure'] ?? false);
        $this->assertTrue($generated['no_footer_nav_exposure'] ?? false);
        $this->assertTrue($generated['future_cms_draft_update_required'] ?? false);
        $this->assertNotEmpty($generated['final_decision'] ?? null);
        $this->assertNotEmpty($generated['next_task'] ?? null);
    }
}
