<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticleTranslationOpsPage;
use App\Filament\Ops\Pages\ContentReleasePage;
use App\Filament\Ops\Pages\ContentWorkspacePage;
use App\Filament\Ops\Pages\PostReleaseObservabilityPage;
use App\Filament\Ops\Pages\QuestionAnalyticsPage;
use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Filament\Ops\Resources\AdminUserResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ContentPageResource;
use App\Filament\Ops\Resources\MediaAssetResource;
use App\Filament\Ops\Resources\OrganizationResource;
use App\Filament\Ops\Resources\SupportArticleResource;
use App\Livewire\Filament\Ops\Livewire\LocaleSwitcher;
use Livewire\Livewire;
use Tests\TestCase;

final class OpsShellPolishTest extends TestCase
{
    public function test_locale_switcher_renders_as_compact_segmented_control(): void
    {
        app()->setLocale('en');

        Livewire::test(LocaleSwitcher::class)
            ->assertSee('ops-language-switcher', false)
            ->assertSee('EN')
            ->assertSee('中文')
            ->assertDontSee('Language English')
            ->assertDontSee('语言 中文');
    }

    public function test_shell_hooks_render_command_search_environment_badge_and_system_footer(): void
    {
        app()->setLocale('en');

        $environmentBadge = view('filament.ops.components.ops-environment-badge')->render();
        $sidebarFooter = view('filament.ops.hooks.sidebar-footer')->render();

        $this->assertStringContainsString('ops-environment-badge', $environmentBadge);
        $this->assertStringContainsString('System status', $sidebarFooter);
        $this->assertStringNotContainsString('Content, commerce, governance, and runtime operations share the same shell.', $sidebarFooter);
    }

    public function test_native_filament_global_search_keeps_command_shortcut_copy(): void
    {
        app()->setLocale('en');

        $this->assertSame('Global command search', __('filament-panels::global-search.field.label'));
        $this->assertSame('Search content, orders, users...', __('filament-panels::global-search.field.placeholder'));

        app()->setLocale('zh_CN');

        $this->assertSame('全局命令搜索', __('filament-panels::global-search.field.label'));
        $this->assertSame('搜索内容、订单、用户...', __('filament-panels::global-search.field.placeholder'));
    }

    public function test_ops_navigation_groups_are_consolidated_for_shell_ia(): void
    {
        app()->setLocale('en');

        $this->assertSame('Content', ArticleResource::getNavigationGroup());
        $this->assertSame('Content', SupportArticleResource::getNavigationGroup());
        $this->assertSame('Content', ContentPageResource::getNavigationGroup());
        $this->assertSame('Content', MediaAssetResource::getNavigationGroup());
        $this->assertSame('Content', ContentWorkspacePage::getNavigationGroup());

        $this->assertSame('Translation', ArticleTranslationOpsPage::getNavigationGroup());

        $this->assertSame('Operations', ContentReleasePage::getNavigationGroup());
        $this->assertSame('Operations', PostReleaseObservabilityPage::getNavigationGroup());

        $this->assertSame('Insights', QuestionAnalyticsPage::getNavigationGroup());
        $this->assertSame('Insights', SeoOperationsPage::getNavigationGroup());

        $this->assertSame('Governance', AdminUserResource::getNavigationGroup());
        $this->assertSame('Governance', OrganizationResource::getNavigationGroup());
    }
}
