<?php

declare(strict_types=1);

namespace Tests\Feature\ContentPages;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentPagePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_import_creates_public_company_and_policy_pages(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('files_found=4')
            ->expectsOutputToContain('pages_found=23')
            ->expectsOutputToContain('will_create=23')
            ->assertExitCode(0);

        $this->assertSame(23, ContentPage::query()->withoutGlobalScopes()->count());

        $about = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'about')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('关于费马测试', (string) $about->title);
        $this->assertSame('company', (string) $about->kind);
        $this->assertTrue((bool) $about->is_public);
        $this->assertContains('我们是谁', $about->headings_json ?? []);

        $privacy = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'privacy')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('policy', (string) $privacy->kind);
        $this->assertSame('2026-04-19', $privacy->effective_at?->format('Y-m-d'));

        $helpFaq = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'help-faq')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('help', (string) $helpFaq->kind);
        $this->assertSame('/help/faq', (string) $helpFaq->path);
    }

    public function test_public_api_returns_content_page_without_frontend_fallback(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/content-pages/about?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'about')
            ->assertJsonPath('page.title', '关于费马测试')
            ->assertJsonPath('page.locale', 'zh-CN')
            ->assertJsonPath('page.is_public', true)
            ->assertJsonPath('page.is_indexable', true)
            ->assertJsonPath('page.headings.0', '我们是谁');

        $this->getJson('/api/v0.5/content-pages/missing-page?locale=zh-CN&org_id=0')
            ->assertNotFound()
            ->assertJsonPath('ok', false);

        $this->getJson('/api/v0.5/content-pages/help-faq?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('page.slug', 'help-faq')
            ->assertJsonPath('page.path', '/help/faq')
            ->assertJsonPath('page.canonical_path', '/help/faq');
    }

    public function test_ops_list_and_update_are_backed_by_content_pages_table(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/internal/content-pages?locale=zh-CN&org_id=0&kind=company')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(5, 'items');

        $this->putJson('/api/v0.5/internal/content-pages/about', [
            'title' => '关于费马测试更新',
            'kicker' => 'Company',
            'summary' => '后台更新后的公司页摘要。',
            'kind' => 'company',
            'template' => 'company',
            'animation_profile' => 'mission',
            'locale' => 'zh-CN',
            'published_at' => '2026-04-19',
            'updated_at' => '2026-04-19',
            'effective_at' => null,
            'source_doc' => '01_关于费马测试.docx',
            'is_public' => true,
            'is_indexable' => true,
            'content_md' => "## 新标题\n\n后台保存正文。",
            'content_html' => '',
            'seo_title' => '关于费马测试更新',
            'meta_description' => '后台更新后的公司页摘要。',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.title', '关于费马测试更新')
            ->assertJsonPath('page.headings.0', '新标题');

        $this->assertDatabaseHas('content_pages', [
            'slug' => 'about',
            'locale' => 'zh-CN',
            'title' => '关于费马测试更新',
        ]);

        $this->putJson('/api/v0.5/internal/content-pages/help-contact', [
            'title' => '联系支持更新',
            'kicker' => 'Help',
            'summary' => '后台更新后的帮助页摘要。',
            'kind' => 'help',
            'template' => 'help',
            'animation_profile' => 'editorial',
            'locale' => 'zh-CN',
            'published_at' => '2026-04-19',
            'updated_at' => '2026-04-19',
            'effective_at' => null,
            'source_doc' => '帮助_联系支持.docx',
            'is_public' => true,
            'is_indexable' => true,
            'content_md' => "## 支持入口\n\n先完成正式流程。",
            'content_html' => '',
            'seo_title' => '联系支持更新',
            'meta_description' => '后台更新后的帮助页摘要。',
        ])
            ->assertOk()
            ->assertJsonPath('page.slug', 'help-contact')
            ->assertJsonPath('page.path', '/help/contact')
            ->assertJsonPath('page.canonical_path', '/help/contact');
    }
}
