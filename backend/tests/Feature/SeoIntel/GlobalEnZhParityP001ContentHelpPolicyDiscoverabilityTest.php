<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use App\Services\Scale\ScaleRegistry;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhParityP001ContentHelpPolicyDiscoverabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private static function invalidContentHelpPolicyPaths(): array
    {
        return [
            '/en/help/about',
            '/en/help/contact',
            '/en/help/faq',
            '/en/help/for-business-and-research',
            '/en/help/team',
            '/en/help/used-and-mentioned',
            '/zh/help/contact',
            '/zh/help/faq',
            '/zh/help/for-business-and-research',
            '/en/method-boundaries',
            '/zh/method-boundaries',
            '/zh/policies',
            '/en/privacy',
            '/zh/privacy',
            '/en/support',
            '/zh/support',
            '/en/terms',
            '/zh/terms',
        ];
    }

    #[Test]
    public function generated_artifact_records_p0_01_authority_boundary(): void
    {
        $path = base_path('docs/seo/generated/global-en-zh-parity-p0-01-content-help-policy-discoverability.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('global-en-zh-parity-p0-01-content-help-policy-discoverability.v1', $payload['schema_version'] ?? null);
        $this->assertSame('GLOBAL-EN-ZH-PARITY-P0-01', $payload['task'] ?? null);
        $this->assertContains('fap-api', $payload['repos'] ?? []);
        $this->assertContains('fap-web', $payload['repos'] ?? []);
        $this->assertSame('87345d16c4b7b22a23ead480925bb1f4c58d2721', data_get($payload, 'basis.source_scan_merge_commit'));
        $this->assertSame(self::invalidContentHelpPolicyPaths(), $payload['invalid_sitemap_paths'] ?? []);
        $this->assertSame(['/en/support', '/zh/support'], $payload['invalid_llms_paths'] ?? []);
        $this->assertTrue((bool) data_get($payload, 'backend_guard.content_pages_require_authority'));
        $this->assertFalse((bool) data_get($payload, 'backend_guard.frontend_fallback_authority_used'));
        $this->assertTrue((bool) data_get($payload, 'acceptance.no_placeholder_content_created'));
        $this->assertTrue((bool) data_get($payload, 'acceptance.no_cms_mutation_performed'));
        $this->assertFalse((bool) data_get($payload, 'acceptance.deploy_performed'));
        $this->assertFalse((bool) data_get($payload, 'acceptance.search_channel_action_performed'));
        $this->assertFalse((bool) data_get($payload, 'acceptance.url_submission_performed'));
        $this->assertSame('GLOBAL-EN-ZH-PARITY-P0-02', $payload['next_task'] ?? null);
    }

    #[Test]
    public function backend_sitemap_source_does_not_emit_missing_content_help_policy_urls_without_authority(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->app->instance(ScaleRegistry::class, $this->mockScaleRegistry());

        $this->createContentPage([
            'slug' => 'about',
            'locale' => 'en',
            'canonical_path' => '/en/about',
            'title' => 'About FermatMind',
        ]);

        $locs = array_map(
            static fn (array $row): string => (string) ($row['loc'] ?? ''),
            app(SitemapGenerator::class)->generateUrls()
        );

        $this->assertContains('https://fermatmind.com/en/about', $locs);

        foreach (self::invalidContentHelpPolicyPaths() as $path) {
            $this->assertNotContains('https://fermatmind.com'.$path, $locs, $path.' must not be exposed without content_pages authority.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createContentPage(array $overrides = []): ContentPage
    {
        return ContentPage::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'about',
            'path' => '/about',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'about',
            'title' => 'About FermatMind',
            'summary' => 'Authority-backed content page.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'translation_group_id' => 'content-page-about',
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'content_md' => '## Overview'.PHP_EOL.'Authority-backed body.',
            'content_html' => '',
            'seo_title' => 'About FermatMind',
            'seo_description' => 'Authority-backed content page.',
            'meta_description' => 'Authority-backed content page.',
            'canonical_path' => '/en/about',
            'status' => ContentPage::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    private function mockScaleRegistry(): ScaleRegistry
    {
        $registry = \Mockery::mock(ScaleRegistry::class);
        $registry->shouldReceive('listActivePublic')->andReturn([]);

        return $registry;
    }
}
