<?php

declare(strict_types=1);

namespace App\Services\Career\StructuredData;

final class CareerDatasetStructuredDataBuilder
{
    public function __construct(
        private readonly CareerStructuredDataOutputPolicy $outputPolicy,
        private readonly CareerBreadcrumbBuilder $breadcrumbBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function build(string $routeKind, array $payload): ?array
    {
        if ($this->outputPolicy->allows($routeKind, CareerStructuredDataOutputPolicy::SCHEMA_DATASET)) {
            return $this->buildDataset($routeKind, $payload);
        }

        if ($this->outputPolicy->allows($routeKind, CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE)) {
            return $this->buildMethodArticle($routeKind, $payload);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function buildDataset(string $routeKind, array $payload): ?array
    {
        $name = $this->normalizeString($payload['dataset_name'] ?? null);
        $url = $this->normalizeString($payload['url'] ?? null);
        if ($name === null || $url === null) {
            return null;
        }

        $distribution = (array) ($payload['distribution'] ?? []);
        $downloadUrl = $this->normalizeString($distribution['download_url'] ?? null);
        $license = (array) ($payload['license'] ?? []);
        $publisher = (array) ($payload['publisher'] ?? []);

        $fragment = [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            '@id' => $url.'#dataset',
            'name' => $name,
            'url' => $url,
            'description' => $this->normalizeString($payload['description'] ?? null),
            'license' => $this->normalizeString($license['url'] ?? null),
            'keywords' => is_array($payload['keywords'] ?? null) ? array_values(array_filter((array) $payload['keywords'])) : [],
        ];

        if ($publisherName = $this->normalizeString($publisher['name'] ?? null)) {
            $fragment['publisher'] = [
                '@type' => 'Organization',
                'name' => $publisherName,
                'url' => $this->normalizeString($publisher['url'] ?? null),
            ];
        }

        if ($downloadUrl !== null) {
            $fragment['distribution'] = [[
                '@type' => 'DataDownload',
                'contentUrl' => $downloadUrl,
                'encodingFormat' => implode(',', (array) ($distribution['format'] ?? [])),
            ]];
        }

        $breadcrumbNodes = [
            ['name' => 'Datasets', 'path' => '/datasets/occupations'],
            ['name' => $name, 'path' => $url],
        ];

        return [
            'route_kind' => $routeKind,
            'canonical_path' => $url,
            'canonical_title' => $name,
            'breadcrumb_nodes' => $breadcrumbNodes,
            'fragments' => [
                'dataset' => array_filter($fragment, static fn (mixed $value): bool => $value !== null),
                'breadcrumb_list' => $this->breadcrumbBuilder->buildBreadcrumbList($breadcrumbNodes),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function buildMethodArticle(string $routeKind, array $payload): ?array
    {
        $headline = $this->normalizeString($payload['title'] ?? null);
        $url = $this->normalizeString($payload['url'] ?? null);
        if ($headline === null || $url === null) {
            return null;
        }

        $fragment = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            '@id' => $url.'#method',
            'url' => $url,
            'mainEntityOfPage' => $url,
            'headline' => $headline,
            'description' => $this->normalizeString($payload['summary'] ?? null),
        ];

        $breadcrumbNodes = [
            ['name' => 'Datasets', 'path' => '/datasets/occupations'],
            ['name' => 'Method', 'path' => $url],
        ];

        return [
            'route_kind' => $routeKind,
            'canonical_path' => $url,
            'canonical_title' => $headline,
            'breadcrumb_nodes' => $breadcrumbNodes,
            'fragments' => [
                'article' => array_filter($fragment, static fn (mixed $value): bool => $value !== null),
                'breadcrumb_list' => $this->breadcrumbBuilder->buildBreadcrumbList($breadcrumbNodes),
            ],
        ];
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
