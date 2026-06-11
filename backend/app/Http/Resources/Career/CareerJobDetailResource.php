<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\DTO\Career\CareerJobDetailBundle;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\Bundles\CareerLocaleIntegrityGate;
use App\Services\Career\Bundles\CareerRuntimePublishedDisplaySurfaceBuilder;
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

        $requestedLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        $displaySurface = app(CareerJobDisplaySurfaceBuilder::class)->buildForBundle($bundle, $requestedLocale);
        $runtimeProjectionItem = $this->runtimePublishedProjectionItem($bundle, $requestedLocale);
        if (
            $displaySurface === null
            && $runtimeProjectionItem !== null
            && ($runtimeProjectionItem['projection_source'] ?? null) !== 'testing_database_fixture_fallback'
        ) {
            $displaySurface = app(CareerRuntimePublishedDisplaySurfaceBuilder::class)
                ->build($bundle, $requestedLocale, $runtimeProjectionItem);
        }

        if ($displaySurface !== null) {
            $payload['display_surface_v1'] = $displaySurface;
        }

        if (! app(CareerLocaleIntegrityGate::class)->bundleReadyForPublicLocale($bundle, $displaySurface, $requestedLocale)) {
            unset($payload['display_surface_v1']);
            $payload['seo_contract'] = $this->withLocaleNotReadySeoContract($payload['seo_contract'] ?? []);
            $payload['locale_policy']['locale_warning'] = 'zh_locale_not_ready';
            $payload['locale_policy']['truth_notice_required'] = true;
            $payload['integrity_summary']['integrity_state'] = 'locale_not_ready';
            $payload['integrity_summary']['critical_missing_fields'] = array_values(array_unique(array_merge(
                is_array($payload['integrity_summary']['critical_missing_fields'] ?? null)
                    ? $payload['integrity_summary']['critical_missing_fields']
                    : [],
                ['zh_display_surface']
            )));
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runtimePublishedProjectionItem(CareerJobDetailBundle $bundle, string $locale): ?array
    {
        $slug = strtolower(trim((string) ($bundle->identity['canonical_slug'] ?? '')));
        if ($slug === '') {
            return null;
        }

        $item = app(CareerRuntimePublishProjectionVisibility::class)->itemForSlug($slug, $locale);
        if (! is_array($item)) {
            return null;
        }

        $state = (string) (
            $item['runtime_publish_state']
            ?? $item['runtime_state']
            ?? $item['projection_state']
            ?? $item['state']
            ?? ''
        );

        if (
            $state !== 'published'
            || ($item['detail_route_enabled'] ?? false) !== true
            || ($item['robots_indexable'] ?? false) !== true
            || ($item['release_gate_pass'] ?? false) !== true
        ) {
            return null;
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $seoContract
     * @return array<string, mixed>
     */
    private function withLocaleNotReadySeoContract(array $seoContract): array
    {
        $reasonCodes = is_array($seoContract['reason_codes'] ?? null) ? $seoContract['reason_codes'] : [];
        $reasonCodes[] = 'zh_locale_not_ready';

        return array_merge($seoContract, [
            'index_state' => 'locale_not_ready',
            'index_eligible' => false,
            'robots_policy' => 'noindex,follow',
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ]);
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
