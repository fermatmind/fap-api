<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\MediaAssetResource\Pages;

use App\Filament\Ops\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Services\Cms\MediaAssetStorageSyncService;
use App\Services\Cms\MediaVariantGenerator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Http\UploadedFile;

class CreateMediaAsset extends CreateRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function afterCreate(): void
    {
        if ($this->record instanceof MediaAsset) {
            $this->processMediaAssetSourceUpload($this->record);
        }
    }

    private function processMediaAssetSourceUpload(MediaAsset $record): void
    {
        $generator = app(MediaVariantGenerator::class);
        $uploadedSource = $this->uploadedSourceFile();

        if ($uploadedSource instanceof UploadedFile) {
            $asset = $generator->storeUploadAndGenerate($record, $uploadedSource);
            app(MediaAssetStorageSyncService::class)->syncAndVerify($asset);

            return;
        }

        if ($generator->canGenerate($record)) {
            $asset = $generator->generate($record);
            app(MediaAssetStorageSyncService::class)->syncAndVerify($asset);
        }
    }

    private function uploadedSourceFile(): ?UploadedFile
    {
        $state = data_get($this->form->getRawState(), 'uploaded_source');

        if (is_array($state)) {
            $state = reset($state);
        }

        return $state instanceof UploadedFile ? $state : null;
    }
}
