<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationEditorialReviewTest extends TestCase
{
    public function test_editorial_review_blocks_publish_without_claiming_operator_approval(): void
    {
        $review = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-editorial-review.v1.json');

        $this->assertSame('SEO-ARTICLE-RIASEC-V2-EDITORIAL-REVIEW-01', $review['task_id']);
        $this->assertSame('REVIEW_BLOCKED_OPERATOR_INPUTS_REQUIRED', $review['decision']);
        $this->assertFalse($review['review_passed']);
        $this->assertFalse($review['operator_approval_claimed']);
        $this->assertFalse($review['publish_allowed']);
        $this->assertFalse($review['search_submit_allowed']);
        $this->assertFalse($review['cms_mutation_performed']);
        $this->assertFalse($review['deploy_performed']);
        $this->assertFalse($review['content_rewrite_performed']);
        $this->assertFalse($review['private_url_accessed']);
    }

    public function test_draft_state_remains_noindex_unpublished_and_machine_draft(): void
    {
        $review = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-editorial-review.v1.json');
        $articles = collect($review['cms_draft_state']['articles'])->keyBy('locale');

        $this->assertSame('pass', $review['cms_draft_state']['status']);
        $this->assertSame(0, $review['cms_draft_state']['records_created_in_this_task']);
        $this->assertSame(0, $review['cms_draft_state']['records_updated_in_this_task']);
        $this->assertSame(0, $review['cms_draft_state']['public_indexable_published_count']);

        foreach (['zh', 'en'] as $locale) {
            $this->assertSame('draft', $articles[$locale]['status']);
            $this->assertFalse($articles[$locale]['is_public']);
            $this->assertFalse($articles[$locale]['is_indexable']);
            $this->assertSame('noindex,nofollow', $articles[$locale]['robots']);
            $this->assertNull($articles[$locale]['published_revision_id']);
            $this->assertSame('machine_draft', $articles[$locale]['working_revision_status']);
            $this->assertNull($articles[$locale]['approved_at']);
        }
    }

    public function test_review_records_required_publish_blockers_and_operator_inputs(): void
    {
        $review = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-editorial-review.v1.json');
        $blockers = collect($review['publish_blockers'])->pluck('id')->all();

        $this->assertContains('accepted_references_missing', $blockers);
        $this->assertContains('cms_media_cover_image_missing', $blockers);
        $this->assertContains('working_revisions_not_approved', $blockers);
        $this->assertContains('claim_boundary_acknowledgement_required', $blockers);
        $this->assertContains('conditional_internal_links_not_activated', $blockers);
        $this->assertContains('product_availability_confirmation_required', $blockers);
        $this->assertContains('controlled_publish_preflight_required', $blockers);

        $this->assertTrue($review['operator_input_request_card']['reference_acceptance']['required']);
        $this->assertTrue($review['operator_input_request_card']['media_resolution']['required']);
        $this->assertTrue($review['operator_input_request_card']['revision_approval']['required']);
        $this->assertTrue($review['operator_input_request_card']['publish_preflight_inputs']['required_later']);
    }

    public function test_public_surface_and_route_boundaries_remain_safe(): void
    {
        $review = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-editorial-review.v1.json');

        $this->assertSame('pass', $review['public_surface_checks']['status']);
        $this->assertTrue($review['public_surface_checks']['article_apis_all_404']);
        $this->assertFalse($review['public_surface_checks']['sitemap_contains_target_slugs']);
        $this->assertFalse($review['public_surface_checks']['llms_contains_target_slugs']);
        $this->assertFalse($review['public_surface_checks']['llms_full_contains_target_slugs']);
        $this->assertFalse($review['public_surface_checks']['search_submission_performed']);

        foreach ($review['public_surface_checks']['article_pages'] as $page) {
            $this->assertSame(404, $page['status']);
            $this->assertFalse($page['article_schema_present']);
            $this->assertFalse($page['faq_schema_present']);
            $this->assertFalse($page['target_canonical_present']);
        }

        $this->assertSame([
            '/zh/tests/holland-career-interest-test-riasec',
            '/en/tests/holland-career-interest-test-riasec',
        ], $review['editorial_system_review']['cta_routes_allowed']);
    }

    public function test_artifact_contains_no_forbidden_private_routes_or_identifiers(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-editorial-review.v1.json') ?: '';

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
