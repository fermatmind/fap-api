<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerJobDetailBundle;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Services\Career\Scoring\CareerWhiteBoxScorePayloadBuilder;
use App\Services\PublicSurface\SeoSurfaceContractService;

final class CareerJobDetailBundleBuilder
{
    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
        private readonly CareerWhiteBoxScorePayloadBuilder $whiteBoxScorePayloadBuilder,
    ) {}

    public function buildBySlug(string $slug): ?CareerJobDetailBundle
    {
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $occupation = Occupation::query()
            ->with([
                'family',
                'aliases',
                'crosswalks',
                'editorialPatches' => fn ($query) => $query->orderByDesc('updated_at')->orderByDesc('created_at'),
            ])
            ->where('canonical_slug', $normalizedSlug)
            ->first();

        if (! $occupation instanceof Occupation) {
            return null;
        }

        $snapshot = RecommendationSnapshot::query()
            ->with([
                'profileProjection',
                'contextSnapshot',
                'truthMetric.sourceTrace',
                'trustManifest',
                'indexState',
                'compileRun',
            ])
            ->where('occupation_id', $occupation->id)
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $snapshot instanceof RecommendationSnapshot) {
            return null;
        }

        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $truthMetric = $snapshot->truthMetric;
        $sourceTrace = $truthMetric?->sourceTrace;
        $trustManifest = $snapshot->trustManifest;
        $indexState = $snapshot->indexState;
        $editorialPatch = $occupation->editorialPatches->first();
        $importRunId = $truthMetric?->import_run_id
            ?? $trustManifest?->import_run_id
            ?? $indexState?->import_run_id;

        $scoreBundle = $this->normalizeArray($payload['score_bundle'] ?? []);
        $warnings = $this->normalizeArray($payload['warnings'] ?? []);

        return new CareerJobDetailBundle(
            identity: [
                'occupation_uuid' => $occupation->id,
                'canonical_slug' => $occupation->canonical_slug,
                'entity_level' => $occupation->entity_level,
                'family_uuid' => $occupation->family_id,
                'parent_uuid' => $occupation->parent_id,
            ],
            localePolicy: [
                'truth_market' => $occupation->truth_market,
                'display_market' => $occupation->display_market,
                'crosswalk_mode' => $occupation->crosswalk_mode,
                'locale_warning' => $occupation->truth_market !== $occupation->display_market
                    ? 'cross_market_display'
                    : null,
                'truth_notice_required' => $occupation->truth_market !== $occupation->display_market,
            ],
            titles: [
                'canonical_en' => $occupation->canonical_title_en,
                'canonical_zh' => $occupation->canonical_title_zh,
                'search_h1_zh' => $occupation->search_h1_zh,
                'short_title_en' => $occupation->canonical_title_en,
                'short_title_zh' => $occupation->canonical_title_zh,
            ],
            aliasIndex: $occupation->aliases
                ->sortBy([
                    ['lang', 'asc'],
                    ['register', 'asc'],
                    ['alias', 'asc'],
                ])
                ->values()
                ->map(static fn ($alias): array => [
                    'alias' => $alias->alias,
                    'normalized' => $alias->normalized,
                    'lang' => $alias->lang,
                    'register' => $alias->register,
                    'intent_scope' => $alias->intent_scope,
                    'target_kind' => $alias->target_kind,
                    'target_uuid' => $alias->occupation_id ?? $alias->family_id,
                    'precision' => $alias->precision_score,
                    'confidence' => $alias->confidence_score,
                ])
                ->all(),
            ontology: [
                'task_prototype_signature' => is_array($occupation->task_prototype_signature) ? $occupation->task_prototype_signature : [],
                'structural_stability' => $occupation->structural_stability,
                'market_semantics_gap' => $occupation->market_semantics_gap,
                'regulatory_divergence' => $occupation->regulatory_divergence,
                'toolchain_divergence' => $occupation->toolchain_divergence,
                'skill_gap_threshold' => $occupation->skill_gap_threshold,
                'trust_inheritance_scope' => is_array($occupation->trust_inheritance_scope) ? $occupation->trust_inheritance_scope : [],
                'crosswalks' => $occupation->crosswalks
                    ->map(static fn ($crosswalk): array => [
                        'source_system' => $crosswalk->source_system,
                        'source_code' => $crosswalk->source_code,
                        'source_title' => $crosswalk->source_title,
                        'mapping_type' => $crosswalk->mapping_type,
                        'confidence_score' => $crosswalk->confidence_score,
                    ])
                    ->all(),
            ],
            truthLayer: [
                'source_refs' => array_values(array_filter([
                    is_string($sourceTrace?->id) ? [
                        'source_trace_id' => $sourceTrace->id,
                        'source_id' => $sourceTrace->source_id,
                        'source_type' => $sourceTrace->source_type,
                        'title' => $sourceTrace->title,
                        'url' => $sourceTrace->url,
                        'fields_used' => is_array($sourceTrace->fields_used) ? $sourceTrace->fields_used : [],
                        'retrieved_at' => optional($sourceTrace->retrieved_at)?->toISOString(),
                        'evidence_strength' => $sourceTrace->evidence_strength,
                    ] : null,
                ])),
                'median_pay_usd_annual' => $truthMetric?->median_pay_usd_annual,
                'jobs_2024' => $truthMetric?->jobs_2024,
                'projected_jobs_2034' => $truthMetric?->projected_jobs_2034,
                'employment_change' => $truthMetric?->employment_change,
                'outlook_pct_2024_2034' => $truthMetric?->outlook_pct_2024_2034,
                'outlook_description' => $truthMetric?->outlook_description,
                'entry_education' => $truthMetric?->entry_education,
                'work_experience' => $truthMetric?->work_experience,
                'on_the_job_training' => $truthMetric?->on_the_job_training,
                'ai_exposure' => $truthMetric?->ai_exposure,
                'ai_rationale' => $truthMetric?->ai_rationale,
                'truth_market' => $truthMetric?->truth_market,
                'truth_last_reviewed_at' => optional($truthMetric?->reviewed_at)->toISOString(),
            ],
            trustManifest: [
                'content_version' => $trustManifest?->content_version,
                'data_version' => $trustManifest?->data_version,
                'logic_version' => $trustManifest?->logic_version,
                'locale_context' => is_array($trustManifest?->locale_context) ? $trustManifest->locale_context : [],
                'methodology' => is_array($trustManifest?->methodology) ? $trustManifest->methodology : [],
                'reviewer_status' => $trustManifest?->reviewer_status,
                'reviewed_at' => optional($trustManifest?->reviewed_at)->toISOString(),
                'ai_assistance' => is_array($trustManifest?->ai_assistance) ? $trustManifest->ai_assistance : [],
                'quality' => is_array($trustManifest?->quality) ? $trustManifest->quality : [],
                'last_substantive_update_at' => optional($trustManifest?->last_substantive_update_at)->toISOString(),
                'next_review_due_at' => optional($trustManifest?->next_review_due_at)->toISOString(),
                'editorial_patch' => [
                    'required' => (bool) ($editorialPatch?->required ?? false),
                    'status' => $editorialPatch?->status,
                    'notes' => is_array($editorialPatch?->notes) ? $editorialPatch->notes : [],
                ],
            ],
            scoreBundle: $scoreBundle,
            whiteBoxScores: $this->whiteBoxScorePayloadBuilder->build($scoreBundle, $warnings),
            warnings: $warnings,
            claimPermissions: $this->normalizeArray($payload['claim_permissions'] ?? []),
            integritySummary: $this->normalizeArray($payload['integrity_summary'] ?? []),
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
                'source_trace_id' => $truthMetric?->source_trace_id,
                'compile_refs' => $this->normalizeArray($payload['compile_refs'] ?? []),
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
            'surface_type' => 'career_job_detail_bundle',
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
    private function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
