<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\TopicResource\Pages;

use App\Filament\Ops\Resources\TopicResource;
use Filament\Resources\Pages\EditRecord;

class EditTopic extends EditRecord
{
    protected static string $resource = TopicResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
