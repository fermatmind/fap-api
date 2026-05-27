<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesControlledCmsImport01Test extends TestCase
{
    #[Test]
    public function controlled_content_page_import_report_preserves_gates(): void
    {
        $reportPath = base_path('docs/seo/global-en-zh-content-pages-controlled-cms-import-01.md');
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-controlled-cms-import-01.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-content-pages-controlled-cms-import-01.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-CMS-IMPORT-01', $generated['task'] ?? null);
        $this->assertSame('content_pages_controlled_cms_import_completed_with_sidecars', $generated['final_decision'] ?? null);
        $this->assertTrue($generated['approval_phrase_verified'] ?? false);

        $preflight = $generated['preflight'] ?? [];
        $this->assertTrue($preflight['package_exists_and_parses'] ?? false);
        $this->assertTrue($preflight['review_packet_exists_and_parses'] ?? false);
        $this->assertTrue($preflight['every_import_target_in_allowed_scope'] ?? false);
        $this->assertTrue($preflight['blocked_or_deferred_items_excluded'] ?? false);
        $this->assertTrue($preflight['publish_will_not_occur'] ?? false);
        $this->assertSame(0, $preflight['sitemap_eligible_true_count'] ?? null);
        $this->assertSame(0, $preflight['llms_eligible_true_count'] ?? null);
        $this->assertSame(0, $preflight['footer_eligible_true_count'] ?? null);
        $this->assertFalse($preflight['search_channel_action_planned'] ?? true);
        $this->assertFalse($preflight['out_of_scope_cms_write_planned'] ?? true);
        $this->assertSame('content-pages:import-local-baseline', $preflight['official_import_runtime'] ?? null);
        $this->assertTrue($preflight['official_import_runtime_supports_dry_run'] ?? false);
        $this->assertTrue($preflight['upsert_disabled_to_protect_existing_published_records'] ?? false);

        $this->assertTrue($generated['dry_run']['passed'] ?? false);
        $this->assertSame(5, $generated['dry_run']['summary']['will_create'] ?? null);
        $this->assertSame(0, $generated['dry_run']['summary']['will_update'] ?? null);
        $this->assertSame(6, $generated['dry_run']['summary']['will_skip'] ?? null);

        $this->assertTrue($generated['controlled_import']['performed'] ?? false);
        $this->assertSame(5, $generated['controlled_import']['summary']['will_create'] ?? null);
        $this->assertSame(0, $generated['controlled_import']['summary']['will_update'] ?? null);
        $this->assertSame(6, $generated['controlled_import']['summary']['will_skip'] ?? null);

        $createdDraftRecords = $generated['created_draft_records'] ?? [];
        sort($createdDraftRecords);
        $this->assertSame([
            'brand',
            'careers',
            'charter',
            'foundation',
            'policies',
        ], $createdDraftRecords);

        $skippedExistingRecords = $generated['skipped_existing_records'] ?? [];
        sort($skippedExistingRecords);
        $this->assertSame([
            'about',
            'help-about',
            'help-contact',
            'help-faq',
            'help-for-business-and-research',
            'method-boundaries',
        ], $skippedExistingRecords);

        $excluded = array_column($generated['excluded_items'] ?? [], 'asset_key');
        $this->assertContains('support', $excluded);
        $this->assertContains('privacy', $excluded);
        $this->assertContains('terms', $excluded);

        $verification = $generated['post_import_verification'] ?? [];
        $this->assertTrue($verification['created_records_exist'] ?? false);
        $this->assertTrue($verification['created_records_not_published'] ?? false);
        $this->assertTrue($verification['created_records_not_public'] ?? false);
        $this->assertTrue($verification['created_records_not_indexable'] ?? false);
        $this->assertTrue($verification['created_records_translation_status_draft'] ?? false);
        $this->assertTrue($verification['created_records_review_state_draft'] ?? false);
        $this->assertTrue($verification['existing_published_records_not_mutated_by_upsert'] ?? false);
        $this->assertTrue($verification['new_draft_public_runtime_not_200'] ?? false);
        $this->assertTrue($verification['sitemap_eligible_false_for_created'] ?? false);
        $this->assertTrue($verification['llms_eligible_false_for_created'] ?? false);
        $this->assertTrue($verification['footer_eligible_false_for_created'] ?? false);
        $this->assertTrue($verification['search_channel_unchanged'] ?? false);
        $this->assertTrue($verification['gates_closed'] ?? false);

        foreach ($generated['post_import_records'] ?? [] as $record) {
            if (in_array($record['slug'] ?? '', $generated['created_draft_records'] ?? [], true)) {
                $this->assertSame('draft', $record['status'] ?? null);
                $this->assertFalse($record['is_public'] ?? true);
                $this->assertFalse($record['is_indexable'] ?? true);
            }
        }

        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_sitemap_llms_footer_exposure'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_production_user_data_access'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-IMPORT-VERIFY-01', $generated['next_task'] ?? null);
    }
}
