<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\TopicResource\Pages;

use App\Filament\Ops\Resources\TopicResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTopics extends ListRecords
{
    protected static string $resource = TopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
