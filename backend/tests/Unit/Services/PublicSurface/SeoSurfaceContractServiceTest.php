<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PublicSurface;

use App\Services\PublicSurface\SeoSurfaceContractService;
use Tests\TestCase;

final class SeoSurfaceContractServiceTest extends TestCase
{
    public function test_it_builds_a_backend_owned_seo_surface_contract(): void
    {
        $service = new SeoSurfaceContractService;

        $contract = $service->build([
            'metadata_scope' => 'public_indexable_detail',
            'surface_type' => 'article_public_detail',
            'canonical_url' => 'https://staging.fermatmind.com/en/articles/how-to-read-mbti-results',
            'robots_policy' => 'index,follow',
            'title' => 'How to Read MBTI Results',
            'description' => 'A practical guide to reading MBTI results.',
            'og_payload' => [
                'title' => 'How to Read MBTI Results',
                'description' => 'A practical guide to reading MBTI results.',
                'type' => 'article',
                'url' => 'https://staging.fermatmind.com/en/articles/how-to-read-mbti-results',
            ],
            'twitter_payload' => [
                'card' => 'summary_large_image',
                'title' => 'How to Read MBTI Results',
                'description' => 'A practical guide to reading MBTI results.',
            ],
            'alternates' => [
                'en' => 'https://staging.fermatmind.com/en/articles/how-to-read-mbti-results',
                'zh-CN' => 'https://staging.fermatmind.com/zh/articles/how-to-read-mbti-results',
            ],
            'structured_data' => [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'mainEntity' => [
                    '@type' => 'BreadcrumbList',
                ],
            ],
        ]);

        $this->assertSame('seo.surface.v1', $contract['metadata_contract_version']);
        $this->assertSame('public_indexable_detail', $contract['metadata_scope']);
        $this->assertSame('article_public_detail', $contract['surface_type']);
        $this->assertSame('https://staging.fermatmind.com/en/articles/how-to-read-mbti-results', $contract['canonical_url']);
        $this->assertSame('index,follow', $contract['robots_policy']);
        $this->assertSame('How to Read MBTI Results', $contract['title']);
        $this->assertSame('A practical guide to reading MBTI results.', $contract['description']);
        $this->assertSame('indexable', $contract['indexability_state']);
        $this->assertSame('included', $contract['sitemap_state']);
        $this->assertSame('allow', $contract['llms_exposure_state']);
        $this->assertSame(['Article', 'BreadcrumbList'], $contract['structured_data_keys']);
        $this->assertIsString($contract['metadata_fingerprint']);
        $this->assertNotSame('', $contract['metadata_fingerprint']);
    }

    public function test_it_defaults_non_indexable_states_from_robots_policy(): void
    {
        $service = new SeoSurfaceContractService;

        $contract = $service->build([
            'metadata_scope' => 'public_share_safe',
            'surface_type' => 'mbti_share_public_safe',
            'canonical_url' => 'https://staging.fermatmind.com/zh/share/share_123',
            'robots_policy' => 'noindex,follow',
            'title' => 'INTJ - Architect',
            'description' => 'Public share-safe summary',
        ]);

        $this->assertSame('noindex', $contract['indexability_state']);
        $this->assertSame('excluded', $contract['sitemap_state']);
        $this->assertSame('withhold', $contract['llms_exposure_state']);
        $this->assertNull($contract['runtime_artifact_ref']);
    }
}
