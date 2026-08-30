<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

use App\Models\PersonalityProfile;

final class PersonalityCurrentIndexProjector
{
    public function __construct(private readonly PersonalityCurrentPageReader $reader) {}

    /** @return list<array<string,mixed>> */
    public function profileItems(string $locale, bool $includeVariants): array
    {
        $pageKind = $includeVariants ? 'variant' : 'profile';
        $items = array_map(
            fn (array $payload): array => $includeVariants
                ? $this->variantItem($payload)
                : $this->profileItem($payload),
            $this->reader->payloads('mbti', $pageKind, $locale),
        );
        $order = array_flip(PersonalityProfile::BASE_TYPE_CODES);
        usort($items, static function (array $left, array $right) use ($order, $includeVariants): int {
            $leftBase = strtoupper((string) ($left['base_type_code'] ?? $left['canonical_type_code'] ?? $left['type_code'] ?? ''));
            $rightBase = strtoupper((string) ($right['base_type_code'] ?? $right['canonical_type_code'] ?? $right['type_code'] ?? ''));
            $baseComparison = ($order[$leftBase] ?? PHP_INT_MAX) <=> ($order[$rightBase] ?? PHP_INT_MAX);
            if ($baseComparison !== 0 || ! $includeVariants) {
                return $baseComparison;
            }

            return ((string) ($left['variant_code'] ?? '')) <=> ((string) ($right['variant_code'] ?? ''));
        });

        return $items;
    }

    /** @return array{at:list<array<string,mixed>>,cross:list<array<string,mixed>>} */
    public function comparisonItems(string $locale): array
    {
        $at = array_map(
            $this->atComparisonItem(...),
            $this->reader->payloads('mbti', 'comparison_at', $locale),
        );
        $order = array_flip(PersonalityProfile::BASE_TYPE_CODES);
        usort($at, static fn (array $left, array $right): int => ($order[(string) ($left['base_type_code'] ?? '')] ?? PHP_INT_MAX)
            <=> ($order[(string) ($right['base_type_code'] ?? '')] ?? PHP_INT_MAX)
        );

        $cross = array_map(
            $this->crossComparisonItem(...),
            $this->reader->payloads('mbti', 'comparison_cross', $locale),
        );
        usort($cross, static fn (array $left, array $right): int => ((string) ($left['slug'] ?? '')) <=> ((string) ($right['slug'] ?? ''))
        );

        return ['at' => $at, 'cross' => $cross];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function profileItem(array $payload): array
    {
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        $profile['seo_meta'] = is_array($payload['seo_meta'] ?? null) ? $payload['seo_meta'] : null;

        return $profile;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function variantItem(array $payload): array
    {
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        $projection = is_array($payload['mbti_public_projection_v1'] ?? null)
            ? $payload['mbti_public_projection_v1']
            : [];
        $canonicalTypeCode = strtoupper((string) ($projection['canonical_type_code'] ?? $profile['canonical_type_code'] ?? $profile['type_code'] ?? ''));
        $variantCode = strtoupper((string) ($projection['variant_code'] ?? ''));
        $runtimeTypeCode = strtoupper((string) ($projection['runtime_type_code'] ?? ($canonicalTypeCode.'-'.$variantCode)));
        $routeSlug = strtolower($runtimeTypeCode);

        return array_merge($profile, [
            'type_code' => $runtimeTypeCode,
            'base_type_code' => $canonicalTypeCode,
            'canonical_type_code' => $canonicalTypeCode,
            'runtime_type_code' => $runtimeTypeCode,
            'variant_code' => $variantCode,
            'slug' => $routeSlug,
            'base_slug' => strtolower($canonicalTypeCode),
            'status' => 'published',
            'is_public' => true,
            'seo_meta' => is_array($payload['seo_meta'] ?? null) ? $payload['seo_meta'] : null,
            'display_type' => $projection['display_type'] ?? $runtimeTypeCode,
            'public_route_slug' => $routeSlug,
            'public_route_type' => data_get($projection, '_meta.public_route_type', '32-type'),
        ]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function atComparisonItem(array $payload): array
    {
        $comparison = is_array($payload['comparison_public_projection_v1'] ?? null)
            ? $payload['comparison_public_projection_v1']
            : (is_array($payload['comparison'] ?? null) ? $payload['comparison'] : []);
        $surface = is_array($payload['seo_surface_v1'] ?? null) ? $payload['seo_surface_v1'] : [];
        $canonical = $comparison['canonical_url'] ?? $surface['canonical_url'] ?? null;
        $indexable = ($surface['indexability_state'] ?? null) === 'indexable';

        return [
            'slug' => (string) ($comparison['comparison_slug'] ?? ''),
            'comparison_type' => 'mbti_at_comparison',
            'base_type_code' => strtoupper((string) ($comparison['base_type_code'] ?? '')),
            'scale_code' => 'MBTI',
            'locale' => (string) ($comparison['locale'] ?? ''),
            'public_route_type' => 'at-comparison',
            'title' => (string) ($comparison['title'] ?? ''),
            'description' => (string) ($comparison['description'] ?? ''),
            'public_url' => $canonical,
            'canonical_url' => $canonical,
            'is_public' => true,
            'is_indexable' => $indexable,
            'status' => $indexable ? 'published' : 'held_for_mbti_index_24',
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function crossComparisonItem(array $payload): array
    {
        $comparison = is_array($payload['comparison_public_projection_v1'] ?? null)
            ? $payload['comparison_public_projection_v1']
            : (is_array($payload['comparison'] ?? null) ? $payload['comparison'] : []);
        $slug = (string) ($comparison['comparison_slug'] ?? '');
        $canonical = $comparison['canonical_url'] ?? null;
        $fields = [
            'authority_source', 'base_type_codes', 'comparison_type', 'description', 'indexability_status',
            'is_indexable', 'is_public', 'last_reviewed_at', 'left_type', 'llms_eligible', 'locale',
            'public_route_type', 'publish_status', 'review_state', 'review_status', 'reviewer', 'right_type',
            'scale_code', 'seo_title', 'sitemap_eligible', 'status', 'summary', 'title',
        ];
        $item = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $comparison)) {
                $item[$field] = $comparison[$field];
            }
        }

        return [
            ...$item,
            'slug' => $slug,
            'canonical_url' => $canonical,
            'public_url' => $canonical,
        ];
    }
}
