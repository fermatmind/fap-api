<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\DataPageResource\Pages;

use App\Filament\Ops\Resources\DataPageResource;
use App\Filament\Ops\Resources\DataPageResource\Support\DataPageWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateDataPage extends CreateRecord
{
    protected static string $resource = DataPageResource::class;

    protected array $workspaceSeoState = [];

    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return 'Create Data Page';
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill([
            ...DataPageWorkspace::defaultFormState(),
            'workspace_governance' => ContentGovernanceService::defaultStateFor(
                DataPageResource::getModel(),
                $this->currentAdminId(),
            ),
        ]);

        $this->callHook('afterFill');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToDataPages')
                ->label('All Data Pages')
                ->url(DataPageResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset($data['workspace_seo'], $data['workspace_governance']);

        $dataCode = DataPageWorkspace::normalizeDataCode((string) ($data['data_code'] ?? ''));
        $data['org_id'] = 0;
        $data['data_code'] = $dataCode;
        $data['slug'] = DataPageWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $dataCode);
        $data['locale'] = DataPageWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
        $data['created_by_admin_user_id'] = $this->currentAdminId();
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        try {
            IntentRegistryService::assertNoConflict(
                DataPageResource::getModel(),
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
        DataPageWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
        $this->getRecord()->unsetRelation('seoMeta');
        DataPageWorkspace::createRevision($this->getRecord(), 'Initial workspace snapshot');
    }

    protected function getRedirectUrl(): string
    {
        return DataPageResource::getUrl('edit', ['record' => $this->getRecord()]);
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
