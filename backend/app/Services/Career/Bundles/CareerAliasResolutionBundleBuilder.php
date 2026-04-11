<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;
use App\DTO\Career\CareerAliasResolutionBundle;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Collection;

final class CareerAliasResolutionBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    public function __construct(
        private readonly FirstWaveReadinessSummaryService $readinessSummaryService,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
        private readonly CareerFamilyHubBundleBuilder $familyHubBundleBuilder,
    ) {}

    public function build(string $query, ?string $locale = null): CareerAliasResolutionBundle
    {
        $rawQuery = trim($query);
        $normalizedQuery = $this->normalizeText($rawQuery) ?? '';
        $normalizedLocale = $this->normalizeLocale($locale);
        $readinessBySlug = $this->readinessRowsBySlug();

        $exactCandidates = [
            ...$this->matchOccupationCandidates($normalizedQuery, $rawQuery, $normalizedLocale, $readinessBySlug, exactOnly: true),
            ...$this->matchFamilyCandidates($normalizedQuery, $rawQuery, $normalizedLocale, exactOnly: true),
        ];

        $candidates = $exactCandidates !== []
            ? $this->dedupeCandidates($exactCandidates)
            : $this->dedupeCandidates([
                ...$this->matchOccupationCandidates($normalizedQuery, $rawQuery, $normalizedLocale, $readinessBySlug, exactOnly: false),
                ...$this->matchFamilyCandidates($normalizedQuery, $rawQuery, $normalizedLocale, exactOnly: false),
            ]);

        return new CareerAliasResolutionBundle(
            query: [
                'raw' => $rawQuery,
                'normalized' => $normalizedQuery,
                'locale' => $normalizedLocale,
            ],
            resolution: $this->buildResolution($candidates),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function buildResolution(array $candidates): array
    {
        if ($candidates === []) {
            return [
                'resolved_kind' => 'none',
            ];
        }

        if (count($candidates) === 1) {
            $candidate = $candidates[0];
            $resolvedKind = (string) ($candidate['candidate_kind'] ?? 'none');

            return match ($resolvedKind) {
                'occupation' => [
                    'resolved_kind' => 'occupation',
                    'occupation' => $this->occupationPayload($candidate),
                ],
                'family' => [
                    'resolved_kind' => 'family',
                    'family' => $this->familyPayload($candidate),
                ],
                default => ['resolved_kind' => 'none'],
            };
        }

        return [
            'resolved_kind' => 'ambiguous',
            'candidates' => array_map(function (array $candidate): array {
                $kind = (string) ($candidate['candidate_kind'] ?? 'none');

                return $kind === 'occupation'
                    ? ['candidate_kind' => 'occupation'] + $this->occupationPayload($candidate)
                    : ['candidate_kind' => 'family'] + $this->familyPayload($candidate);
            }, $candidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function occupationPayload(array $candidate): array
    {
        return [
            'occupation_uuid' => $candidate['occupation_uuid'] ?? null,
            'canonical_slug' => $candidate['canonical_slug'] ?? null,
            'canonical_title_en' => $candidate['canonical_title_en'] ?? null,
            'canonical_title_zh' => $candidate['canonical_title_zh'] ?? null,
            'seo_contract' => $candidate['seo_contract'] ?? [],
            'trust_summary' => $candidate['trust_summary'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function familyPayload(array $candidate): array
    {
        return [
            'family_uuid' => $candidate['family_uuid'] ?? null,
            'canonical_slug' => $candidate['canonical_slug'] ?? null,
            'title_en' => $candidate['title_en'] ?? null,
            'title_zh' => $candidate['title_zh'] ?? null,
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $readinessBySlug
     * @return list<array<string, mixed>>
     */
    private function matchOccupationCandidates(
        string $normalizedQuery,
        string $rawQuery,
        ?string $normalizedLocale,
        Collection $readinessBySlug,
        bool $exactOnly,
    ): array {
        if ($normalizedQuery === '') {
            return [];
        }

        $snapshots = RecommendationSnapshot::query()
            ->with([
                'occupation.aliases',
                'trustManifest',
                'indexState',
                'profileProjection',
                'contextSnapshot',
                'compileRun',
            ])
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('occupation', function ($query): void {
                $query->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES);
            })
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
            ->filter(static fn (mixed $snapshot): bool => $snapshot instanceof RecommendationSnapshot);

        $candidates = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof RecommendationSnapshot) {
                continue;
            }

            $occupation = $snapshot->occupation;
            if (! $occupation instanceof Occupation) {
                continue;
            }

            $readiness = $readinessBySlug->get((string) $occupation->canonical_slug);
            if (! is_array($readiness)
                || (string) ($readiness['status'] ?? '') !== 'publish_ready'
                || ! (bool) ($readiness['index_eligible'] ?? false)
            ) {
                continue;
            }

            $matchTier = $this->resolveOccupationMatchTier($occupation, $normalizedQuery, $rawQuery, $normalizedLocale, $exactOnly);
            if ($matchTier === null) {
                continue;
            }

            $candidates[] = [
                'candidate_key' => 'occupation:'.$occupation->id,
                'candidate_kind' => 'occupation',
                'match_tier' => $matchTier,
                'occupation_uuid' => $occupation->id,
                'canonical_slug' => $occupation->canonical_slug,
                'canonical_title_en' => $occupation->canonical_title_en,
                'canonical_title_zh' => $occupation->canonical_title_zh,
                'seo_contract' => $this->buildOccupationSeoContract($occupation, $snapshot),
                'trust_summary' => [
                    'reviewer_status' => $readiness['reviewer_status'] ?? $snapshot->trustManifest?->reviewer_status,
                ],
            ];
        }

        return $this->filterBestTier($candidates);
    }

    private function resolveOccupationMatchTier(
        Occupation $occupation,
        string $normalizedQuery,
        string $rawQuery,
        ?string $normalizedLocale,
        bool $exactOnly,
    ): ?string {
        $canonicalSlug = $this->normalizeText($occupation->canonical_slug);
        $canonicalTitleEn = $this->normalizeText($occupation->canonical_title_en);
        $canonicalTitleZh = $this->normalizeRawText($occupation->canonical_title_zh);

        if ($canonicalSlug === $normalizedQuery
            || $canonicalTitleEn === $normalizedQuery
            || ($canonicalTitleZh !== null && $canonicalTitleZh === $rawQuery)
            || $this->matchesOccupationAlias($occupation, $normalizedQuery, $normalizedLocale, exact: true)
        ) {
            return 'exact';
        }

        if ($exactOnly) {
            return null;
        }

        if (($canonicalSlug !== null && str_starts_with($canonicalSlug, $normalizedQuery))
            || ($canonicalTitleEn !== null && str_starts_with($canonicalTitleEn, $normalizedQuery))
            || ($canonicalTitleZh !== null && str_starts_with($canonicalTitleZh, $rawQuery))
            || $this->matchesOccupationAlias($occupation, $normalizedQuery, $normalizedLocale, exact: false)
        ) {
            return 'prefix';
        }

        return null;
    }

    private function matchesOccupationAlias(
        Occupation $occupation,
        string $normalizedQuery,
        ?string $normalizedLocale,
        bool $exact,
    ): bool {
        $aliases = $occupation->aliases instanceof Collection ? $occupation->aliases : collect();

        foreach ($aliases as $alias) {
            if (! $alias instanceof OccupationAlias) {
                continue;
            }

            if ($normalizedLocale !== null && ! str_starts_with(strtolower((string) $alias->lang), $normalizedLocale)) {
                continue;
            }

            $aliasNormalized = $this->normalizeText($alias->normalized);
            if ($aliasNormalized === null) {
                continue;
            }

            if ($exact && $aliasNormalized === $normalizedQuery) {
                return true;
            }

            if (! $exact && str_starts_with($aliasNormalized, $normalizedQuery)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchFamilyCandidates(
        string $normalizedQuery,
        string $rawQuery,
        ?string $normalizedLocale,
        bool $exactOnly,
    ): array {
        if ($normalizedQuery === '') {
            return [];
        }

        $families = OccupationFamily::query()
            ->with('aliases')
            ->orderBy('title_en')
            ->orderBy('canonical_slug')
            ->get();

        $candidates = [];

        foreach ($families as $family) {
            if (! $family instanceof OccupationFamily) {
                continue;
            }

            if (! $this->familyHasVisibleChildren($family)) {
                continue;
            }

            $matchTier = $this->resolveFamilyMatchTier($family, $normalizedQuery, $rawQuery, $normalizedLocale, $exactOnly);
            if ($matchTier === null) {
                continue;
            }

            $candidates[] = [
                'candidate_key' => 'family:'.$family->id,
                'candidate_kind' => 'family',
                'match_tier' => $matchTier,
                'family_uuid' => $family->id,
                'canonical_slug' => $family->canonical_slug,
                'title_en' => $family->title_en,
                'title_zh' => $family->title_zh,
            ];
        }

        return $this->filterBestTier($candidates);
    }

    private function resolveFamilyMatchTier(
        OccupationFamily $family,
        string $normalizedQuery,
        string $rawQuery,
        ?string $normalizedLocale,
        bool $exactOnly,
    ): ?string {
        $canonicalSlug = $this->normalizeText($family->canonical_slug);
        $titleEn = $this->normalizeText($family->title_en);
        $titleZh = $this->normalizeRawText($family->title_zh);

        if ($canonicalSlug === $normalizedQuery
            || $titleEn === $normalizedQuery
            || ($titleZh !== null && $titleZh === $rawQuery)
            || $this->matchesExplicitFamilyAlias($family, $normalizedQuery, $normalizedLocale, exact: true)
        ) {
            return 'exact';
        }

        if ($exactOnly) {
            return null;
        }

        if (($canonicalSlug !== null && str_starts_with($canonicalSlug, $normalizedQuery))
            || ($titleEn !== null && str_starts_with($titleEn, $normalizedQuery))
            || ($titleZh !== null && str_starts_with($titleZh, $rawQuery))
            || $this->matchesExplicitFamilyAlias($family, $normalizedQuery, $normalizedLocale, exact: false)
        ) {
            return 'prefix';
        }

        return null;
    }

    private function matchesExplicitFamilyAlias(
        OccupationFamily $family,
        string $normalizedQuery,
        ?string $normalizedLocale,
        bool $exact,
    ): bool {
        $aliases = $family->aliases instanceof Collection ? $family->aliases : collect();

        foreach ($aliases as $alias) {
            if (! $alias instanceof OccupationAlias) {
                continue;
            }

            $targetKind = strtolower(trim((string) $alias->target_kind));
            if ($alias->occupation_id !== null && $targetKind !== 'family') {
                continue;
            }

            if ($normalizedLocale !== null && ! str_starts_with(strtolower((string) $alias->lang), $normalizedLocale)) {
                continue;
            }

            $aliasNormalized = $this->normalizeText($alias->normalized);
            if ($aliasNormalized === null) {
                continue;
            }

            if ($exact && $aliasNormalized === $normalizedQuery) {
                return true;
            }

            if (! $exact && str_starts_with($aliasNormalized, $normalizedQuery)) {
                return true;
            }
        }

        return false;
    }

    private function familyHasVisibleChildren(OccupationFamily $family): bool
    {
        $bundle = $this->familyHubBundleBuilder->buildBySlug((string) $family->canonical_slug);
        if ($bundle === null) {
            return false;
        }

        return count($bundle->visibleChildren) > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function filterBestTier(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $bestTier = collect($candidates)
            ->pluck('match_tier')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(static fn (string $tier): int => $tier === 'exact' ? 0 : 1)
            ->min();

        $bestTierValue = $bestTier === 0 ? 'exact' : 'prefix';

        return array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => (string) ($candidate['match_tier'] ?? '') === $bestTierValue
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeCandidates(array $candidates): array
    {
        $deduped = [];
        foreach ($candidates as $candidate) {
            $key = (string) ($candidate['candidate_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $deduped[$key] = $candidate;
        }

        $values = array_values($deduped);
        usort($values, function (array $left, array $right): int {
            $kindCompare = strcmp((string) ($left['candidate_kind'] ?? ''), (string) ($right['candidate_kind'] ?? ''));
            if ($kindCompare !== 0) {
                return $kindCompare;
            }

            $leftTitle = strtolower((string) ($left['canonical_title_en'] ?? $left['title_en'] ?? ''));
            $rightTitle = strtolower((string) ($right['canonical_title_en'] ?? $right['title_en'] ?? ''));
            if ($leftTitle !== $rightTitle) {
                return strcmp($leftTitle, $rightTitle);
            }

            return strcmp(
                strtolower((string) ($left['canonical_slug'] ?? '')),
                strtolower((string) ($right['canonical_slug'] ?? ''))
            );
        });

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOccupationSeoContract(Occupation $occupation, RecommendationSnapshot $snapshot): array
    {
        $indexState = $snapshot->indexState;
        $canonicalPath = is_string($indexState?->canonical_path) ? $indexState->canonical_path : '/career/jobs/'.$occupation->canonical_slug;
        $canonicalTarget = $indexState?->canonical_target;
        $indexEligible = (bool) ($indexState?->index_eligible ?? false);
        $publicIndexState = IndexStateValue::publicFacing((string) ($indexState?->index_state ?? ''), $indexEligible);
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_alias_resolution_occupation',
            'canonical_url' => $canonicalTarget ?: $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => $occupation->canonical_title_en,
            'description' => $occupation->canonical_title_en,
            'indexability_state' => $publicIndexState,
            'sitemap_state' => $indexEligible ? 'included' : 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => $canonicalTarget,
            'index_state' => $publicIndexState,
            'index_eligible' => $indexEligible,
            'reason_codes' => is_array($indexState?->reason_codes) ? $indexState->reason_codes : [],
            'metadata_contract_version' => $surface['metadata_contract_version'] ?? $surface['version'] ?? null,
            'surface_type' => $surface['surface_type'] ?? null,
            'robots_policy' => $surface['robots_policy'] ?? $robotsPolicy,
            'metadata_fingerprint' => $surface['metadata_fingerprint'] ?? null,
        ];
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function readinessRowsBySlug(): Collection
    {
        $summary = $this->readinessSummaryService->build();

        return collect($summary->occupations)
            ->filter(static fn (mixed $row): bool => is_array($row) && is_string($row['canonical_slug'] ?? null))
            ->keyBy(static fn (array $row): string => (string) $row['canonical_slug']);
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeRawText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeLocale(?string $locale): ?string
    {
        $normalized = strtolower(trim((string) $locale));

        return $normalized === '' ? null : $normalized;
    }
}
