<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Publish\FirstWavePublishGate;
use App\DTO\Career\CareerAliasResolutionBundle;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CareerAliasResolutionBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    private const MAX_ALIAS_LOOKUP_ROWS = 50;

    private const MAX_RESOLUTION_SNAPSHOT_ROWS = 100;

    private const MAX_FAMILY_LOOKUP_ROWS = 50;

    public function __construct(
        private readonly FirstWavePublishGate $publishGate,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    public function build(string $query, ?string $locale = null): CareerAliasResolutionBundle
    {
        $rawQuery = trim($query);
        $normalizedQuery = $this->normalizeText($rawQuery) ?? '';
        $normalizedLocale = $this->normalizeLocale($locale);

        $exactCandidates = [
            ...$this->matchOccupationCandidates($normalizedQuery, $rawQuery, $normalizedLocale, exactOnly: true),
            ...$this->matchFamilyCandidates($normalizedQuery, $rawQuery, $normalizedLocale, exactOnly: true),
        ];

        $candidates = $exactCandidates !== []
            ? $this->dedupeCandidates($exactCandidates)
            : $this->dedupeCandidates([
                ...$this->matchOccupationCandidates($normalizedQuery, $rawQuery, $normalizedLocale, exactOnly: false),
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
     * @return list<array<string, mixed>>
     */
    private function matchOccupationCandidates(
        string $normalizedQuery,
        string $rawQuery,
        ?string $normalizedLocale,
        bool $exactOnly,
    ): array {
        if ($normalizedQuery === '') {
            return [];
        }

        $aliasMatchTiersByOccupationId = $this->matchingOccupationAliasTiers(
            $normalizedQuery,
            $normalizedLocale,
            $exactOnly,
        );
        $aliasOccupationIds = array_keys($aliasMatchTiersByOccupationId);

        $snapshots = RecommendationSnapshot::query()
            ->with([
                'occupation',
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
            ->where(function (Builder $query) use ($aliasOccupationIds, $exactOnly, $normalizedQuery, $rawQuery): void {
                $query->whereHas('occupation', function (Builder $occupationQuery) use ($exactOnly, $normalizedQuery, $rawQuery): void {
                    $occupationQuery->where(function (Builder $matchQuery) use ($exactOnly, $normalizedQuery, $rawQuery): void {
                        $matchQuery->where('canonical_slug', $normalizedQuery)
                            ->orWhereRaw('LOWER(canonical_title_en) = ?', [$normalizedQuery])
                            ->orWhere('canonical_title_zh', $rawQuery);

                        if (! $exactOnly) {
                            $matchQuery->orWhereRaw("canonical_slug LIKE ? ESCAPE '!'", [$this->likePrefixPattern($normalizedQuery)])
                                ->orWhereRaw("LOWER(canonical_title_en) LIKE ? ESCAPE '!'", [$this->likePrefixPattern($normalizedQuery)])
                                ->orWhereRaw("canonical_title_zh LIKE ? ESCAPE '!'", [$this->likePrefixPattern($rawQuery)]);
                        }
                    });
                });

                if ($aliasOccupationIds !== []) {
                    $query->orWhereIn('occupation_id', $aliasOccupationIds);
                }
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_RESOLUTION_SNAPSHOT_ROWS)
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

            if (! $this->isOccupationPublishReady($occupation, $snapshot)) {
                continue;
            }

            $matchTier = $this->resolveOccupationMatchTier(
                $occupation,
                $normalizedQuery,
                $rawQuery,
                $aliasMatchTiersByOccupationId,
                $exactOnly,
            );
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
                    'reviewer_status' => $snapshot->trustManifest?->reviewer_status,
                ],
            ];
        }

        return $this->filterBestTier($candidates);
    }

    private function resolveOccupationMatchTier(
        Occupation $occupation,
        string $normalizedQuery,
        string $rawQuery,
        array $aliasMatchTiersByOccupationId,
        bool $exactOnly,
    ): ?string {
        $canonicalSlug = $this->normalizeText($occupation->canonical_slug);
        $canonicalTitleEn = $this->normalizeText($occupation->canonical_title_en);
        $canonicalTitleZh = $this->normalizeRawText($occupation->canonical_title_zh);
        $aliasMatchTier = $aliasMatchTiersByOccupationId[(string) $occupation->id] ?? null;

        if ($canonicalSlug === $normalizedQuery
            || $canonicalTitleEn === $normalizedQuery
            || ($canonicalTitleZh !== null && $canonicalTitleZh === $rawQuery)
            || $aliasMatchTier === 'exact'
        ) {
            return 'exact';
        }

        if ($exactOnly) {
            return null;
        }

        if (($canonicalSlug !== null && str_starts_with($canonicalSlug, $normalizedQuery))
            || ($canonicalTitleEn !== null && str_starts_with($canonicalTitleEn, $normalizedQuery))
            || ($canonicalTitleZh !== null && str_starts_with($canonicalTitleZh, $rawQuery))
            || $aliasMatchTier === 'prefix'
        ) {
            return 'prefix';
        }

        return null;
    }

    /**
     * @return array<string, 'exact'|'prefix'>
     */
    private function matchingOccupationAliasTiers(
        string $normalizedQuery,
        ?string $normalizedLocale,
        bool $exactOnly,
    ): array {
        $aliases = OccupationAlias::query()
            ->select(['occupation_id', 'normalized'])
            ->whereNotNull('occupation_id')
            ->when($normalizedLocale !== null, function (Builder $query) use ($normalizedLocale): void {
                $query->where('lang', 'like', $normalizedLocale.'%');
            })
            ->where(function (Builder $query) use ($exactOnly, $normalizedQuery): void {
                if ($exactOnly) {
                    $query->where('normalized', $normalizedQuery);

                    return;
                }

                $query->whereRaw("normalized LIKE ? ESCAPE '!'", [$this->likePrefixPattern($normalizedQuery)]);
            })
            ->orderBy('normalized')
            ->orderBy('id')
            ->limit(self::MAX_ALIAS_LOOKUP_ROWS)
            ->get();

        $tiers = [];
        foreach ($aliases as $alias) {
            if (! $alias instanceof OccupationAlias || ! is_string($alias->occupation_id)) {
                continue;
            }

            $tier = $this->normalizeText($alias->normalized) === $normalizedQuery ? 'exact' : 'prefix';
            $occupationId = (string) $alias->occupation_id;

            if (($tiers[$occupationId] ?? null) !== 'exact') {
                $tiers[$occupationId] = $tier;
            }
        }

        return $tiers;
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

        $aliasMatchTiersByFamilyId = $this->matchingFamilyAliasTiers(
            $normalizedQuery,
            $normalizedLocale,
            $exactOnly,
        );
        $aliasFamilyIds = array_keys($aliasMatchTiersByFamilyId);

        $families = OccupationFamily::query()
            ->where(function (Builder $query) use ($aliasFamilyIds, $exactOnly, $normalizedQuery, $rawQuery): void {
                $query->where(function (Builder $matchQuery) use ($exactOnly, $normalizedQuery, $rawQuery): void {
                    $matchQuery->where('canonical_slug', $normalizedQuery)
                        ->orWhereRaw('LOWER(title_en) = ?', [$normalizedQuery])
                        ->orWhere('title_zh', $rawQuery);

                    if (! $exactOnly) {
                        $matchQuery->orWhereRaw("canonical_slug LIKE ? ESCAPE '!'", [$this->likePrefixPattern($normalizedQuery)])
                            ->orWhereRaw("LOWER(title_en) LIKE ? ESCAPE '!'", [$this->likePrefixPattern($normalizedQuery)])
                            ->orWhereRaw("title_zh LIKE ? ESCAPE '!'", [$this->likePrefixPattern($rawQuery)]);
                    }
                });

                if ($aliasFamilyIds !== []) {
                    $query->orWhereIn('id', $aliasFamilyIds);
                }
            })
            ->orderBy('title_en')
            ->orderBy('canonical_slug')
            ->limit(self::MAX_FAMILY_LOOKUP_ROWS)
            ->get();

        $candidates = [];

        foreach ($families as $family) {
            if (! $family instanceof OccupationFamily) {
                continue;
            }

            if (! $this->familyHasVisibleChildren($family)) {
                continue;
            }

            $matchTier = $this->resolveFamilyMatchTier(
                $family,
                $normalizedQuery,
                $rawQuery,
                $aliasMatchTiersByFamilyId,
                $exactOnly,
            );
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
        array $aliasMatchTiersByFamilyId,
        bool $exactOnly,
    ): ?string {
        $canonicalSlug = $this->normalizeText($family->canonical_slug);
        $titleEn = $this->normalizeText($family->title_en);
        $titleZh = $this->normalizeRawText($family->title_zh);
        $aliasMatchTier = $aliasMatchTiersByFamilyId[(string) $family->id] ?? null;

        if ($canonicalSlug === $normalizedQuery
            || $titleEn === $normalizedQuery
            || ($titleZh !== null && $titleZh === $rawQuery)
            || $aliasMatchTier === 'exact'
        ) {
            return 'exact';
        }

        if ($exactOnly) {
            return null;
        }

        if (($canonicalSlug !== null && str_starts_with($canonicalSlug, $normalizedQuery))
            || ($titleEn !== null && str_starts_with($titleEn, $normalizedQuery))
            || ($titleZh !== null && str_starts_with($titleZh, $rawQuery))
            || $aliasMatchTier === 'prefix'
        ) {
            return 'prefix';
        }

        return null;
    }

    /**
     * @return array<string, 'exact'|'prefix'>
     */
    private function matchingFamilyAliasTiers(
        string $normalizedQuery,
        ?string $normalizedLocale,
        bool $exactOnly,
    ): array {
        $aliases = OccupationAlias::query()
            ->select(['family_id', 'normalized'])
            ->whereNotNull('family_id')
            ->where(function (Builder $query): void {
                $query->whereNull('occupation_id')
                    ->orWhereRaw('LOWER(target_kind) = ?', ['family']);
            })
            ->when($normalizedLocale !== null, function (Builder $query) use ($normalizedLocale): void {
                $query->where('lang', 'like', $normalizedLocale.'%');
            })
            ->where(function (Builder $query) use ($exactOnly, $normalizedQuery): void {
                if ($exactOnly) {
                    $query->where('normalized', $normalizedQuery);

                    return;
                }

                $query->whereRaw("normalized LIKE ? ESCAPE '!'", [$this->likePrefixPattern($normalizedQuery)]);
            })
            ->orderBy('normalized')
            ->orderBy('id')
            ->limit(self::MAX_ALIAS_LOOKUP_ROWS)
            ->get();

        $tiers = [];
        foreach ($aliases as $alias) {
            if (! $alias instanceof OccupationAlias || ! is_string($alias->family_id)) {
                continue;
            }

            $tier = $this->normalizeText($alias->normalized) === $normalizedQuery ? 'exact' : 'prefix';
            $familyId = (string) $alias->family_id;

            if (($tiers[$familyId] ?? null) !== 'exact') {
                $tiers[$familyId] = $tier;
            }
        }

        return $tiers;
    }

    private function familyHasVisibleChildren(OccupationFamily $family): bool
    {
        $snapshots = RecommendationSnapshot::query()
            ->with([
                'occupation',
                'trustManifest',
                'indexState',
                'contextSnapshot',
                'profileProjection',
                'compileRun',
            ])
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('occupation', function (Builder $query) use ($family): void {
                $query->where('family_id', $family->id)
                    ->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES);
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
            ->limit(self::MAX_RESOLUTION_SNAPSHOT_ROWS)
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

        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof RecommendationSnapshot) {
                continue;
            }

            $occupation = $snapshot->occupation;
            if ($occupation instanceof Occupation && $this->isOccupationPublishReady($occupation, $snapshot)) {
                return true;
            }
        }

        return false;
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

    private function isOccupationPublishReady(Occupation $occupation, RecommendationSnapshot $snapshot): bool
    {
        $gate = $this->publishGate->evaluate([
            'crosswalk_mode' => $occupation->crosswalk_mode,
            'confidence_score' => data_get(
                $snapshot->trustManifest?->quality,
                'confidence_score',
                data_get($snapshot->trustManifest?->quality, 'confidence', 0)
            ),
            'reviewer_status' => $snapshot->trustManifest?->reviewer_status,
            'index_state' => $snapshot->indexState?->index_state,
            'index_eligible' => $snapshot->indexState?->index_eligible,
            'allow_strong_claim' => data_get($snapshot->snapshot_payload, 'claim_permissions.allow_strong_claim', false),
        ]);

        return (bool) ($gate['publishable'] ?? false);
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

    private function likePrefixPattern(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    private function normalizeLocale(?string $locale): ?string
    {
        $normalized = strtolower(trim((string) $locale));

        return $normalized === '' ? null : $normalized;
    }
}
