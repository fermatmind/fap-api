<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use Filament\Resources\Pages\EditRecord;

final class EditPersonalityVariantCloneContent extends EditRecord
{
    protected static string $resource = PersonalityVariantCloneContentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
