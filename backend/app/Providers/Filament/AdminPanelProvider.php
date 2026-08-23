<?php

namespace App\Providers\Filament;

use App\Filament\Ops\Pages\OpsDashboard;
use App\Filament\Ops\Pages\OpsLogin;
use App\Http\Middleware\BindOpsLoginResponse;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\LocalizeOpsUiResponse;
use App\Http\Middleware\OpsAccessControl;
use App\Http\Middleware\RequireOpsOrgSelected;
use App\Http\Middleware\ResolveOrgContext;
use App\Http\Middleware\SetOpsLocale;
use App\Http\Middleware\SetOpsRequestContext;
use App\Http\Middleware\VerifyCsrfToken;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ops')
            ->path('ops')
            ->login(OpsLogin::class)
            ->authGuard((string) config('admin.guard', 'admin'))
            ->brandName('Fermat Ops')
            ->colors([
                'danger' => '#DC2626',
                'gray' => Color::Gray,
                'info' => '#2563EB',
                'primary' => [
                    50 => '241, 242, 255',
                    100 => '224, 226, 255',
                    200 => '199, 203, 255',
                    300 => '165, 171, 250',
                    400 => '129, 140, 248',
                    500 => '99, 102, 241',
                    600 => '79, 91, 213',
                    700 => '67, 75, 184',
                    800 => '55, 61, 148',
                    900 => '49, 54, 117',
                    950 => '29, 31, 68',
                ],
                'success' => '#16A34A',
                'warning' => '#D97706',
            ])
            ->font('Inter')
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Light)
            ->theme('ops-theme')
            ->sidebarWidth('14.75rem')
            ->collapsedSidebarWidth('4rem')
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldSuffix('⌘K')
            ->discoverResources(in: app_path('Filament/Ops/Resources'), for: 'App\\Filament\\Ops\\Resources')
            ->discoverPages(in: app_path('Filament/Ops/Pages'), for: 'App\\Filament\\Ops\\Pages')
            ->pages([
                OpsDashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.workspace')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.content')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.commerce')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.content_release')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.legacy_content')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.content_overview')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.support')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.translation')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.operations')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.insights')),
                NavigationGroup::make()
                    ->label(fn (): string => (string) __('ops.group.governance')),
            ])
            ->discoverWidgets(in: app_path('Filament/Ops/Widgets'), for: 'App\\Filament\\Ops\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetOpsRequestContext::class,
                ResolveOrgContext::class,
                SetOpsLocale::class,
                LocalizeOpsUiResponse::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                BindOpsLoginResponse::class,
                EnsureAdminTotpVerified::class,
                RequireOpsOrgSelected::class,
                OpsAccessControl::class,
            ])
            ->persistentMiddleware([
                SetOpsRequestContext::class,
                ResolveOrgContext::class,
                SetOpsLocale::class,
                LocalizeOpsUiResponse::class,
                EnsureAdminTotpVerified::class,
                RequireOpsOrgSelected::class,
                OpsAccessControl::class,
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn () => view('filament.ops.hooks.login-intro')
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.ops.hooks.topbar-context')
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn () => view('filament.ops.hooks.topbar-controls')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.ops.hooks.livewire-page-expired-recovery')
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn () => view('filament.ops.hooks.sidebar-footer')
            )
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
