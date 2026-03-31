<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePersonalityVariantCloneContent extends CreateRecord
{
    protected static string $resource = PersonalityVariantCloneContentResource::class;
}
