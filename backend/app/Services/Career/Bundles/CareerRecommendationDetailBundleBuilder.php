<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerRecommendationDetailBundle;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Services\Career\Scoring\CareerWhiteBoxScorePayloadBuilder;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Collection;

final class CareerRecommendationDetailBundleBuilder
{
    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
        private readonly CareerWhiteBoxScorePayloadBuilder $whiteBoxScorePayloadBuilder,
    ) {}

    public function buildByType(string $type): ?CareerRecommendationDetailBundle
    {
        $requestedType = trim($type);
        $normalizedType = strtoupper($requestedType);
        if ($normalizedType === '') {
            return null;
        }

        $canonicalType = strtoupper(strtok($normalizedType, '-') ?: $normalizedType);

        $snapshots = $this->matchingSnapshots($normalizedType, $canonicalType);
        /** @var RecommendationSnapshot|null $snapshot */
        $snapshot = $snapshots->first();

        if (! $snapshot instanceof RecommendationSnapshot || ! $snapshot->occupation) {
            return null;
        }

        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $projectionPayload = is_array($snapshot->profileProjection?->projection_payload) ? $snapshot->profileProjection->projection_payload : [];
        $subjectMeta = $this->normalizeArray($projectionPayload['recommendation_subject_meta'] ?? []);
        if ($subjectMeta === []) {
            return null;
        }

        $truthMetric = $snapshot->truthMetric;
        $sourceTrace = $truthMetric?->sourceTrace;
        $trustManifest = $snapshot->trustManifest;
        $indexState = $snapshot->indexState;
        $importRunId = $truthMetric?->import_run_id
            ?? $trustManifest?->import_run_id
            ?? $indexState?->import_run_id;

        $scoreBundle = $this->normalizeArray($payload['score_bundle'] ?? []);
        $warnings = $this->normalizeArray($payload['warnings'] ?? []);

        return new CareerRecommendationDetailBundle(
            identity: [
                'occupation_uuid' => $snapshot->occupation->id,
                'canonical_slug' => $snapshot->occupation->canonical_slug,
                'entity_level' => $snapshot->occupation->entity_level,
                'family_uuid' => $snapshot->occupation->family_id,
                'canonical_title_en' => $snapshot->occupation->canonical_title_en,
                'canonical_title_zh' => $snapshot->occupation->canonical_title_zh,
            ],
            recommendationSubjectMeta: $subjectMeta,
            supportingTruthSummary: [
                'truth_market' => $truthMetric?->truth_market ?? $snapshot->occupation->truth_market,
                'display_market' => $snapshot->occupation->display_market,
                'median_pay_usd_annual' => $truthMetric?->median_pay_usd_annual,
                'outlook_pct_2024_2034' => $truthMetric?->outlook_pct_2024_2034,
                'outlook_description' => $truthMetric?->outlook_description,
                'ai_exposure' => $truthMetric?->ai_exposure,
                'source_trace' => is_string($sourceTrace?->id) ? [
                    'source_trace_id' => $sourceTrace->id,
                    'source_id' => $sourceTrace->source_id,
                    'title' => $sourceTrace->title,
                    'url' => $sourceTrace->url,
                    'evidence_strength' => $sourceTrace->evidence_strength,
                ] : null,
            ],
            scoreBundle: $scoreBundle,
            whiteBoxScores: $this->whiteBoxScorePayloadBuilder->build($scoreBundle, $warnings),
            warnings: $warnings,
            claimPermissions: $this->normalizeArray($payload['claim_permissions'] ?? []),
            integritySummary: $this->normalizeArray($payload['integrity_summary'] ?? []),
            trustManifest: [
                'content_version' => $trustManifest?->content_version,
                'data_version' => $trustManifest?->data_version,
                'logic_version' => $trustManifest?->logic_version,
                'reviewer_status' => $trustManifest?->reviewer_status,
                'reviewed_at' => optional($trustManifest?->reviewed_at)->toISOString(),
                'quality' => is_array($trustManifest?->quality) ? $trustManifest->quality : [],
                'locale_context' => is_array($trustManifest?->locale_context) ? $trustManifest->locale_context : [],
                'methodology' => is_array($trustManifest?->methodology) ? $trustManifest->methodology : [],
            ],
            matchedJobs: $this->buildMatchedJobs($snapshots),
            seoContract: $this->buildSeoContract($snapshot, $subjectMeta, $requestedType),
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
                'profile_projection_id' => $snapshot->profile_projection_id,
                'context_snapshot_id' => $snapshot->context_snapshot_id,
                'compile_refs' => $this->normalizeArray($payload['compile_refs'] ?? []),
            ],
        );
    }

    /**
     * @return Collection<int, RecommendationSnapshot>
     */
    private function matchingSnapshots(string $normalizedType, string $canonicalType): Collection
    {
        /** @var Collection<int, RecommendationSnapshot> $snapshots */
        $snapshots = RecommendationSnapshot::query()
            ->with([
                'occupation.family',
                'truthMetric.sourceTrace',
                'trustManifest',
                'indexState',
                'profileProjection',
                'contextSnapshot',
                'compileRun',
            ])
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', function ($query) use ($normalizedType, $canonicalType): void {
                $query->where(function ($inner) use ($normalizedType, $canonicalType): void {
                    $inner->where('projection_payload->recommendation_subject_meta->type_code', $normalizedType)
                        ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $normalizedType)
                        ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $canonicalType);
                });
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->get();

        return $snapshots;
    }

    /**
     * @param  Collection<int, RecommendationSnapshot>  $snapshots
     * @return list<array<string, mixed>>
     */
    private function buildMatchedJobs(Collection $snapshots): array
    {
        $items = $snapshots
            ->groupBy('occupation_id')
            ->map(function (Collection $group): ?RecommendationSnapshot {
                /** @var RecommendationSnapshot|null $selected */
                $selected = $group
                    ->sort(function (RecommendationSnapshot $left, RecommendationSnapshot $right): int {
                        return $this->compareSnapshots($left, $right);
                    })
                    ->first();

                return $selected;
            })
            ->filter(static fn (mixed $snapshot): bool => $snapshot instanceof RecommendationSnapshot)
            ->map(function (RecommendationSnapshot $snapshot): ?array {
                $occupation = $snapshot->occupation;
                if (! $occupation instanceof Occupation || $snapshot->indexState === null || $snapshot->trustManifest === null) {
                    return null;
                }

                return [
                    'occupation_uuid' => $occupation->id,
                    'canonical_slug' => $occupation->canonical_slug,
                    'title' => $occupation->canonical_title_en,
                    'seo_contract' => $this->buildMatchedJobSeoContract($occupation, $snapshot),
                    'trust_summary' => [
                        'reviewer_status' => $snapshot->trustManifest?->reviewer_status,
                    ],
                ];
            })
            ->filter()
            ->sortBy(static fn (array $item): array => [
                strtolower((string) ($item['title'] ?? '')),
                strtolower((string) ($item['canonical_slug'] ?? '')),
            ])
            ->values();

        /** @var list<array<string, mixed>> $matchedJobs */
        $matchedJobs = $items->all();

        return $matchedJobs;
    }

    private function compareSnapshots(RecommendationSnapshot $left, RecommendationSnapshot $right): int
    {
        $leftCompiled = optional($left->compiled_at)?->getTimestamp() ?? 0;
        $rightCompiled = optional($right->compiled_at)?->getTimestamp() ?? 0;
        if ($leftCompiled !== $rightCompiled) {
            return $rightCompiled <=> $leftCompiled;
        }

        return strcmp((string) $left->id, (string) $right->id);
    }

    /**
     * @param  array<string, mixed>  $subjectMeta
     * @return array<string, mixed>
     */
    private function buildSeoContract(RecommendationSnapshot $snapshot, array $subjectMeta, string $requestedType): array
    {
        $indexState = $snapshot->indexState;
        $routeSubject = $this->routeSubject($subjectMeta, $requestedType);
        $canonicalPath = '/career/recommendations/mbti/'.$routeSubject;
        $indexEligible = (bool) ($indexState?->index_eligible ?? false);
        $publicIndexState = IndexStateValue::publicFacing((string) ($indexState?->index_state ?? ''), $indexEligible);
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_recommendation_detail_bundle',
            'canonical_url' => $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => (string) ($subjectMeta['display_title'] ?? $subjectMeta['type_code'] ?? $snapshot->occupation?->canonical_title_en ?? ''),
            'description' => (string) ($snapshot->occupation?->canonical_title_en ?? ''),
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
    private function buildMatchedJobSeoContract(Occupation $occupation, RecommendationSnapshot $snapshot): array
    {
        $indexState = $snapshot->indexState;
        $canonicalPath = is_string($indexState?->canonical_path) ? $indexState->canonical_path : '/career/jobs/'.$occupation->canonical_slug;
        $indexEligible = (bool) ($indexState?->index_eligible ?? false);
        $publicIndexState = IndexStateValue::publicFacing((string) ($indexState?->index_state ?? ''), $indexEligible);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => $indexState?->canonical_target,
            'index_state' => $publicIndexState,
            'index_eligible' => $indexEligible,
            'reason_codes' => is_array($indexState?->reason_codes) ? $indexState->reason_codes : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $subjectMeta
     */
    private function routeSubject(array $subjectMeta, string $requestedType): string
    {
        $candidates = [
            $requestedType,
            is_scalar($subjectMeta['public_route_slug'] ?? null) ? (string) $subjectMeta['public_route_slug'] : null,
            is_scalar($subjectMeta['route_type'] ?? null) ? (string) $subjectMeta['route_type'] : null,
            is_scalar($subjectMeta['canonical_type_code'] ?? null) ? (string) $subjectMeta['canonical_type_code'] : null,
            is_scalar($subjectMeta['type_code'] ?? null) ? (string) $subjectMeta['type_code'] : null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = strtolower(trim($candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'unknown';
    }
}
