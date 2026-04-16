<?php

declare(strict_types=1);

namespace App\Services\Career\StructuredData;

use App\DTO\Career\CareerFamilyHubBundle;
use App\DTO\Career\CareerJobDetailBundle;

final class CareerStructuredDataBuilder
{
    public function __construct(
        private readonly CareerJobDetailStructuredDataBuilder $jobDetailBuilder,
        private readonly CareerFamilyHubStructuredDataBuilder $familyHubBuilder,
        private readonly CareerArticleStructuredDataBuilder $articleBuilder,
        private readonly CareerDatasetStructuredDataBuilder $datasetBuilder,
        private readonly CareerStructuredDataOutputPolicy $outputPolicy,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $routeKind, mixed $payload): ?array
    {
        if (
            $this->outputPolicy->allows($routeKind, CareerStructuredDataOutputPolicy::SCHEMA_DATASET)
            || in_array(
                $routeKind,
                ['career_dataset_method'],
                true
            )
        ) {
            return is_array($payload)
                ? $this->datasetBuilder->build($routeKind, $payload)
                : null;
        }

        if ($this->outputPolicy->allows($routeKind, CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE)) {
            return is_array($payload)
                ? $this->articleBuilder->build($routeKind, $payload)
                : null;
        }

        return match ($routeKind) {
            'career_job_detail' => $payload instanceof CareerJobDetailBundle
                ? $this->jobDetailBuilder->build($payload)
                : null,
            'career_family_hub' => $payload instanceof CareerFamilyHubBundle
                ? $this->familyHubBuilder->build($payload)
                : null,
            default => null,
        };
    }
}
