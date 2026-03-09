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
        return 'Career Jobs';
    }

    public function getSubheading(): ?string
    {
        return 'Global career content workspace for structured career job profiles. Not tenant-specific.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create Career Job')
                ->icon('heroicon-o-plus'),
        ];
    }
}
