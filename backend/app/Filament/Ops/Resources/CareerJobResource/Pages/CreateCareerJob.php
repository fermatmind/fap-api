<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerJobResource\Pages;

use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\CareerJobResource\Support\CareerJobWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return 'Create Career Job';
    }

    public function getSubheading(): ?string
    {
        return 'Build a structured career job in the main workspace, then finish publish and SEO cues in the side rail.';
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill([
            ...CareerJobWorkspace::defaultFormState(),
            'workspace_governance' => ContentGovernanceService::defaultStateFor(
                CareerJobResource::getModel(),
                $this->currentAdminId(),
            ),
        ]);

        $this->callHook('afterFill');
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

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Create Career Job')
            ->icon('heroicon-o-check-circle');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Create & Add Another')
            ->icon('heroicon-o-document-duplicate');
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
    protected function mutateFormDataBeforeCreate(array $data): array
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
        $data['created_by_admin_user_id'] = $this->currentAdminId();
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        try {
            IntentRegistryService::assertNoConflict(
                CareerJobResource::getModel(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? null,
                    'slug' => $data['slug'] ?? null,
                    'body_md' => $data['body_md'] ?? null,
                    'sections' => $this->workspaceSectionsState,
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
        CareerJobWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        CareerJobWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
        $this->getRecord()->unsetRelation('sections');
        $this->getRecord()->unsetRelation('seoMeta');
        CareerJobWorkspace::createRevision($this->getRecord(), 'Initial workspace snapshot', auth((string) config('admin.guard', 'admin'))->user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Career job created';
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
