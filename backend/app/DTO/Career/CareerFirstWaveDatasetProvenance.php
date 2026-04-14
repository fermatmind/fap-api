<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDatasetProvenance
{
    public function __construct(
        public readonly ?string $datasetName,
        public readonly ?string $datasetVersion,
        public readonly ?string $datasetChecksum,
        public readonly ?string $sourcePath,
        public readonly ?string $importRunId,
        public readonly ?string $compileRunId,
        public readonly ?string $contentVersion,
        public readonly ?string $dataVersion,
        public readonly ?string $logicVersion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset_name' => $this->datasetName,
            'dataset_version' => $this->datasetVersion,
            'dataset_checksum' => $this->datasetChecksum,
            'source_path' => $this->sourcePath,
            'import_run_id' => $this->importRunId,
            'compile_run_id' => $this->compileRunId,
            'content_version' => $this->contentVersion,
            'data_version' => $this->dataVersion,
            'logic_version' => $this->logicVersion,
        ];
    }
}
