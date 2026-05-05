<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\ContentAssets;

final readonly class BigFiveV2AssetPackage
{
    /**
     * @param  list<string>  $manifestFiles
     * @param  list<string>  $checksumFiles
     * @param  list<string>  $supportedFiles
     * @param  list<string>  $versions
     * @param  list<string>  $runtimeUses
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $key,
        public string $relativePath,
        public int $fileCount,
        public array $manifestFiles,
        public array $checksumFiles,
        public array $supportedFiles,
        public array $versions,
        public array $runtimeUses,
        public bool $productionUseAllowed,
        public bool $readyForRuntime,
        public bool $readyForProduction,
        public array $errors,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'relative_path' => $this->relativePath,
            'file_count' => $this->fileCount,
            'manifest_files' => $this->manifestFiles,
            'checksum_files' => $this->checksumFiles,
            'supported_files' => $this->supportedFiles,
            'versions' => $this->versions,
            'runtime_uses' => $this->runtimeUses,
            'production_use_allowed' => $this->productionUseAllowed,
            'ready_for_runtime' => $this->readyForRuntime,
            'ready_for_production' => $this->readyForProduction,
            'errors' => $this->errors,
        ];
    }
}
