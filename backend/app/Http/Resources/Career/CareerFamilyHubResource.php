<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerFamilyHubBundle;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerFamilyHubBundle
 */
final class CareerFamilyHubResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFamilyHubBundle $bundle */
        $bundle = $this->resource;

        return array_merge($bundle->toArray(), [
            'structured_data' => $this->buildStructuredData($bundle),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStructuredData(CareerFamilyHubBundle $bundle): array
    {
        $payload = app(CareerStructuredDataBuilder::class)->build('career_family_hub', $bundle);
        $fragments = is_array($payload['fragments'] ?? null) ? $payload['fragments'] : [];

        return [
            'collection_page' => $this->projectCollectionPageFragment($fragments['collection_page'] ?? null),
            'item_list' => $this->projectItemListFragment($fragments['item_list'] ?? null),
            'breadcrumb_list' => $this->projectBreadcrumbListFragment($fragments['breadcrumb_list'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectCollectionPageFragment(mixed $fragment): array
    {
        if (! is_array($fragment)) {
            return [];
        }

        return array_filter([
            '@context' => $fragment['@context'] ?? null,
            '@type' => $fragment['@type'] ?? null,
            'name' => $fragment['name'] ?? null,
            'url' => $fragment['url'] ?? null,
            'mainEntityOfPage' => $fragment['mainEntityOfPage'] ?? null,
            'numberOfItems' => $fragment['numberOfItems'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectItemListFragment(mixed $fragment): array
    {
        if (! is_array($fragment)) {
            return [];
        }

        return array_filter([
            '@context' => $fragment['@context'] ?? null,
            '@type' => $fragment['@type'] ?? null,
            'numberOfItems' => $fragment['numberOfItems'] ?? null,
            'itemListElement' => $this->projectItemListElements($fragment['itemListElement'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectItemListElements(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $projected = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $projected[] = array_filter([
                '@type' => $item['@type'] ?? null,
                'position' => $item['position'] ?? null,
                'name' => $item['name'] ?? null,
                'url' => $item['url'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $projected;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectBreadcrumbListFragment(mixed $fragment): array
    {
        if (! is_array($fragment)) {
            return [];
        }

        return array_filter([
            '@context' => $fragment['@context'] ?? null,
            '@type' => $fragment['@type'] ?? null,
            'itemListElement' => $this->projectBreadcrumbListElements($fragment['itemListElement'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectBreadcrumbListElements(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $projected = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $projected[] = array_filter([
                '@type' => $item['@type'] ?? null,
                'position' => $item['position'] ?? null,
                'name' => $item['name'] ?? null,
                'item' => $item['item'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $projected;
    }
}
