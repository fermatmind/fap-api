<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\MediaAssetResource\Pages;

use App\Filament\Ops\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Services\Cms\MediaVariantGenerator;
use Filament\Resources\Pages\CreateRecord;

class CreateMediaAsset extends CreateRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function afterCreate(): void
    {
        if ($this->record instanceof MediaAsset) {
            $generator = app(MediaVariantGenerator::class);
            if ($generator->canGenerate($this->record)) {
                $generator->generate($this->record);
            }
        }
    }
}
