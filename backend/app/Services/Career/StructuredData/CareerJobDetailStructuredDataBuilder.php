<?php

declare(strict_types=1);

namespace App\Services\Career\StructuredData;

use App\DTO\Career\CareerJobDetailBundle;

final class CareerJobDetailStructuredDataBuilder
{
    public function __construct(
        private readonly CareerBreadcrumbBuilder $breadcrumbBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CareerJobDetailBundle $bundle): array
    {
        $canonicalPath = $this->normalizeString($bundle->seoContract['canonical_path'] ?? null)
            ?? '/career/jobs/'.$this->normalizeString($bundle->identity['canonical_slug'] ?? null);
        $canonicalTitle = $this->resolveTitle($bundle);
        $breadcrumbNodes = $this->breadcrumbBuilder->buildForJobDetail($bundle);

        return [
            'route_kind' => 'career_job_detail',
            'canonical_path' => $canonicalPath,
            'canonical_title' => $canonicalTitle,
            'breadcrumb_nodes' => $breadcrumbNodes,
            'fragments' => [
                'occupation' => $this->buildOccupationFragment($bundle, $canonicalPath, $canonicalTitle),
                'breadcrumb_list' => $this->breadcrumbBuilder->buildBreadcrumbList($breadcrumbNodes),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOccupationFragment(
        CareerJobDetailBundle $bundle,
        ?string $canonicalPath,
        string $canonicalTitle,
    ): array {
        $fragment = [
            '@context' => 'https://schema.org',
            '@type' => 'Occupation',
            'name' => $canonicalTitle,
            'url' => $canonicalPath,
            'mainEntityOfPage' => $canonicalPath,
        ];

        $educationRequirements = $this->normalizeString($bundle->truthLayer['entry_education'] ?? null);
        $experienceRequirements = $this->normalizeString($bundle->truthLayer['work_experience'] ?? null);

        if ($educationRequirements !== null) {
            $fragment['educationRequirements'] = $educationRequirements;
        }

        if ($experienceRequirements !== null) {
            $fragment['experienceRequirements'] = $experienceRequirements;
        }

        return array_filter($fragment, static fn (mixed $value): bool => $value !== null);
    }

    private function resolveTitle(CareerJobDetailBundle $bundle): string
    {
        return $this->normalizeString($bundle->titles['canonical_en'] ?? null)
            ?? $this->normalizeString($bundle->titles['canonical_zh'] ?? null)
            ?? $this->normalizeString($bundle->identity['canonical_slug'] ?? null)
            ?? 'Career role';
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
