<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationReferencesSourceGatePreflightTest extends TestCase
{
    public function test_references_source_gate_is_reconciled_without_publish(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-references-source-gate-preflight.v1.json');

        $this->assertSame('RIASEC-EXPLANATION-REFERENCES-SOURCE-GATE-PREFLIGHT-01', $artifact['task_id']);
        $this->assertSame('GO_PUBLISH_PREFLIGHT_DRY_RUN_PASSED_PUBLISH_STILL_NOT_AUTHORIZED', $artifact['decision']);
        $this->assertTrue($artifact['cms_mutation_performed']);
        $this->assertTrue($artifact['references_source_gate_reconciled']);
        $this->assertTrue($artifact['draft_revision_approval_performed']);
        $this->assertFalse($artifact['article_content_rewrite_performed']);
        $this->assertFalse($artifact['publish_performed']);
        $this->assertFalse($artifact['search_submission_performed']);
        $this->assertFalse($artifact['deploy_performed']);
        $this->assertFalse($artifact['private_url_accessed']);
    }

    public function test_source_set_is_complete_and_publicly_verified(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-references-source-gate-preflight.v1.json');

        $this->assertSame(5, $artifact['source_gate']['references_count_after']);
        $this->assertSame('complete', $artifact['source_gate']['references_status_after']);
        $this->assertSame('accepted', $artifact['source_gate']['source_review_status']);
        $this->assertTrue($artifact['source_gate']['non_endorsement_boundary']);
        $this->assertTrue($artifact['source_gate']['no_diagnosis_boundary']);
        $this->assertTrue($artifact['source_gate']['no_deterministic_career_result_boundary']);
        $this->assertTrue($artifact['source_gate']['official_affiliation_not_implied']);

        $this->assertCount(5, $artifact['source_set']);
        foreach ($artifact['source_set'] as $source) {
            $this->assertSame(200, $source['http_status']);
            $this->assertStringStartsWith('https://', $source['url']);
        }
    }

    public function test_both_drafts_have_complete_references_and_stay_private(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-references-source-gate-preflight.v1.json');
        $articles = collect($artifact['article_mutations'])->keyBy('article_id');

        foreach ([40, 41] as $articleId) {
            $this->assertArrayHasKey($articleId, $articles);
            $article = $articles[$articleId];
            $this->assertSame('draft', $article['status_after']);
            $this->assertFalse($article['is_public_after']);
            $this->assertFalse($article['is_indexable_after']);
            $this->assertSame('approved', $article['translation_status_after']);
            $this->assertSame('approved', $article['working_revision_status_after']);
            $this->assertSame(5, $article['references_count_after']);
            $this->assertSame('complete', $article['references_status_after']);
            $this->assertSame(5, $article['seo_references_count_after']);
            $this->assertSame('complete', $article['media_status_after']);
        }
    }

    public function test_publish_preflight_passes_but_does_not_publish(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-references-source-gate-preflight.v1.json');
        $preflight = $artifact['publish_preflight'];

        $this->assertTrue($artifact['publish_preflight_performed']);
        $this->assertTrue($artifact['publish_preflight_dry_run']);
        $this->assertTrue($artifact['publish_preflight_ok']);
        $this->assertTrue($preflight['ok']);
        $this->assertTrue($preflight['dry_run']);
        $this->assertSame([], $preflight['errors']);
        $this->assertSame([], $preflight['published_article_ids']);

        foreach ($preflight['articles'] as $article) {
            $this->assertTrue($article['ok']);
            $this->assertSame([], $article['errors']);
            $this->assertSame(5, $article['references_count']);
            $this->assertSame('complete', $article['media_status']);
            $this->assertSame('complete', $article['graph_status']);
            $this->assertSame(1, $article['cta_count']);
            $this->assertSame(6, $article['faq_count']);
        }
    }

    public function test_public_absence_and_hard_boundaries_remain_closed(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-references-source-gate-preflight.v1.json');

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
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-references-source-gate-preflight.v1.json') ?: '';

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
