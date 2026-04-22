<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerGuideResource\Pages;

use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditCareerGuide extends EditRecord
{
    protected static string $resource = CareerGuideResource::class;

    /**
     * @var array<int, array{career_job_id: int}>
     */
    protected array $workspaceRelatedJobsState = [];

    /**
     * @var array<int, array{personality_profile_id: int}>
     */
    protected array $workspaceRelatedPersonalityProfilesState = [];

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceSeoState = [];

    public function getTitle(): string|Htmlable
    {
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : __('ops.resources.career_guides.edit_title');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.career_guides.edit_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCareerGuides')
                ->label(__('ops.resources.career_guides.actions.all'))
                ->url(CareerGuideResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('ops.actions.save_changes'))
            ->icon('heroicon-o-check-circle');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label(__('ops.resources.career_guides.actions.back_to_list'))
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
            'workspace_related_jobs' => CareerGuideWorkspace::workspaceRelatedJobsFromRecord($this->getRecord()),
            'workspace_related_personality_profiles' => CareerGuideWorkspace::workspaceRelatedPersonalityProfilesFromRecord($this->getRecord()),
            'workspace_seo' => CareerGuideWorkspace::workspaceSeoFromRecord($this->getRecord()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locale = CareerGuideWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));

        $this->workspaceRelatedJobsState = CareerGuideWorkspace::normalizeRelatedJobRows(
            is_array($data['workspace_related_jobs'] ?? null) ? $data['workspace_related_jobs'] : [],
            $locale,
        );
        $this->workspaceRelatedPersonalityProfilesState = CareerGuideWorkspace::normalizeRelatedPersonalityRows(
            is_array($data['workspace_related_personality_profiles'] ?? null) ? $data['workspace_related_personality_profiles'] : [],
            $locale,
        );
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];

        unset(
            $data['workspace_related_jobs'],
            $data['workspace_related_personality_profiles'],
            $data['workspace_seo'],
        );

        $guideCode = CareerGuideWorkspace::normalizeGuideCode(
            (string) ($data['guide_code'] ?? ''),
            (string) ($data['slug'] ?? ''),
        );

        $data['org_id'] = 0;
        $data['guide_code'] = $guideCode;
        $data['slug'] = CareerGuideWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $guideCode);
        $data['locale'] = $locale;
        $data['related_industry_slugs_json'] = CareerGuideWorkspace::normalizeIndustrySlugs($data['related_industry_slugs_json'] ?? []);
        $data['schema_version'] = 'v1';

        return $data;
    }

    protected function afterSave(): void
    {
        CareerGuideWorkspace::syncRelatedJobs($this->getRecord(), $this->workspaceRelatedJobsState);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($this->getRecord(), $this->workspaceRelatedPersonalityProfilesState);
        CareerGuideWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        CareerGuideWorkspace::createRevision($this->getRecord(), __('ops.resources.common.revisions.workspace_update'));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('ops.resources.career_guides.notifications.updated');
    }

    protected function getRedirectUrl(): string
    {
        return CareerGuideResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
