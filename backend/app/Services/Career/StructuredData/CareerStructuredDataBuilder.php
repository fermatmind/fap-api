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
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $routeKind, mixed $payload): ?array
    {
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
