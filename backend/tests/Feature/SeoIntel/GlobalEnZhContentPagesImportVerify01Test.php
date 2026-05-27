<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesImportVerify01Test extends TestCase
{
    #[Test]
    public function content_page_import_verify_artifact_preserves_closed_gates(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-import-verify-01.v1.json');

        $this->assertFileExists(base_path('docs/seo/global-en-zh-content-pages-import-verify-01.md'));
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-content-pages-import-verify-01.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-IMPORT-VERIFY-01', $generated['task'] ?? null);

        $importedRecords = $generated['imported_records'] ?? [];
        sort($importedRecords);

        $this->assertSame([
            'brand',
            'careers',
            'charter',
            'foundation',
            'policies',
        ], $importedRecords);

        $this->assertNotEmpty($generated['final_decision'] ?? null);
        $this->assertNotEmpty($generated['next_task'] ?? null);

        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_sitemap_llms_exposure'] ?? false);
        $this->assertTrue($generated['no_footer_nav_exposure'] ?? false);

        $this->assertTrue($generated['cms_record_state']['all_exist'] ?? false);
        $this->assertTrue($generated['cms_record_state']['all_draft_only'] ?? false);
        $this->assertTrue($generated['cms_record_state']['all_non_public'] ?? false);
        $this->assertTrue($generated['cms_record_state']['all_non_indexable'] ?? false);
        $this->assertTrue($generated['cms_record_state']['all_published_at_null'] ?? false);
        $this->assertTrue($generated['existing_published_records_check']['all_still_published_public_indexable'] ?? false);
        $this->assertFalse($generated['existing_published_records_check']['mutation_detected'] ?? true);
        $this->assertTrue($generated['public_runtime_check']['all_draft_paths_not_200'] ?? false);
        $this->assertTrue($generated['sitemap_check']['draft_paths_absent'] ?? false);
        $this->assertTrue($generated['llms_check']['llms_txt']['draft_paths_absent'] ?? false);
        $this->assertTrue($generated['llms_check']['llms_full_txt']['draft_paths_absent'] ?? false);
        $this->assertTrue($generated['footer_nav_check']['draft_links_absent'] ?? false);
        $this->assertTrue($generated['search_channel_check']['no_queue_items_for_imported_drafts'] ?? false);
        $this->assertTrue($generated['search_channel_check']['gates_closed'] ?? false);
    }
}
