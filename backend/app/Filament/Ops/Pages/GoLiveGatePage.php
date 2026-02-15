<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Ops\GoLiveGateService;
use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;

class GoLiveGatePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Go-Live Gate';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'go-live-gate';

    protected static string $view = 'filament.ops.pages.go-live-gate-page';

    /** @var array<string,mixed> */
    public array $gate = [];

    public function mount(GoLiveGateService $service): void
    {
        $this->gate = $service->snapshot();
    }

    public function runChecks(GoLiveGateService $service): void
    {
        $this->gate = $service->run();
    }

    public function refreshChecks(GoLiveGateService $service): void
    {
        $this->gate = $service->snapshot();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.go_live_gate');
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_OWNER)
                || $user->hasPermission(PermissionNames::ADMIN_GO_LIVE_GATE)
            );
    }
}
