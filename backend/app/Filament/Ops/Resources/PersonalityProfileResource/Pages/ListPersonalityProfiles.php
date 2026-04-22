<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityProfileResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\PersonalityProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListPersonalityProfiles extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = PersonalityProfileResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('ops.resources.personality_profiles.plural');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.personality_profiles.list_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.resources.personality_profiles.actions.create'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
