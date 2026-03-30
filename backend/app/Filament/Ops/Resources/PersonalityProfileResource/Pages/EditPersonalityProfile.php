<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityProfileResource\Pages;

use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Filament\Ops\Resources\PersonalityProfileResource\Support\PersonalityWorkspace;
use App\Models\PersonalityProfile;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditPersonalityProfile extends EditRecord
{
    protected static string $resource = PersonalityProfileResource::class;

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
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : 'Edit Personality Profile';
    }

    public function getSubheading(): ?string
    {
        return 'Maintain the structured MBTI profile without leaving the editorial workspace.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToPersonality')
                ->label('All Personality Profiles')
                ->url(PersonalityProfileResource::getUrl())
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
            ->label('Back to Personality')
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
            'workspace_sections' => PersonalityWorkspace::workspaceSectionsFromRecord($this->getRecord()),
            'workspace_seo' => PersonalityWorkspace::workspaceSeoFromRecord($this->getRecord()),
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

        $typeCode = PersonalityWorkspace::normalizeTypeCode((string) ($data['type_code'] ?? ''));

        $data['org_id'] = 0;
        $data['scale_code'] = PersonalityProfile::SCALE_CODE_MBTI;
        $data['type_code'] = $typeCode;
        $data['canonical_type_code'] = PersonalityWorkspace::normalizeCanonicalTypeCode(
            (string) ($data['canonical_type_code'] ?? ''),
            $typeCode,
        );
        $data['slug'] = PersonalityWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $typeCode);
        $data['locale'] = PersonalityWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
        $data['schema_version'] = PersonalityWorkspace::normalizeSchemaVersion(
            (string) ($data['schema_version'] ?? $this->getRecord()->schema_version ?? PersonalityProfile::SCHEMA_VERSION_V1),
        );
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        try {
            IntentRegistryService::assertNoConflict(
                $this->getRecord(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? $this->getRecord()->title,
                    'slug' => $data['slug'] ?? $this->getRecord()->slug,
                    'hero_summary_md' => $data['hero_summary_md'] ?? $this->getRecord()->hero_summary_md,
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
        PersonalityWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        PersonalityWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
        $this->getRecord()->unsetRelation('sections');
        $this->getRecord()->unsetRelation('seoMeta');
        PersonalityWorkspace::createRevision($this->getRecord(), 'Workspace update');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Personality profile updated';
    }

    protected function getRedirectUrl(): string
    {
        return PersonalityProfileResource::getUrl('edit', ['record' => $this->getRecord()]);
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
