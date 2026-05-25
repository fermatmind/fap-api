<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity06MediaAssetsParityInventoryTest extends TestCase
{
    #[Test]
    public function generated_media_inventory_records_authority_and_no_mutation_controls(): void
    {
        $path = base_path('docs/seo/generated/en-parity-06-media-assets-parity-inventory.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('en-parity-06-media-assets-parity-inventory.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-06', $payload['task'] ?? null);
        $this->assertSame('backend_cms_media_and_content_baselines', $payload['source_authority'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['media_upload_performed'] ?? true));
        $this->assertFalse((bool) ($payload['image_generation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_modified'] ?? true));

        $this->assertSame(3, data_get($payload, 'current_baseline_summary.media_library_assets_count'));
        $this->assertSame(3, data_get($payload, 'current_baseline_summary.media_library_assets_with_alt_count'));
        $this->assertSame(3, data_get($payload, 'current_baseline_summary.media_library_assets_with_caption_count'));
        $this->assertSame(9, data_get($payload, 'current_baseline_summary.media_library_variants_count'));
        $this->assertSame(20, data_get($payload, 'current_baseline_summary.article_en_count'));
        $this->assertSame(25, data_get($payload, 'current_baseline_summary.article_zh_count'));
        $this->assertSame(0, data_get($payload, 'current_baseline_summary.article_en_missing_cover_alt_count'));
        $this->assertSame(0, data_get($payload, 'current_baseline_summary.article_en_alt_contains_chinese_count'));
        $this->assertSame(72, data_get($payload, 'current_baseline_summary.career_guides_missing_og_image_count'));
        $this->assertFalse((bool) data_get($payload, 'authority_contract.frontend_fallback_can_satisfy_media_authority'));
    }

    #[Test]
    public function article_cover_metadata_matches_committed_baseline_authority(): void
    {
        $enArticles = $this->articles('content_baselines/articles/articles.en.json');
        $zhArticles = $this->articles('content_baselines/articles/articles.zh-CN.json');

        $this->assertCount(20, $enArticles);
        $this->assertCount(25, $zhArticles);

        foreach ($enArticles as $article) {
            $this->assertNotEmpty($article['cover_image_url'] ?? null, (string) ($article['slug'] ?? 'unknown'));
            $this->assertNotEmpty($article['cover_image_alt'] ?? null, (string) ($article['slug'] ?? 'unknown'));
            $this->assertIsArray($article['cover_image_variants'] ?? null, (string) ($article['slug'] ?? 'unknown'));
            $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', (string) ($article['cover_image_alt'] ?? ''));
        }

        $sharedCoverSlugs = collect($enArticles)
            ->filter(function (array $article) use ($zhArticles): bool {
                $zh = collect($zhArticles)->firstWhere('slug', $article['slug'] ?? null);

                return is_array($zh)
                    && ($zh['cover_image_url'] ?? null) === ($article['cover_image_url'] ?? null);
            })
            ->pluck('slug')
            ->sort()
            ->values()
            ->all();

        $payload = $this->generatedPayload();
        $expected = $payload['article_cover_parity']['shared_cover_pair_slugs'] ?? [];
        sort($expected);

        $this->assertSame($expected, $sharedCoverSlugs);
        $this->assertCount(19, $sharedCoverSlugs);
    }

    #[Test]
    public function career_guide_social_images_remain_explicitly_missing_not_frontend_fallback(): void
    {
        $enGuides = $this->guides('content_baselines/career_guides/career_guides.en.json');
        $zhGuides = $this->guides('content_baselines/career_guides/career_guides.zh-CN.json');

        $this->assertCount(36, $enGuides);
        $this->assertCount(36, $zhGuides);

        foreach (array_merge($enGuides, $zhGuides) as $guide) {
            $seo = $guide['seo_meta'] ?? [];
            $this->assertEmpty($seo['og_image_url'] ?? null, (string) ($guide['guide_code'] ?? 'unknown'));
            $this->assertEmpty($seo['twitter_image_url'] ?? null, (string) ($guide['guide_code'] ?? 'unknown'));
        }

        $payload = $this->generatedPayload();
        $this->assertFalse((bool) data_get($payload, 'career_guide_media_parity.og_image_authority_present'));
        $this->assertSame(
            'defer_to_human_reviewed_media_variant_package_before_public_exposure',
            data_get($payload, 'career_guide_media_parity.missing_social_image_policy')
        );
    }

    #[Test]
    public function visual_embedded_text_gaps_are_sidecarized_with_asset_scope(): void
    {
        $payload = $this->generatedPayload();
        $sidecarIds = collect($payload['sidecar_media_review_items'] ?? [])
            ->pluck('id')
            ->all();

        $this->assertFalse((bool) ($payload['visual_ocr_performed'] ?? true));
        $this->assertContains('en_parity_06_shared_article_cover_visual_text_review', $sidecarIds);
        $this->assertContains('en_parity_06_career_guide_social_images', $sidecarIds);
        $this->assertContains('en_parity_06_mbti_default_share_visual_review', $sidecarIds);

        foreach ($payload['sidecar_media_review_items'] ?? [] as $item) {
            $this->assertNotEmpty($item['asset_scope'] ?? null);
            $this->assertNotEmpty($item['usage_pages'] ?? null);
            $this->assertNotEmpty($item['follow_up_recommendation'] ?? null);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articles(string $path): array
    {
        $payload = json_decode((string) file_get_contents(base_path('../'.$path)), true, 512, JSON_THROW_ON_ERROR);

        return $payload['articles'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function guides(string $path): array
    {
        $payload = json_decode((string) file_get_contents(base_path('../'.$path)), true, 512, JSON_THROW_ON_ERROR);

        return $payload['guides'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function generatedPayload(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/en-parity-06-media-assets-parity-inventory.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
