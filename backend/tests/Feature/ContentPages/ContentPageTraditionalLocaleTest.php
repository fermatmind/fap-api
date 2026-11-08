<?php

declare(strict_types=1);

namespace Tests\Feature\ContentPages;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentPageTraditionalLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_and_caches_each_published_language_independently(): void
    {
        foreach (['zh-CN' => '关于费马测试', 'zh-TW' => '關於費馬測試', 'en' => 'About FermatMind'] as $locale => $title) {
            $this->page($locale, $title);
        }

        foreach (['zh-CN', 'zh-TW', 'en', 'zh-TW', 'zh', 'en'] as $locale) {
            $expected = $locale === 'zh' ? 'zh-CN' : $locale;
            $this->getJson('/api/v0.5/content-pages/about?locale='.$locale.'&org_id=0')
                ->assertOk()
                ->assertJsonPath('page.locale', $expected)
                ->assertJsonPath('page.title', ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->where('locale', $expected)->value('title'));
        }
    }

    public function test_missing_traditional_variant_does_not_fall_back_to_simplified(): void
    {
        $this->page('zh-CN', '关于费马测试');
        $this->getJson('/api/v0.5/content-pages/about?locale=zh-CN')->assertOk();
        $this->getJson('/api/v0.5/content-pages/about?locale=zh-TW')
            ->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_private_draft_future_and_other_org_variants_are_not_public(): void
    {
        $this->page('zh-CN', '关于费马测试');
        foreach ([
            ['status' => 'draft'],
            ['is_public' => false],
            ['published_at' => now()->addDay()->toDateString()],
            ['org_id' => 7],
        ] as $attributes) {
            $page = $this->page('zh-TW', '關於費馬測試', $attributes);
            $this->getJson('/api/v0.5/content-pages/about?locale=zh-TW&org_id=0')->assertNotFound();
            $page->delete();
        }
    }

    public function test_unsupported_language_remains_a_validation_error(): void
    {
        $this->getJson('/api/v0.5/content-pages/about?locale=zh-Hant')->assertUnprocessable();
    }

    private function page(string $locale, string $title, array $attributes = []): ContentPage
    {
        return ContentPage::query()->withoutGlobalScopes()->create(array_replace([
            'org_id' => 0,
            'slug' => 'about',
            'path' => '/about',
            'kind' => 'company',
            'page_type' => 'company',
            'title' => $title,
            'seo_title' => $title,
            'meta_description' => $title,
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => $locale,
            'content_md' => '## '.$title."\n\n".$title,
            'is_public' => true,
            'is_indexable' => true,
            'status' => 'published',
        ], $attributes));
    }
}
