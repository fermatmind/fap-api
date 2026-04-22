<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityProfileResource\Pages;

use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Filament\Ops\Resources\PersonalityProfileResource\Support\PersonalityWorkspace;
use App\Models\PersonalityProfile;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

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

    public function getTitle(): string|Htmlable
    {
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : __('ops.resources.personality_profiles.edit_title');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.personality_profiles.edit_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToPersonality')
                ->label(__('ops.resources.personality_profiles.actions.all'))
                ->url(PersonalityProfileResource::getUrl())
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
            ->label(__('ops.resources.personality_profiles.actions.back_to_list'))
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

        return $data;
    }

    protected function afterSave(): void
    {
        PersonalityWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        PersonalityWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        $this->getRecord()->unsetRelation('sections');
        $this->getRecord()->unsetRelation('seoMeta');
        PersonalityWorkspace::createRevision($this->getRecord(), __('ops.resources.common.revisions.workspace_update'));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('ops.resources.personality_profiles.notifications.updated');
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
