<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerPublicDatasetMethodContract
{
    /**
     * @param  list<string>  $included
     * @param  list<string>  $excluded
     * @param  list<string>  $boundaryNotes
     */
    public function __construct(
        public readonly string $datasetKey,
        public readonly string $datasetScope,
        public readonly string $methodUrl,
        public readonly string $hubUrl,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $sourceSummary,
        public readonly string $reviewDisciplineSummary,
        public readonly array $included,
        public readonly array $excluded,
        public readonly array $boundaryNotes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_kind' => 'career_public_dataset_method',
            'contract_version' => 'career.dataset_public_method.v1',
            'dataset_key' => $this->datasetKey,
            'dataset_scope' => $this->datasetScope,
            'method_url' => $this->methodUrl,
            'hub_url' => $this->hubUrl,
            'title' => $this->title,
            'summary' => $this->summary,
            'source_summary' => $this->sourceSummary,
            'review_discipline_summary' => $this->reviewDisciplineSummary,
            'included' => $this->included,
            'excluded' => $this->excluded,
            'boundary_notes' => $this->boundaryNotes,
        ];
    }
}
