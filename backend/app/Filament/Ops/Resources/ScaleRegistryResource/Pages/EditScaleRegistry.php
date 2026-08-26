<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleRegistryResource\Pages;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Models\ScaleRegistryV2;
use App\Services\Scale\ScaleRegistryWriter;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditScaleRegistry extends EditRecord
{
    protected static string $resource = ScaleRegistryResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ScaleRegistryV2, 404);

        $data['org_id'] = (int) $record->org_id;
        $data['code'] = (string) $record->code;
        app(ScaleRegistryWriter::class)->upsertScale($data);

        return $record->refresh();
    }
}
