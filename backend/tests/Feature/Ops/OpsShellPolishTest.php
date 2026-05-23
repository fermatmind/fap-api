<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticlePublishingOpsPage;
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

        $this->assertSame('Psychometrics', QuestionAnalyticsPage::getNavigationGroup());

        $this->assertSame('Translation', ArticleTranslationOpsPage::getNavigationGroup());

        $this->assertSame('Publishing Ops', ContentReleasePage::getNavigationGroup());
        $this->assertSame('Publishing Ops', PostReleaseObservabilityPage::getNavigationGroup());

        $this->assertSame('SEO & Growth', ArticlePublishingOpsPage::getNavigationGroup());
        $this->assertSame('SEO & Growth', SeoOperationsPage::getNavigationGroup());

        $this->assertSame('Governance', AdminUserResource::getNavigationGroup());
        $this->assertSame('Governance', OrganizationResource::getNavigationGroup());
    }

    public function test_ops_dashboard_stats_keep_severity_and_funnel_contract_visible(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));

        $this->assertStringContainsString('--ops-state-danger', $theme);
        $this->assertStringContainsString('.fi-wi-stats-overview-stat:has(.fi-color-danger)', $theme);
        $this->assertStringContainsString('border-inline-start-width: 4px', $theme);

        app()->setLocale('en');
        $this->assertStringContainsString(
            'Landing > Test start > Submit > Paid unlock',
            (string) __('ops.widgets.no_funnel_events_7d'),
        );

        app()->setLocale('zh_CN');
        $this->assertStringContainsString(
            '落地页 > 开始测评 > 提交 > 付费解锁',
            (string) __('ops.widgets.no_funnel_events_7d'),
        );
    }

    public function test_ops_theme_tokens_keep_industrial_radius_and_tracking_constraints(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));

        foreach (['--ops-radius-input: 8px;', '--ops-radius-button: 8px;', '--ops-radius-card: 8px;', '--ops-radius-overlay: 8px;'] as $token) {
            $this->assertStringContainsString($token, $theme);
        }

        foreach (['--ops-bg-telemetry: #111827;', '--ops-state-success-signal: #00ff66;', '--ops-border-strong: #b8c0cc;'] as $token) {
            $this->assertStringContainsString($token, $theme);
        }

        $this->assertDoesNotMatchRegularExpression('/letter-spacing:\\s*-/', $theme);
        $this->assertDoesNotMatchRegularExpression('/border-radius:\\s*(1[0-9]|20)px;/', $theme);
        $this->assertDoesNotMatchRegularExpression('/border-radius:\\s*calc\\(var\\(--ops-radius-card\\)\\s*\\+/', $theme);
    }
}
