<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\DataPageResource\Pages;

use App\Filament\Ops\Resources\DataPageResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListDataPages extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = DataPageResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Data';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create Data Page')
                ->icon('heroicon-o-plus'),
        ];
    }
}
