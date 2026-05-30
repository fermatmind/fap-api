<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\DailyGivingRecordResource\Pages;

use App\Filament\Ops\Resources\DailyGivingRecordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailyGivingRecord extends EditRecord
{
    protected static string $resource = DailyGivingRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
