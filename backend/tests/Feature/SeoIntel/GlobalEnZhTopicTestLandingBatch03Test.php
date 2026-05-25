<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhTopicTestLandingBatch03Test extends TestCase
{
    #[Test]
    public function topic_test_landing_package_is_review_gated_and_does_not_create_placeholders(): void
    {
        $generatedPath = base_path('docs/seo/generated/global-en-zh-topic-test-landing-batch-03.v1.json');
        $packagePath = base_path('docs/seo/import-packages/global-en-zh-topic-test-landing-batch-03.import.v1.json');

        $this->assertFileExists($generatedPath);
        $this->assertFileExists($packagePath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-topic-test-landing-batch-03.v1', $generated['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-TOPIC-TEST-LANDING-BATCH-03', $generated['task'] ?? null);
        $this->assertSame('global-en-zh-topic-test-landing-batch-03.import.v1', $package['schema_version'] ?? null);
        $this->assertSame('draft_import_readiness_only', $package['package_type'] ?? null);

        $items = $package['items'] ?? [];
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $keys = array_column($items, 'topic_or_test_key');
        foreach ([
            'mbti',
            'big-five',
            'iq-eq',
            'riasec',
            'iq',
            'eq',
            'clinical-screening',
            'career',
            'personality',
            'mbti-personality-test-16-personality-types',
            'big-five-personality-test-ocean-model',
            'holland-career-interest-test-riasec',
            'iq-test-intelligence-quotient-assessment',
            'eq-test-emotional-intelligence-assessment',
            'depression-screening-test-standard-edition',
            'clinical-depression-anxiety-assessment-professional-edition',
            'enneagram-personality-test-nine-types',
        ] as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }

        foreach ($items as $item) {
            $this->assertTrue($item['human_review_required'] ?? false);
            $this->assertFalse($item['draft_package_sitemap_eligible'] ?? true);
            $this->assertFalse($item['draft_package_llms_eligible'] ?? true);
            $this->assertFalse($item['draft_package_footer_or_nav_eligible'] ?? true);
            $this->assertFalse($item['draft_package_jsonld_eligible'] ?? true);
            $this->assertFalse($item['search_channel_eligible'] ?? true);
            $this->assertIsArray($item['missing_en_blocks'] ?? null);
            $this->assertNotEmpty($item['jsonld_eligibility_notes'] ?? '');
            $this->assertNotEmpty($item['og_alt_draft_notes'] ?? '');
            $this->assertContains($item['publish_state'] ?? null, ['already_live_authority_review', 'deferred_missing_authority']);
        }

        $deferredKeys = array_column($generated['deferred_items'] ?? [], 'topic_or_test_key');
        foreach (['riasec', 'iq', 'eq', 'clinical-screening', 'career', 'personality'] as $deferredKey) {
            $this->assertContains($deferredKey, $deferredKeys);
        }

        $this->assertSame(0, $generated['generated_draft_copy_count'] ?? null);
        $this->assertGreaterThanOrEqual(6, $generated['deferred_missing_authority_count'] ?? 0);
        $this->assertSame(0, $generated['draft_package_sitemap_eligible_count'] ?? null);
        $this->assertSame(0, $generated['draft_package_llms_eligible_count'] ?? null);
        $this->assertSame(0, $generated['draft_package_jsonld_eligible_count'] ?? null);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertTrue($generated['no_pseo_generation'] ?? false);
        $this->assertTrue($generated['no_frontend_fallback_authority'] ?? false);
        $this->assertSame('GLOBAL-EN-ZH-RESEARCH-CLAIM-REVIEW-BATCH-04', $generated['next_task'] ?? null);
    }
}
