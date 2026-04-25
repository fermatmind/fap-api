<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Ops\GoLiveGateService;
use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;

class GoLiveGatePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'go-live-gate';

    protected static string $view = 'filament.ops.pages.go-live-gate-page';

    /** @var array<string,mixed> */
    public array $gate = [];

    public function mount(GoLiveGateService $service): void
    {
        $this->gate = $this->localizedGate($service->snapshot());
    }

    public function runChecks(GoLiveGateService $service): void
    {
        $this->gate = $this->localizedGate($service->run());
    }

    public function refreshChecks(GoLiveGateService $service): void
    {
        $this->gate = $this->localizedGate($service->snapshot());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.go_live_gate');
    }

    public function getTitle(): string
    {
        return __('ops.custom_pages.go_live_gate.title');
    }

    public static function getLabel(): string
    {
        return __('ops.custom_pages.go_live_gate.title');
    }

    public function getHeading(): string
    {
        return __('ops.custom_pages.go_live_gate.heading');
    }

    public function getBreadcrumb(): string
    {
        return __('ops.custom_pages.go_live_gate.breadcrumb');
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public function groupLabel(string $groupKey, array $group): string
    {
        return $this->translated("ops.custom_pages.go_live_gate.groups.{$groupKey}", (string) ($group['label'] ?? $groupKey));
    }

    /**
     * @param  array<string, mixed>  $check
     */
    public function checkLabel(array $check): string
    {
        $key = (string) ($check['key'] ?? '');

        return $this->translated("ops.custom_pages.go_live_gate.checks.{$key}.label", $key !== '' ? $key : '-');
    }

    /**
     * @param  array<string, mixed>  $check
     */
    public function checkMessage(array $check): string
    {
        $key = (string) ($check['key'] ?? '');

        if (str_starts_with($key, 'provider_') && str_ends_with($key, '_configured')) {
            $provider = substr($key, strlen('provider_'), -strlen('_configured'));

            return __('ops.custom_pages.go_live_gate.checks.provider_configured.message', [
                'provider' => $provider,
            ]);
        }

        return $this->translated(
            "ops.custom_pages.go_live_gate.checks.{$key}.message",
            (string) ($check['message'] ?? '')
        );
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

    private function translated(string $key, string $fallback): string
    {
        $value = (string) __($key);

        return $value !== $key ? $value : $fallback;
    }

    /**
     * @param  array<string, mixed>  $gate
     * @return array<string, mixed>
     */
    private function localizedGate(array $gate): array
    {
        $groups = [];

        foreach ((array) ($gate['groups'] ?? []) as $groupKey => $group) {
            $group = (array) $group;
            $checks = [];

            foreach ((array) ($group['checks'] ?? []) as $check) {
                $check = (array) $check;
                $check['label'] = $this->checkLabel($check);
                $check['message'] = $this->checkMessage($check);
                $checks[] = $check;
            }

            $group['label'] = $this->groupLabel((string) $groupKey, $group);
            $group['checks'] = $checks;
            $groups[$groupKey] = $group;
        }

        $gate['groups'] = $groups;

        return $gate;
    }
}
