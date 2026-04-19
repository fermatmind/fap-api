<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\MediaAssetResource\Pages;

use App\Filament\Ops\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Services\Cms\MediaVariantGenerator;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMediaAsset extends EditRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(false),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record instanceof MediaAsset) {
            $generator = app(MediaVariantGenerator::class);
            if ($generator->canGenerate($this->record)) {
                $generator->generate($this->record);
            }
        }
    }
}
