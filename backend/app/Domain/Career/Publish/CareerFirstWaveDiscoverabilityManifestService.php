<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\DTO\Career\CareerFirstWaveDiscoverabilityManifest;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerFamilyHubBundleBuilder;

final class CareerFirstWaveDiscoverabilityManifestService
{
    public const MANIFEST_VERSION = 'career.discoverability.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly CareerFirstWaveLaunchTierSummaryService $launchTierSummaryService,
        private readonly CareerFamilyHubBundleBuilder $familyHubBundleBuilder,
    ) {}

    public function build(): CareerFirstWaveDiscoverabilityManifest
    {
        $manifest = $this->manifestReader->read();
        $launchTierSummary = $this->launchTierSummaryService->build()->toArray();

        $routes = array_merge(
            $this->buildJobDetailRoutes((array) ($manifest['occupations'] ?? []), (array) ($launchTierSummary['occupations'] ?? [])),
            $this->buildFamilyHubRoutes((array) ($manifest['occupations'] ?? [])),
        );

        usort($routes, static function (array $left, array $right): int {
            $leftKey = sprintf('%s|%s', (string) ($left['route_kind'] ?? ''), (string) ($left['canonical_path'] ?? ''));
            $rightKey = sprintf('%s|%s', (string) ($right['route_kind'] ?? ''), (string) ($right['canonical_path'] ?? ''));

            return strcmp($leftKey, $rightKey);
        });

        return new CareerFirstWaveDiscoverabilityManifest(
            manifestVersion: self::MANIFEST_VERSION,
            scope: self::SCOPE,
            routes: $routes,
        );
    }

    /**
     * @param  list<mixed>  $manifestOccupations
     * @param  list<mixed>  $launchTierRows
     * @return list<array<string, mixed>>
     */
    private function buildJobDetailRoutes(array $manifestOccupations, array $launchTierRows): array
    {
        $manifestSlugs = [];
        foreach ($manifestOccupations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug !== '') {
                $manifestSlugs[$slug] = true;
            }
        }

        $routes = [];
        foreach ($launchTierRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug === '' || ! isset($manifestSlugs[$slug])) {
                continue;
            }

            $discoverable = (string) ($row['launch_tier'] ?? '') === WaveClassification::STABLE;

            $routes[] = [
                'route_kind' => 'career_job_detail',
                'canonical_path' => '/career/jobs/'.$slug,
                'discoverability_state' => $discoverable ? 'discoverable' : 'excluded',
                'reason_codes' => $this->curateJobDetailReasonCodes(
                    launchTier: (string) ($row['launch_tier'] ?? ''),
                    blockedGovernanceStatus: $this->normalizeNullableString($row['blocked_governance_status'] ?? null),
                    indexEligible: (bool) ($row['index_eligible'] ?? false),
                ),
                'occupation_uuid' => (string) ($row['occupation_uuid'] ?? ''),
                'canonical_slug' => $slug,
                'canonical_title_en' => (string) ($row['canonical_title_en'] ?? ''),
                'launch_tier' => (string) ($row['launch_tier'] ?? WaveClassification::HOLD),
                'readiness_status' => (string) ($row['readiness_status'] ?? ''),
                'public_index_state' => (string) ($row['public_index_state'] ?? 'noindex'),
                'index_eligible' => (bool) ($row['index_eligible'] ?? false),
                'reviewer_status' => $this->normalizeNullableString($row['reviewer_status'] ?? null),
                'crosswalk_mode' => $this->normalizeNullableString($row['crosswalk_mode'] ?? null),
                'blocked_governance_status' => $this->normalizeNullableString($row['blocked_governance_status'] ?? null),
            ];
        }

        return $routes;
    }

    /**
     * @param  list<mixed>  $manifestOccupations
     * @return list<array<string, mixed>>
     */
    private function buildFamilyHubRoutes(array $manifestOccupations): array
    {
        $manifestSlugs = [];
        foreach ($manifestOccupations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['canonical_slug'] ?? ''));
            if ($slug !== '') {
                $manifestSlugs[] = $slug;
            }
        }

        if ($manifestSlugs === []) {
            return [];
        }

        $families = OccupationFamily::query()
            ->with('occupations')
            ->whereHas('occupations', static function ($query) use ($manifestSlugs): void {
                $query->whereIn('canonical_slug', $manifestSlugs);
            })
            ->orderBy('canonical_slug')
            ->get();

        $routes = [];
        foreach ($families as $family) {
            $bundle = $this->familyHubBundleBuilder->buildBySlug((string) $family->canonical_slug);
            if ($bundle === null) {
                continue;
            }

            $visibleChildrenCount = (int) ($bundle->counts['visible_children_count'] ?? 0);
            $discoverable = $visibleChildrenCount > 0;

            $routes[] = [
                'route_kind' => 'career_family_hub',
                'canonical_path' => '/career/family/'.$family->canonical_slug,
                'discoverability_state' => $discoverable ? 'discoverable' : 'excluded',
                'reason_codes' => $discoverable ? ['visible_children_present'] : ['excluded_zero_visible_children'],
                'family_uuid' => (string) $family->id,
                'canonical_slug' => (string) $family->canonical_slug,
                'title_en' => (string) $family->title_en,
                'visible_children_count' => $visibleChildrenCount,
            ];
        }

        return $routes;
    }

    /**
     * @return list<string>
     */
    private function curateJobDetailReasonCodes(
        string $launchTier,
        ?string $blockedGovernanceStatus,
        bool $indexEligible,
    ): array {
        if ($launchTier === WaveClassification::STABLE) {
            return ['stable_launch_tier'];
        }

        $reasonCodes = [];
        if ($blockedGovernanceStatus !== null) {
            $reasonCodes[] = 'excluded_blocked_governance';
        }

        if (! $indexEligible) {
            $reasonCodes[] = 'excluded_not_index_eligible';
        }

        if ($reasonCodes === []) {
            $reasonCodes[] = 'excluded_non_stable_tier';
        }

        return array_values(array_unique($reasonCodes));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
