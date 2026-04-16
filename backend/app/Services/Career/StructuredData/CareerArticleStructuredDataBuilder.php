<?php

declare(strict_types=1);

namespace App\Services\Career\StructuredData;

final class CareerArticleStructuredDataBuilder
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
        if (! $this->outputPolicy->allows($routeKind, CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE)) {
            return null;
        }

        $headline = $this->normalizeString($payload['headline'] ?? null);
        $description = $this->normalizeString($payload['description'] ?? null);
        $url = $this->normalizeString($payload['url'] ?? null);

        if ($headline === null || $url === null) {
            return null;
        }

        $fragment = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            '@id' => $this->normalizeString($payload['id'] ?? null) ?? $url.'#article',
            'url' => $url,
            'mainEntityOfPage' => $this->normalizeString($payload['main_entity_of_page'] ?? null) ?? $url,
            'headline' => $headline,
        ];

        if ($description !== null) {
            $fragment['description'] = $description;
        }

        if ($datePublished = $this->normalizeString($payload['date_published'] ?? null)) {
            $fragment['datePublished'] = $datePublished;
        }

        if ($dateModified = $this->normalizeString($payload['date_modified'] ?? null)) {
            $fragment['dateModified'] = $dateModified;
        }

        if ($articleSection = $this->normalizeString($payload['article_section'] ?? null)) {
            $fragment['articleSection'] = $articleSection;
        }

        $keywords = $this->normalizeKeywords($payload['keywords'] ?? null);
        if ($keywords !== []) {
            $fragment['keywords'] = $keywords;
        }

        if ($authorName = $this->normalizeString($payload['author_name'] ?? null)) {
            $fragment['author'] = [
                '@type' => 'Person',
                'name' => $authorName,
            ];
        } elseif ($authorOrgName = $this->normalizeString($payload['author_org_name'] ?? null)) {
            $fragment['author'] = [
                '@type' => 'Organization',
                'name' => $authorOrgName,
            ];
        }

        if ($publisherName = $this->normalizeString($payload['publisher_name'] ?? null)) {
            $fragment['publisher'] = [
                '@type' => 'Organization',
                'name' => $publisherName,
            ];
        }

        $breadcrumbNodes = $this->buildBreadcrumbNodes(
            routeKind: $routeKind,
            canonicalPath: $url,
            canonicalTitle: $headline,
        );

        return [
            'route_kind' => $routeKind,
            'canonical_path' => $url,
            'canonical_title' => $headline,
            'breadcrumb_nodes' => $breadcrumbNodes,
            'fragments' => [
                'article' => $fragment,
                'breadcrumb_list' => $this->breadcrumbBuilder->buildBreadcrumbList($breadcrumbNodes),
            ],
        ];
    }

    /**
     * @return list<array{name:string,path:string}>
     */
    private function buildBreadcrumbNodes(
        string $routeKind,
        string $canonicalPath,
        string $canonicalTitle,
    ): array {
        $rootName = match ($routeKind) {
            'career_guide_public_detail' => 'Career guides',
            'article_public_detail' => 'Articles',
            default => 'Career',
        };

        return array_values(array_filter([
            [
                'name' => $rootName,
                'path' => $canonicalPath,
            ],
            [
                'name' => $canonicalTitle,
                'path' => $canonicalPath,
            ],
        ], static fn (array $node): bool => trim($node['name']) !== '' && trim($node['path']) !== ''));
    }

    /**
     * @return list<string>
     */
    private function normalizeKeywords(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keywords = [];
        foreach ($value as $item) {
            $keyword = $this->normalizeString($item);
            if ($keyword === null) {
                continue;
            }

            $keywords[] = $keyword;
        }

        return array_values(array_unique($keywords));
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
