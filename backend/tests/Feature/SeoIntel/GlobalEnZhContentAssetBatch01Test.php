<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentAssetBatch01Test extends TestCase
{
    #[Test]
    public function generated_content_asset_batch_records_non_mutating_import_boundaries(): void
    {
        $path = base_path('docs/seo/generated/global-en-zh-content-asset-batch-01.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-content-asset-batch-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-CONTENT-ASSET-BATCH-01', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['substantial_english_prose_generated'] ?? true));
        $this->assertFalse((bool) ($payload['draft_pages_exposed_in_sitemap_llms'] ?? true));
    }

    #[Test]
    public function content_batch_classifies_targets_and_keeps_drafts_out_of_discoverability(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/global-en-zh-content-asset-batch-01.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(12, $payload['target_summary']['targeted_content_help_policy_items'] ?? null);
        $this->assertSame(5, $payload['target_summary']['draft_review_only'] ?? null);
        $this->assertSame(2, $payload['target_summary']['deferred_missing_authority'] ?? null);
        $this->assertSame(0, $payload['target_summary']['published_by_this_pr'] ?? null);
        $this->assertSame(0, $payload['target_summary']['sitemap_llms_exposure_added_by_this_pr'] ?? null);

        $items = collect($payload['content_items'] ?? [])->keyBy('entity_key');

        foreach (['about', 'privacy', 'terms', 'help-about', 'help-contact', 'help-faq'] as $key) {
            $this->assertSame('authority_backed_pair_or_import_ready', $items[$key]['state'] ?? null);
        }

        foreach (['brand', 'careers', 'charter', 'foundation', 'policies'] as $key) {
            $this->assertSame('draft_review_only', $items[$key]['state'] ?? null);
            $this->assertFalse((bool) ($items[$key]['sitemap_llms_exposure_eligible'] ?? true));
        }

        $this->assertSame('deferred_missing_authority', $items['support']['state'] ?? null);
        $this->assertFalse((bool) ($items['support']['sitemap_llms_exposure_eligible'] ?? true));
    }

    #[Test]
    public function claim_and_exposure_guards_remain_closed(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/global-en-zh-content-asset-batch-01.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame([], $payload['forbidden_claim_hits'] ?? null);
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.draft_review_only_must_not_enter_sitemap'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.draft_review_only_must_not_enter_llms'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.deferred_missing_authority_must_not_enter_sitemap'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.deferred_missing_authority_must_not_enter_llms'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guard.frontend_fallback_must_not_be_counterpart'));
        $this->assertNotEmpty($payload['remaining_gaps'] ?? []);
        $this->assertNotEmpty($payload['recommended_next_tasks'] ?? []);
        $this->assertSame(
            'content_asset_batch_import_package_ready_with_human_review_deferred_assets',
            $payload['final_decision'] ?? null
        );
        $this->assertSame('GLOBAL-EN-ZH-PARITY-ARTICLE-COUNTERPART-BATCH-01', $payload['next_task'] ?? null);
    }
}
