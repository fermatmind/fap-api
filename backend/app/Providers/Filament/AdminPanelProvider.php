<?php

namespace App\Providers\Filament;

use App\Filament\Ops\Pages\OpsDashboard;
use App\Http\Middleware\BindOpsLoginResponse;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\OpsAccessControl;
use App\Http\Middleware\RequireOpsOrgSelected;
use App\Http\Middleware\ResolveOrgContext;
use App\Http\Middleware\SetOpsLocale;
use App\Http\Middleware\SetOpsRequestContext;
use App\Http\Middleware\VerifyCsrfToken;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Panel;
use Filament\PanelProvider;
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
            ->login()
            ->authGuard((string) config('admin.guard', 'admin'))
            ->brandName('Fermat Ops')
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
                SetOpsLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                BindOpsLoginResponse::class,
                SetOpsRequestContext::class,
                ResolveOrgContext::class,
                EnsureAdminTotpVerified::class,
                RequireOpsOrgSelected::class,
                OpsAccessControl::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn () => view('filament.ops.livewire.locale-switcher-hook')
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn () => view('filament.ops.livewire.current-org-switcher-hook')
            )
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
