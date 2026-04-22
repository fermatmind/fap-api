<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\InterpretationGuideResource\Pages;

use App\Filament\Ops\Resources\InterpretationGuideResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInterpretationGuide extends EditRecord
{
    protected static string $resource = InterpretationGuideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(false),
        ];
    }
}
