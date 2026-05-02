<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerRecommendationIndexItemBundle;
use App\Models\RecommendationSnapshot;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Collection;

final class CareerRecommendationIndexBundleBuilder
{
    private const SAFE_CROSSWALK_MODES = ['exact', 'trust_inheritance', 'direct_match'];

    private const MAX_PUBLIC_RECOMMENDATION_ROWS = 512;

    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    /**
     * @return list<CareerRecommendationIndexItemBundle>
     */
    public function build(bool $includeNonIndexable = false): array
    {
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
            ->whereHas('occupation', static function ($query): void {
                $query->whereIn('crosswalk_mode', self::SAFE_CROSSWALK_MODES);
            })
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave')
                    ->whereNotNull('projection_payload->recommendation_subject_meta');
            })
            ->whereHas('compileRun', static function ($query): void {
                $query->where('status', 'completed')
                    ->where('dry_run', false);
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PUBLIC_RECOMMENDATION_ROWS)
            ->get();

        $grouped = $snapshots
            ->map(function (RecommendationSnapshot $snapshot): ?array {
                $subjectMeta = $this->subjectMeta($snapshot);
                $routeSubject = $this->routeSubject($subjectMeta);

                if ($routeSubject === null) {
                    return null;
                }

                return [
                    'route_subject' => $routeSubject,
                    'snapshot' => $snapshot,
                ];
            })
            ->filter()
            ->groupBy('route_subject');

        $items = $grouped
            ->map(function (Collection $group) use ($includeNonIndexable): ?CareerRecommendationIndexItemBundle {
                $selected = $group
                    ->sort(function (array $left, array $right): int {
                        /** @var RecommendationSnapshot $leftSnapshot */
                        $leftSnapshot = $left['snapshot'];
                        /** @var RecommendationSnapshot $rightSnapshot */
                        $rightSnapshot = $right['snapshot'];

                        return $this->compareSnapshots($leftSnapshot, $rightSnapshot);
                    })
                    ->first();

                if (! is_array($selected)) {
                    return null;
                }

                /** @var RecommendationSnapshot $snapshot */
                $snapshot = $selected['snapshot'];
                $indexState = $snapshot->indexState;
                if (! $includeNonIndexable && ! (bool) ($indexState?->index_eligible ?? false)) {
                    return null;
                }

                return $this->buildItem($snapshot);
            })
            ->filter()
            ->values();

        /** @var Collection<int, CareerRecommendationIndexItemBundle> $items */
        $items = $items->sortBy(static fn (CareerRecommendationIndexItemBundle $item): array => [
            strtolower((string) ($item->recommendationSubjectMeta['public_route_slug'] ?? '')),
            strtolower((string) ($item->recommendationSubjectMeta['type_code'] ?? '')),
        ])->values();

        return $items->all();
    }

    private function buildItem(RecommendationSnapshot $snapshot): CareerRecommendationIndexItemBundle
    {
        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $subjectMeta = $this->subjectMeta($snapshot);
        $trustManifest = $snapshot->trustManifest;
        $indexState = $snapshot->indexState;
        $importRunId = $trustManifest?->import_run_id
            ?? $indexState?->import_run_id;
        $claimPermissions = is_array($payload['claim_permissions'] ?? null) ? $payload['claim_permissions'] : [];

        return new CareerRecommendationIndexItemBundle(
            recommendationSubjectMeta: $subjectMeta,
            scoreSummary: [
                'fit_score' => $this->compactScore($payload['score_bundle']['fit_score'] ?? null),
                'confidence_score' => $this->compactScore($payload['score_bundle']['confidence_score'] ?? null),
            ],
            trustSummary: [
                'reviewer_status' => $trustManifest?->reviewer_status,
                'reviewed_at' => optional($trustManifest?->reviewed_at)->toISOString(),
                'content_version' => $trustManifest?->content_version,
                'data_version' => $trustManifest?->data_version,
                'logic_version' => $trustManifest?->logic_version,
                'allow_strong_claim' => (bool) ($claimPermissions['allow_strong_claim'] ?? false),
                'allow_salary_comparison' => (bool) ($claimPermissions['allow_salary_comparison'] ?? false),
                'allow_ai_strategy' => (bool) ($claimPermissions['allow_ai_strategy'] ?? false),
                'reason_codes' => is_array($claimPermissions['reason_codes'] ?? null) ? $claimPermissions['reason_codes'] : [],
            ],
            seoContract: $this->buildSeoContract($snapshot, $subjectMeta),
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

    private function compareSnapshots(RecommendationSnapshot $left, RecommendationSnapshot $right): int
    {
        $leftEligible = (bool) ($left->indexState?->index_eligible ?? false);
        $rightEligible = (bool) ($right->indexState?->index_eligible ?? false);
        if ($leftEligible !== $rightEligible) {
            return $leftEligible ? -1 : 1;
        }

        $leftCompiled = optional($left->compiled_at)?->getTimestamp() ?? 0;
        $rightCompiled = optional($right->compiled_at)?->getTimestamp() ?? 0;
        if ($leftCompiled !== $rightCompiled) {
            return $rightCompiled <=> $leftCompiled;
        }

        $leftSlug = strtolower((string) ($left->occupation?->canonical_slug ?? ''));
        $rightSlug = strtolower((string) ($right->occupation?->canonical_slug ?? ''));
        if ($leftSlug !== $rightSlug) {
            return $leftSlug <=> $rightSlug;
        }

        return strcmp((string) $left->id, (string) $right->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSeoContract(RecommendationSnapshot $snapshot, array $subjectMeta): array
    {
        $indexState = $snapshot->indexState;
        $routeSubject = $this->routeSubject($subjectMeta) ?? 'unknown';
        $canonicalPath = '/career/recommendations/mbti/'.$routeSubject;
        $indexEligible = (bool) ($indexState?->index_eligible ?? false);
        $publicIndexState = IndexStateValue::publicFacing((string) ($indexState?->index_state ?? ''), $indexEligible);
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_recommendation_index_item_bundle',
            'canonical_url' => $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => (string) ($subjectMeta['display_title'] ?? $subjectMeta['type_code'] ?? $routeSubject),
            'description' => (string) ($subjectMeta['display_title'] ?? $routeSubject),
            'indexability_state' => $publicIndexState,
            'sitemap_state' => $indexEligible ? 'included' : 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => $indexState?->canonical_target,
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
    private function subjectMeta(RecommendationSnapshot $snapshot): array
    {
        $projectionPayload = is_array($snapshot->profileProjection?->projection_payload) ? $snapshot->profileProjection->projection_payload : [];
        $subjectMeta = is_array($projectionPayload['recommendation_subject_meta'] ?? null)
            ? $projectionPayload['recommendation_subject_meta']
            : [];

        $routeSubject = $this->routeSubject($subjectMeta);

        return [
            'type_code' => $subjectMeta['type_code'] ?? null,
            'canonical_type_code' => $subjectMeta['canonical_type_code'] ?? null,
            'display_title' => $subjectMeta['display_title'] ?? $subjectMeta['type_code'] ?? null,
            'public_route_slug' => $routeSubject,
        ];
    }

    private function routeSubject(array $subjectMeta): ?string
    {
        $candidates = [
            $subjectMeta['public_route_slug'] ?? null,
            $subjectMeta['route_type'] ?? null,
            $subjectMeta['canonical_type_code'] ?? null,
            $subjectMeta['type_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $normalized = strtolower(trim((string) $candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
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
