<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\MethodPageResource\Pages;

use App\Filament\Ops\Resources\MethodPageResource;
use App\Filament\Ops\Resources\MethodPageResource\Support\MethodPageWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditMethodPage extends EditRecord
{
    protected static string $resource = MethodPageResource::class;

    protected array $workspaceSeoState = [];

    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : 'Edit Method';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToMethods')
                ->label('All Methods')
                ->url(MethodPageResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...$data,
            'workspace_seo' => MethodPageWorkspace::workspaceSeoFromRecord($this->getRecord()),
            'workspace_governance' => ContentGovernanceService::stateFromRecord($this->getRecord()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset($data['workspace_seo'], $data['workspace_governance']);

        $methodCode = MethodPageWorkspace::normalizeMethodCode((string) ($data['method_code'] ?? ''));
        $data['org_id'] = 0;
        $data['method_code'] = $methodCode;
        $data['slug'] = MethodPageWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $methodCode);
        $data['locale'] = MethodPageWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        try {
            IntentRegistryService::assertNoConflict(
                $this->getRecord(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? $this->getRecord()->title,
                    'slug' => $data['slug'] ?? $this->getRecord()->slug,
                    'outline' => $data['body_md'] ?? $this->getRecord()->body_md,
                ],
                0,
                $this->getRecord(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'workspace_governance.primary_query' => $e->getMessage(),
            ]);
        }

        return ContentGovernanceService::preserveReleaseManagedState($this->getRecord(), $data);
    }

    protected function afterSave(): void
    {
        MethodPageWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
        $this->getRecord()->unsetRelation('seoMeta');
        MethodPageWorkspace::createRevision($this->getRecord(), 'Workspace update');
    }

    protected function getRedirectUrl(): string
    {
        return MethodPageResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    private function currentAdminId(): ?int
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }
}
