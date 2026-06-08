<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationControlledPublishPostpublishSmokeTest extends TestCase
{
    public function test_controlled_publish_is_recorded_without_search_or_deploy(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-controlled-publish-postpublish-smoke.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-CONTROLLED-PUBLISH-01', $artifact['task_id']);
        $this->assertSame('CONDITIONAL_GO_PUBLISH_DONE_NO_GO_SEARCH_SUBMISSION_ZH_FRONTEND_404', $artifact['decision']);
        $this->assertTrue($artifact['preflight_rerun_before_publish']['ok']);
        $this->assertTrue($artifact['preflight_rerun_before_publish']['dry_run']);
        $this->assertTrue($artifact['controlled_publish']['performed']);
        $this->assertTrue($artifact['controlled_publish']['ok']);
        $this->assertFalse($artifact['controlled_publish']['dry_run']);
        $this->assertSame([40, 41], $artifact['controlled_publish']['published_article_ids']);
        $this->assertFalse($artifact['controlled_publish']['search_submission_performed']);
        $this->assertFalse($artifact['controlled_publish']['deploy_performed']);
    }

    public function test_both_articles_are_published_and_indexable_in_cms_state(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-controlled-publish-postpublish-smoke.v1.json');
        $articles = collect($artifact['article_state_post_publish'])->keyBy('article_id');

        foreach ([40, 41] as $articleId) {
            $this->assertArrayHasKey($articleId, $articles);
            $article = $articles[$articleId];
            $this->assertSame('published', $article['status']);
            $this->assertTrue($article['is_public']);
            $this->assertTrue($article['is_indexable']);
            $this->assertSame('index,follow', $article['seo_robots']);
            $this->assertTrue($article['seo_indexable']);
            $this->assertSame(5, $article['references_count']);
            $this->assertSame('complete', $article['references_status']);
            $this->assertSame('complete', $article['media_status']);
        }
    }

    public function test_post_publish_smoke_blocks_search_submission_until_zh_frontend_passes(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-controlled-publish-postpublish-smoke.v1.json');

        $api = collect($artifact['post_publish_smoke']['api'])->keyBy('article_id');
        $frontend = collect($artifact['post_publish_smoke']['frontend'])->keyBy('article_id');

        $this->assertSame(200, $api[40]['http_status']);
        $this->assertSame(200, $api[41]['http_status']);
        $this->assertSame(404, $frontend[40]['http_status']);
        $this->assertSame('blocked', $frontend[40]['status']);
        $this->assertSame('zh_frontend_canonical_404', $frontend[40]['blocker']);
        $this->assertSame(200, $frontend[41]['http_status']);
        $this->assertSame('passed', $frontend[41]['status']);

        $this->assertFalse($artifact['post_publish_smoke']['sitemap_llms']['search_submission_allowed']);
        $blockers = collect($artifact['remaining_blockers'])->pluck('id')->all();
        $this->assertContains('zh_frontend_canonical_404', $blockers);
        $this->assertContains('sitemap_llms_not_converged', $blockers);
        $this->assertContains('search_submission_not_authorized', $blockers);
    }

    public function test_locale_contract_evidence_is_recorded(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-controlled-publish-postpublish-smoke.v1.json');
        $locale = $artifact['post_publish_smoke']['locale_contract_evidence'];

        $this->assertSame('zh', $locale['published_chinese_article_locale']);
        $this->assertSame('zh-CN', $locale['existing_chinese_canary_locale']);
        $this->assertSame(200, $locale['zh_api_with_locale_zh_status']);
        $this->assertSame(404, $locale['zh_api_with_locale_zh_cn_status']);
        $this->assertSame(200, $locale['existing_canary_api_with_locale_zh_cn_status']);
    }

    public function test_hard_boundaries_remain_closed(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-controlled-publish-postpublish-smoke.v1.json');

        $this->assertTrue($artifact['hard_boundaries']['no_article_body_title_h1_meta_faq_cta_rewrite']);
        $this->assertTrue($artifact['hard_boundaries']['no_search_submission']);
        $this->assertTrue($artifact['hard_boundaries']['no_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['no_frontend_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['no_private_or_tokenized_url_access']);
        $this->assertTrue($artifact['hard_boundaries']['public_canonical_routes_only']);
    }

    public function test_artifact_contains_no_forbidden_route_or_identifier_patterns(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-controlled-publish-postpublish-smoke.v1.json') ?: '';

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
