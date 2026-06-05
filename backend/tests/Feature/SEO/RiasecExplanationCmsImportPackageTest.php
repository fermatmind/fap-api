<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationCmsImportPackageTest extends TestCase
{
    public function test_cms_import_package_is_draft_only_for_two_locales(): void
    {
        $package = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-import-package.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-CMS-IMPORT-PACKAGE-01', $package['task_id']);
        $this->assertFalse($package['cms_draft_created']);
        $this->assertFalse($package['publish_allowed']);
        $this->assertFalse($package['search_submit_allowed']);
        $this->assertFalse($package['content_rewrite_performed']);
        $this->assertSame('draft_package_only_no_cms_write', $package['importer_mode']);
        $this->assertTrue($package['preflight_readiness']['go_for_draft_preflight']);
        $this->assertFalse($package['preflight_readiness']['go_for_draft_create']);
        $this->assertTrue($package['preflight_readiness']['draft_create_requires_exact_user_authorization']);

        $targets = collect($package['import_targets'])->keyBy('locale');

        $this->assertSame('/zh/articles/riasec-holland-career-interest-test-explained', $targets['zh']['canonical_path']);
        $this->assertSame('/en/articles/what-is-riasec-holland-code-career-interest-test', $targets['en']['canonical_path']);

        foreach (['zh', 'en'] as $locale) {
            $target = $targets[$locale];

            $this->assertSame('draft', $target['draft_defaults']['status']);
            $this->assertFalse($target['draft_defaults']['is_public']);
            $this->assertFalse($target['draft_defaults']['is_indexable']);
            $this->assertSame('noindex,nofollow', $target['draft_defaults']['robots']);
            $this->assertNull($target['draft_defaults']['published_at']);
            $this->assertNull($target['draft_defaults']['published_revision_id']);
            $this->assertFalse($target['draft_defaults']['publish_allowed']);
            $this->assertFalse($target['draft_defaults']['search_submit_allowed']);
            $this->assertFalse($target['draft_defaults']['schema_enabled']);
            $this->assertTrue($target['draft_defaults']['requires_operator_review']);
            $this->assertSame('__CMS_MEDIA_PLACEHOLDER_REQUIRED__', $target['cover_image']);
            $this->assertCount(6, $target['faq_entries']);
            $this->assertNotEmpty($target['body_markdown']);
        }
    }

    public function test_cms_import_package_keeps_public_cta_and_conditional_internal_links(): void
    {
        $package = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-import-package.v1.json');
        $targets = collect($package['import_targets'])->keyBy('locale');

        $this->assertSame('/zh/tests/holland-career-interest-test-riasec', $targets['zh']['cta_suggestions']['primary_cta_href']);
        $this->assertSame('/en/tests/holland-career-interest-test-riasec', $targets['en']['cta_suggestions']['primary_cta_href']);

        $this->assertContains('/zh/career/jobs', collect($targets['zh']['internal_link_plan'])->where('status', 'conditional')->pluck('href')->all());
        $this->assertContains('/en/career/jobs', collect($targets['en']['internal_link_plan'])->where('status', 'conditional')->pluck('href')->all());
        $this->assertSame('conditional_until_route_eligibility_confirmed', $package['global_safety']['career_hub_links']);
    }

    public function test_cms_import_package_contains_no_forbidden_routes_or_private_identifiers(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-import-package.v1.json') ?: '';

        $this->assertDoesNotMatchRegularExpression('#(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|private)(?:/|\\?)#i', $contents);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\\b/i', $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
