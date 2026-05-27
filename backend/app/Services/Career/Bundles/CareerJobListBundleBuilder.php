<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\Import\RunStatus;
use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\DTO\Career\CareerJobListItemBundle;
use App\Models\CareerCompileRun;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\RecommendationSnapshot;
use App\Models\Scopes\TenantScope;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Collection;

final class CareerJobListBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    private const DIRECTORY_DRAFT_CROSSWALK_MODE = 'directory_draft';

    private const PUBLIC_DIRECTORY_STUB_KIND = 'public_directory_stub';

    private const DISPLAY_SURFACE_VERSION = 'display.surface.v1';

    private const DISPLAY_ASSET_VERSION = 'v4.2';

    private const DISPLAY_ASSET_TYPE = 'career_job_public_display';

    private const DISPLAY_READY_STATUS = 'ready_for_pilot';

    private const DISPLAY_COMPONENT_ORDER_COUNT = 24;

    private const DISPLAY_ASSET_BACKED_MANUAL_HOLD_SLUGS = [
        'software-developers',
    ];

    private const MAX_PUBLIC_COMPILED_ROWS = 3200;

    private const MAX_PUBLIC_DOCX_ROWS = 3200;

    private const MAX_PUBLIC_DIRECTORY_DRAFT_ROWS = 3200;

    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
        private readonly CareerRuntimePublishProjectionVisibility $runtimePublishProjection,
        private readonly CareerJobDisplaySurfaceBuilder $displaySurfaceBuilder,
    ) {}

    /**
     * @return list<CareerJobListItemBundle>
     */
    public function build(bool $includeNonIndexable = false): array
    {
        $compileRunId = $this->latestCompletedJobListCompileRunId();
        $runtimeDetailItems = $this->runtimePublishProjection->publicDetailItems();
        $runtimeDetailSlugs = $this->runtimeProjectionSlugSet($runtimeDetailItems);
        $hasRuntimeDetailAuthority = $runtimeDetailSlugs !== [];

        $snapshotQuery = RecommendationSnapshot::query()
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
            ->whereHas('occupation', static function ($query): void {
                $query->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES);
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
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PUBLIC_COMPILED_ROWS);

        if ($hasRuntimeDetailAuthority) {
            $snapshotQuery->whereHas('occupation', static function ($query) use ($runtimeDetailSlugs): void {
                $query->whereIn('canonical_slug', array_keys($runtimeDetailSlugs));
            });
        }

        if ($compileRunId !== null) {
            $snapshotQuery->where('compile_run_id', $compileRunId);
        } else {
            $snapshotQuery->whereRaw('1 = 0');
        }

        $snapshots = $snapshotQuery->get();

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

                if (! $this->runtimePublishProjection->datasetVisible((string) $occupation->canonical_slug)) {
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

        $docxItems = $this->buildPublishedDocxCareerJobItems(
            $compiledSlugs,
            $hasRuntimeDetailAuthority ? $runtimeDetailSlugs : null,
        );
        if ($docxItems !== []) {
            $items = $items->concat($docxItems)->values();
        }

        $visibleSlugs = $items
            ->map(static fn (CareerJobListItemBundle $item): string => (string) ($item->identity['canonical_slug'] ?? ''))
            ->filter()
            ->all();
        $directoryDraftItems = $this->buildDirectoryDraftCareerJobItems(
            $visibleSlugs,
            $hasRuntimeDetailAuthority ? $runtimeDetailSlugs : null,
        );
        if ($directoryDraftItems !== []) {
            $items = $items->concat($directoryDraftItems)->values();
        }

        $visibleSlugs = $items
            ->map(static fn (CareerJobListItemBundle $item): string => (string) ($item->identity['canonical_slug'] ?? ''))
            ->filter()
            ->all();
        $runtimeProjectionItems = $this->buildRuntimeProjectionCareerJobItems(
            $visibleSlugs,
            $includeNonIndexable,
            $runtimeDetailItems,
        );
        if ($runtimeProjectionItems !== []) {
            $items = $items->concat($runtimeProjectionItems)->values();
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
     * @param  array<string, true>|null  $allowedSlugs
     * @return list<CareerJobListItemBundle>
     */
    private function buildPublishedDocxCareerJobItems(array $excludedSlugs, ?array $allowedSlugs = null): array
    {
        $excluded = array_flip(array_filter($excludedSlugs));

        $query = CareerJob::query()
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
            ->limit(self::MAX_PUBLIC_DOCX_ROWS);

        if ($allowedSlugs !== null) {
            $query->whereIn('slug', array_keys($allowedSlugs));
        }

        return $query->get()
            ->filter(fn (CareerJob $job): bool => ! isset($excluded[(string) $job->slug])
                && $this->isDocxCareerJob($job)
                && $this->runtimePublishProjection->datasetVisible((string) $job->slug))
            ->values()
            ->map(fn (CareerJob $job): CareerJobListItemBundle => $this->buildDocxCareerJobItem($job))
            ->all();
    }

    /**
     * @param  list<string>  $excludedSlugs
     * @param  array<string, true>|null  $allowedSlugs
     * @return list<CareerJobListItemBundle>
     */
    private function buildDirectoryDraftCareerJobItems(array $excludedSlugs, ?array $allowedSlugs = null): array
    {
        $excluded = array_flip(array_filter($excludedSlugs));

        $query = Occupation::query()
            ->with(['crosswalks', 'displayAssets'])
            ->where('crosswalk_mode', self::DIRECTORY_DRAFT_CROSSWALK_MODE)
            ->orderBy('canonical_title_en')
            ->orderBy('canonical_slug')
            ->limit(self::MAX_PUBLIC_DIRECTORY_DRAFT_ROWS);

        if ($allowedSlugs !== null) {
            $query->whereIn('canonical_slug', array_keys($allowedSlugs));
        }

        return $query->get()
            ->filter(fn (Occupation $occupation): bool => ! isset($excluded[(string) $occupation->canonical_slug])
                && $this->runtimePublishProjection->datasetVisible((string) $occupation->canonical_slug))
            ->values()
            ->map(fn (Occupation $occupation): CareerJobListItemBundle => $this->buildDirectoryDraftCareerJobItem($occupation))
            ->all();
    }

    /**
     * @param  list<string>  $excludedSlugs
     * @param  list<array<string, mixed>>|null  $runtimeDetailItems
     * @return list<CareerJobListItemBundle>
     */
    private function buildRuntimeProjectionCareerJobItems(
        array $excludedSlugs,
        bool $includeNonIndexable,
        ?array $runtimeDetailItems = null
    ): array {
        $excluded = array_flip(array_filter($excludedSlugs));
        $projectionItemsBySlug = [];

        foreach (($runtimeDetailItems ?? $this->runtimePublishProjection->publicDetailItems()) as $item) {
            $slug = trim((string) ($item['slug'] ?? ''));
            if ($slug === '' || isset($excluded[$slug])) {
                continue;
            }

            if (! $includeNonIndexable && ($item['robots_indexable'] ?? false) !== true) {
                continue;
            }

            $projectionItemsBySlug[$slug] = $item;
        }

        if ($projectionItemsBySlug === []) {
            return [];
        }

        return Occupation::query()
            ->with('family:id,canonical_slug')
            ->whereIn('canonical_slug', array_keys($projectionItemsBySlug))
            ->orderBy('canonical_title_en')
            ->orderBy('canonical_slug')
            ->get()
            ->map(fn (Occupation $occupation): CareerJobListItemBundle => $this->buildRuntimeProjectionCareerJobItem(
                $occupation,
                $projectionItemsBySlug[(string) $occupation->canonical_slug] ?? [],
            ))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, true>
     */
    private function runtimeProjectionSlugSet(array $items): array
    {
        $slugs = [];

        foreach ($items as $item) {
            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }

        return $slugs;
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

    private function latestCompletedJobListCompileRunId(): ?string
    {
        $id = CareerCompileRun::query()
            ->where('status', RunStatus::COMPLETED)
            ->where('dry_run', false)
            ->whereHas('recommendationSnapshots', static function ($query): void {
                $query->whereNotNull('compiled_at')
                    ->whereHas('occupation', static function ($query): void {
                        $query->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES);
                    })
                    ->whereHas('contextSnapshot', static function ($query): void {
                        $query->where('context_payload->materialization', 'career_first_wave');
                    })
                    ->whereHas('profileProjection', static function ($query): void {
                        $query->where('projection_payload->materialization', 'career_first_wave')
                            ->whereNull('projection_payload->recommendation_subject_meta');
                    });
            })
            ->orderByDesc('finished_at')
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->value('id');

        return is_string($id) && $id !== '' ? $id : null;
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
        if ($this->directoryDraftHasDisplayAssetBackedDetail($occupation)) {
            return $this->buildDisplayAssetBackedDirectoryDraftCareerJobItem($occupation);
        }

        $runtimeProjectionItem = $this->runtimePublishedDirectoryDraftProjectionItem($occupation);
        if ($runtimeProjectionItem !== null) {
            return $this->buildRuntimeProjectionCareerJobItem($occupation, $runtimeProjectionItem);
        }

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

    private function buildDisplayAssetBackedDirectoryDraftCareerJobItem(Occupation $occupation): CareerJobListItemBundle
    {
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
                'truth_market' => $occupation->truth_market,
            ],
            trustSummary: [
                'reviewer_status' => 'pilot_display_asset',
                'reviewed_at' => null,
                'content_version' => 'display_asset_backed_v4_2',
                'data_version' => 'career_job_display_assets.v4.2',
                'logic_version' => 'career.protocol.job_list.display_asset_backed.v1',
                'editorial_patch_required' => false,
                'editorial_patch_status' => null,
                'allow_strong_claim' => false,
                'allow_salary_comparison' => false,
                'allow_ai_strategy' => false,
                'reason_codes' => ['validated_display_asset_backed_release', 'runtime_publish_projection'],
            ],
            scoreSummary: [],
            seoContract: $this->buildDisplayAssetBackedDirectoryDraftSeoContract($occupation),
            provenanceMeta: [
                'content_version' => 'display_asset_backed_v4_2',
                'data_version' => 'career_job_display_assets.v4.2',
                'logic_version' => 'career.protocol.job_list.display_asset_backed.v1',
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

    /**
     * @param  array<string, mixed>  $projectionItem
     */
    private function buildRuntimeProjectionCareerJobItem(Occupation $occupation, array $projectionItem): CareerJobListItemBundle
    {
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
                'truth_market' => $occupation->truth_market,
            ],
            trustSummary: [
                'reviewer_status' => 'runtime_publish_projection',
                'reviewed_at' => null,
                'content_version' => 'runtime_publish_projection',
                'data_version' => 'runtime_publish_projection',
                'logic_version' => 'career.protocol.job_detail.runtime_projection.v1',
                'editorial_patch_required' => false,
                'editorial_patch_status' => null,
                'allow_strong_claim' => true,
                'allow_salary_comparison' => false,
                'allow_ai_strategy' => false,
                'reason_codes' => array_values(array_unique(array_filter(array_merge(
                    ['runtime_publish_projection', 'career_job_index_runtime_projection_fallback'],
                    is_array($projectionItem['reason_codes'] ?? null) ? $projectionItem['reason_codes'] : [],
                ), static fn (mixed $reason): bool => is_scalar($reason) && trim((string) $reason) !== ''))),
            ],
            scoreSummary: [],
            seoContract: $this->buildRuntimeProjectionSeoContract($occupation, $projectionItem),
            provenanceMeta: [
                'content_version' => 'runtime_publish_projection',
                'data_version' => 'runtime_publish_projection',
                'logic_version' => 'career.protocol.job_detail.runtime_projection.v1',
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
    private function buildDisplayAssetBackedDirectoryDraftSeoContract(Occupation $occupation): array
    {
        $canonicalPath = '/career/jobs/'.(string) $occupation->canonical_slug;
        $indexEligible = $this->runtimeProjectionVisibilityAllowsIndexing((string) $occupation->canonical_slug);
        $indexState = $indexEligible ? IndexStateValue::INDEXABLE : IndexStateValue::TRUST_LIMITED;
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_job_list_item_display_asset_backed_bundle',
            'canonical_url' => $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => $occupation->canonical_title_en,
            'description' => $occupation->canonical_title_en,
            'indexability_state' => $indexState,
            'sitemap_state' => $indexEligible ? 'included' : 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => $canonicalPath,
            'index_state' => $indexState,
            'index_eligible' => $indexEligible,
            'reason_codes' => $indexEligible
                ? ['validated_display_asset_backed_release', 'runtime_publish_projection']
                : ['display_asset_backed_pilot_noindex'],
            'metadata_contract_version' => $surface['metadata_contract_version'] ?? $surface['version'] ?? null,
            'surface_type' => $surface['surface_type'] ?? null,
            'robots_policy' => $surface['robots_policy'] ?? $robotsPolicy,
            'metadata_fingerprint' => $surface['metadata_fingerprint'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $projectionItem
     * @return array<string, mixed>
     */
    private function buildRuntimeProjectionSeoContract(Occupation $occupation, array $projectionItem): array
    {
        $canonicalPath = is_string($projectionItem['canonical_path'] ?? null)
            ? $projectionItem['canonical_path']
            : '/career/jobs/'.(string) $occupation->canonical_slug;
        $canonicalTarget = is_string($projectionItem['canonical_target'] ?? null)
            ? $projectionItem['canonical_target']
            : null;
        $indexEligible = ($projectionItem['robots_indexable'] ?? false) === true;
        $publicIndexState = $indexEligible ? IndexStateValue::INDEXABLE : IndexStateValue::NOINDEX;
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_job_list_item_runtime_projection_bundle',
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
            'reason_codes' => array_values(array_unique(array_filter(array_merge(
                ['runtime_publish_projection', 'career_job_index_runtime_projection_fallback'],
                is_array($projectionItem['reason_codes'] ?? null) ? $projectionItem['reason_codes'] : [],
            ), static fn (mixed $reason): bool => is_scalar($reason) && trim((string) $reason) !== ''))),
            'metadata_contract_version' => $surface['metadata_contract_version'] ?? $surface['version'] ?? null,
            'surface_type' => $surface['surface_type'] ?? null,
            'robots_policy' => $surface['robots_policy'] ?? $robotsPolicy,
            'metadata_fingerprint' => $surface['metadata_fingerprint'] ?? null,
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

    private function directoryDraftHasDisplayAssetBackedDetail(Occupation $occupation): bool
    {
        $slug = strtolower(trim((string) $occupation->canonical_slug));
        if ($slug === '' || in_array($slug, self::DISPLAY_ASSET_BACKED_MANUAL_HOLD_SLUGS, true)) {
            return false;
        }

        if (! $this->runtimeProjectionVisibilityAllowsIndexing($slug)) {
            return false;
        }

        if (! $this->hasDisplayAssetBackedAuthority($occupation)) {
            return false;
        }

        if (! $this->validDisplayAssetBackedAsset($occupation, $slug) instanceof CareerJobDisplayAsset) {
            return false;
        }

        return $this->displaySurfaceBuilder->buildForOccupation($occupation, 'en') !== null;
    }

    private function runtimeProjectionVisibilityAllowsIndexing(string $slug): bool
    {
        return $this->runtimePublishProjection->detailRouteEnabled($slug)
            && $this->runtimePublishProjection->robotsIndexable($slug)
            && $this->runtimePublishProjection->releaseGatePass($slug);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runtimePublishedDirectoryDraftProjectionItem(Occupation $occupation): ?array
    {
        $slug = strtolower(trim((string) $occupation->canonical_slug));
        if ($slug === '' || in_array($slug, self::DISPLAY_ASSET_BACKED_MANUAL_HOLD_SLUGS, true)) {
            return null;
        }

        $item = $this->runtimePublishProjection->itemForSlug($slug, 'en');
        if (! is_array($item)) {
            return null;
        }

        $state = (string) (
            $item['runtime_publish_state']
            ?? $item['runtime_state']
            ?? $item['projection_state']
            ?? $item['state']
            ?? ''
        );

        if ($state !== 'published') {
            return null;
        }

        if (($item['detail_route_enabled'] ?? false) !== true
            || ($item['robots_indexable'] ?? false) !== true
            || ($item['release_gate_pass'] ?? false) !== true) {
            return null;
        }

        return array_merge($item, [
            'slug' => $slug,
            'canonical_path' => is_string($item['canonical_path'] ?? null)
                ? $item['canonical_path']
                : '/career/jobs/'.$slug,
            'reason_codes' => array_values(array_unique(array_filter(array_merge(
                ['runtime_publish_projection', 'runtime_published_navigation_shell'],
                is_array($item['reason_codes'] ?? null) ? $item['reason_codes'] : [],
            ), static fn (mixed $reason): bool => is_scalar($reason) && trim((string) $reason) !== ''))),
        ]);
    }

    private function hasDisplayAssetBackedAuthority(Occupation $occupation): bool
    {
        $occupation->loadMissing('crosswalks');

        $requiredSystems = ['us_soc', 'onet_soc_2019'];
        foreach ($requiredSystems as $sourceSystem) {
            $rows = $occupation->crosswalks
                ->filter(static fn (OccupationCrosswalk $crosswalk): bool => strtolower((string) $crosswalk->source_system) === $sourceSystem)
                ->values();

            if ($rows->count() !== 1) {
                return false;
            }

            $sourceCode = trim((string) $rows->first()?->source_code);
            if ($sourceCode === '') {
                return false;
            }
        }

        return true;
    }

    private function validDisplayAssetBackedAsset(Occupation $occupation, string $subjectSlug): ?CareerJobDisplayAsset
    {
        $assets = $occupation->relationLoaded('displayAssets')
            ? $occupation->displayAssets
                ->where('canonical_slug', $subjectSlug)
                ->where('surface_version', self::DISPLAY_SURFACE_VERSION)
                ->where('asset_version', self::DISPLAY_ASSET_VERSION)
                ->where('template_version', self::DISPLAY_ASSET_VERSION)
                ->where('status', self::DISPLAY_READY_STATUS)
                ->where('asset_type', self::DISPLAY_ASSET_TYPE)
                ->values()
            : CareerJobDisplayAsset::query()
                ->where('occupation_id', $occupation->id)
                ->where('canonical_slug', $subjectSlug)
                ->where('surface_version', self::DISPLAY_SURFACE_VERSION)
                ->where('asset_version', self::DISPLAY_ASSET_VERSION)
                ->where('template_version', self::DISPLAY_ASSET_VERSION)
                ->where('status', self::DISPLAY_READY_STATUS)
                ->where('asset_type', self::DISPLAY_ASSET_TYPE)
                ->get();

        if ($assets->count() !== 1) {
            return null;
        }

        $asset = $assets->first();
        if (! $asset instanceof CareerJobDisplayAsset) {
            return null;
        }

        $componentOrder = is_array($asset->component_order_json) ? array_values($asset->component_order_json) : [];
        if (count($componentOrder) !== self::DISPLAY_COMPONENT_ORDER_COUNT) {
            return null;
        }

        $pages = is_array($asset->page_payload_json) ? $asset->page_payload_json : [];
        $localizedPages = is_array($pages['page'] ?? null) ? $pages['page'] : $pages;
        if (! is_array($localizedPages['zh'] ?? null) || ! is_array($localizedPages['en'] ?? null)) {
            return null;
        }

        return $asset;
    }
}
