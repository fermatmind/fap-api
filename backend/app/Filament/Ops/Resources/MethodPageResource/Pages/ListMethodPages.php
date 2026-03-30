<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\MethodPageResource\Pages;

use App\Filament\Ops\Resources\MethodPageResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListMethodPages extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = MethodPageResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Methods';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create Method')
                ->icon('heroicon-o-plus'),
        ];
    }
}
