<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerJobResource\Pages;

use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\CareerJobResource\Support\CareerJobWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateCareerJob extends CreateRecord
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
        return __('ops.resources.career_jobs.create_title');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.career_jobs.create_subheading');
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(CareerJobWorkspace::defaultFormState());

        $this->callHook('afterFill');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCareerJobs')
                ->label(__('ops.resources.career_jobs.actions.all'))
                ->url(CareerJobResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('ops.resources.career_jobs.actions.create'))
            ->icon('heroicon-o-check-circle');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label(__('ops.resources.common.actions.create_another'))
            ->icon('heroicon-o-document-duplicate');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label(__('ops.resources.career_jobs.actions.back_to_list'))
            ->icon('heroicon-o-arrow-left');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
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
        $data['created_by_admin_user_id'] = $this->currentAdminId();
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        return $data;
    }

    protected function afterCreate(): void
    {
        CareerJobWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        CareerJobWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        $this->getRecord()->unsetRelation('sections');
        $this->getRecord()->unsetRelation('seoMeta');
        CareerJobWorkspace::createRevision($this->getRecord(), __('ops.resources.common.revisions.initial_workspace_snapshot'), auth((string) config('admin.guard', 'admin'))->user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('ops.resources.career_jobs.notifications.created');
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
