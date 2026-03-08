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
        return 'Personality';
    }

    public function getSubheading(): ?string
    {
        return 'Global MBTI content workspace for structured personality profiles. Not tenant-specific.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create Personality Profile')
                ->icon('heroicon-o-plus'),
        ];
    }
}
