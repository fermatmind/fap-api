<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\InterpretationGuideResource\Pages;

use App\Filament\Ops\Resources\InterpretationGuideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInterpretationGuide extends CreateRecord
{
    protected static string $resource = InterpretationGuideResource::class;
}
