<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationOperatorInputMediaBlockerTest extends TestCase
{
    public function test_operator_inputs_are_recorded_without_opening_publish_preflight(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-operator-input-media-blocker.v1.json');

        $this->assertSame('RIASEC-EXPLANATION-OPERATOR-INPUT-MEDIA-BLOCKER-01', $artifact['task_id']);
        $this->assertSame('CONDITIONAL_GO_OPERATOR_INPUTS_RECORDED_MEDIA_BLOCKER', $artifact['decision']);
        $this->assertTrue($artifact['operator_inputs_recorded_from_current_scan']);
        $this->assertFalse($artifact['publish_preflight_allowed']);
        $this->assertFalse($artifact['cms_mutation_allowed']);
        $this->assertFalse($artifact['publish_allowed']);
        $this->assertFalse($artifact['search_submission_allowed']);
        $this->assertFalse($artifact['deploy_allowed']);
        $this->assertFalse($artifact['content_rewrite_allowed']);
    }

    public function test_reference_and_claim_decisions_are_explicitly_accepted(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-operator-input-media-blocker.v1.json');
        $reference = $artifact['operator_input_decisions']['reference_acceptance'];
        $claim = $artifact['operator_input_decisions']['claim_warning_decision'];

        $this->assertSame('accepted_for_operator_recording', $reference['status']);
        $this->assertSame('cms_references_visible_reference_section', $reference['citation_style']);
        $this->assertCount(5, $reference['accepted_source_urls']);
        $this->assertContains('https://www.onetcenter.org/reports/IP_Manual.html', $reference['accepted_source_urls']);
        $this->assertContains('https://dictionary.apa.org/five-factor-personality-model', $reference['accepted_source_urls']);
        $this->assertSame('accepted_with_exploratory_boundary', $reference['holland_hexagon_terms_acceptance']);
        $this->assertSame('accepted_conservative_comparison_only', $reference['mbti_big_five_comparison_acceptance']);
        $this->assertTrue($reference['no_official_affiliation_acknowledgement']);

        $this->assertSame('acknowledged', $claim['status']);
        $this->assertSame(2, $claim['zh_claim_warning_count']);
        $this->assertSame(0, $claim['en_claim_warning_count']);
        $this->assertTrue($claim['claim_warning_acknowledgement']);
        $this->assertFalse($claim['gpt_revision_required']);
    }

    public function test_media_remains_unknown_and_revision_approval_is_held(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-operator-input-media-blocker.v1.json');
        $media = $artifact['operator_input_decisions']['media_resolution'];
        $revision = $artifact['operator_input_decisions']['revision_approval'];
        $blockers = collect($artifact['remaining_blockers'])->keyBy('id');

        $this->assertSame('blocked', $media['status']);
        $this->assertSame('Unknown', $media['cms_media_id']);
        $this->assertSame('Unknown', $media['cover_image_url']);
        $this->assertSame('Unknown', $media['cover_image_alt_reviewed']);
        $this->assertSame('Unknown', $media['og_image_ready']);
        $this->assertSame('Unknown', $media['twitter_image_ready']);

        $this->assertSame('hold', $revision['status']);
        $this->assertSame(45, $revision['zh_revision_id']);
        $this->assertSame(46, $revision['en_revision_id']);
        $this->assertSame('Unknown', $revision['approved_by']);
        $this->assertSame('Unknown', $revision['approved_at']);
        $this->assertFalse($revision['cms_draft_approval_allowed']);

        $this->assertSame('blocked', $blockers['cms_media_missing']['status']);
        $this->assertSame('blocked', $blockers['revision_approval_held_until_media']['status']);
        $this->assertSame('hard_gate', $blockers['publish_preflight_not_authorized']['status']);
    }

    public function test_public_routes_are_safe_and_draft_routes_are_not_exposed(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-operator-input-media-blocker.v1.json');
        $routes = collect($artifact['public_route_observations']['safe_public_routes'])->keyBy('route');
        $drafts = collect($artifact['public_route_observations']['draft_route_exposure'])->keyBy('route');

        foreach ([
            '/zh/tests/holland-career-interest-test-riasec',
            '/en/tests/holland-career-interest-test-riasec',
            '/zh/career/jobs',
            '/en/career/jobs',
        ] as $route) {
            $this->assertSame(200, $routes[$route]['http_status']);
            $this->assertSame('index, follow', $routes[$route]['robots']);
        }

        $this->assertSame(404, $drafts['/zh/articles/riasec-holland-career-interest-test-explained']['http_status']);
        $this->assertSame(404, $drafts['/en/articles/what-is-riasec-holland-code-career-interest-test']['http_status']);
        $this->assertSame(0, $drafts['/zh/articles/riasec-holland-career-interest-test-explained']['sitemap_count']);
        $this->assertSame(0, $drafts['/en/articles/what-is-riasec-holland-code-career-interest-test']['llms_count']);
    }

    public function test_next_step_is_media_selection_not_publish_preflight(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-operator-input-media-blocker.v1.json');

        $this->assertSame('RIASEC_V2_CMS_MEDIA_SELECTION_REQUIRED', $artifact['next_step']['id']);
        $this->assertSame('SEO-ARTICLE-RIASEC-V2-PUBLISH-PREFLIGHT-01', $artifact['next_step']['not_recommended_yet']);
        $this->assertTrue($artifact['hard_boundaries']['no_article_body_title_h1_meta_faq_cta_rewrite']);
        $this->assertTrue($artifact['hard_boundaries']['no_cms_mutation']);
        $this->assertTrue($artifact['hard_boundaries']['no_publish']);
        $this->assertTrue($artifact['hard_boundaries']['no_search_submission']);
        $this->assertTrue($artifact['hard_boundaries']['no_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['public_canonical_routes_only']);
        $this->assertTrue($artifact['hard_boundaries']['no_private_or_tokenized_url_access']);
    }

    public function test_artifact_contains_no_forbidden_private_routes_or_identifiers(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-operator-input-media-blocker.v1.json') ?: '';

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
