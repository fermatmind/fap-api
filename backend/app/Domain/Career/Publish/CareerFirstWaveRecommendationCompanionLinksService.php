<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\Links\CareerRecommendationSupportLinkageBuilder;
use App\DTO\Career\CareerFirstWaveRecommendationCompanionLinksSummary;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerRecommendationDetailBundleBuilder;

final class CareerFirstWaveRecommendationCompanionLinksService
{
    public const SUMMARY_VERSION = 'career.companion.recommendation.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    public function __construct(
        private readonly CareerRecommendationDetailBundleBuilder $recommendationDetailBundleBuilder,
        private readonly CareerFirstWaveDiscoverabilityManifestService $discoverabilityManifestService,
        private readonly CareerRecommendationSupportLinkageBuilder $recommendationSupportLinkageBuilder,
    ) {}

    public function buildByType(string $type, string $locale = 'en'): ?CareerFirstWaveRecommendationCompanionLinksSummary
    {
        $normalizedType = trim($type);
        if ($normalizedType === '') {
            return null;
        }

        $bundle = $this->recommendationDetailBundleBuilder->buildByType($normalizedType);
        if ($bundle === null) {
            return null;
        }

        $bundlePayload = $bundle->toArray();
        $subjectMeta = is_array($bundlePayload['recommendation_subject_meta'] ?? null)
            ? $bundlePayload['recommendation_subject_meta']
            : [];

        $publicRouteSlug = $this->normalizeRecommendationRouteSlug($subjectMeta);
        if ($publicRouteSlug === null) {
            return null;
        }

        $targetSlug = trim((string) data_get($bundlePayload, 'identity.canonical_slug', ''));
        if ($targetSlug === '') {
            return null;
        }

        $targetOccupation = Occupation::query()
            ->with('family')
            ->where('canonical_slug', $targetSlug)
            ->first();

        if (! $targetOccupation instanceof Occupation) {
            return null;
        }

        $manifest = $this->discoverabilityManifestService->build()->toArray();
        $routes = collect((array) ($manifest['routes'] ?? []))
            ->filter(static fn (mixed $row): bool => is_array($row));

        $discoverableJobRoutes = $routes
            ->filter(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_job_detail'
                && ($row['discoverability_state'] ?? null) === 'discoverable')
            ->keyBy(static fn (array $row): string => (string) ($row['canonical_slug'] ?? ''));

        $companionLinks = [];

        $targetJobRoute = $discoverableJobRoutes->get($targetSlug);
        if (is_array($targetJobRoute)) {
            $companionLinks[] = [
                'route_kind' => 'career_job_detail',
                'canonical_path' => (string) ($targetJobRoute['canonical_path'] ?? '/career/jobs/'.$targetSlug),
                'canonical_slug' => $targetSlug,
                'link_reason_code' => 'target_job_detail_companion',
                'occupation_uuid' => (string) $targetOccupation->id,
                'canonical_title_en' => (string) $targetOccupation->canonical_title_en,
            ];
        }

        $family = $targetOccupation->family;
        if ($family instanceof OccupationFamily) {
            $familyRoute = $routes->first(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_family_hub'
                && ($row['canonical_slug'] ?? null) === $family->canonical_slug
                && ($row['discoverability_state'] ?? null) === 'discoverable');

            if (is_array($familyRoute)) {
                $companionLinks[] = [
                    'route_kind' => 'career_family_hub',
                    'canonical_path' => (string) ($familyRoute['canonical_path'] ?? '/career/family/'.$family->canonical_slug),
                    'canonical_slug' => (string) $family->canonical_slug,
                    'link_reason_code' => 'target_family_hub_companion',
                    'family_uuid' => (string) $family->id,
                    'title_en' => (string) $family->title_en,
                ];
            }
        }

        $matchedJobs = collect((array) ($bundlePayload['matched_jobs'] ?? []))
            ->filter(static fn (mixed $row): bool => is_array($row));

        foreach ($matchedJobs as $matchedJob) {
            $matchedSlug = trim((string) ($matchedJob['canonical_slug'] ?? ''));
            if ($matchedSlug === '') {
                continue;
            }

            $route = $discoverableJobRoutes->get($matchedSlug);
            if (! is_array($route)) {
                continue;
            }

            $companionLinks[] = [
                'route_kind' => 'career_job_detail',
                'canonical_path' => (string) ($route['canonical_path'] ?? '/career/jobs/'.$matchedSlug),
                'canonical_slug' => $matchedSlug,
                'link_reason_code' => 'matched_job_detail_companion',
                'occupation_uuid' => (string) ($matchedJob['occupation_uuid'] ?? ''),
                'canonical_title_en' => (string) ($matchedJob['title'] ?? ''),
            ];
        }

        $supportLinkage = $this->recommendationSupportLinkageBuilder->buildByType($normalizedType, $locale);
        $supportLinks = collect((array) data_get($supportLinkage, 'support_links', []))
            ->filter(static fn (mixed $row): bool => is_array($row));

        foreach ($supportLinks as $supportLink) {
            $canonicalPath = trim((string) ($supportLink['canonical_path'] ?? ''));
            $canonicalSlug = trim((string) ($supportLink['canonical_slug'] ?? ''));
            $routeKind = trim((string) ($supportLink['route_kind'] ?? ''));

            if ($canonicalPath === '' || $canonicalSlug === '') {
                continue;
            }

            if ($routeKind === 'test_landing') {
                $companionLinks[] = [
                    'route_kind' => 'test_landing',
                    'canonical_path' => $canonicalPath,
                    'canonical_slug' => $canonicalSlug,
                    'link_reason_code' => 'recommendation_test_support',
                    'scale_code' => 'MBTI',
                ];

                continue;
            }

            if ($routeKind === 'topic_detail') {
                $topicCode = trim((string) data_get($supportLink, 'topic_code', $canonicalSlug));

                if ($topicCode === '') {
                    $topicCode = $canonicalSlug;
                }

                $companionLinks[] = [
                    'route_kind' => 'topic_detail',
                    'canonical_path' => $canonicalPath,
                    'canonical_slug' => $canonicalSlug,
                    'link_reason_code' => 'recommendation_topic_support',
                    'topic_code' => $topicCode,
                ];
            }
        }

        $dedupedLinks = collect($companionLinks)
            ->unique(static fn (array $row): string => sprintf(
                '%s|%s|%s',
                (string) ($row['route_kind'] ?? ''),
                (string) ($row['canonical_path'] ?? ''),
                (string) ($row['canonical_slug'] ?? '')
            ))
            ->values()
            ->all();

        $counts = [
            'total' => count($dedupedLinks),
            'job_detail' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_job_detail')),
            'family_hub' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_family_hub')),
            'test_landing' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'test_landing')),
            'topic_detail' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'topic_detail')),
        ];

        return new CareerFirstWaveRecommendationCompanionLinksSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            subjectIdentity: [
                'type_code' => $this->normalizeNullableString($subjectMeta['type_code'] ?? null),
                'canonical_type_code' => $this->normalizeNullableString($subjectMeta['canonical_type_code'] ?? null),
                'public_route_slug' => $publicRouteSlug,
                'display_title' => $this->normalizeNullableString($subjectMeta['display_title'] ?? null),
            ],
            counts: $counts,
            companionLinks: $dedupedLinks,
        );
    }

    private function normalizeRecommendationRouteSlug(array $subjectMeta): ?string
    {
        $candidates = [
            $subjectMeta['public_route_slug'] ?? null,
            $subjectMeta['route_type'] ?? null,
            $subjectMeta['canonical_type_code'] ?? null,
            $subjectMeta['type_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $normalized = strtolower(trim((string) $candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
