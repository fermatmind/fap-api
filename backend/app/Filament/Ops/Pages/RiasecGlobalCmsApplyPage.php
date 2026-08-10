<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Ops\RiasecGlobalCmsApplyBridge;
use App\Support\Rbac\PermissionNames;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use RuntimeException;

final class RiasecGlobalCmsApplyPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'riasec-global-cms-apply';

    protected static string $view = 'filament.ops.pages.riasec-global-cms-apply-page';

    public string $beforeSnapshotJson = '';

    public string $targetPackageJson = '';

    /** @var array<string,mixed> */
    public array $receipt = [];

    public static function canAccess(): bool
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && $user->hasPermission(PermissionNames::ADMIN_OWNER);
    }

    public function getTitle(): string
    {
        return 'RIASEC Global CMS Apply Bridge';
    }

    public function preflightExactPackage(RiasecGlobalCmsApplyBridge $bridge): void
    {
        $this->authorizeOwner();
        $this->validateEvidence();

        try {
            $this->receipt = $bridge->preflight($this->beforeSnapshotJson, $this->targetPackageJson);
            Notification::make()->title('Exact package preflight passed')->success()->send();
        } catch (RuntimeException $exception) {
            $this->receipt = [];
            Notification::make()->title('Preflight blocked')->body($exception->getMessage())->danger()->send();
        }
    }

    public function applyExactPackage(RiasecGlobalCmsApplyBridge $bridge): void
    {
        $actorAdminId = $this->authorizeOwner();
        $this->validateEvidence();

        try {
            $this->receipt = $bridge->apply(
                $this->beforeSnapshotJson,
                $this->targetPackageJson,
                $actorAdminId,
                $this->requestContext(),
            );
            Notification::make()->title('Exact RIASEC CMS package applied')->success()->send();
        } catch (RuntimeException $exception) {
            $this->receipt = [];
            Notification::make()->title('Apply blocked')->body($exception->getMessage())->danger()->send();
        }
    }

    public function rollbackExactPackage(RiasecGlobalCmsApplyBridge $bridge): void
    {
        $actorAdminId = $this->authorizeOwner();
        $this->validateEvidence();

        try {
            $this->receipt = $bridge->rollback(
                $this->beforeSnapshotJson,
                $this->targetPackageJson,
                $actorAdminId,
                $this->requestContext(),
            );
            Notification::make()->title('Exact RIASEC CMS package rolled back')->success()->send();
        } catch (RuntimeException $exception) {
            $this->receipt = [];
            Notification::make()->title('Rollback blocked')->body($exception->getMessage())->danger()->send();
        }
    }

    private function authorizeOwner(): int
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();
        abort_unless(
            is_object($user)
                && method_exists($user, 'hasPermission')
                && method_exists($user, 'getAuthIdentifier')
                && $user->hasPermission(PermissionNames::ADMIN_OWNER),
            403,
        );

        return (int) $user->getAuthIdentifier();
    }

    private function validateEvidence(): void
    {
        $this->validate([
            'beforeSnapshotJson' => ['required', 'string', 'max:50000'],
            'targetPackageJson' => ['required', 'string', 'max:50000'],
        ]);
    }

    /**
     * @return array{ip:?string,user_agent:?string,request_id:?string}
     */
    private function requestContext(): array
    {
        return [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->header('X-Request-ID'),
        ];
    }
}
