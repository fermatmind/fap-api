<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerGuideResource\Pages;

use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateCareerGuide extends CreateRecord
{
    protected static string $resource = CareerGuideResource::class;

    /**
     * @var array<int, array{career_job_id: int}>
     */
    protected array $workspaceRelatedJobsState = [];

    /**
     * @var array<int, array{article_id: int}>
     */
    protected array $workspaceRelatedArticlesState = [];

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
        return 'Create Career Guide';
    }

    public function getSubheading(): ?string
    {
        return 'Build a locale-aware career guide in the main workspace, then finish publish, SEO, and revision cues in the side rail.';
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(CareerGuideWorkspace::defaultFormState());

        $this->callHook('afterFill');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCareerGuides')
                ->label('All Career Guides')
                ->url(CareerGuideResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Create Career Guide')
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
            ->label('Back to Career Guides')
            ->icon('heroicon-o-arrow-left');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $locale = CareerGuideWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));

        $this->workspaceRelatedJobsState = CareerGuideWorkspace::normalizeRelatedJobRows(
            is_array($data['workspace_related_jobs'] ?? null) ? $data['workspace_related_jobs'] : [],
            $locale,
        );
        $this->workspaceRelatedArticlesState = CareerGuideWorkspace::normalizeRelatedArticleRows(
            is_array($data['workspace_related_articles'] ?? null) ? $data['workspace_related_articles'] : [],
            $locale,
        );
        $this->workspaceRelatedPersonalityProfilesState = CareerGuideWorkspace::normalizeRelatedPersonalityRows(
            is_array($data['workspace_related_personality_profiles'] ?? null) ? $data['workspace_related_personality_profiles'] : [],
            $locale,
        );
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];

        unset(
            $data['workspace_related_jobs'],
            $data['workspace_related_articles'],
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

    protected function afterCreate(): void
    {
        CareerGuideWorkspace::syncRelatedJobs($this->getRecord(), $this->workspaceRelatedJobsState);
        CareerGuideWorkspace::syncRelatedArticles($this->getRecord(), $this->workspaceRelatedArticlesState);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($this->getRecord(), $this->workspaceRelatedPersonalityProfilesState);
        CareerGuideWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        CareerGuideWorkspace::createRevision($this->getRecord(), 'Initial workspace snapshot');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Career guide created';
    }

    protected function getRedirectUrl(): string
    {
        return CareerGuideResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
