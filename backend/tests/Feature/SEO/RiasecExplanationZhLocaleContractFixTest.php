<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationZhLocaleContractFixTest extends TestCase
{
    public function test_zh_locale_contract_fix_is_recorded_without_content_publish_search_or_deploy_scope(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-zh-locale-contract-fix.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-ZH-LOCALE-CONTRACT-FIX-01', $artifact['task_id']);
        $this->assertSame('GO_ZH_CANONICAL_200_SEARCH_SUBMISSION_STILL_BLOCKED', $artifact['decision']);
        $this->assertTrue($artifact['scope']['cms_mutation_performed']);
        $this->assertFalse($artifact['scope']['article_content_rewrite_performed']);
        $this->assertFalse($artifact['scope']['publish_performed_in_this_task']);
        $this->assertFalse($artifact['scope']['search_submission_performed']);
        $this->assertFalse($artifact['scope']['deploy_performed']);
        $this->assertFalse($artifact['scope']['frontend_deploy_performed']);
        $this->assertFalse($artifact['scope']['private_or_tokenized_url_accessed']);
        $this->assertTrue($artifact['scope']['public_canonical_routes_only']);
    }

    public function test_article_40_locale_fields_are_normalized_to_zh_cn(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-zh-locale-contract-fix.v1.json');

        $before = $artifact['production_locale_state']['before'];
        $after = $artifact['production_locale_state']['after'];

        $this->assertSame('zh', $before['article_locale']);
        $this->assertSame('zh', $before['source_locale']);
        $this->assertSame('zh', $before['seo_meta_locale']);
        $this->assertSame(['zh'], $before['article_test_edge_locales']);
        $this->assertSame(['zh'], $before['article_translation_revision_locales']);

        $this->assertSame('zh-CN', $after['article_locale']);
        $this->assertSame('zh-CN', $after['source_locale']);
        $this->assertSame('zh-CN', $after['seo_meta_locale']);
        $this->assertSame('zh-CN', $after['editorial_import_locale']);
        $this->assertSame(['zh-CN'], $after['article_test_edge_locales']);
        $this->assertSame(['zh-CN'], $after['article_translation_revision_locales']);
    }

    public function test_public_zh_canonical_route_now_passes_and_en_remains_live(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-zh-locale-contract-fix.v1.json');
        $api = collect($artifact['post_fix_http_smoke']['api'])->keyBy('locale_query');
        $frontend = collect($artifact['post_fix_http_smoke']['frontend'])->keyBy('locale_segment');

        $this->assertSame(200, $api['zh-CN']['http_status']);
        $this->assertSame('passed', $api['zh-CN']['status']);
        $this->assertSame(404, $api['zh']['http_status']);
        $this->assertSame('expected_after_locale_normalization', $api['zh']['status']);

        $this->assertSame(200, $frontend['zh']['http_status']);
        $this->assertSame('passed', $frontend['zh']['status']);
        $this->assertSame(200, $frontend['en']['http_status']);
        $this->assertSame('passed', $frontend['en']['status']);
    }

    public function test_search_submission_remains_blocked_until_sitemap_and_llms_converge(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-zh-locale-contract-fix.v1.json');

        $this->assertFalse($artifact['sitemap_llms_smoke']['search_submission_allowed']);
        $this->assertSame('not_fully_converged', $artifact['sitemap_llms_smoke']['convergence_status']);
        $this->assertFalse($artifact['sitemap_llms_smoke']['sitemap_xml']['contains_zh_article']);
        $this->assertFalse($artifact['sitemap_llms_smoke']['sitemap_xml']['contains_en_article']);
        $this->assertFalse($artifact['sitemap_llms_smoke']['llms_txt']['contains_zh_article']);
        $this->assertTrue($artifact['sitemap_llms_smoke']['llms_txt']['contains_en_article']);
        $this->assertFalse($artifact['sitemap_llms_smoke']['llms_full_txt']['contains_zh_article']);
        $this->assertTrue($artifact['sitemap_llms_smoke']['llms_full_txt']['contains_en_article']);

        $blockers = collect($artifact['remaining_blockers'])->pluck('id')->all();
        $this->assertContains('sitemap_llms_not_fully_converged', $blockers);
        $this->assertContains('search_submission_not_authorized', $blockers);
    }

    public function test_hard_boundaries_remain_closed(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-zh-locale-contract-fix.v1.json');

        $this->assertTrue($artifact['hard_boundaries']['no_article_body_title_h1_meta_faq_cta_rewrite']);
        $this->assertTrue($artifact['hard_boundaries']['no_publish_in_this_task']);
        $this->assertTrue($artifact['hard_boundaries']['no_search_submission']);
        $this->assertTrue($artifact['hard_boundaries']['no_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['no_frontend_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['no_private_or_tokenized_url_access']);
        $this->assertTrue($artifact['hard_boundaries']['public_canonical_routes_only']);
    }

    public function test_artifact_contains_no_forbidden_route_or_identifier_patterns(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-zh-locale-contract-fix.v1.json') ?: '';

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
