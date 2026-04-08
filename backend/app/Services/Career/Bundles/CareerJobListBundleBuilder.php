<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\DTO\Career\CareerJobListItemBundle;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Collection;

final class CareerJobListBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

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

        /** @var Collection<int, CareerJobListItemBundle> $items */
        $items = $items->sortBy(static fn (CareerJobListItemBundle $item): array => [
            strtolower((string) ($item->titles['canonical_en'] ?? '')),
            strtolower((string) ($item->identity['canonical_slug'] ?? '')),
        ])->values();

        return $items->all();
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
            'surface_type' => 'career_job_list_item_bundle',
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
}
