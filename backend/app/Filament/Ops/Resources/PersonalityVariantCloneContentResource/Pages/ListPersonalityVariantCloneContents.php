<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use Filament\Resources\Pages\ListRecords;

final class ListPersonalityVariantCloneContents extends ListRecords
{
    protected static string $resource = PersonalityVariantCloneContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
