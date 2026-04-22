<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerGuideResource\Pages;

use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCareerGuides extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = CareerGuideResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('ops.resources.career_guides.plural');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.career_guides.list_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.resources.career_guides.actions.create'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
