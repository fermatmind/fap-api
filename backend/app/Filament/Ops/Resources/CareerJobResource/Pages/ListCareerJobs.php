<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerJobResource\Pages;

use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCareerJobs extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = CareerJobResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('ops.resources.career_jobs.plural');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.career_jobs.list_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('ops.resources.career_jobs.actions.create'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
