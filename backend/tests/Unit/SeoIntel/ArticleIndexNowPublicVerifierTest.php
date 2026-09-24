<?php

declare(strict_types=1);

namespace Tests\Unit\SeoIntel;

use App\Models\ArticleSeoMeta;
use App\Services\SeoIntel\SearchChannelQueue\ArticleIndexNowPublicVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ArticleIndexNowPublicVerifierTest extends TestCase
{
    public function test_requires_live_self_canonical_indexable_html_with_current_metadata_and_body(): void
    {
        $url = 'https://fermatmind.com/zh/articles/example';
        $seo = new ArticleSeoMeta(['seo_title' => 'Current title', 'seo_description' => 'Current description']);
        $main = str_repeat('Real public article content with source boundaries. ', 10);
        Http::fake([$url => Http::response('<html><head><title>Current title | FermatMind</title>'
            .'<link rel="canonical" href="'.$url.'"><meta name="robots" content="index, follow">'
            .'<meta name="description" content="Current description"></head><body><main><h1>Article title</h1>'
            .$main.'</main></body></html>', 200)]);

        self::assertSame([], app(ArticleIndexNowPublicVerifier::class)->issues($url, $seo));
        Http::assertSentCount(1);
    }

    public function test_holds_redirect_noindex_wrong_canonical_and_empty_body(): void
    {
        $url = 'https://fermatmind.com/zh/articles/example';
        $seo = new ArticleSeoMeta(['seo_title' => 'Current title', 'seo_description' => 'Current description']);
        Http::fakeSequence()
            ->push('', 301)
            ->push('<html><head><title>Old title</title>'
                .'<link rel="canonical" href="https://fermatmind.com/zh/articles/other">'
                .'<meta name="robots" content="noindex, index, follow">'
                .'<meta name="description" content="Old description"></head><body><main></main></body></html>', 200);
        self::assertSame(['public_html_not_200'], app(ArticleIndexNowPublicVerifier::class)->issues($url, $seo));

        $issues = app(ArticleIndexNowPublicVerifier::class)->issues($url, $seo);
        self::assertContains('public_canonical_mismatch', $issues);
        self::assertContains('public_robots_not_indexable', $issues);
        self::assertContains('public_title_not_current', $issues);
        self::assertContains('public_description_not_current', $issues);
        self::assertContains('public_article_body_missing', $issues);
    }
}
