<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\DailyGivingRecordResource\Pages;

use App\Filament\Ops\Resources\DailyGivingRecordResource;
use App\Models\DailyGivingRecord;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateDailyGivingRecord extends CreateRecord
{
    protected static string $resource = DailyGivingRecordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['record_code'])) {
            $date = isset($data['donation_date'])
                ? Carbon::parse($data['donation_date'])
                : now();
            $count = DailyGivingRecord::query()
                ->whereYear('donation_date', $date->year)
                ->whereMonth('donation_date', $date->month)
                ->count();
            $data['record_code'] = sprintf(
                'FM-GIVING-%s-%s',
                $date->format('Y-m'),
                str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT),
            );
        }

        return $data;
    }
}
