<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityProfileResource\Pages;

use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Filament\Ops\Resources\PersonalityProfileResource\Support\PersonalityWorkspace;
use App\Models\PersonalityProfile;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreatePersonalityProfile extends CreateRecord
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
        return __('ops.resources.personality_profiles.create_title');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.personality_profiles.create_subheading');
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(PersonalityWorkspace::defaultFormState());

        $this->callHook('afterFill');
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

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(__('ops.resources.personality_profiles.actions.create'))
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
            ->label(__('ops.resources.personality_profiles.actions.back_to_list'))
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
            (string) ($data['schema_version'] ?? PersonalityProfile::SCHEMA_VERSION_V2),
        );
        $data['created_by_admin_user_id'] = $this->currentAdminId();
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        return $data;
    }

    protected function afterCreate(): void
    {
        PersonalityWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        PersonalityWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        $this->getRecord()->unsetRelation('sections');
        $this->getRecord()->unsetRelation('seoMeta');
        PersonalityWorkspace::createRevision($this->getRecord(), __('ops.resources.common.revisions.initial_workspace_snapshot'));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('ops.resources.personality_profiles.notifications.created');
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
