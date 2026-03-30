<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerGuideResource\Pages;

use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateCareerGuide extends CreateRecord
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

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceGovernanceState = [];

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

        $this->form->fill([
            ...CareerGuideWorkspace::defaultFormState(),
            'workspace_governance' => ContentGovernanceService::defaultStateFor(
                CareerGuideResource::getModel(),
                $this->currentAdminId(),
            ),
        ]);

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
        $this->workspaceRelatedPersonalityProfilesState = CareerGuideWorkspace::normalizeRelatedPersonalityRows(
            is_array($data['workspace_related_personality_profiles'] ?? null) ? $data['workspace_related_personality_profiles'] : [],
            $locale,
        );
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset(
            $data['workspace_related_jobs'],
            $data['workspace_related_personality_profiles'],
            $data['workspace_seo'],
            $data['workspace_governance'],
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

        try {
            IntentRegistryService::assertNoConflict(
                CareerGuideResource::getModel(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? null,
                    'slug' => $data['slug'] ?? null,
                    'body_md' => $data['body_md'] ?? null,
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
        CareerGuideWorkspace::syncRelatedJobs($this->getRecord(), $this->workspaceRelatedJobsState);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($this->getRecord(), $this->workspaceRelatedPersonalityProfilesState);
        CareerGuideWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
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
