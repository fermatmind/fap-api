<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\DataPageResource\Pages;

use App\Filament\Ops\Resources\DataPageResource;
use App\Filament\Ops\Resources\DataPageResource\Support\DataPageWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditDataPage extends EditRecord
{
    protected static string $resource = DataPageResource::class;

    protected array $workspaceSeoState = [];

    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : 'Edit Data Page';
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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...$data,
            'workspace_seo' => DataPageWorkspace::workspaceSeoFromRecord($this->getRecord()),
            'workspace_governance' => ContentGovernanceService::stateFromRecord($this->getRecord()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset($data['workspace_seo'], $data['workspace_governance']);

        $dataCode = DataPageWorkspace::normalizeDataCode((string) ($data['data_code'] ?? ''));
        $data['org_id'] = 0;
        $data['data_code'] = $dataCode;
        $data['slug'] = DataPageWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $dataCode);
        $data['locale'] = DataPageWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
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
        DataPageWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
        $this->getRecord()->unsetRelation('seoMeta');
        DataPageWorkspace::createRevision($this->getRecord(), 'Workspace update');
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
