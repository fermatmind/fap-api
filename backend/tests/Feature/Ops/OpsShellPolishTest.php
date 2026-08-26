<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticlePublishingOpsPage;
use App\Filament\Ops\Pages\ArticleTranslationOpsPage;
use App\Filament\Ops\Pages\ContentReleasePage;
use App\Filament\Ops\Pages\ContentWorkspacePage;
use App\Filament\Ops\Pages\OpsDashboard;
use App\Filament\Ops\Pages\PostReleaseObservabilityPage;
use App\Filament\Ops\Pages\QuestionAnalyticsPage;
use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Filament\Ops\Resources\AdminUserResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\AuditLogResource;
use App\Filament\Ops\Resources\ContentPageResource;
use App\Filament\Ops\Resources\DailyGivingRecordResource;
use App\Filament\Ops\Resources\DeployResource;
use App\Filament\Ops\Resources\MediaAssetResource;
use App\Filament\Ops\Resources\OrganizationResource;
use App\Filament\Ops\Resources\PermissionResource;
use App\Filament\Ops\Resources\RoleResource;
use App\Filament\Ops\Resources\SupportArticleResource;
use App\Livewire\Filament\Ops\Livewire\LocaleSwitcher;
use Livewire\Livewire;
use Tests\TestCase;

final class OpsShellPolishTest extends TestCase
{
    public function test_governance_and_observability_resources_use_chinese_model_labels(): void
    {
        app()->setLocale('zh_CN');

        $this->assertSame('审计日志', AuditLogResource::getModelLabel());
        $this->assertSame('部署事件', DeployResource::getModelLabel());
        $this->assertSame('管理员用户', AdminUserResource::getModelLabel());
        $this->assertSame('权限', PermissionResource::getModelLabel());
        $this->assertSame('角色', RoleResource::getModelLabel());
        $this->assertSame('组织', OrganizationResource::getModelLabel());
    }

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

    public function test_shell_hooks_render_environment_badge_and_compact_system_footer(): void
    {
        app()->setLocale('en');

        $environmentBadge = view('filament.ops.components.ops-environment-badge')->render();
        $sidebarFooter = view('filament.ops.hooks.sidebar-footer')->render();

        $this->assertStringContainsString('ops-environment-badge', $environmentBadge);
        $this->assertStringContainsString('Ready', $sidebarFooter);
        $this->assertStringNotContainsString('System status', $sidebarFooter);
        $this->assertStringNotContainsString('Operations console', $sidebarFooter);
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
        $this->assertSame('Editorial Tools', SupportArticleResource::getNavigationGroup());
        $this->assertSame('Content', ContentPageResource::getNavigationGroup());
        $this->assertSame('Content', MediaAssetResource::getNavigationGroup());
        $this->assertSame('Content', ContentWorkspacePage::getNavigationGroup());
        $this->assertSame('Workspace', OpsDashboard::getNavigationGroup());
        $this->assertSame('CONTENT', DailyGivingRecordResource::getNavigationGroup());

        $this->assertSame('Psychometrics', QuestionAnalyticsPage::getNavigationGroup());

        $this->assertSame('Translation', ArticleTranslationOpsPage::getNavigationGroup());

        $this->assertSame('Publishing Ops', ContentReleasePage::getNavigationGroup());
        $this->assertSame('Publishing Ops', PostReleaseObservabilityPage::getNavigationGroup());

        $this->assertSame('SEO & Growth', ArticlePublishingOpsPage::getNavigationGroup());
        $this->assertSame('SEO & Growth', SeoOperationsPage::getNavigationGroup());

        $this->assertSame('Governance', AdminUserResource::getNavigationGroup());
        $this->assertSame('Governance', OrganizationResource::getNavigationGroup());
    }

