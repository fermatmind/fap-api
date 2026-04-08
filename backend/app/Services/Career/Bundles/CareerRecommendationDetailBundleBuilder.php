<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\DTO\Career\CareerRecommendationDetailBundle;
use App\Models\RecommendationSnapshot;
use App\Services\PublicSurface\SeoSurfaceContractService;

final class CareerRecommendationDetailBundleBuilder
{
    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
    ) {}

    public function buildByType(string $type): ?CareerRecommendationDetailBundle
    {
        $normalizedType = strtoupper(trim($type));
        if ($normalizedType === '') {
            return null;
        }

        $canonicalType = strtoupper(strtok($normalizedType, '-') ?: $normalizedType);

        $snapshot = RecommendationSnapshot::query()
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
            ->whereHas('profileProjection', function ($query) use ($normalizedType, $canonicalType): void {
                $query->where(function ($inner) use ($normalizedType, $canonicalType): void {
                    $inner->where('projection_payload->recommendation_subject_meta->type_code', $normalizedType)
                        ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $normalizedType)
                        ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $canonicalType);
                });
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->first();

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
            scoreBundle: $this->normalizeArray($payload['score_bundle'] ?? []),
            warnings: $this->normalizeArray($payload['warnings'] ?? []),
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
                'profile_projection_id' => $snapshot->profile_projection_id,
                'context_snapshot_id' => $snapshot->context_snapshot_id,
                'compile_refs' => $this->normalizeArray($payload['compile_refs'] ?? []),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $subjectMeta
     * @return array<string, mixed>
     */
    private function buildSeoContract(RecommendationSnapshot $snapshot, array $subjectMeta): array
    {
        $indexState = $snapshot->indexState;
        $typeCode = strtolower((string) ($subjectMeta['type_code'] ?? $subjectMeta['canonical_type_code'] ?? ''));
        $canonicalPath = is_string($indexState?->canonical_path) && $typeCode !== ''
            ? '/career/recommendations/mbti/'.$typeCode
            : '/career/recommendations/mbti/'.$typeCode;
        $indexEligible = (bool) ($indexState?->index_eligible ?? false);
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_recommendation_detail_bundle',
            'canonical_url' => $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => (string) ($subjectMeta['display_title'] ?? $subjectMeta['type_code'] ?? $snapshot->occupation?->canonical_title_en ?? ''),
            'description' => (string) ($snapshot->occupation?->canonical_title_en ?? ''),
            'indexability_state' => $indexEligible ? 'indexable' : ((string) ($indexState?->index_state ?? 'noindex')),
            'sitemap_state' => $indexEligible ? 'included' : 'excluded',
        ]);

        return [
            'canonical_path' => $canonicalPath,
            'canonical_target' => $indexState?->canonical_target,
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
    private function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
