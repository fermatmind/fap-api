<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhContentPagesTranslationBatch01Test extends TestCase
{
    #[Test]
    public function content_pages_translation_package_is_draft_only_and_review_gated(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-content-pages-translation-batch-01.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-content-pages-translation-batch-01.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-content-pages-translation-batch-01.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-CONTENT-PAGES-TRANSLATION-BATCH-01', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-content-pages-translation-batch-01.import.v1', $package['schema_version'] ?? null);
        $this->assertSame('draft_import_only', $package['package_type'] ?? null);

        $items = $package['items'] ?? [];
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $assetKeys = array_column($items, 'asset_key');
        foreach ([
            'brand',
            'charter',
            'foundation',
            'careers',
            'policies',
            'support',
            'about',
            'help-about',
            'help-contact',
            'help-faq',
            'help-for-business-and-research',
            'method-boundaries',
        ] as $expectedAssetKey) {
            $this->assertContains($expectedAssetKey, $assetKeys);
        }

        foreach ($items as $item) {
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertFalse($item['sitemap_eligible'] ?? true);
            $this->assertFalse($item['llms_eligible'] ?? true);
            $this->assertFalse($item['footer_eligible'] ?? true);
            $this->assertFalse($item['search_channel_eligible'] ?? true);
            $this->assertContains($item['publish_state'] ?? null, ['draft_import_only', 'deferred_missing_authority']);
        }

        $translatedKeys = $generated['translated_asset_keys'] ?? [];
        $this->assertContains('brand', $translatedKeys);
        $this->assertContains('charter', $translatedKeys);
        $this->assertContains('foundation', $translatedKeys);
        $this->assertContains('careers', $translatedKeys);
        $this->assertContains('policies', $translatedKeys);

        $deferredItems = $generated['deferred_items'] ?? [];
        $this->assertContains('support', array_column($deferredItems, 'asset_key'));

        $this->assertSame(0, $generated['sitemap_eligible_count'] ?? null);
        $this->assertSame(0, $generated['llms_eligible_count'] ?? null);
        $this->assertSame(0, $generated['footer_eligible_count'] ?? null);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
    }
}
