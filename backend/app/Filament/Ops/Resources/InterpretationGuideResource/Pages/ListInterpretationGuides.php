<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\InterpretationGuideResource\Pages;

use App\Filament\Ops\Resources\InterpretationGuideResource;
use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInterpretationGuides extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = InterpretationGuideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
