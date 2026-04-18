<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\Domain\Career\Feedback\CareerFeedbackTimelineAuthorityService;
use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Publish\CareerLifecycleOperationalSummaryService;
use App\DTO\Career\CareerJobDetailBundle;
use App\Models\CareerJob;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Models\Scopes\TenantScope;
use App\Services\Analytics\CareerConversionClosureBuilder;
use App\Services\Career\Scoring\CareerWhiteBoxScorePayloadBuilder;
use App\Services\PublicSurface\SeoSurfaceContractService;
use Illuminate\Support\Arr;

final class CareerJobDetailBundleBuilder
{
    public function __construct(
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
        private readonly CareerWhiteBoxScorePayloadBuilder $whiteBoxScorePayloadBuilder,
        private readonly CareerFeedbackTimelineAuthorityService $feedbackTimelineAuthorityService,
        private readonly CareerLifecycleOperationalSummaryService $lifecycleOperationalSummaryService,
        private readonly CareerConversionClosureBuilder $conversionClosureBuilder,
    ) {}

    public function buildBySlug(string $slug): ?CareerJobDetailBundle
    {
        $normalizedSlug = strtolower(trim($slug));
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
            return $this->buildFromPublishedDocxCareerJob($normalizedSlug);
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
            return $this->buildFromPublishedDocxCareerJob($normalizedSlug);
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
        $subjectSlug = strtolower((string) $occupation->canonical_slug);
        $docxJob = $this->findPublishedDocxCareerJob($subjectSlug);
        $canonicalTitleZh = $occupation->canonical_title_zh ?: ($docxJob?->title ? (string) $docxJob->title : null);
        $searchH1Zh = $occupation->search_h1_zh ?: $canonicalTitleZh;
        $lifecycleOperational = $this->lifecycleOperationalSummaryService->buildForSlug($subjectSlug);
        $conversionClosure = $this->conversionClosureBuilder->buildForSubjectSlug($subjectSlug);

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
                'canonical_zh' => $canonicalTitleZh,
                'search_h1_zh' => $searchH1Zh,
                'short_title_en' => $occupation->canonical_title_en,
                'short_title_zh' => $canonicalTitleZh,
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
            contentSections: $docxJob instanceof CareerJob ? $this->contentSectionsFromCareerJob($docxJob) : [],
            contentBodyMd: $docxJob instanceof CareerJob ? $this->contentBodyMdFromCareerJob($docxJob) : null,
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
            lifecycleCompanion: $this->feedbackTimelineAuthorityService->buildCompanionForJobSnapshot($snapshot),
            lifecycleOperational: $lifecycleOperational,
            shortlistContract: [
                'enabled' => true,
                'subject_kind' => 'job_slug',
                'subject_slug' => $subjectSlug,
                'source_page_type' => 'career_job_detail',
                'state_endpoint' => '/api/v0.5/career/shortlist/state',
                'write_endpoint' => '/api/v0.5/career/shortlist',
            ],
            conversionClosure: $conversionClosure,
        );
    }

    private function buildFromPublishedDocxCareerJob(string $slug): ?CareerJobDetailBundle
    {
        $job = $this->findPublishedDocxCareerJob($slug);

        if (! $job instanceof CareerJob) {
            return null;
        }

        $salary = is_array($job->salary_json) ? $job->salary_json : [];
        $outlook = is_array($job->outlook_json) ? $job->outlook_json : [];
        $market = is_array($job->market_demand_json) ? $job->market_demand_json : [];
        $growth = is_array($job->growth_path_json) ? $job->growth_path_json : [];
        $sourceRefs = $this->sourceRefsFromMarketDemand($market);
        $scoreBundle = $this->docxScoreBundle($job);
        $warnings = [
            'red_flags' => [],
            'amber_flags' => ['docx_baseline_authority_without_compiled_snapshot'],
            'blocked_claims' => [],
        ];
        $canonicalPath = '/career/jobs/'.(string) $job->slug;
        $indexEligible = (bool) $job->is_indexable;
        $publicIndexState = $indexEligible ? 'index' : 'noindex';
        $robotsPolicy = $indexEligible ? 'index,follow' : 'noindex,follow';
        $surface = $this->seoSurfaceContractService->build([
            'metadata_scope' => 'career_protocol_bundle',
            'surface_type' => 'career_job_detail_bundle',
            'canonical_url' => $canonicalPath,
            'robots_policy' => $robotsPolicy,
            'title' => (string) $job->title,
            'description' => (string) ($job->excerpt ?? $job->title),
            'indexability_state' => $publicIndexState,
            'sitemap_state' => $indexEligible ? 'included' : 'excluded',
        ]);

        $subjectSlug = (string) $job->slug;

        return new CareerJobDetailBundle(
            identity: [
                'occupation_uuid' => 'career_job:'.$subjectSlug,
                'canonical_slug' => $subjectSlug,
                'entity_level' => 'career_job_detail',
                'family_uuid' => null,
                'parent_uuid' => null,
            ],
            localePolicy: [
                'truth_market' => 'US',
                'display_market' => 'zh-CN',
                'crosswalk_mode' => 'docx_baseline',
                'locale_warning' => 'localized_from_us_bls_source',
                'truth_notice_required' => true,
            ],
            titles: [
                'canonical_en' => $job->subtitle,
                'canonical_zh' => (string) $job->title,
                'search_h1_zh' => (string) $job->title,
                'short_title_en' => $job->subtitle,
                'short_title_zh' => (string) $job->title,
            ],
            aliasIndex: [],
            ontology: [
                'task_prototype_signature' => [],
                'structural_stability' => null,
                'market_semantics_gap' => null,
                'regulatory_divergence' => null,
                'toolchain_divergence' => null,
                'skill_gap_threshold' => null,
                'trust_inheritance_scope' => [],
                'crosswalks' => [],
            ],
            truthLayer: [
                'summary' => $job->excerpt,
                'source_refs' => array_values(array_filter(array_map(
                    static fn (array $source): string => (string) ($source['url'] ?? ''),
                    $sourceRefs,
                ))),
                'median_pay_usd_annual' => $salary['annual_median_usd'] ?? null,
                'jobs_2024' => $outlook['jobs_2024'] ?? null,
                'projected_jobs_2034' => $outlook['projected_jobs_2034'] ?? null,
                'employment_change' => $outlook['employment_change'] ?? null,
                'outlook_pct_2024_2034' => $outlook['outlook_pct_2024_2034'] ?? null,
                'outlook_description' => $outlook['outlook_raw'] ?? null,
                'entry_education' => $this->growthLine($growth, 0),
                'work_experience' => null,
                'on_the_job_training' => null,
                'ai_exposure' => $market['ai_exposure_score_10'] ?? null,
                'ai_rationale' => $market['ai_exposure_raw'] ?? null,
                'truth_market' => 'US',
                'truth_last_reviewed_at' => optional($job->updated_at)->toISOString(),
            ],
            contentSections: $this->contentSectionsFromCareerJob($job),
            contentBodyMd: $this->contentBodyMdFromCareerJob($job),
            trustManifest: [
                'manifest_version' => 'trust_manifest.v1',
                'entity_id' => $subjectSlug,
                'page_type' => 'career_job_detail',
                'page_slug' => $subjectSlug,
                'content_version' => 'docx_342_career_batch',
                'data_version' => 'docx_342_career_batch',
                'logic_version' => 'career.protocol.job_detail.docx_baseline.v1',
                'locale_context' => [
                    'truth_market' => 'US',
                    'display_market' => 'zh-CN',
                    'locale' => 'zh-CN',
                ],
                'source_trace' => array_map(static fn (array $source): array => [
                    'ref' => (string) ($source['label'] ?? 'source'),
                    'source' => (string) ($source['label'] ?? 'source'),
                    'url' => $source['url'] ?? null,
                    'last_reviewed_at' => null,
                ], $sourceRefs),
                'methodology' => [
                    'crosswalk_mode' => 'docx_baseline',
                    'derivation_policy' => 'cms_docx_baseline_authority',
                    'notes' => ['Generated from the 342 occupation DOCX baseline imported into backend CMS.'],
                ],
                'reviewer' => [
                    'reviewed' => true,
                    'reviewer_id' => null,
                    'reviewer_status' => 'approved',
                ],
                'reviewer_status' => 'approved',
                'reviewed_at' => optional($job->updated_at)->toISOString(),
                'ai_assistance' => [
                    'used' => true,
                    'summary' => 'DOCX baseline content was generated before import and preserved as backend CMS authority.',
                ],
                'quality' => [
                    'complete' => true,
                    'reviewed' => true,
                    'stale' => false,
                    'blocked_reasons' => [],
                ],
                'last_substantive_update_at' => optional($job->updated_at)->toISOString(),
                'next_review_due_at' => null,
            ],
            scoreBundle: $scoreBundle,
            whiteBoxScores: $this->whiteBoxScorePayloadBuilder->build($scoreBundle, $warnings),
            warnings: $warnings,
            claimPermissions: [
                'allow_strong_claim' => true,
                'allow_salary_comparison' => ($salary['annual_median_usd'] ?? null) !== null,
                'allow_ai_strategy' => ($market['ai_exposure_score_10'] ?? null) !== null,
                'allow_transition_recommendation' => false,
                'allow_cross_market_pay_copy' => false,
                'reason_codes' => ['docx_baseline_authority'],
            ],
            integritySummary: [
                'integrity_state' => 'docx_baseline',
                'critical_missing_fields' => [],
                'confidence_cap' => 70,
                'degradation_factor' => 0,
            ],
            seoContract: [
                'canonical_path' => $canonicalPath,
                'canonical_target' => $canonicalPath,
                'index_state' => $publicIndexState,
                'index_eligible' => $indexEligible,
                'reason_codes' => ['docx_baseline_authority'],
                'metadata_contract_version' => $surface['metadata_contract_version'] ?? $surface['version'] ?? null,
                'surface_type' => $surface['surface_type'] ?? null,
                'robots_policy' => $surface['robots_policy'] ?? $robotsPolicy,
                'metadata_fingerprint' => $surface['metadata_fingerprint'] ?? null,
            ],
            provenanceMeta: [
                'content_version' => 'docx_342_career_batch',
                'data_version' => 'docx_342_career_batch',
                'logic_version' => 'career.protocol.job_detail.docx_baseline.v1',
                'compiler_version' => null,
                'compiled_at' => optional($job->updated_at)->toISOString(),
                'truth_metric_id' => null,
                'trust_manifest_id' => null,
                'index_state_id' => null,
                'compile_run_id' => null,
                'import_run_id' => null,
                'source_trace_id' => null,
                'compile_refs' => [
                    'cms_job_id' => (int) $job->id,
                    'source_docx' => Arr::get($job->seoMeta?->jsonld_overrides_json, 'source_docx'),
                ],
            ],
            lifecycleCompanion: [],
            lifecycleOperational: [
                'member_kind' => 'career_job_detail',
                'canonical_slug' => $subjectSlug,
                'current_projection_uuid' => null,
                'current_recommendation_snapshot_uuid' => null,
                'timeline_entry_count' => 0,
                'latest_feedback_at' => null,
                'delta_available' => false,
                'lifecycle_state' => 'baseline_only',
                'closure_state' => 'baseline_only',
            ],
            shortlistContract: [
                'enabled' => true,
                'subject_kind' => 'job_slug',
                'subject_slug' => $subjectSlug,
                'source_page_type' => 'career_job_detail',
                'state_endpoint' => '/api/v0.5/career/shortlist/state',
                'write_endpoint' => '/api/v0.5/career/shortlist',
            ],
            conversionClosure: [
                'subject_slug' => $subjectSlug,
                'counts' => [],
                'readiness' => [],
            ],
        );
    }

    private function findPublishedDocxCareerJob(string $slug): ?CareerJob
    {
        $job = CareerJob::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['sections', 'seoMeta'])
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', $slug)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->first();

        if (! $job instanceof CareerJob || ! $this->isDocxCareerJob($job)) {
            return null;
        }

        return $job;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentSectionsFromCareerJob(CareerJob $job): array
    {
        return $job->sections
            ->filter(static fn ($section): bool => (bool) $section->is_enabled)
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->map(static fn ($section): array => [
                'section_key' => $section->section_key,
                'title' => $section->title,
                'render_variant' => $section->render_variant,
                'body_md' => $section->body_md,
                'body_html' => $section->body_html,
                'payload_json' => is_array($section->payload_json) ? $section->payload_json : [],
                'sort_order' => $section->sort_order,
            ])
            ->all();
    }

    private function contentBodyMdFromCareerJob(CareerJob $job): ?string
    {
        $body = trim((string) $job->body_md);

        return $body === '' ? null : $body;
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

    private function isDocxCareerJob(CareerJob $job): bool
    {
        return is_string(Arr::get($job->seoMeta?->jsonld_overrides_json, 'source_docx'))
            && Arr::get($job->market_demand_json, 'source_refs.0.url') !== null;
    }

    /**
     * @param  array<string, mixed>  $market
     * @return list<array{label?: string, url?: string}>
     */
    private function sourceRefsFromMarketDemand(array $market): array
    {
        $rows = $market['source_refs'] ?? [];

        return is_array($rows)
            ? array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $growth
     */
    private function growthLine(array $growth, int $index): ?string
    {
        $raw = $growth['raw'] ?? [];

        if (! is_array($raw) || ! is_scalar($raw[$index] ?? null)) {
            return null;
        }

        $line = trim((string) $raw[$index]);

        return $line === '' ? null : $line;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function docxScoreBundle(CareerJob $job): array
    {
        $aiExposure = is_array($job->market_demand_json)
            ? (int) ($job->market_demand_json['ai_exposure_score_10'] ?? 0)
            : 0;
        $aiSurvival = $aiExposure > 0 ? max(0, min(100, 100 - ($aiExposure * 10))) : null;

        return [
            'fit_score' => $this->scoreResult(null, 'missing_personality_fit_model'),
            'strain_score' => $this->scoreResult(null, 'missing_strain_model'),
            'ai_survival_score' => $this->scoreResult($aiSurvival, 'missing_ai_exposure_score'),
            'mobility_score' => $this->scoreResult(null, 'missing_mobility_model'),
            'confidence_score' => $this->scoreResult(70, 'docx_baseline_confidence'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scoreResult(?int $value, string $fallbackReason): array
    {
        return [
            'value' => $value,
            'integrity_state' => $value === null ? 'missing' : 'docx_baseline',
            'critical_missing_fields' => $value === null ? [$fallbackReason] : [],
            'confidence_cap' => 70,
            'formula_ref' => 'career.docx_baseline.v1',
            'component_breakdown' => [],
            'penalties' => [],
            'degradation_factor' => $value === null ? 1 : 0,
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
