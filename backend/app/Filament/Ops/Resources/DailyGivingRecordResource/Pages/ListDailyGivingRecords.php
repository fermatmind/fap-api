<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\DailyGivingRecordResource\Pages;

use App\Filament\Ops\Resources\DailyGivingRecordResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDailyGivingRecords extends ListRecords
{
    protected static string $resource = DailyGivingRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
