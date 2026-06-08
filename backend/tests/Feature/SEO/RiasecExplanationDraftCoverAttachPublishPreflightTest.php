<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationDraftCoverAttachPublishPreflightTest extends TestCase
{
    public function test_cover_attach_and_revision_approval_are_recorded_without_publish(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-draft-cover-attach-publish-preflight.v1.json');

        $this->assertSame('RIASEC-EXPLANATION-DRAFT-COVER-ATTACH-PUBLISH-PREFLIGHT-01', $artifact['task_id']);
        $this->assertSame('NO_GO_REFERENCES_MISSING', $artifact['decision']);
        $this->assertTrue($artifact['cms_mutation_performed']);
        $this->assertTrue($artifact['article_cover_attach_performed']);
        $this->assertTrue($artifact['draft_revision_approval_performed']);
        $this->assertTrue($artifact['publish_preflight_performed']);
        $this->assertTrue($artifact['publish_preflight_dry_run']);
        $this->assertFalse($artifact['publish_preflight_ok']);
        $this->assertFalse($artifact['publish_performed']);
        $this->assertFalse($artifact['search_submission_performed']);
        $this->assertFalse($artifact['deploy_performed']);
        $this->assertFalse($artifact['private_url_accessed']);
        $this->assertFalse($artifact['article_content_rewrite_performed']);
    }

    public function test_both_articles_have_cover_and_approved_revision_state(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-draft-cover-attach-publish-preflight.v1.json');
        $articles = collect($artifact['article_mutations'])->keyBy('article_id');

        foreach ([40, 41] as $articleId) {
            $this->assertArrayHasKey($articleId, $articles);
            $article = $articles[$articleId];
            $this->assertSame('draft', $article['status_after']);
            $this->assertFalse($article['is_public_after']);
            $this->assertFalse($article['is_indexable_after']);
            $this->assertSame('approved', $article['translation_status_after']);
            $this->assertSame('approved', $article['working_revision_status_after']);
            $this->assertSame(1, $article['reviewed_by']);
            $this->assertTrue($article['cover_image_attached']);
            $this->assertTrue($article['seo_og_image_attached']);
            $this->assertSame('complete', $article['import_media_status_after']);
            $this->assertSame(0, $article['references_count']);
        }
    }

    public function test_publish_preflight_is_blocked_only_by_references_in_artifact(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-draft-cover-attach-publish-preflight.v1.json');
        $preflight = $artifact['publish_preflight'];

        $this->assertFalse($preflight['ok']);
        $this->assertTrue($preflight['dry_run']);
        $this->assertSame(1, $preflight['articles'][0]['cta_count']);
        $this->assertSame(6, $preflight['articles'][0]['faq_count']);
        $this->assertSame('complete', $preflight['articles'][0]['media_status']);
        $this->assertSame('complete', $preflight['articles'][1]['media_status']);
        $this->assertSame(['references_missing'], $preflight['blocking_error_codes']);

        foreach ($preflight['articles'] as $article) {
            $this->assertSame(0, $article['references_count']);
            $this->assertSame('references_missing', $article['errors'][0]['code']);
        }
    }

    public function test_public_absence_and_hard_boundaries_remain_closed(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-draft-cover-attach-publish-preflight.v1.json');

        $this->assertSame(404, $artifact['public_absence_postcheck']['zh_article_public_api_http_status']);
        $this->assertSame(404, $artifact['public_absence_postcheck']['en_article_public_api_http_status']);
        $this->assertTrue($artifact['public_absence_postcheck']['articles_remain_non_public']);
        $this->assertTrue($artifact['public_absence_postcheck']['articles_remain_non_indexable']);

        $this->assertTrue($artifact['hard_boundaries']['no_article_body_title_h1_meta_faq_cta_rewrite']);
        $this->assertTrue($artifact['hard_boundaries']['no_publish']);
        $this->assertTrue($artifact['hard_boundaries']['no_search_submission']);
        $this->assertTrue($artifact['hard_boundaries']['no_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['public_canonical_routes_only']);
        $this->assertTrue($artifact['hard_boundaries']['no_private_or_tokenized_url_access']);
    }

    public function test_artifact_contains_no_forbidden_route_or_identifier_patterns(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-draft-cover-attach-publish-preflight.v1.json') ?: '';

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
