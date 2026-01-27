<?php

namespace App\Providers\Filament;

use App\Filament\Resources\AdminUserResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\ContentReleaseResource;
use App\Filament\Resources\DeployResource;
use App\Filament\Widgets\HealthzStatusWidget;
use App\Filament\Widgets\FunnelWidget;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Routing\Middleware\SubstituteBindings;
use App\Http\Middleware\VerifyCsrfToken;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard((string) config('admin.guard', 'admin'))
            ->brandName('Fermat Admin')
            ->resources([
                AdminUserResource::class,
                RoleResource::class,
                PermissionResource::class,
                AuditLogResource::class,
                ContentReleaseResource::class,
                DeployResource::class,
            ])
            ->widgets([
                HealthzStatusWidget::class,
                FunnelWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
