<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerJobResource\Pages;

use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\CareerJobResource\Support\CareerJobWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditCareerJob extends EditRecord
{
    protected static string $resource = CareerJobResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceSectionsState = [];

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceSeoState = [];

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : 'Edit Career Job';
    }

    public function getSubheading(): ?string
    {
        return 'Maintain the career job without leaving the structured editorial workspace.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCareerJobs')
                ->label('All Career Jobs')
                ->url(CareerJobResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Save Changes')
            ->icon('heroicon-o-check-circle');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Back to Career Jobs')
            ->icon('heroicon-o-arrow-left');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...$data,
            'workspace_sections' => CareerJobWorkspace::workspaceSectionsFromRecord($this->getRecord()),
            'workspace_seo' => CareerJobWorkspace::workspaceSeoFromRecord($this->getRecord()),
            'workspace_governance' => ContentGovernanceService::stateFromRecord($this->getRecord()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->workspaceSectionsState = is_array($data['workspace_sections'] ?? null) ? $data['workspace_sections'] : [];
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset($data['workspace_sections'], $data['workspace_seo'], $data['workspace_governance']);

        $jobCode = CareerJobWorkspace::normalizeJobCode(
            (string) ($data['job_code'] ?? ''),
            (string) ($data['slug'] ?? ''),
        );

        $data['org_id'] = 0;
        $data['job_code'] = $jobCode;
        $data['slug'] = CareerJobWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $jobCode);
        $data['locale'] = CareerJobWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        try {
            IntentRegistryService::assertNoConflict(
                $this->getRecord(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? $this->getRecord()->title,
                    'slug' => $data['slug'] ?? $this->getRecord()->slug,
                    'body_md' => $data['body_md'] ?? $this->getRecord()->body_md,
                    'sections' => $this->workspaceSectionsState,
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
        CareerJobWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        CareerJobWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
        $this->getRecord()->unsetRelation('sections');
        $this->getRecord()->unsetRelation('seoMeta');
        CareerJobWorkspace::createRevision($this->getRecord(), 'Workspace update', auth((string) config('admin.guard', 'admin'))->user());
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Career job updated';
    }

    protected function getRedirectUrl(): string
    {
        return CareerJobResource::getUrl('edit', ['record' => $this->getRecord()]);
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
