<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationMediaSelectionReadinessTest extends TestCase
{
    public function test_media_selection_remains_blocked_without_reusable_candidate(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-media-selection-readiness.v1.json');

        $this->assertSame('RIASEC-EXPLANATION-MEDIA-SELECTION-READINESS-01', $artifact['task_id']);
        $this->assertSame('NO_GO_NO_REUSABLE_CMS_MEDIA_CANDIDATE', $artifact['decision']);
        $this->assertFalse($artifact['media_selection_ready']);
        $this->assertFalse($artifact['cms_media_id_ready']);
        $this->assertFalse($artifact['publish_preflight_allowed']);
        $this->assertFalse($artifact['cms_mutation_allowed']);
        $this->assertFalse($artifact['media_upload_allowed']);
        $this->assertFalse($artifact['publish_allowed']);
        $this->assertFalse($artifact['search_submission_allowed']);
        $this->assertFalse($artifact['deploy_allowed']);
        $this->assertFalse($artifact['content_rewrite_allowed']);
    }

    public function test_article_cover_runtime_requirements_match_backend_readiness_contract(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-media-selection-readiness.v1.json');
        $requirements = $artifact['article_cover_runtime_requirements'];

        $this->assertSame('ArticleResource mediaAssetOptions and articleCoverReadinessFailures', $requirements['source']);
        $this->assertSame([
            'hero',
            'card',
            'thumbnail',
            'og',
            'preload',
        ], $requirements['required_variant_keys']);

        foreach ([
            'MediaAsset status is published',
            'MediaAsset is_public is true',
            'MediaAsset cdn_status is verified',
            'MediaAsset source URL is accepted by PublicMediaUrlGuard',
            'MediaAsset alt is non-empty and reviewed',
            'All required generated variants exist: hero, card, thumbnail, og, preload',
            'Each required variant cdn_status is verified',
            'Each required variant URL is accepted by PublicMediaUrlGuard',
        ] as $expectedRequirement) {
            $this->assertContains($expectedRequirement, $requirements['requirements']);
        }
    }

    public function test_existing_baseline_assets_are_rejected_for_topical_mismatch(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-media-selection-readiness.v1.json');
        $scan = $artifact['repo_media_inventory_scan'];
        $rejected = collect($scan['rejected_existing_assets'])->keyBy('asset_key');

        $this->assertSame(6, $scan['default_media_assets_count']);
        $this->assertSame(0, $scan['reusable_candidate_count']);
        $this->assertSame('no_existing_repo_baseline_asset_is_acceptable_for_riasec_article_cover', $scan['candidate_decision']);

        foreach ([
            'share.mbti.default',
            'social.wechat.official_qr',
            'social.wechat.qr',
            'iq-owner-original-30-card',
            'iq-owner-original-30-og',
            'iq-full-report-cover',
        ] as $assetKey) {
            $this->assertArrayHasKey($assetKey, $rejected);
            $this->assertNotEmpty($rejected[$assetKey]['reason']);
        }
    }

    public function test_next_step_is_media_input_not_publish_preflight(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-media-selection-readiness.v1.json');
        $request = $artifact['recommended_media_request'];
        $blockers = collect($artifact['remaining_blockers'])->keyBy('id');

        $this->assertSame('article.riasec.explanation.cover.v1', $request['asset_key_suggestion']);
        $this->assertSame('article_cover', $request['surface']);
        $this->assertContains('cms_media_id', $request['required_operator_inputs']);
        $this->assertContains('cover_image_url', $request['required_operator_inputs']);
        $this->assertContains('cover_image_alt_reviewed', $request['required_operator_inputs']);
        $this->assertContains('og_image_ready', $request['required_operator_inputs']);
        $this->assertContains('twitter_image_ready', $request['required_operator_inputs']);

        $this->assertSame('blocked', $blockers['cms_media_id_unknown']['status']);
        $this->assertSame('blocked', $blockers['cover_url_unknown']['status']);
        $this->assertSame('blocked', $blockers['alt_and_social_images_unknown']['status']);
        $this->assertSame('blocked', $blockers['revision_approval_held_until_media']['status']);
        $this->assertSame('RIASEC_V2_CMS_MEDIA_INPUT_REQUIRED', $artifact['next_step']['id']);
        $this->assertSame('SEO-ARTICLE-RIASEC-V2-PUBLISH-PREFLIGHT-01', $artifact['next_step']['not_recommended_yet']);
    }

    public function test_hard_boundaries_remain_closed(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-media-selection-readiness.v1.json');

        $this->assertFalse($artifact['cms_mutation_performed']);
        $this->assertFalse($artifact['media_upload_performed']);
        $this->assertFalse($artifact['publish_performed']);
        $this->assertFalse($artifact['search_submission_performed']);
        $this->assertFalse($artifact['deploy_performed']);
        $this->assertFalse($artifact['content_rewrite_performed']);
        $this->assertFalse($artifact['private_url_accessed']);

        $this->assertTrue($artifact['hard_boundaries']['no_article_body_title_h1_meta_faq_cta_rewrite']);
        $this->assertTrue($artifact['hard_boundaries']['no_cms_mutation']);
        $this->assertTrue($artifact['hard_boundaries']['no_media_upload']);
        $this->assertTrue($artifact['hard_boundaries']['no_publish']);
        $this->assertTrue($artifact['hard_boundaries']['no_search_submission']);
        $this->assertTrue($artifact['hard_boundaries']['no_deploy']);
        $this->assertTrue($artifact['hard_boundaries']['public_canonical_routes_only']);
        $this->assertTrue($artifact['hard_boundaries']['no_private_or_tokenized_url_access']);
    }

    public function test_artifact_contains_no_forbidden_route_or_identifier_patterns(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-media-selection-readiness.v1.json') ?: '';

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
