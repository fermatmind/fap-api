<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleRegistryResource\Pages;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Models\ScaleRegistryV2;
use App\Services\Scale\ScaleRegistryWriter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateScaleRegistry extends CreateRecord
{
    protected static string $resource = ScaleRegistryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        app(ScaleRegistryWriter::class)->upsertScale($data);

        return ScaleRegistryV2::queryByOrgWhitelist([(int) $data['org_id']])
            ->where('org_id', (int) $data['org_id'])
            ->where('code', strtoupper(trim((string) $data['code'])))
            ->firstOrFail();
    }
}
