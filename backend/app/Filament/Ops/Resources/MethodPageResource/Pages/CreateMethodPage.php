<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\MethodPageResource\Pages;

use App\Filament\Ops\Resources\MethodPageResource;
use App\Filament\Ops\Resources\MethodPageResource\Support\MethodPageWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateMethodPage extends CreateRecord
{
    protected static string $resource = MethodPageResource::class;

    protected array $workspaceSeoState = [];

    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return 'Create Method';
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill([
            ...MethodPageWorkspace::defaultFormState(),
            'workspace_governance' => ContentGovernanceService::defaultStateFor(
                MethodPageResource::getModel(),
                $this->currentAdminId(),
            ),
        ]);

        $this->callHook('afterFill');
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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset($data['workspace_seo'], $data['workspace_governance']);

        $methodCode = MethodPageWorkspace::normalizeMethodCode((string) ($data['method_code'] ?? ''));
        $data['org_id'] = 0;
        $data['method_code'] = $methodCode;
        $data['slug'] = MethodPageWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $methodCode);
        $data['locale'] = MethodPageWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
        $data['created_by_admin_user_id'] = $this->currentAdminId();
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        try {
            IntentRegistryService::assertNoConflict(
                MethodPageResource::getModel(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? null,
                    'slug' => $data['slug'] ?? null,
                    'outline' => $data['body_md'] ?? null,
                ],
                0,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'workspace_governance.primary_query' => $e->getMessage(),
            ]);
        }

        return ContentGovernanceService::enforceReleaseManagedDraft($data);
    }

    protected function afterCreate(): void
    {
        MethodPageWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
        $this->getRecord()->unsetRelation('seoMeta');
        MethodPageWorkspace::createRevision($this->getRecord(), 'Initial workspace snapshot');
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
