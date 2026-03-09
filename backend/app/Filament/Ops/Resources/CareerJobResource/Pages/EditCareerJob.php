<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerJobResource\Pages;

use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\CareerJobResource\Support\CareerJobWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

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

        unset($data['workspace_sections'], $data['workspace_seo']);

        $jobCode = CareerJobWorkspace::normalizeJobCode(
            (string) ($data['job_code'] ?? ''),
            (string) ($data['slug'] ?? ''),
        );

        $data['org_id'] = 0;
        $data['job_code'] = $jobCode;
        $data['slug'] = CareerJobWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $jobCode);
        $data['locale'] = CareerJobWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        return $data;
    }

    protected function afterSave(): void
    {
        CareerJobWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        CareerJobWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
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
