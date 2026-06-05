<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecArticlePackageV2ValidationTest extends TestCase
{
    public function test_riasec_explanation_v2_validation_allows_reference_review_only(): void
    {
        $validation = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-content-package-v2.validation.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-PACKAGE-VALIDATION-01', $validation['task_id']);
        $this->assertSame('GO for reference/source review', $validation['decision']);
        $this->assertTrue($validation['content_safe']);
        $this->assertFalse($validation['content_blocked']);
        $this->assertSame([], $validation['blockers']);

        $this->assertFalse($validation['cms_draft_created']);
        $this->assertFalse($validation['publish_allowed']);
        $this->assertFalse($validation['search_submit_allowed']);
        $this->assertFalse($validation['codex_content_rewrite_performed']);

        $this->assertTrue($validation['checks']['no_private_url']);
        $this->assertTrue($validation['checks']['no_result_order_share_pay_payment_history_route']);
        $this->assertTrue($validation['checks']['no_tokenized_url']);
        $this->assertTrue($validation['checks']['no_raw_private_identifier']);
        $this->assertSame('pass', $validation['checks']['claim_boundary']);
        $this->assertSame('pass', $validation['checks']['publish_hold_flags']);
        $this->assertTrue($validation['checks']['references_required']);
        $this->assertTrue($validation['checks']['unresolved_reference_fields_marked_needs_source_verification']);
    }

    public function test_riasec_explanation_v2_locale_validation_keeps_cta_faq_and_unknown_boundaries(): void
    {
        $validation = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-content-package-v2.validation.json');

        $localeChecks = collect($validation['checks']['locale_field_checks'])->keyBy('locale');

        $this->assertSame('/zh/tests/holland-career-interest-test-riasec', $localeChecks['zh']['primary_cta_href']);
        $this->assertSame('/en/tests/holland-career-interest-test-riasec', $localeChecks['en']['primary_cta_href']);

        foreach (['zh', 'en'] as $locale) {
            $this->assertTrue($localeChecks[$locale]['slug_length_pass']);
            $this->assertTrue($localeChecks[$locale]['seo_title_length_pass']);
            $this->assertTrue($localeChecks[$locale]['seo_description_length_pass']);
            $this->assertTrue($localeChecks[$locale]['body_markdown_exists']);
            $this->assertSame(6, $localeChecks[$locale]['faq_count']);
            $this->assertSame(6, $localeChecks[$locale]['visible_faq_count']);
            $this->assertSame(6, $localeChecks[$locale]['expected_faq_schema_mainEntity_count']);
            $this->assertSame(6, $localeChecks[$locale]['body_visible_faq_heading_count']);
            $this->assertTrue($localeChecks[$locale]['faq_count_matches_schema']);
            $this->assertTrue($localeChecks[$locale]['primary_cta_public_canonical_pass']);
            $this->assertTrue($localeChecks[$locale]['internal_links_pass']);
            $this->assertTrue($localeChecks[$locale]['unknown_preserved']);
        }

        $this->assertSame(['/zh/career/jobs'], $localeChecks['zh']['conditional_links']);
        $this->assertSame(['/en/career/jobs'], $localeChecks['en']['conditional_links']);
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
