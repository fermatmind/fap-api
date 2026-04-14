<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDatasetDescriptor
{
    public function __construct(
        public readonly string $datasetKey,
        public readonly string $datasetScope,
        public readonly string $manifestVersion,
        public readonly string $selectionPolicyVersion,
        public readonly ?string $datasetName,
        public readonly ?string $datasetVersion,
        public readonly ?string $datasetChecksum,
        public readonly ?string $sourcePath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset_key' => $this->datasetKey,
            'dataset_scope' => $this->datasetScope,
            'manifest_version' => $this->manifestVersion,
            'selection_policy_version' => $this->selectionPolicyVersion,
            'dataset_name' => $this->datasetName,
            'dataset_version' => $this->datasetVersion,
            'dataset_checksum' => $this->datasetChecksum,
            'source_path' => $this->sourcePath,
        ];
    }
}
