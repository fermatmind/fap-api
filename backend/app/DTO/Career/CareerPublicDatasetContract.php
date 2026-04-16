<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerPublicDatasetContract
{
    /**
     * @param  array<string, mixed>  $publication
     * @param  array<string, mixed>  $collectionSummary
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string $datasetKey,
        public readonly string $datasetScope,
        public readonly string $datasetName,
        public readonly string $datasetNameZh,
        public readonly array $publication,
        public readonly array $collectionSummary,
        public readonly array $filters,
        public readonly string $methodUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_kind' => 'career_public_dataset_hub',
            'contract_version' => 'career.dataset_public_contract.v1',
            'dataset_key' => $this->datasetKey,
            'dataset_scope' => $this->datasetScope,
            'dataset_name' => $this->datasetName,
            'dataset_name_zh' => $this->datasetNameZh,
            'publication' => $this->publication,
            'collection_summary' => $this->collectionSummary,
            'filters' => $this->filters,
            'method_url' => $this->methodUrl,
        ];
    }
}
