<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ResultResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\ResultResource;
use Filament\Resources\Pages\ListRecords;

class ListResults extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = ResultResource::class;

    public function getTitle(): string
    {
        return __('ops.resources.support.results_explorer');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
