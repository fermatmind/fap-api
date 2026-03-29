<?php

namespace App\Providers\Filament;

use App\Filament\Ops\Pages\OpsDashboard;
use App\Filament\Ops\Pages\OpsLogin;
use App\Http\Middleware\BindOpsLoginResponse;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\OpsAccessControl;
use App\Http\Middleware\RequireOpsOrgSelected;
use App\Http\Middleware\ResolveOrgContext;
use App\Http\Middleware\SetOpsLocale;
use App\Http\Middleware\SetOpsRequestContext;
use App\Http\Middleware\VerifyCsrfToken;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
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
                    50 => '239, 246, 255',
                    100 => '219, 234, 254',
                    200 => '191, 219, 254',
                    300 => '147, 197, 253',
                    400 => '96, 165, 250',
                    500 => '59, 130, 246',
                    600 => '37, 99, 235',
                    700 => '29, 78, 216',
                    800 => '30, 64, 175',
                    900 => '30, 58, 138',
                    950 => '23, 37, 84',
                ],
                'success' => '#16A34A',
                'warning' => '#D97706',
            ])
            ->font('Instrument Sans')
            ->darkMode(false)
            ->defaultThemeMode(ThemeMode::Light)
            ->theme('ops-theme')
            ->discoverResources(in: app_path('Filament/Ops/Resources'), for: 'App\\Filament\\Ops\\Resources')
            ->discoverPages(in: app_path('Filament/Ops/Pages'), for: 'App\\Filament\\Ops\\Pages')
            ->pages([
                OpsDashboard::class,
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
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                BindOpsLoginResponse::class,
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
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn () => view('filament.ops.hooks.sidebar-footer')
            )
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
