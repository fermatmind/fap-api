<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\RecommendationSnapshot;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CareerDetailReadyPublicationCandidateScanner
{
    private const SCHEMA_VERSION = 'career_detail_ready_publication_candidates.v1';

    private const TARGET_KEY = 'detail_ready_1048';

    private const MANUAL_HOLD_SLUGS = [
        'software-developers',
    ];

    private const DISPLAY_SURFACE_VERSION = 'display.surface.v1';

    private const DISPLAY_ASSET_VERSION = 'v4.2';

    private const DISPLAY_ASSET_TYPE = 'career_job_public_display';

    private const DISPLAY_READY_STATUS = 'ready_for_pilot';

    private const DISPLAY_COMPONENT_ORDER_COUNT = 24;

    public function __construct(
        private readonly CareerRuntimePublishProjectionVisibility $runtimeProjection,
        private readonly CareerFullReleaseLedgerProjectionService $fullReleaseLedgerProjectionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(): array
    {
        $currentPublicSlugs = $this->currentPublicDetailSlugs();
        $docxReadySlugs = $this->docxReadySlugs();
        $displayAssetReadySlugs = $this->displayAssetReadySlugs();
        $compiledReadySlugs = $this->compiledReadySlugs();
        $unionReadySlugs = $this->sortedUnique([
            ...$docxReadySlugs,
            ...$displayAssetReadySlugs,
            ...$compiledReadySlugs,
        ]);
        $readyNotPublicSlugs = $this->diff($unionReadySlugs, $currentPublicSlugs);
        $manualHoldReadySlugs = $this->intersect($unionReadySlugs, self::MANUAL_HOLD_SLUGS);
        $ledger = $this->ledgerSummary();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'target_key' => self::TARGET_KEY,
            'generated_at' => now()->toISOString(),
            'writes_database' => false,
            'apply_allowed' => false,
            'rollout_allowed' => false,
            'deploy_allowed' => false,
            'cms_mutation_allowed' => false,
            'fap_web_fallback_authority_allowed' => false,
            'product_visible_claim_boundary' => [
                'target' => 'all currently backend-authoritative detail-ready Career jobs',
                'not_a_2786_partition_accounting_claim' => true,
                'do_not_claim_all_2786_visible' => true,
                'raw_occupation_assets_are_not_publication_authority' => true,
            ],
            'counts' => [
                'current_public_detail' => count($currentPublicSlugs),
                'docx_ready' => count($docxReadySlugs),
                'display_asset_ready' => count($displayAssetReadySlugs),
                'compiled_snapshot_ready' => count($compiledReadySlugs),
                'union_detail_ready' => count($unionReadySlugs),
                'ready_not_currently_public' => count($readyNotPublicSlugs),
                'manual_hold_ready' => count($manualHoldReadySlugs),
                'raw_occupation_assets' => Occupation::query()->count(),
                'career_job_rows' => CareerJob::query()->withoutGlobalScope(TenantScope::class)->count(),
                'display_asset_rows' => CareerJobDisplayAsset::query()->count(),
            ],
            'current_public_30' => [
                'count' => count($currentPublicSlugs),
                'slugs' => $currentPublicSlugs,
            ],
            'ready_not_public_1018' => [
                'count' => count($readyNotPublicSlugs),
                'slugs' => $readyNotPublicSlugs,
            ],
            'sources' => [
                'docx_ready' => [
                    'count' => count($docxReadySlugs),
                    'slugs' => $docxReadySlugs,
                ],
                'display_asset_ready' => [
                    'count' => count($displayAssetReadySlugs),
                    'slugs' => $displayAssetReadySlugs,
                ],
                'compiled_ready' => [
                    'count' => count($compiledReadySlugs),
                    'slugs' => $compiledReadySlugs,
                ],
                'union_detail_ready' => [
                    'count' => count($unionReadySlugs),
                    'slugs' => $unionReadySlugs,
                ],
            ],
            'manual_hold' => [
                'policy' => 'do_not_force_enable_without_explicit_manual_hold_release_decision',
                'configured_slugs' => self::MANUAL_HOLD_SLUGS,
                'ready_slugs' => $manualHoldReadySlugs,
            ],
            'ledger_classification' => $ledger,
            'row_counts' => [
                'career_jobs_by_locale' => $this->careerJobRowsByLocale(),
            ],
            'next_required_action' => 'REPAIR-CAREER-DETAIL-READY-TARGET-AUTHORITY-1',
        ];
    }

    /**
     * @return list<string>
     */
    private function currentPublicDetailSlugs(): array
    {
        return $this->sortedUnique(array_map(
            static fn (array $item): string => strtolower(trim((string) ($item['slug'] ?? ''))),
            $this->runtimeProjection->publicDetailItems(),
        ));
    }

    /**
     * @return list<string>
     */
    private function docxReadySlugs(): array
    {
        return CareerJob::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->get()
            ->filter(static fn (CareerJob $job): bool => is_string(data_get($job->seoMeta?->jsonld_overrides_json, 'source_docx'))
                && data_get($job->market_demand_json, 'source_refs.0.url') !== null)
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => strtolower(trim((string) $slug)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function displayAssetReadySlugs(): array
    {
        return Occupation::query()
            ->with(['crosswalks', 'displayAssets'])
            ->get()
            ->filter(fn (Occupation $occupation): bool => $this->hasValidDisplayAssetDetailAuthority($occupation))
            ->pluck('canonical_slug')
            ->map(static fn (mixed $slug): string => strtolower(trim((string) $slug)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function compiledReadySlugs(): array
    {
        return RecommendationSnapshot::query()
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('occupation', static function ($query): void {
                $query->whereIn('crosswalk_mode', ['exact', 'trust_inheritance', 'direct_match']);
            })
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave')
                    ->whereNull('projection_payload->recommendation_subject_meta');
            })
            ->whereHas('compileRun', static function ($query): void {
                $query->where('status', 'completed')
                    ->where('dry_run', false);
            })
            ->with('occupation:id,canonical_slug')
            ->get()
            ->map(static fn (RecommendationSnapshot $snapshot): string => strtolower(trim((string) $snapshot->occupation?->canonical_slug)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function hasValidDisplayAssetDetailAuthority(Occupation $occupation): bool
    {
        $slug = strtolower(trim((string) $occupation->canonical_slug));
        if ($slug === '' || in_array($slug, self::MANUAL_HOLD_SLUGS, true)) {
            return false;
        }

        foreach (['us_soc', 'onet_soc_2019'] as $sourceSystem) {
            $rows = $occupation->crosswalks
                ->filter(static fn (OccupationCrosswalk $crosswalk): bool => strtolower((string) $crosswalk->source_system) === $sourceSystem)
                ->values();

            if ($rows->count() !== 1 || trim((string) $rows->first()?->source_code) === '') {
                return false;
            }
        }

        $assets = $occupation->displayAssets
            ->where('canonical_slug', $slug)
            ->where('surface_version', self::DISPLAY_SURFACE_VERSION)
            ->where('asset_version', self::DISPLAY_ASSET_VERSION)
            ->where('template_version', self::DISPLAY_ASSET_VERSION)
            ->where('status', self::DISPLAY_READY_STATUS)
            ->where('asset_type', self::DISPLAY_ASSET_TYPE)
            ->values();

        if ($assets->count() !== 1) {
            return false;
        }

        $asset = $assets->first();
        if (! $asset instanceof CareerJobDisplayAsset) {
            return false;
        }

        $componentOrder = is_array($asset->component_order_json) ? array_values($asset->component_order_json) : [];
        $pages = is_array($asset->page_payload_json) ? $asset->page_payload_json : [];
        $localizedPages = is_array($pages['page'] ?? null) ? $pages['page'] : $pages;

        return count($componentOrder) === self::DISPLAY_COMPONENT_ORDER_COUNT
            && is_array($localizedPages['zh'] ?? null)
            && is_array($localizedPages['en'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerSummary(): array
    {
        try {
            $ledger = $this->fullReleaseLedgerProjectionService->build()[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? [];
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $members = collect((array) ($ledger['members'] ?? []))
            ->filter(static fn (mixed $member): bool => is_array($member))
            ->values();

        return [
            'available' => true,
            'release_counts' => $ledger['counts']['release_counts'] ?? $ledger['release_counts'] ?? [],
            'tracking_counts' => $ledger['tracking_counts'] ?? [],
            'release_cohort_counts' => $this->countBy($members, 'release_cohort'),
            'blocker_reason_counts' => $this->blockerReasonCounts($members),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $members
     * @return array<string, int>
     */
    private function countBy(Collection $members, string $field): array
    {
        return $members
            ->map(static fn (array $member): string => trim((string) ($member[$field] ?? '__missing__')))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $members
     * @return array<string, int>
     */
    private function blockerReasonCounts(Collection $members): array
    {
        $counts = [];
        foreach ($members as $member) {
            foreach ((array) ($member['blocker_reasons'] ?? []) as $reason) {
                $key = trim((string) $reason);
                if ($key === '') {
                    continue;
                }
                $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @return list<array{locale: string, count: int}>
     */
    private function careerJobRowsByLocale(): array
    {
        return CareerJob::query()
            ->withoutGlobalScope(TenantScope::class)
            ->select('locale', DB::raw('count(*) as count'))
            ->groupBy('locale')
            ->orderBy('locale')
            ->get()
            ->map(static fn (mixed $row): array => [
                'locale' => (string) $row->locale,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<list<string>|string>  $groups
     * @return list<string>
     */
    private function sortedUnique(array $groups): array
    {
        $slugs = [];
        foreach ($groups as $group) {
            foreach ((array) $group as $slug) {
                $normalized = strtolower(trim((string) $slug));
                if ($normalized !== '') {
                    $slugs[$normalized] = true;
                }
            }
        }

        $keys = array_keys($slugs);
        sort($keys);

        return $keys;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function diff(array $left, array $right): array
    {
        $rightSet = array_fill_keys($right, true);

        return array_values(array_filter($left, static fn (string $slug): bool => ! isset($rightSet[$slug])));
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function intersect(array $left, array $right): array
    {
        $rightSet = array_fill_keys($right, true);

        return array_values(array_filter($left, static fn (string $slug): bool => isset($rightSet[$slug])));
    }
}
