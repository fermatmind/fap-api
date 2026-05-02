<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerJobDetailBundle;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerJobDetailBundle
 */
final class CareerJobDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerJobDetailBundle $bundle */
        $bundle = $this->resource;

        $payload = array_merge($bundle->toArray(), [
            'structured_data' => $this->buildStructuredData($bundle),
        ]);

        $displaySurface = app(CareerJobDisplaySurfaceBuilder::class)->buildForBundle(
            $bundle,
            is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN'
        );

        if ($displaySurface !== null) {
            $payload['display_surface_v1'] = $displaySurface;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStructuredData(CareerJobDetailBundle $bundle): array
    {
        $payload = app(CareerStructuredDataBuilder::class)->build('career_job_detail', $bundle);
        $fragments = is_array($payload['fragments'] ?? null) ? $payload['fragments'] : [];

        return [
            'occupation' => $this->projectOccupationFragment($fragments['occupation'] ?? null),
            'breadcrumb_list' => $this->projectBreadcrumbListFragment($fragments['breadcrumb_list'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectOccupationFragment(mixed $fragment): array
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
            'educationRequirements' => $fragment['educationRequirements'] ?? null,
            'experienceRequirements' => $fragment['experienceRequirements'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
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
            'itemListElement' => is_array($fragment['itemListElement'] ?? null) ? $fragment['itemListElement'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
