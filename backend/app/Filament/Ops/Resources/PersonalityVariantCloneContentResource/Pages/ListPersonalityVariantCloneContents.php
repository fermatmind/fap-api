<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use App\Models\PersonalityProfile;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

final class ListPersonalityVariantCloneContents extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = PersonalityVariantCloneContentResource::class;

    public int $personalityProfileId = 0;

    public function mount(): void
    {
        $this->personalityProfileId = max(0, (int) request()->query('profile', 0));

        parent::mount();
    }

    public function getSubheading(): string|Htmlable|null
    {
        $profile = $this->selectedProfile();

        if (! $profile instanceof PersonalityProfile) {
            return __('ops.resources.personality_desktop.list_subheading');
        }

        return __('ops.resources.personality_desktop.profile_subheading', [
            'type' => (string) $profile->type_code,
            'locale' => (string) $profile->locale,
        ]);
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->when(
                $this->personalityProfileId > 0,
                fn (Builder $query): Builder => $query->whereHas(
                    'variant',
                    fn (Builder $variantQuery): Builder => $variantQuery
                        ->withoutGlobalScopes()
                        ->where('personality_profile_id', $this->personalityProfileId),
                ),
            );
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('ops.resources.personality_desktop.actions.create'))
                ->url($this->createUrl()),
        ];
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        if ($this->selectedProfile() instanceof PersonalityProfile) {
            return __('ops.resources.personality_desktop.empty_title');
        }

        return parent::getTableEmptyStateHeading();
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        if ($this->selectedProfile() instanceof PersonalityProfile) {
            return __('ops.resources.personality_desktop.empty_description');
        }

        return parent::getTableEmptyStateDescription();
    }

    protected function getTableEmptyStateActions(): array
    {
        if (! PersonalityVariantCloneContentResource::canCreate()) {
            return [];
        }

        return [
            Action::make('create')
                ->label(__('ops.resources.personality_desktop.actions.create_first'))
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->url($this->createUrl()),
        ];
    }

    private function selectedProfile(): ?PersonalityProfile
    {
        if ($this->personalityProfileId <= 0) {
            return null;
        }

        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->find($this->personalityProfileId);
    }

    private function createUrl(): string
    {
        return PersonalityVariantCloneContentResource::getUrl('create', array_filter([
            'profile' => $this->personalityProfileId > 0 ? $this->personalityProfileId : null,
        ]));
    }
}
