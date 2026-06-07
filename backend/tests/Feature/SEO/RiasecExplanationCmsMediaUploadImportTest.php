<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class RiasecExplanationCmsMediaUploadImportTest extends TestCase
{
    public function test_cms_media_import_is_recorded_but_publish_remains_blocked(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-media-upload-import.v1.json');

        $this->assertSame('RIASEC-EXPLANATION-CMS-MEDIA-UPLOAD-IMPORT-01', $artifact['task_id']);
        $this->assertSame('CONDITIONAL_GO_CMS_MEDIA_IMPORTED_PUBLISH_STILL_BLOCKED', $artifact['decision']);
        $this->assertTrue($artifact['cms_mutation_performed']);
        $this->assertTrue($artifact['media_upload_performed']);
        $this->assertFalse($artifact['article_revision_mutation_performed']);
        $this->assertFalse($artifact['article_content_rewrite_performed']);
        $this->assertFalse($artifact['publish_performed']);
        $this->assertFalse($artifact['search_submission_performed']);
        $this->assertFalse($artifact['deploy_performed']);
        $this->assertFalse($artifact['private_url_accessed']);
    }

    public function test_media_asset_satisfies_article_cover_readiness_shape(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-media-upload-import.v1.json');
        $asset = $artifact['production_media_asset'];

        $this->assertSame(6, $asset['asset_id']);
        $this->assertSame('article.riasec.explanation.cover.v1', $asset['asset_key']);
        $this->assertSame('published', $asset['status']);
        $this->assertTrue($asset['is_public']);
        $this->assertSame('verified', $asset['cdn_status']);
        $this->assertSame('image/webp', $asset['mime_type']);
        $this->assertGreaterThan(0, $asset['width']);
        $this->assertGreaterThan(0, $asset['height']);
        $this->assertSame(
            'Abstract career-interest map with six work activity icons around a compass.',
            $asset['alt']
        );
    }

    public function test_required_variants_are_verified_public_images(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-media-upload-import.v1.json');
        $variants = collect($artifact['variants'])->keyBy('variant_key');

        foreach (['hero', 'card', 'thumbnail', 'og', 'preload'] as $variantKey) {
            $this->assertArrayHasKey($variantKey, $variants);
            $variant = $variants[$variantKey];
            $this->assertSame('verified', $variant['cdn_status']);
            $this->assertStringStartsWith('https://api.fermatmind.com/storage/', $variant['url']);
            $this->assertStringStartsWith('image/', $variant['mime_type']);
            $this->assertGreaterThan(0, $variant['width']);
            $this->assertGreaterThan(0, $variant['height']);
            $this->assertNull($variant['last_error']);
        }
    }

    public function test_remaining_blockers_prevent_publish_progression(): void
    {
        $artifact = $this->readJson(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-media-upload-import.v1.json');
        $blockers = collect($artifact['remaining_blockers'])->keyBy('id');

        $this->assertSame('blocked', $blockers['draft_revisions_not_approved']['status']);
        $this->assertSame('blocked', $blockers['article_cover_not_attached_to_drafts']['status']);
        $this->assertSame('blocked', $blockers['publish_preflight_not_run']['status']);
        $this->assertSame('RIASEC_V2_ARTICLE_COVER_ATTACH_OR_DRAFT_APPROVAL_INPUT_REQUIRED', $artifact['next_step']['id']);
        $this->assertContains('article draft mutation', $artifact['next_step']['not_authorized_yet']);
        $this->assertContains('publish', $artifact['next_step']['not_authorized_yet']);
    }

    public function test_artifact_contains_no_forbidden_route_or_identifier_patterns(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../docs/seo/generated/riasec-explanation-cms-media-upload-import.v1.json') ?: '';

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