    public function test_ops_dashboard_stats_keep_precision_console_and_funnel_contract_visible(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));

        $this->assertStringContainsString('--ops-state-danger', $theme);
        $this->assertStringContainsString('--ops-electric: #4f5bd5;', $theme);
        $this->assertStringContainsString('border-inline-start-width: 1px;', $theme);
        $this->assertStringContainsString('background: var(--ops-bg-surface);', $theme);

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

    public function test_requested_ops_surfaces_omit_redundant_helper_copy(): void
    {
        $views = [
            resource_path('views/filament/ops/pages/webhook-monitor.blade.php'),
            resource_path('views/filament/ops/pages/queue-monitor.blade.php'),
            resource_path('views/filament/ops/pages/select-org-page.blade.php'),
            resource_path('views/filament/ops/pages/test-kpi-daily-page.blade.php'),
            resource_path('views/filament/ops/pages/content-search.blade.php'),
            resource_path('views/filament/ops/pages/content-metrics.blade.php'),
            resource_path('views/filament/ops/pages/content-growth-attribution.blade.php'),
            resource_path('views/filament/ops/pages/global-search-page.blade.php'),
            resource_path('views/filament/ops/pages/delivery-tools.blade.php'),
            resource_path('views/filament/ops/pages/secure-link.blade.php'),
            resource_path('views/filament/ops/pages/public-content-health.blade.php'),
            resource_path('views/filament/ops/pages/go-live-gate-page.blade.php'),
            resource_path('views/filament/ops/widgets/ops-action-queue-widget.blade.php'),
            resource_path('views/filament/ops/widgets/test-kpi-daily-inline-widget.blade.php'),
        ];

        $source = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $views));

        foreach ([
            'signature_ok = false',
            'status or handle failure backlog',
            'test_kpi_daily_detail_desc',
            'visible_workspaces',
            'workspace_scope',
            "content_search.description')",
            "content_metrics.contract_hint')",
            "content_growth_attribution.description')",
            "content_growth_attribution.contract_hint')",
            "content_growth_attribution.dashboard_desc')",
            "content_growth_attribution.diagnostics_desc')",
            "content_growth_attribution.matrix_desc')",
            "global_search.description')",
            "delivery_tools.reason_hint')",
            "public-content-health.generated_at'",
            "go_live_gate.group_hint')",
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $source);
        }

        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));
        $this->assertStringContainsString('.fi-wi-stats-overview-stat.ops-stat-centered', $theme);
        $this->assertStringContainsString('.ops-kpi-card-centered', $theme);
        $this->assertStringContainsString('.ops-field-grid--centered', $theme);
        $this->assertStringContainsString('.ops-toolbar--center-actions', $theme);

        $growthAttribution = (string) file_get_contents(resource_path('views/filament/ops/pages/content-growth-attribution.blade.php'));
        $this->assertStringContainsString('class="ops-toolbar--center-actions"', $growthAttribution);
        $this->assertStringContainsString('class="ops-field-grid--centered"', $growthAttribution);
        $this->assertStringContainsString(':show-hints="false"', $growthAttribution);
    }

    public function test_cms_operator_surfaces_keep_compact_copy_and_alignment_contracts(): void
    {
        $contentOverview = (string) file_get_contents(resource_path('views/filament/ops/pages/content-overview.blade.php'));
        $contentWorkspace = (string) file_get_contents(resource_path('views/filament/ops/pages/content-workspace.blade.php'));
        $editorialReview = (string) file_get_contents(resource_path('views/filament/ops/pages/editorial-review.blade.php'));
        $postRelease = (string) file_get_contents(resource_path('views/filament/ops/pages/post-release-observability.blade.php'));
        $articlePublishing = (string) file_get_contents(resource_path('views/filament/ops/pages/article-publishing-ops.blade.php'));

        foreach ([$contentOverview, $contentWorkspace, $editorialReview, $postRelease, $articlePublishing] as $view) {
            $this->assertStringContainsString('class="ops-toolbar--center-actions"', $view);
        }

        foreach ([
            'content_overview.eyebrow',
            'content_overview.description',
            'content_overview.contract_label',
            'content_overview.contract_hint',
            'content_overview.lifecycle.hint',
            'content_overview.health_desc',
            'content_overview.recent_desc',
            'content_overview.empty_desc',
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $contentOverview);
        }

        foreach ([
            'content_workspace.eyebrow',
            'content_workspace.description',
            'content_workspace.permission_boundary',
            'content_workspace.permission_boundary_hint',
            'content_workspace.advanced_tools_desc',
            'content_workspace.snapshot_desc',
            'content_workspace.editorial_desc',
            'content_workspace.taxonomy_desc',
            'content_workspace.optional_types_desc',
            'content_workspace.access_model_desc',
            '$card[\'description\']',
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $contentWorkspace);
        }

        $this->assertStringContainsString(':show-hints="false"', $contentWorkspace);
        $this->assertStringContainsString('class="ops-field-grid--centered-head"', $contentWorkspace);

        foreach ([
            'editorial_review.eyebrow',
            'editorial_review.description',
            'editorial_review.approval_boundary',
            'editorial_review.approval_hint',
            'editorial_review.snapshot_desc',
            'editorial_review.queue_desc',
            'editorial_review.filters_hint',
            'editorial_review.empty_desc',
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $editorialReview);
        }

        $this->assertStringContainsString(':show-hints="false"', $editorialReview);
        $this->assertStringContainsString('empty-eyebrow=""', $editorialReview);
        $this->assertStringContainsString('empty-description=""', $editorialReview);

        foreach ([
            'post_release_observability.description',
            'post_release_observability.contract_label',
            'post_release_observability.contract_hint',
            'post_release_observability.telemetry_desc',
            'post_release_observability.published_desc',
            'post_release_observability.events_desc',
            'post_release_observability.empty_audits_eyebrow',
            'post_release_observability.empty_audits_desc',
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $postRelease);
        }

        $this->assertSame(1, substr_count($postRelease, 'post_release_observability.eyebrow'));
        $this->assertStringContainsString(':show-hints="false"', $postRelease);

        foreach ([
            'article_publishing_ops.eyebrow',
            'article_publishing_ops.description',
            'article_publishing_ops.contract_label',
            'article_publishing_ops.contract_hint',
            'article_publishing_ops.queue_desc',
            'article_publishing_ops.daily_desc',
            'article_publishing_ops.queue_table_desc',
            'article_publishing_ops.import_table_desc',
            'article_publishing_ops.release_failure_desc',
            'article_publishing_ops.empty_release_desc',
            'article_publishing_ops.review_due_desc',
            'article_publishing_ops.empty_review_desc',
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $articlePublishing);
        }

        $this->assertSame(2, substr_count($articlePublishing, 'class="ops-field-grid--centered"'));
        $this->assertSame(2, substr_count($articlePublishing, ':show-hints="false"'));
    }

    public function test_ops_theme_tokens_match_complete_preview_light_and_dark_contract(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));

        foreach (['--ops-radius-input: 7px;', '--ops-radius-button: 7px;', '--ops-radius-card: 10px;', '--ops-radius-overlay: 10px;'] as $token) {
            $this->assertStringContainsString($token, $theme);
        }

        foreach (['--ops-bg-app: #f4f6f9;', '--ops-bg-surface: #ffffff;', '--ops-border-strong: #c8cfda;'] as $token) {
            $this->assertStringContainsString($token, $theme);
        }

        foreach (['--ops-electric: #4f5bd5;', '--ops-electric-solid: #4f5bd5;', '--ops-sidebar-bg: #0a0e16;', '--ops-sidebar-text-active: #dfe2ff;'] as $token) {
            $this->assertStringContainsString($token, $theme);
        }

        foreach (['--ops-sidebar-width: 14.75rem;', '--ops-topbar-height: 3.625rem;', '--ops-content-max-width: 96.25rem;'] as $token) {
            $this->assertStringContainsString($token, $theme);
        }

        foreach (['--ops-bg-app: #0b0e17;', '--ops-bg-surface: #111625;', '--ops-electric: #818cf8;'] as $token) {
            $this->assertStringContainsString($token, $theme);
        }

        $this->assertStringContainsString('background: var(--ops-sidebar-bg) !important;', $theme);
        $this->assertStringContainsString('box-shadow: inset 2px 0 0 var(--ops-electric);', $theme);
        $this->assertStringContainsString('.fi-header > div:last-child .fi-ac > .fi-ac-action:not(:first-child)', $theme);
        $this->assertStringContainsString('.fi-sidebar-header .fi-icon-btn', $theme);
        $this->assertStringContainsString('background: var(--ops-bg-editor);', $theme);

        $this->assertDoesNotMatchRegularExpression('/border-radius:\\s*calc\\(var\\(--ops-radius-card\\)\\s*\\+/', $theme);
    }

    public function test_complete_preview_shell_has_one_authoritative_geometry_contract(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));
        $compiledTheme = (string) file_get_contents(resource_path('css/filament/ops/theme.compiled.css'));
        $panelProvider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));

        $this->assertSame(1, preg_match_all('/\\.fi-body\\.fi-panel-ops \\.fi-main\\s*\\{[^}]*max-width:\\s*var\\(--ops-content-max-width\\)/s', $theme));
        $this->assertSame(1, preg_match_all('/\\.fi-body\\.fi-panel-ops \\.fi-topbar nav\\s*\\{[^}]*height:\\s*var\\(--ops-topbar-height\\)/s', $theme));
        $this->assertDoesNotMatchRegularExpression('/\\.fi-body\\.fi-panel-ops \\.fi-main\\s*\\{[^}]*max-width:\\s*90rem/s', $theme);
        $this->assertDoesNotMatchRegularExpression('/\\.fi-body\\.fi-panel-ops \\.fi-topbar nav\\s*\\{[^}]*min-height:\\s*3\\.375rem/s', $theme);
        $this->assertStringContainsString("->sidebarWidth('14.75rem')", $panelProvider);

        foreach (['--ops-sidebar-width:14.75rem', '--ops-topbar-height:3.625rem', '--ops-content-max-width:96.25rem'] as $token) {
            $this->assertStringContainsString($token, $compiledTheme);
        }
    }

    public function test_complete_preview_shared_workspace_components_render_without_mock_values(): void
    {
        $dataStrip = view('filament.ops.components.ops-data-strip', [
            'metrics' => [['label' => 'Orders', 'value' => 0, 'meta' => 'Production']],
        ])->render();
        $notConnected = view('filament.ops.components.ops-not-connected', [
            'title' => 'Search Console',
            'description' => 'Not connected',
        ])->render();
        $topbarControls = (string) file_get_contents(resource_path('views/filament/ops/hooks/topbar-controls.blade.php'));

        $this->assertStringContainsString('ops-data-strip', $dataStrip);
        $this->assertStringContainsString('Production', $dataStrip);
        $this->assertStringContainsString('ops-not-connected', $notConnected);
        $this->assertStringContainsString('Not connected', $notConnected);
        $this->assertStringContainsString('x-filament-panels::theme-switcher', $topbarControls);
    }

    public function test_ops_custom_selects_reserve_space_for_single_chevron(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));

        $this->assertStringContainsString('.ops-input:is(select)', $theme);
        $this->assertStringContainsString('-webkit-appearance: none;', $theme);
        $this->assertStringContainsString('-moz-appearance: none;', $theme);
        $this->assertStringContainsString('background-image: url("data:image/svg+xml', $theme);
        $this->assertStringContainsString('padding-inline-end: 2.65rem;', $theme);
        $this->assertStringContainsString('text-overflow: ellipsis;', $theme);
        $this->assertStringContainsString('.ops-content-search-controls__filters .ops-input:is(select)', $theme);
    }

    public function test_ops_shared_empty_state_uses_stable_centered_panel_spacing(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));
        $compiledTheme = (string) file_get_contents(resource_path('css/filament/ops/theme.compiled.css'));
        $tailwindConfig = (string) file_get_contents(resource_path('css/filament/ops/tailwind.config.js'));
        $emptyState = view('components.filament.ops.shared.empty-state', [
            'description' => 'Use this shared empty state when a result panel has no records yet.',
            'eyebrow' => 'Support',
            'icon' => 'heroicon-o-magnifying-glass',
            'title' => 'Start searching',
        ])->render();

        $this->assertStringContainsString('ops-empty-state', $emptyState);
        $this->assertStringContainsString('ops-empty-state__icon', $emptyState);
        $this->assertStringContainsString('width: 100%;', $theme);
        $this->assertStringContainsString('min-height: 12rem;', $theme);
        $this->assertStringContainsString('align-content: center;', $theme);
        $this->assertStringContainsString('justify-items: center;', $theme);
        $this->assertStringContainsString('border-radius: var(--ops-radius-card);', $theme);
        $this->assertStringContainsString('./resources/views/components/filament/ops/**/*.blade.php', $tailwindConfig);
        $this->assertStringContainsString('.ops-empty-state', $compiledTheme);
    }
}
