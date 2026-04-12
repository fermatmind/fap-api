<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveOccupationCompanionLinksSummary;
use App\Models\Occupation;
use App\Models\OccupationFamily;

final class CareerFirstWaveOccupationCompanionLinksService
{
    public const SUMMARY_VERSION = 'career.companion.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    public function __construct(
        private readonly CareerFirstWaveDiscoverabilityManifestService $discoverabilityManifestService,
    ) {}

    public function buildBySlug(string $slug): ?CareerFirstWaveOccupationCompanionLinksSummary
    {
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $subject = Occupation::query()
            ->with('family')
            ->where('canonical_slug', $normalizedSlug)
            ->first();

        if (! $subject instanceof Occupation) {
            return null;
        }

        $manifest = $this->discoverabilityManifestService->build()->toArray();
        $routes = collect((array) ($manifest['routes'] ?? []))
            ->filter(static fn (mixed $row): bool => is_array($row));

        $jobRoutes = $routes
            ->where('route_kind', 'career_job_detail')
            ->keyBy(static fn (array $row): string => (string) ($row['canonical_slug'] ?? ''));

        if (! $jobRoutes->has($subject->canonical_slug)) {
            return null;
        }

        $companionLinks = [];

        $family = $subject->family;
        if ($family instanceof OccupationFamily) {
            $familyRoute = $routes
                ->first(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_family_hub'
                    && ($row['canonical_slug'] ?? null) === $family->canonical_slug
                    && ($row['discoverability_state'] ?? null) === 'discoverable');

            if (is_array($familyRoute)) {
                $companionLinks[] = [
                    'route_kind' => 'career_family_hub',
                    'canonical_path' => (string) ($familyRoute['canonical_path'] ?? '/career/family/'.$family->canonical_slug),
                    'canonical_slug' => (string) $family->canonical_slug,
                    'link_reason_code' => 'family_hub_companion',
                    'family_uuid' => (string) $family->id,
                    'title_en' => (string) $family->title_en,
                ];
            }

            $siblings = Occupation::query()
                ->where('family_id', $family->id)
                ->whereKeyNot($subject->id)
                ->orderBy('canonical_title_en')
                ->orderBy('canonical_slug')
                ->get();

            foreach ($siblings as $sibling) {
                $route = $jobRoutes->get((string) $sibling->canonical_slug);
                if (! is_array($route) || ($route['discoverability_state'] ?? null) !== 'discoverable') {
                    continue;
                }

                $companionLinks[] = [
                    'route_kind' => 'career_job_detail',
                    'canonical_path' => (string) ($route['canonical_path'] ?? '/career/jobs/'.$sibling->canonical_slug),
                    'canonical_slug' => (string) $sibling->canonical_slug,
                    'link_reason_code' => 'same_family_job_companion',
                    'occupation_uuid' => (string) $sibling->id,
                    'canonical_title_en' => (string) $sibling->canonical_title_en,
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
            ->sortBy(static fn (array $row): array => [
                strtolower((string) ($row['route_kind'] ?? '')),
                strtolower((string) ($row['canonical_path'] ?? '')),
            ])
            ->values()
            ->all();

        $counts = [
            'total' => count($dedupedLinks),
            'job_detail' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_job_detail')),
            'family_hub' => count(array_filter($dedupedLinks, static fn (array $row): bool => ($row['route_kind'] ?? null) === 'career_family_hub')),
        ];

        return new CareerFirstWaveOccupationCompanionLinksSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            subjectIdentity: [
                'occupation_uuid' => (string) $subject->id,
                'canonical_slug' => (string) $subject->canonical_slug,
                'canonical_title_en' => (string) $subject->canonical_title_en,
            ],
            counts: $counts,
            companionLinks: $dedupedLinks,
        );
    }
}
