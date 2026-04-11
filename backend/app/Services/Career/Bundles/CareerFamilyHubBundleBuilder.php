<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;
use App\DTO\Career\CareerFamilyHubBundle;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Collection;

final class CareerFamilyHubBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    public function __construct(
        private readonly FirstWaveReadinessSummaryService $readinessSummaryService,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    public function buildBySlug(string $slug): ?CareerFamilyHubBundle
    {
        $family = OccupationFamily::query()
            ->with('occupations')
            ->where('canonical_slug', trim($slug))
            ->first();

        if (! $family instanceof OccupationFamily) {
            return null;
        }

        /** @var Collection<int, Occupation> $occupations */
        $occupations = $family->occupations instanceof Collection
            ? $family->occupations
            : collect();

        $readinessSummary = $this->readinessSummaryService->build();
        $readinessBySlug = collect($readinessSummary->occupations)
            ->filter(static fn (mixed $row): bool => is_array($row) && is_string($row['canonical_slug'] ?? null))
            ->keyBy(static fn (array $row): string => (string) $row['canonical_slug']);

        $counts = $this->buildCounts($occupations, $readinessBySlug);
        $visibleChildren = $this->buildVisibleChildren($occupations, $readinessBySlug);
        $counts['visible_children_count'] = count($visibleChildren);

        return new CareerFamilyHubBundle(
            family: [
                'family_uuid' => $family->id,
                'canonical_slug' => $family->canonical_slug,
                'title_en' => $family->title_en,
                'title_zh' => $family->title_zh,
            ],
            visibleChildren: $visibleChildren,
            counts: $counts,
        );
    }

    /**
     * @param  Collection<int, Occupation>  $occupations
     * @param  Collection<string, array<string, mixed>>  $readinessBySlug
     * @return array<string, int>
     */
    private function buildCounts(Collection $occupations, Collection $readinessBySlug): array
    {
        $counts = [
            'visible_children_count' => 0,
            'publish_ready_count' => 0,
            'blocked_override_eligible_count' => 0,
            'blocked_not_safely_remediable_count' => 0,
            'blocked_total' => 0,
        ];

        foreach ($occupations as $occupation) {
            $row = $readinessBySlug->get((string) $occupation->canonical_slug);
            if (! is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');

            if ($status === 'publish_ready') {
                $counts['publish_ready_count']++;

                continue;
            }

            if ($status === 'blocked_override_eligible') {
                $counts['blocked_override_eligible_count']++;
                $counts['blocked_total']++;

                continue;
            }

            if ($status === 'blocked_not_safely_remediable') {
                $counts['blocked_not_safely_remediable_count']++;
                $counts['blocked_total']++;
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, Occupation>  $occupations
     * @param  Collection<string, array<string, mixed>>  $readinessBySlug
     * @return list<array<string, mixed>>
     */
    private function buildVisibleChildren(Collection $occupations, Collection $readinessBySlug): array
    {
        $eligibleOccupationIds = $occupations
            ->filter(function (Occupation $occupation) use ($readinessBySlug): bool {
                $row = $readinessBySlug->get((string) $occupation->canonical_slug);
                if (! is_array($row)) {
                    return false;
                }

                return (string) ($row['status'] ?? '') === 'publish_ready'
                    && (bool) ($row['index_eligible'] ?? false)
                    && in_array((string) $occupation->crosswalk_mode, self::SAFE_CROSSWALK_MODES, true);
            })
            ->pluck('id')
            ->all();

        if ($eligibleOccupationIds === []) {
            return [];
        }

        $snapshots = RecommendationSnapshot::query()
            ->with([
                'occupation',
                'trustManifest',
                'indexState',
                'contextSnapshot',
                'profileProjection',
                'compileRun',
            ])
            ->whereIn('occupation_id', $eligibleOccupationIds)
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->whereHas('indexState', static function ($query): void {
                $query->where('index_eligible', true);
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('occupation_id')
            ->map(function (Collection $group): ?RecommendationSnapshot {
                /** @var RecommendationSnapshot|null $selected */
                $selected = $group
                    ->sort(function (RecommendationSnapshot $left, RecommendationSnapshot $right): int {
                        $leftCompiled = optional($left->compiled_at)?->getTimestamp() ?? 0;
                        $rightCompiled = optional($right->compiled_at)?->getTimestamp() ?? 0;
                        if ($leftCompiled !== $rightCompiled) {
                            return $rightCompiled <=> $leftCompiled;
                        }

                        return strcmp((string) $left->id, (string) $right->id);
                    })
                    ->first();

                return $selected;
            })
            ->filter(static fn (mixed $snapshot): bool => $snapshot instanceof RecommendationSnapshot)
            ->map(function (RecommendationSnapshot $snapshot) use ($readinessBySlug): ?array {
                $occupation = $snapshot->occupation;
                if (! $occupation instanceof Occupation) {
                    return null;
                }

                $row = $readinessBySlug->get((string) $occupation->canonical_slug);
                if (! is_array($row) || (string) ($row['status'] ?? '') !== 'publish_ready' || ! (bool) ($row['index_eligible'] ?? false)) {
                    return null;
                }

                return [
                    'occupation_uuid' => $occupation->id,
                    'canonical_slug' => $occupation->canonical_slug,
                    'canonical_title_en' => $occupation->canonical_title_en,
                    'canonical_title_zh' => $occupation->canonical_title_zh,
                    'seo_contract' => $this->buildSeoContract($occupation, $snapshot),
                    'trust_summary' => [
                        'reviewer_status' => $row['reviewer_status'] ?? $snapshot->trustManifest?->reviewer_status,
                    ],
                ];
            })
            ->filter()
            ->sortBy(static fn (array $item): array => [
                strtolower((string) ($item['canonical_title_en'] ?? '')),
                strtolower((string) ($item['canonical_slug'] ?? '')),
            ])
            ->values()
            ->all();

        /** @var list<array<string, mixed>> $snapshots */
        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSeoContract(Occupation $occupation, RecommendationSnapshot $snapshot): array
    {
        $indexState = $snapshot->indexState;
        $canonicalPath = is_string($indexState?->canonical_path) ? $indexState->canonical_path : '/career/jobs/'.$occupation->canonical_slug;
        $canonicalTarget = $indexState?->canonical_target;
        $indexEligible = (bool) ($indexState?->index_eligible ?? false);
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_family_hub_child',
            'canonical_url' => $canonicalTarget ?: $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => $occupation->canonical_title_en,
            'description' => $occupation->canonical_title_en,
            'indexability_state' => $indexEligible ? 'indexable' : ((string) ($indexState?->index_state ?? 'noindex')),
            'sitemap_state' => $indexEligible ? 'included' : 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => $canonicalTarget,
            'index_state' => $indexState?->index_state,
            'index_eligible' => $indexEligible,
            'reason_codes' => is_array($indexState?->reason_codes) ? $indexState->reason_codes : [],
            'metadata_contract_version' => $surface['metadata_contract_version'] ?? $surface['version'] ?? null,
            'surface_type' => $surface['surface_type'] ?? null,
            'robots_policy' => $surface['robots_policy'] ?? $robotsPolicy,
            'metadata_fingerprint' => $surface['metadata_fingerprint'] ?? null,
        ];
    }
}
