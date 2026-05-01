<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerSearchResultBundle;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\RecommendationSnapshot;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CareerSearchBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance'];

    private const MAX_SEARCH_CANDIDATE_ROWS = 100;

    private const MAX_DIRECTORY_DRAFT_SEARCH_ROWS = 100;

    private const DIRECTORY_DRAFT_CROSSWALK_MODE = 'directory_draft';

    /**
     * @var array<string, int>
     */
    private const MATCH_PRIORITY = [
        'canonical_slug_exact' => 0,
        'canonical_title_exact' => 1,
        'alias_exact' => 2,
        'canonical_slug_prefix' => 3,
        'canonical_title_prefix' => 4,
        'alias_prefix' => 5,
    ];

    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    /**
     * @return list<CareerSearchResultBundle>
     */
    public function build(string $query, int $limit = 10, ?string $locale = null, string $mode = 'auto'): array
    {
        $normalizedQuery = $this->normalizeText($query);
        if ($normalizedQuery === null) {
            return [];
        }

        $mode = in_array($mode, ['auto', 'exact', 'prefix'], true) ? $mode : 'auto';
        $limit = max(1, min($limit, 20));
        $prefixQuery = $this->likePrefixPattern($normalizedQuery);
        $rawQuery = trim($query);
        $rawPrefixQuery = $this->likePrefixPattern($rawQuery);
        $normalizedLocale = $this->normalizeLocale($locale);

        $snapshots = RecommendationSnapshot::query()
            ->with([
                'occupation.aliases',
                'trustManifest',
                'indexState',
                'compileRun',
                'profileProjection',
                'contextSnapshot',
            ])
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('occupation', static function (Builder $query): void {
                $query->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES);
            })
            ->whereHas('contextSnapshot', static function (Builder $query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function (Builder $query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->whereHas('indexState', static function (Builder $query): void {
                $query->where('index_eligible', true);
            })
            ->where(function (Builder $query) use ($mode, $normalizedQuery, $prefixQuery, $rawQuery, $rawPrefixQuery, $normalizedLocale): void {
                $query->whereHas('occupation', function (Builder $occupationQuery) use ($mode, $normalizedQuery, $prefixQuery, $rawQuery, $rawPrefixQuery): void {
                    $occupationQuery->where(function (Builder $matchQuery) use ($mode, $normalizedQuery, $prefixQuery, $rawQuery, $rawPrefixQuery): void {
                        $matchQuery->where('canonical_slug', $normalizedQuery)
                            ->orWhereRaw('LOWER(canonical_title_en) = ?', [$normalizedQuery])
                            ->orWhere('canonical_title_zh', $rawQuery);

                        if ($mode !== 'exact') {
                            $matchQuery->orWhereRaw("canonical_slug LIKE ? ESCAPE '!'", [$prefixQuery])
                                ->orWhereRaw("LOWER(canonical_title_en) LIKE ? ESCAPE '!'", [$prefixQuery])
                                ->orWhereRaw("canonical_title_zh LIKE ? ESCAPE '!'", [$rawPrefixQuery]);
                        }
                    });
                })->orWhereHas('occupation.aliases', function (Builder $aliasQuery) use ($mode, $normalizedQuery, $prefixQuery, $normalizedLocale): void {
                    if ($normalizedLocale !== null) {
                        $aliasQuery->where('lang', 'like', $normalizedLocale.'%');
                    }

                    $aliasQuery->where(function (Builder $matchQuery) use ($mode, $normalizedQuery, $prefixQuery): void {
                        $matchQuery->where('normalized', $normalizedQuery);

                        if ($mode !== 'exact') {
                            $matchQuery->orWhereRaw("normalized LIKE ? ESCAPE '!'", [$prefixQuery]);
                        }
                    });
                });
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_SEARCH_CANDIDATE_ROWS)
            ->get();

        $rankedRows = $snapshots
            ->groupBy('occupation_id')
            ->map(function (Collection $group) use ($normalizedQuery, $rawQuery, $normalizedLocale, $mode): ?array {
                /** @var RecommendationSnapshot|null $snapshot */
                $snapshot = $group
                    ->sort(function (RecommendationSnapshot $left, RecommendationSnapshot $right): int {
                        $leftCompiled = optional($left->compiled_at)?->getTimestamp() ?? 0;
                        $rightCompiled = optional($right->compiled_at)?->getTimestamp() ?? 0;
                        if ($leftCompiled !== $rightCompiled) {
                            return $rightCompiled <=> $leftCompiled;
                        }

                        return strcmp((string) $left->id, (string) $right->id);
                    })
                    ->first();

                if (! $snapshot instanceof RecommendationSnapshot) {
                    return null;
                }

                $occupation = $snapshot->occupation;
                if (! $occupation instanceof Occupation) {
                    return null;
                }

                $match = $this->resolveMatch($occupation, $normalizedQuery, $rawQuery, $normalizedLocale, $mode);
                if ($match === null) {
                    return null;
                }

                return [
                    'priority' => self::MATCH_PRIORITY[$match['kind']] ?? 999,
                    'source_priority' => 0,
                    'sort_title' => strtolower((string) ($occupation->canonical_title_en ?? '')),
                    'sort_slug' => strtolower((string) ($occupation->canonical_slug ?? '')),
                    'bundle' => $this->buildItem($snapshot, $occupation, $match['kind'], $match['text']),
                ];
            })
            ->filter()
            ->values();

        $safeSlugs = $rankedRows
            ->map(static fn (array $row): string => (string) ($row['bundle']->identity['canonical_slug'] ?? ''))
            ->filter()
            ->all();
        $directoryDraftRows = $this->buildDirectoryDraftSearchRows(
            query: $normalizedQuery,
            rawQuery: $rawQuery,
            normalizedLocale: $normalizedLocale,
            mode: $mode,
            excludedSlugs: $safeSlugs,
        );
        if ($directoryDraftRows !== []) {
            $rankedRows = $rankedRows->concat($directoryDraftRows)->values();
        }

        $results = $rankedRows
            ->sortBy([
                ['priority', 'asc'],
                ['source_priority', 'asc'],
                ['sort_title', 'asc'],
                ['sort_slug', 'asc'],
            ])
            ->values()
            ->take($limit)
            ->map(static fn (array $row): CareerSearchResultBundle => $row['bundle'])
            ->all();

        return $results;
    }

    /**
     * @param  list<string>  $excludedSlugs
     * @return list<array{priority:int,source_priority:int,sort_title:string,sort_slug:string,bundle:CareerSearchResultBundle}>
     */
    private function buildDirectoryDraftSearchRows(
        string $query,
        string $rawQuery,
        ?string $normalizedLocale,
        string $mode,
        array $excludedSlugs,
    ): array {
        $excluded = array_flip(array_filter($excludedSlugs));
        $prefixQuery = $this->likePrefixPattern($query);
        $rawPrefixQuery = $this->likePrefixPattern($rawQuery);

        return Occupation::query()
            ->with('aliases')
            ->where('crosswalk_mode', self::DIRECTORY_DRAFT_CROSSWALK_MODE)
            ->where(function (Builder $occupationQuery) use ($mode, $query, $prefixQuery, $rawQuery, $rawPrefixQuery, $normalizedLocale): void {
                $occupationQuery->where(function (Builder $matchQuery) use ($mode, $query, $prefixQuery, $rawQuery, $rawPrefixQuery): void {
                    $matchQuery->where('canonical_slug', $query)
                        ->orWhereRaw('LOWER(canonical_title_en) = ?', [$query])
                        ->orWhere('canonical_title_zh', $rawQuery);

                    if ($mode !== 'exact') {
                        $matchQuery->orWhereRaw("canonical_slug LIKE ? ESCAPE '!'", [$prefixQuery])
                            ->orWhereRaw("LOWER(canonical_title_en) LIKE ? ESCAPE '!'", [$prefixQuery])
                            ->orWhereRaw("canonical_title_zh LIKE ? ESCAPE '!'", [$rawPrefixQuery]);
                    }
                })->orWhereHas('aliases', function (Builder $aliasQuery) use ($mode, $query, $prefixQuery, $normalizedLocale): void {
                    if ($normalizedLocale !== null) {
                        $aliasQuery->where('lang', 'like', $normalizedLocale.'%');
                    }

                    $aliasQuery->where(function (Builder $matchQuery) use ($mode, $query, $prefixQuery): void {
                        $matchQuery->where('normalized', $query);

                        if ($mode !== 'exact') {
                            $matchQuery->orWhereRaw("normalized LIKE ? ESCAPE '!'", [$prefixQuery]);
                        }
                    });
                });
            })
            ->orderBy('canonical_title_en')
            ->orderBy('canonical_slug')
            ->limit(self::MAX_DIRECTORY_DRAFT_SEARCH_ROWS)
            ->get()
            ->filter(static fn (Occupation $occupation): bool => ! isset($excluded[(string) $occupation->canonical_slug]))
            ->map(function (Occupation $occupation) use ($query, $rawQuery, $normalizedLocale, $mode): ?array {
                $match = $this->resolveMatch($occupation, $query, $rawQuery, $normalizedLocale, $mode);
                if ($match === null) {
                    return null;
                }

                return [
                    'priority' => self::MATCH_PRIORITY[$match['kind']] ?? 999,
                    'source_priority' => 1,
                    'sort_title' => strtolower((string) ($occupation->canonical_title_en ?? '')),
                    'sort_slug' => strtolower((string) ($occupation->canonical_slug ?? '')),
                    'bundle' => $this->buildDirectoryDraftItem($occupation, $match['kind'], $match['text']),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{kind:string,text:string}|null
     */
    private function resolveMatch(Occupation $occupation, string $normalizedQuery, string $rawQuery, ?string $normalizedLocale, string $mode): ?array
    {
        $slug = $this->normalizeText($occupation->canonical_slug);
        $titleEn = $this->normalizeText($occupation->canonical_title_en);
        $titleZh = $this->normalizeRawText($occupation->canonical_title_zh);
        $allowPrefix = $mode !== 'exact';

        if ($slug === $normalizedQuery) {
            return ['kind' => 'canonical_slug_exact', 'text' => (string) $occupation->canonical_slug];
        }
        if ($titleEn === $normalizedQuery) {
            return ['kind' => 'canonical_title_exact', 'text' => (string) $occupation->canonical_title_en];
        }
        if ($titleZh !== null && $titleZh === $rawQuery) {
            return ['kind' => 'canonical_title_exact', 'text' => (string) $occupation->canonical_title_zh];
        }

        $aliases = $occupation->aliases instanceof Collection ? $occupation->aliases : collect();
        foreach ($aliases as $alias) {
            if (! $alias instanceof OccupationAlias) {
                continue;
            }
            if ($normalizedLocale !== null && ! str_starts_with(strtolower((string) $alias->lang), $normalizedLocale)) {
                continue;
            }

            $aliasNormalized = $this->normalizeText($alias->normalized);
            if ($aliasNormalized === $normalizedQuery) {
                return ['kind' => 'alias_exact', 'text' => (string) $alias->alias];
            }
        }

        if ($allowPrefix) {
            if ($slug !== null && str_starts_with($slug, $normalizedQuery)) {
                return ['kind' => 'canonical_slug_prefix', 'text' => (string) $occupation->canonical_slug];
            }
            if ($titleEn !== null && str_starts_with($titleEn, $normalizedQuery)) {
                return ['kind' => 'canonical_title_prefix', 'text' => (string) $occupation->canonical_title_en];
            }
            if ($titleZh !== null && str_starts_with($titleZh, $rawQuery)) {
                return ['kind' => 'canonical_title_prefix', 'text' => (string) $occupation->canonical_title_zh];
            }

            foreach ($aliases as $alias) {
                if (! $alias instanceof OccupationAlias) {
                    continue;
                }
                if ($normalizedLocale !== null && ! str_starts_with(strtolower((string) $alias->lang), $normalizedLocale)) {
                    continue;
                }

                $aliasNormalized = $this->normalizeText($alias->normalized);
                if ($aliasNormalized !== null && str_starts_with($aliasNormalized, $normalizedQuery)) {
                    return ['kind' => 'alias_prefix', 'text' => (string) $alias->alias];
                }
            }
        }

        return null;
    }

    private function buildItem(
        RecommendationSnapshot $snapshot,
        Occupation $occupation,
        string $matchKind,
        string $matchedText,
    ): CareerSearchResultBundle {
        $trustManifest = $snapshot->trustManifest;
        $importRunId = $snapshot->compileRun?->import_run_id;

        return new CareerSearchResultBundle(
            matchKind: $matchKind,
            matchedText: $matchedText,
            identity: [
                'occupation_uuid' => $occupation->id,
                'canonical_slug' => $occupation->canonical_slug,
            ],
            titles: [
                'canonical_en' => $occupation->canonical_title_en,
                'canonical_zh' => $occupation->canonical_title_zh,
                'search_h1_zh' => $occupation->search_h1_zh,
            ],
            seoContract: $this->buildSeoContract($occupation, $snapshot),
            trustSummary: [
                'reviewer_status' => $trustManifest?->reviewer_status,
                'reviewed_at' => optional($trustManifest?->reviewed_at)->toISOString(),
                'content_version' => $trustManifest?->content_version,
                'data_version' => $trustManifest?->data_version,
                'logic_version' => $trustManifest?->logic_version,
            ],
            provenanceMeta: [
                'compiler_version' => $snapshot->compiler_version,
                'compiled_at' => optional($snapshot->compiled_at)->toISOString(),
                'trust_manifest_id' => $snapshot->trust_manifest_id,
                'index_state_id' => $snapshot->index_state_id,
                'compile_run_id' => $snapshot->compile_run_id,
                'import_run_id' => $importRunId,
            ],
        );
    }

    private function buildDirectoryDraftItem(
        Occupation $occupation,
        string $matchKind,
        string $matchedText,
    ): CareerSearchResultBundle {
        return new CareerSearchResultBundle(
            matchKind: $matchKind,
            matchedText: $matchedText,
            identity: [
                'occupation_uuid' => $occupation->id,
                'canonical_slug' => $occupation->canonical_slug,
            ],
            titles: [
                'canonical_en' => $occupation->canonical_title_en,
                'canonical_zh' => $occupation->canonical_title_zh,
                'search_h1_zh' => $occupation->search_h1_zh,
            ],
            seoContract: $this->buildDirectoryDraftSeoContract($occupation),
            trustSummary: [
                'status' => 'unavailable',
                'reviewed_at' => null,
                'cross_market_notice' => null,
            ],
            provenanceMeta: [
                'compiler_version' => null,
                'compiled_at' => null,
                'trust_manifest_id' => null,
                'index_state_id' => null,
                'compile_run_id' => null,
            ],
        );
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
        $publicIndexState = IndexStateValue::publicFacing((string) ($indexState?->index_state ?? ''), $indexEligible);
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_search_result_bundle',
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
     * @return array<string, mixed>
     */
    private function buildDirectoryDraftSeoContract(Occupation $occupation): array
    {
        $canonicalPath = '/career/jobs/'.(string) $occupation->canonical_slug;
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_search_result_bundle',
            'canonical_url' => $canonicalPath,
            'robots_policy' => 'noindex,follow',
            'title' => $occupation->canonical_title_en,
            'description' => $occupation->canonical_title_en,
            'indexability_state' => IndexStateValue::NOINDEX,
            'sitemap_state' => 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => null,
            'index_state' => IndexStateValue::NOINDEX,
            'index_eligible' => false,
            'reason_codes' => ['detail_page_unavailable'],
            'metadata_contract_version' => $surface['metadata_contract_version'] ?? $surface['version'] ?? null,
            'surface_type' => $surface['surface_type'] ?? null,
            'robots_policy' => $surface['robots_policy'] ?? 'noindex,follow',
            'metadata_fingerprint' => $surface['metadata_fingerprint'] ?? null,
        ];
    }

    private function normalizeLocale(?string $locale): ?string
    {
        $normalized = $this->normalizeText($locale);
        if ($normalized === null) {
            return null;
        }

        return match ($normalized) {
            'zh', 'zh-cn' => 'zh',
            'en', 'en-us' => 'en',
            default => null,
        };
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return mb_strtolower($normalized, 'UTF-8');
    }

    private function normalizeRawText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function likePrefixPattern(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
