<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Ops\RiasecGlobalCmsApplyBridge;
use App\Support\Rbac\PermissionNames;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use RuntimeException;

/** @review-surface riasec_content_release_review */
final class RiasecGlobalCmsApplyPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'riasec-global-cms-apply';

    protected static string $view = 'filament.ops.pages.riasec-global-cms-apply-page';

    public string $beforeSnapshotJson = '';

    public string $targetPackageJson = '';

    public string $expectedDeployedSha = '';

    public string $expectedReleaseId = '';

    public string $preflightFingerprint = '';

    public string $operatorApprovalPhrase = '';

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
        $actorAdminId = $this->authorizeOwner();
        $this->validateEvidence();
        $this->preflightFingerprint = '';
        $this->operatorApprovalPhrase = '';

        try {
            $this->receipt = $bridge->preflight(
                $this->beforeSnapshotJson,
                $this->targetPackageJson,
                $actorAdminId,
                $this->expectedDeployedSha,
                $this->expectedReleaseId,
            );
            $this->preflightFingerprint = (string) ($this->receipt['preflight_fingerprint'] ?? '');
            Notification::make()->title('Exact package preflight passed')->success()->send();
        } catch (RuntimeException $exception) {
            $this->receipt = [];
            Notification::make()->title('Preflight blocked')->body($exception->getMessage())->danger()->send();
        }
    }

    public function applyExactPackage(RiasecGlobalCmsApplyBridge $bridge): void
    {
        $actorAdminId = $this->authorizeOwner();
        $this->validateEvidence(requiresAuthorization: true);

        try {
            $this->receipt = $bridge->apply(
                $this->beforeSnapshotJson,
                $this->targetPackageJson,
                $actorAdminId,
                $this->expectedDeployedSha,
                $this->expectedReleaseId,
                $this->preflightFingerprint,
                $this->operatorApprovalPhrase,
                $this->requestContext(),
            );
            Notification::make()->title('Exact RIASEC CMS package applied')->success()->send();
        } catch (RuntimeException $exception) {
            $this->receipt = [];
            Notification::make()->title('Apply blocked')->body($exception->getMessage())->danger()->send();
        }
    }

    public function preflightExactRollback(RiasecGlobalCmsApplyBridge $bridge): void
    {
        $actorAdminId = $this->authorizeOwner();
        $this->validateEvidence();
        $this->preflightFingerprint = '';
        $this->operatorApprovalPhrase = '';

        try {
            $this->receipt = $bridge->preflightRollback(
                $this->beforeSnapshotJson,
                $this->targetPackageJson,
                $actorAdminId,
                $this->expectedDeployedSha,
                $this->expectedReleaseId,
            );
            $this->preflightFingerprint = (string) ($this->receipt['preflight_fingerprint'] ?? '');
            Notification::make()->title('Exact rollback preflight passed')->success()->send();
        } catch (RuntimeException $exception) {
            $this->receipt = [];
            Notification::make()->title('Rollback preflight blocked')->body($exception->getMessage())->danger()->send();
        }
    }

    public function rollbackExactPackage(RiasecGlobalCmsApplyBridge $bridge): void
    {
        $actorAdminId = $this->authorizeOwner();
        $this->validateEvidence(requiresAuthorization: true);

        try {
            $this->receipt = $bridge->rollback(
                $this->beforeSnapshotJson,
                $this->targetPackageJson,
                $actorAdminId,
                $this->expectedDeployedSha,
                $this->expectedReleaseId,
                $this->preflightFingerprint,
                $this->operatorApprovalPhrase,
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

    private function validateEvidence(bool $requiresAuthorization = false): void
    {
        $rules = [
            'beforeSnapshotJson' => ['required', 'string', 'max:50000'],
            'targetPackageJson' => ['required', 'string', 'max:50000'],
            'expectedDeployedSha' => ['required', 'regex:/^[0-9a-f]{40}$/'],
            'expectedReleaseId' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/'],
        ];
        if ($requiresAuthorization) {
            $rules['preflightFingerprint'] = ['required', 'regex:/^[0-9a-f]{64}$/'];
            $rules['operatorApprovalPhrase'] = ['required', 'string', 'max:1000'];
        }

        $this->validate($rules);
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
