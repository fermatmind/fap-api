<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerJobListItemBundle;
use App\Models\CareerJob;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Models\Scopes\TenantScope;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Collection;

final class CareerJobListBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    private const DIRECTORY_DRAFT_CROSSWALK_MODE = 'directory_draft';

    private const PUBLIC_DIRECTORY_STUB_KIND = 'public_directory_stub';

    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    /**
     * @return list<CareerJobListItemBundle>
     */
    public function build(bool $includeNonIndexable = false): array
    {
        $snapshots = RecommendationSnapshot::query()
            ->with([
                'occupation.editorialPatches' => fn ($query) => $query->orderByDesc('updated_at')->orderByDesc('created_at'),
                'truthMetric',
                'trustManifest',
                'indexState',
                'compileRun',
                'profileProjection',
                'contextSnapshot',
            ])
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('occupation', static function ($query): void {
                $query->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES);
            })
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->get();

        $selectedSnapshots = $snapshots
            ->groupBy('occupation_id')
            ->map(function (Collection $group) use ($includeNonIndexable): ?RecommendationSnapshot {
                /** @var RecommendationSnapshot|null $selected */
                $selected = $group
                    ->sort(function (RecommendationSnapshot $left, RecommendationSnapshot $right) use ($includeNonIndexable): int {
                        return $this->compareSnapshots($left, $right, $includeNonIndexable);
                    })
                    ->first();

                return $selected;
            })
            ->filter()
            ->values();

        $items = $selectedSnapshots
            ->map(function (RecommendationSnapshot $snapshot) use ($includeNonIndexable): ?CareerJobListItemBundle {
                $occupation = $snapshot->occupation;
                if (! $occupation instanceof Occupation) {
                    return null;
                }

                $indexState = $snapshot->indexState;
                if (! $includeNonIndexable && ! (bool) ($indexState?->index_eligible ?? false)) {
                    return null;
                }

                return $this->buildItem($snapshot, $occupation);
            })
            ->filter()
            ->values();

        $compiledSlugs = $items
            ->map(static fn (CareerJobListItemBundle $item): string => (string) ($item->identity['canonical_slug'] ?? ''))
            ->filter()
            ->all();

        $docxItems = $this->buildPublishedDocxCareerJobItems($compiledSlugs);
        if ($docxItems !== []) {
            $items = $items->concat($docxItems)->values();
        }

        $visibleSlugs = $items
            ->map(static fn (CareerJobListItemBundle $item): string => (string) ($item->identity['canonical_slug'] ?? ''))
            ->filter()
            ->all();
        $directoryDraftItems = $this->buildDirectoryDraftCareerJobItems($visibleSlugs);
        if ($directoryDraftItems !== []) {
            $items = $items->concat($directoryDraftItems)->values();
        }

        /** @var Collection<int, CareerJobListItemBundle> $items */
        $items = $items->sortBy(static fn (CareerJobListItemBundle $item): array => [
            strtolower((string) ($item->titles['canonical_en'] ?? '')),
            strtolower((string) ($item->identity['canonical_slug'] ?? '')),
        ])->values();

        return $items->all();
    }

    /**
     * @param  list<string>  $excludedSlugs
     * @return list<CareerJobListItemBundle>
     */
    private function buildPublishedDocxCareerJobItems(array $excludedSlugs): array
    {
        $excluded = array_flip(array_filter($excludedSlugs));

        return CareerJob::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->orderBy('subtitle')
            ->orderBy('slug')
            ->get()
            ->filter(fn (CareerJob $job): bool => ! isset($excluded[(string) $job->slug]) && $this->isDocxCareerJob($job))
            ->values()
            ->map(fn (CareerJob $job): CareerJobListItemBundle => $this->buildDocxCareerJobItem($job))
            ->all();
    }

    /**
     * @param  list<string>  $excludedSlugs
     * @return list<CareerJobListItemBundle>
     */
    private function buildDirectoryDraftCareerJobItems(array $excludedSlugs): array
    {
        $excluded = array_flip(array_filter($excludedSlugs));

        return Occupation::query()
            ->where('crosswalk_mode', self::DIRECTORY_DRAFT_CROSSWALK_MODE)
            ->orderBy('canonical_title_en')
            ->orderBy('canonical_slug')
            ->get()
            ->filter(static fn (Occupation $occupation): bool => ! isset($excluded[(string) $occupation->canonical_slug]))
            ->values()
            ->map(fn (Occupation $occupation): CareerJobListItemBundle => $this->buildDirectoryDraftCareerJobItem($occupation))
            ->all();
    }

    private function compareSnapshots(RecommendationSnapshot $left, RecommendationSnapshot $right, bool $includeNonIndexable): int
    {
        if (! $includeNonIndexable) {
            $leftEligible = (bool) ($left->indexState?->index_eligible ?? false);
            $rightEligible = (bool) ($right->indexState?->index_eligible ?? false);
            if ($leftEligible !== $rightEligible) {
                return $leftEligible ? -1 : 1;
            }
        }

        $leftCompiled = optional($left->compiled_at)?->getTimestamp() ?? 0;
        $rightCompiled = optional($right->compiled_at)?->getTimestamp() ?? 0;
        if ($leftCompiled !== $rightCompiled) {
            return $rightCompiled <=> $leftCompiled;
        }

        return strcmp((string) $left->id, (string) $right->id);
    }

    private function buildItem(RecommendationSnapshot $snapshot, Occupation $occupation): CareerJobListItemBundle
    {
        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $truthMetric = $snapshot->truthMetric;
        $trustManifest = $snapshot->trustManifest;
        $indexState = $snapshot->indexState;
        $editorialPatch = $occupation->editorialPatches->first();
        $claimPermissions = is_array($payload['claim_permissions'] ?? null) ? $payload['claim_permissions'] : [];
        $importRunId = $truthMetric?->import_run_id
            ?? $trustManifest?->import_run_id
            ?? $indexState?->import_run_id;

        return new CareerJobListItemBundle(
            identity: [
                'occupation_uuid' => $occupation->id,
                'canonical_slug' => $occupation->canonical_slug,
                'entity_level' => $occupation->entity_level,
                'family_uuid' => $occupation->family_id,
            ],
            titles: [
                'canonical_en' => $occupation->canonical_title_en,
                'canonical_zh' => $occupation->canonical_title_zh,
                'search_h1_zh' => $occupation->search_h1_zh,
            ],
            truthSummary: [
                'truth_market' => $truthMetric?->truth_market,
                'median_pay_usd_annual' => $claimPermissions['allow_salary_comparison'] ?? false
                    ? $truthMetric?->median_pay_usd_annual
                    : null,
                'outlook_pct_2024_2034' => $truthMetric?->outlook_pct_2024_2034,
                'outlook_description' => $truthMetric?->outlook_description,
                'ai_exposure' => $claimPermissions['allow_ai_strategy'] ?? false
                    ? $truthMetric?->ai_exposure
                    : null,
            ],
            trustSummary: [
                'reviewer_status' => $trustManifest?->reviewer_status,
                'reviewed_at' => optional($trustManifest?->reviewed_at)->toISOString(),
                'content_version' => $trustManifest?->content_version,
                'data_version' => $trustManifest?->data_version,
                'logic_version' => $trustManifest?->logic_version,
                'editorial_patch_required' => (bool) ($editorialPatch?->required ?? false),
                'editorial_patch_status' => $editorialPatch?->status,
                'allow_strong_claim' => (bool) ($claimPermissions['allow_strong_claim'] ?? false),
                'allow_salary_comparison' => (bool) ($claimPermissions['allow_salary_comparison'] ?? false),
                'allow_ai_strategy' => (bool) ($claimPermissions['allow_ai_strategy'] ?? false),
                'reason_codes' => is_array($claimPermissions['reason_codes'] ?? null) ? $claimPermissions['reason_codes'] : [],
            ],
            scoreSummary: [
                'fit_score' => $this->compactScore($payload['score_bundle']['fit_score'] ?? null),
                'confidence_score' => $this->compactScore($payload['score_bundle']['confidence_score'] ?? null),
            ],
            seoContract: $this->buildSeoContract($occupation, $snapshot),
            provenanceMeta: [
                'content_version' => $trustManifest?->content_version,
                'data_version' => $trustManifest?->data_version,
                'logic_version' => $trustManifest?->logic_version,
                'compiler_version' => $snapshot->compiler_version,
                'compiled_at' => optional($snapshot->compiled_at)->toISOString(),
                'truth_metric_id' => $snapshot->truth_metric_id,
                'trust_manifest_id' => $snapshot->trust_manifest_id,
                'index_state_id' => $snapshot->index_state_id,
                'compile_run_id' => $snapshot->compile_run_id,
                'import_run_id' => $importRunId,
            ],
        );
    }

    private function buildDocxCareerJobItem(CareerJob $job): CareerJobListItemBundle
    {
        $salary = is_array($job->salary_json) ? $job->salary_json : [];
        $outlook = is_array($job->outlook_json) ? $job->outlook_json : [];
        $market = is_array($job->market_demand_json) ? $job->market_demand_json : [];

        return new CareerJobListItemBundle(
            identity: [
                'occupation_uuid' => 'career_job:'.(string) $job->slug,
                'canonical_slug' => (string) $job->slug,
                'entity_level' => 'career_job_detail',
                'family_uuid' => null,
            ],
            titles: [
                'canonical_en' => $job->subtitle,
                'canonical_zh' => (string) $job->title,
                'search_h1_zh' => (string) $job->title,
            ],
            truthSummary: [
                'truth_market' => 'US',
                'median_pay_usd_annual' => $salary['annual_median_usd'] ?? null,
                'outlook_pct_2024_2034' => $outlook['outlook_pct_2024_2034'] ?? null,
                'outlook_description' => $outlook['outlook_raw'] ?? null,
                'ai_exposure' => $market['ai_exposure_score_10'] ?? null,
            ],
            trustSummary: [
                'reviewer_status' => 'docx_baseline_imported',
                'reviewed_at' => optional($job->updated_at)->toISOString(),
                'content_version' => 'docx_342_career_batch',
                'data_version' => 'docx_342_career_batch',
                'logic_version' => 'career.protocol.job_detail.docx_baseline.v1',
                'editorial_patch_required' => false,
                'editorial_patch_status' => null,
                'allow_strong_claim' => true,
                'allow_salary_comparison' => true,
                'allow_ai_strategy' => true,
                'reason_codes' => [],
            ],
            scoreSummary: [
                'fit_score' => [
                    'value' => null,
                    'integrity_state' => 'docx_baseline_without_fit_score',
                    'band' => null,
                ],
                'confidence_score' => [
                    'value' => null,
                    'integrity_state' => 'docx_baseline_without_confidence_score',
                    'band' => null,
                ],
            ],
            seoContract: $this->buildDocxSeoContract($job),
            provenanceMeta: [
                'content_version' => 'docx_342_career_batch',
                'data_version' => 'docx_342_career_batch',
                'logic_version' => 'career.protocol.job_detail.docx_baseline.v1',
                'compiler_version' => null,
                'compiled_at' => null,
                'truth_metric_id' => null,
                'trust_manifest_id' => null,
                'index_state_id' => null,
                'compile_run_id' => null,
                'import_run_id' => null,
            ],
        );
    }

    private function buildDirectoryDraftCareerJobItem(Occupation $occupation): CareerJobListItemBundle
    {
        return new CareerJobListItemBundle(
            identity: [
                'canonical_slug' => $occupation->canonical_slug,
            ],
            titles: [
                'canonical_en' => $occupation->canonical_title_en,
                'canonical_zh' => $occupation->canonical_title_zh,
                'search_h1_zh' => $occupation->search_h1_zh,
            ],
            truthSummary: [
                'truth_market' => $occupation->truth_market,
            ],
            trustSummary: [
                'public_stub_kind' => self::PUBLIC_DIRECTORY_STUB_KIND,
                'status' => 'unavailable',
                'availability' => 'detail_unavailable',
                'allow_strong_claim' => false,
                'allow_salary_comparison' => false,
                'allow_ai_strategy' => false,
                'reason_codes' => ['detail_page_unavailable'],
            ],
            scoreSummary: [],
            seoContract: $this->buildDirectoryDraftSeoContract($occupation, 'career_job_list_item_bundle'),
            provenanceMeta: [],
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
            'surface_type' => 'career_job_list_item_bundle',
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
    private function buildDocxSeoContract(CareerJob $job): array
    {
        $canonicalPath = '/career/jobs/'.(string) $job->slug;
        $indexEligible = (bool) $job->is_indexable;
        $publicIndexState = $indexEligible ? IndexStateValue::INDEXABLE : IndexStateValue::NOINDEX;
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_job_list_item_bundle',
            'canonical_url' => $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => (string) ($job->subtitle ?? $job->title),
            'description' => (string) ($job->excerpt ?? $job->title),
            'indexability_state' => $publicIndexState,
            'sitemap_state' => $indexEligible ? 'included' : 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => null,
            'index_state' => $publicIndexState,
            'index_eligible' => $indexEligible,
            'reason_codes' => [],
            'metadata_contract_version' => $surface['metadata_contract_version'] ?? $surface['version'] ?? null,
            'surface_type' => $surface['surface_type'] ?? null,
            'robots_policy' => $surface['robots_policy'] ?? $robotsPolicy,
            'metadata_fingerprint' => $surface['metadata_fingerprint'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDirectoryDraftSeoContract(Occupation $occupation, string $surfaceType): array
    {
        $canonicalPath = '/career/jobs/'.(string) $occupation->canonical_slug;
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => $surfaceType,
            'canonical_url' => $canonicalPath,
            'robots_policy' => 'noindex,follow',
            'title' => $occupation->canonical_title_en,
            'description' => $occupation->canonical_title_en,
            'indexability_state' => IndexStateValue::NOINDEX,
            'sitemap_state' => 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'index_state' => IndexStateValue::NOINDEX,
            'index_eligible' => false,
            'reason_codes' => ['detail_page_unavailable'],
            'public_stub_kind' => self::PUBLIC_DIRECTORY_STUB_KIND,
            'surface_type' => $surface['surface_type'] ?? null,
            'robots_policy' => $surface['robots_policy'] ?? 'noindex,follow',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactScore(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'value' => null,
                'integrity_state' => null,
                'band' => null,
            ];
        }

        return [
            'value' => $value['value'] ?? null,
            'integrity_state' => $value['integrity_state'] ?? null,
            'band' => $value['band'] ?? null,
        ];
    }

    private function isDocxCareerJob(CareerJob $job): bool
    {
        return is_string(data_get($job->seoMeta?->jsonld_overrides_json, 'source_docx'))
            && data_get($job->market_demand_json, 'source_refs.0.url') !== null;
    }
}
