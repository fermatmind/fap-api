<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

use App\Domain\Career\Import\CrosswalkSeedPolicy;
use App\Domain\Career\Import\FirstWaveAliasHardeningService;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\ContextSnapshot;
use App\Models\EditorialPatch;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Models\OccupationSkillGraph;
use App\Models\OccupationTruthMetric;
use App\Models\ProfileProjection;
use App\Models\RecommendationSnapshot;
use App\Models\SourceTrace;
use App\Models\TrustManifest;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\Transition\CareerTransitionPathWriter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CareerAuthorityMaterializer
{
    public const PROJECTION_VERSION = 'career_authority_wave_projection.v1';

    public function __construct(
        private readonly CrosswalkSeedPolicy $crosswalkSeedPolicy,
        private readonly CareerRecommendationCompiler $compiler,
        private readonly FirstWaveAliasHardeningService $firstWaveAliasHardeningService,
        private readonly CareerTransitionPathWriter $transitionPathWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public function materializeImportRow(array $normalized, CareerImportRun $importRun): array
    {
        return DB::transaction(function () use ($normalized, $importRun): array {
            $familyAttributes = [
                'canonical_slug' => (string) $normalized['family_slug'],
                'title_en' => (string) $normalized['family_title_en'],
                'title_zh' => $normalized['family_title_zh'] ?? '',
            ];
            $family = null;
            if (($normalized['family_uuid'] ?? null) !== null) {
                $family = OccupationFamily::query()->find((string) $normalized['family_uuid']);
            }
            if (! $family instanceof OccupationFamily) {
                $family = OccupationFamily::query()
                    ->where('canonical_slug', (string) $normalized['family_slug'])
                    ->first();
            }
            if ($family instanceof OccupationFamily) {
                $family->forceFill($familyAttributes)->save();
            } else {
                $family = OccupationFamily::query()->create([
                    'id' => $normalized['family_uuid'] ?? null,
                    ...$familyAttributes,
                ]);
            }

            $occupationAttributes = [
                'family_id' => $family->id,
                'entity_level' => 'market_child',
                'truth_market' => (string) $normalized['truth_market'],
                'display_market' => (string) $normalized['display_market'],
                'crosswalk_mode' => (string) $normalized['crosswalk_mode'],
                'canonical_slug' => (string) $normalized['canonical_slug'],
                'canonical_title_en' => (string) $normalized['canonical_title_en'],
                'canonical_title_zh' => $normalized['canonical_title_zh'] ?? '',
                'search_h1_zh' => ($normalized['canonical_title_zh'] ?? null) !== null
                    ? (string) $normalized['canonical_title_zh'].'职业诊断'
                    : (string) $normalized['canonical_title_en'],
                'structural_stability' => $normalized['structural_stability'],
                'task_prototype_signature' => $normalized['task_prototype_signature'],
                'market_semantics_gap' => $normalized['market_semantics_gap'],
                'regulatory_divergence' => $normalized['regulatory_divergence'],
                'toolchain_divergence' => $normalized['toolchain_divergence'],
                'skill_gap_threshold' => $normalized['skill_gap_threshold'],
                'trust_inheritance_scope' => $normalized['trust_inheritance_scope'],
            ];
            $occupation = null;
            if (($normalized['occupation_uuid'] ?? null) !== null) {
                $occupation = Occupation::query()->find((string) $normalized['occupation_uuid']);
            }
            if (! $occupation instanceof Occupation) {
                $occupation = Occupation::query()
                    ->where('canonical_slug', (string) $normalized['canonical_slug'])
                    ->first();
            }
            if ($occupation instanceof Occupation) {
                $occupation->forceFill($occupationAttributes)->save();
            } else {
                $occupation = Occupation::query()->create([
                    'id' => $normalized['occupation_uuid'] ?? null,
                    ...$occupationAttributes,
                ]);
            }

            $counts = [
                'families_upserted' => 1,
                'occupations_upserted' => 1,
                'aliases_created' => 0,
                'crosswalks_created' => 0,
                'source_traces_created' => 0,
                'truth_metrics_created' => 0,
                'skill_graphs_created' => 0,
                'trust_manifests_created' => 0,
                'editorial_patches_created' => 0,
                'index_states_created' => 0,
            ];

            foreach ($this->aliasPayloads($normalized, $occupation, $family, $importRun) as $payload) {
                $created = OccupationAlias::query()->firstOrCreate(
                    [
                        'import_run_id' => $importRun->id,
                        'row_fingerprint' => $payload['row_fingerprint'],
                    ],
                    $payload,
                );

                $counts['aliases_created'] += $created->wasRecentlyCreated ? 1 : 0;
            }

            $crosswalkPayload = [
                'occupation_id' => $occupation->id,
                'source_system' => (string) $normalized['crosswalk_source_system'],
                'source_code' => $normalized['crosswalk_source_code'],
                'source_title' => (string) $normalized['crosswalk_source_title'],
                'mapping_type' => (string) $normalized['mapping_mode'],
                'confidence_score' => (string) $normalized['mapping_mode'] === 'exact' ? 1.0 : 0.82,
                'notes' => 'first_wave_authority_import',
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'occupation_slug' => $occupation->canonical_slug,
                    'source_system' => $normalized['crosswalk_source_system'],
                    'source_code' => $normalized['crosswalk_source_code'],
                    'mapping_type' => $normalized['mapping_mode'],
                ]),
            ];
            $crosswalk = OccupationCrosswalk::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $crosswalkPayload['row_fingerprint'],
                ],
                $crosswalkPayload,
            );
            $counts['crosswalks_created'] += $crosswalk->wasRecentlyCreated ? 1 : 0;

            $sourceTracePayload = [
                'source_id' => (string) $normalized['canonical_slug'],
                'source_type' => 'career_first_wave',
                'title' => (string) $normalized['source_title'],
                'url' => $normalized['bls_url'] ?? $normalized['source_url'],
                'fields_used' => [
                    'ai_exposure',
                    'median_pay_usd_annual',
                    'jobs_2024',
                    'projected_jobs_2034',
                    'employment_change',
                    'outlook_pct_2024_2034',
                ],
                'retrieved_at' => $importRun->started_at,
                'evidence_strength' => (string) $normalized['mapping_mode'] === 'exact' ? 0.92 : 0.74,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'source_type' => 'career_first_wave',
                    'source_id' => $normalized['canonical_slug'],
                    'url' => $normalized['bls_url'] ?? $normalized['source_url'],
                    'retrieved_at' => optional($importRun->started_at)->toISOString(),
                ]),
            ];
            $sourceTrace = SourceTrace::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $sourceTracePayload['row_fingerprint'],
                ],
                $sourceTracePayload,
            );
            $counts['source_traces_created'] += $sourceTrace->wasRecentlyCreated ? 1 : 0;

            $truthMetricPayload = [
                'occupation_id' => $occupation->id,
                'source_trace_id' => $sourceTrace->id,
                'median_pay_usd_annual' => $normalized['median_pay_usd_annual'],
                'jobs_2024' => $normalized['jobs_2024'],
                'projected_jobs_2034' => $normalized['projected_jobs_2034'],
                'employment_change' => $normalized['employment_change'],
                'outlook_pct_2024_2034' => $normalized['outlook_pct_2024_2034'],
                'outlook_description' => $normalized['outlook_description'],
                'entry_education' => $normalized['entry_education'],
                'work_experience' => $normalized['work_experience'],
                'on_the_job_training' => $normalized['on_the_job_training'],
                'ai_exposure' => $normalized['ai_exposure'],
                'ai_rationale' => $normalized['ai_rationale'],
                'truth_market' => (string) $normalized['truth_market'],
                'effective_at' => $importRun->started_at,
                'reviewed_at' => null,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'occupation_slug' => $occupation->canonical_slug,
                    'truth_market' => $normalized['truth_market'],
                    'median_pay_usd_annual' => $normalized['median_pay_usd_annual'],
                    'jobs_2024' => $normalized['jobs_2024'],
                    'projected_jobs_2034' => $normalized['projected_jobs_2034'],
                    'employment_change' => $normalized['employment_change'],
                    'outlook_pct_2024_2034' => $normalized['outlook_pct_2024_2034'],
                    'ai_exposure' => $normalized['ai_exposure'],
                ]),
            ];
            $truthMetric = OccupationTruthMetric::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $truthMetricPayload['row_fingerprint'],
                ],
                $truthMetricPayload,
            );
            $counts['truth_metrics_created'] += $truthMetric->wasRecentlyCreated ? 1 : 0;

            $skillGraphPayload = $this->skillGraphPayload($normalized, $occupation, $importRun);
            $skillGraph = OccupationSkillGraph::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $skillGraphPayload['row_fingerprint'],
                ],
                $skillGraphPayload,
            );
            $counts['skill_graphs_created'] += $skillGraph->wasRecentlyCreated ? 1 : 0;

            $seed = $this->crosswalkSeedPolicy->seed($normalized);

            $trustManifestPayload = [
                'occupation_id' => $occupation->id,
                'content_version' => 'career_first_wave.v1',
                'data_version' => (string) ($importRun->dataset_version ?? 'dataset.unknown'),
                'logic_version' => CareerRecommendationCompiler::COMPILER_VERSION,
                'locale_context' => [
                    'truth_market' => $normalized['truth_market'],
                    'display_market' => $normalized['display_market'],
                ],
                'methodology' => [
                    'dataset_name' => $importRun->dataset_name,
                    'scope_mode' => $importRun->scope_mode,
                    'mapping_mode' => $normalized['mapping_mode'],
                ],
                'reviewer_status' => 'pending',
                'reviewer_id' => null,
                'reviewed_at' => null,
                'ai_assistance' => [
                    'ingestion' => 'first_wave_pipeline',
                    'source_trace_url' => $normalized['source_url'],
                ],
                'quality' => [
                    'confidence' => (string) $normalized['mapping_mode'] === 'exact' ? 0.74 : 0.58,
                    'review_required' => true,
                ],
                'last_substantive_update_at' => $importRun->started_at,
                'next_review_due_at' => null,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'occupation_slug' => $occupation->canonical_slug,
                    'content_version' => 'career_first_wave.v1',
                    'data_version' => $importRun->dataset_version,
                    'logic_version' => CareerRecommendationCompiler::COMPILER_VERSION,
                    'mapping_mode' => $normalized['mapping_mode'],
                ]),
            ];
            $trustManifest = TrustManifest::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $trustManifestPayload['row_fingerprint'],
                ],
                $trustManifestPayload,
            );
            $counts['trust_manifests_created'] += $trustManifest->wasRecentlyCreated ? 1 : 0;

            $editorialPatchPayload = [
                'occupation_id' => $occupation->id,
                'required' => $seed['editorial_patch_required'],
                'status' => $seed['editorial_patch_status'],
                'patch_version' => null,
                'notes' => [
                    'reason_codes' => $seed['reason_codes'],
                    'scope_mode' => $importRun->scope_mode,
                ],
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'occupation_slug' => $occupation->canonical_slug,
                    'required' => $seed['editorial_patch_required'],
                    'status' => $seed['editorial_patch_status'],
                    'reason_codes' => $seed['reason_codes'],
                ]),
            ];
            $editorialPatch = EditorialPatch::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $editorialPatchPayload['row_fingerprint'],
                ],
                $editorialPatchPayload,
            );
            $counts['editorial_patches_created'] += $editorialPatch->wasRecentlyCreated ? 1 : 0;

            $indexStatePayload = [
                'occupation_id' => $occupation->id,
                'index_state' => $seed['index_state'],
                'index_eligible' => $seed['index_eligible'],
                'canonical_path' => (string) $normalized['canonical_path'],
                'canonical_target' => null,
                'reason_codes' => $seed['reason_codes'],
                'changed_at' => $importRun->started_at,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => $this->fingerprint([
                    'occupation_slug' => $occupation->canonical_slug,
                    'index_state' => $seed['index_state'],
                    'index_eligible' => $seed['index_eligible'],
                    'canonical_path' => $normalized['canonical_path'],
                    'reason_codes' => $seed['reason_codes'],
                ]),
            ];
            $indexState = IndexState::query()->firstOrCreate(
                [
                    'import_run_id' => $importRun->id,
                    'row_fingerprint' => $indexStatePayload['row_fingerprint'],
                ],
                $indexStatePayload,
            );
            $counts['index_states_created'] += $indexState->wasRecentlyCreated ? 1 : 0;

            return [
                'family' => $family,
                'occupation' => $occupation,
                'crosswalk' => $crosswalk,
                'sourceTrace' => $sourceTrace,
                'truthMetric' => $truthMetric,
                'skillGraph' => $skillGraph,
                'trustManifest' => $trustManifest,
                'editorialPatch' => $editorialPatch,
                'indexState' => $indexState,
                'counts' => $counts,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    public function materializeCompileSnapshot(
        Occupation $occupation,
        CareerCompileRun $compileRun,
        CareerImportRun $importRun,
        array $resolved
    ): RecommendationSnapshot {
        return DB::transaction(function () use ($occupation, $compileRun, $importRun, $resolved): RecommendationSnapshot {
            $contextSnapshot = ContextSnapshot::query()->create([
                'compile_run_id' => $compileRun->id,
                'visitor_id' => sprintf('career-wave:%s:%s', $compileRun->id, $occupation->canonical_slug),
                'captured_at' => $compileRun->started_at,
                'current_occupation_id' => $occupation->id,
                'employment_status' => 'authority_baseline',
                'monthly_comp_band' => 'unknown',
                'burnout_level' => 0.35,
                'switch_urgency' => 0.42,
                'risk_tolerance' => 0.55,
                'geo_region' => (string) ($resolved['display_market'] ?? $occupation->display_market),
                'family_constraint_level' => 0.4,
                'manager_track_preference' => 0.5,
                'time_horizon_months' => 12,
                'context_payload' => [
                    'materialization' => 'career_first_wave',
                    'import_run_id' => $importRun->id,
                    'compile_run_id' => $compileRun->id,
                    'scope_mode' => $compileRun->scope_mode,
                ],
            ]);

            $profileProjection = ProfileProjection::query()->create([
                'compile_run_id' => $compileRun->id,
                'visitor_id' => sprintf('career-wave:%s:%s', $compileRun->id, $occupation->canonical_slug),
                'context_snapshot_id' => $contextSnapshot->id,
                'projection_version' => self::PROJECTION_VERSION,
                'psychometric_axis_coverage' => 0.66,
                'projection_payload' => [
                    'fit_axes' => [
                        'abstraction' => 0.72,
                        'autonomy' => 0.65,
                        'collaboration' => 0.5,
                        'variability' => 0.58,
                        'variant_trigger_load' => 0.12,
                    ],
                    'materialization' => 'career_first_wave',
                    'compile_run_id' => $compileRun->id,
                ],
            ]);

            $snapshot = $this->compiler->compile($profileProjection, $occupation, [
                'compile_run_id' => $compileRun->id,
                'trust_manifest_id' => $resolved['trust_manifest_id'] ?? null,
                'index_state_id' => $resolved['index_state_id'] ?? null,
                'truth_metric_id' => $resolved['truth_metric_id'] ?? null,
                'import_run_id' => $importRun->id,
                'compile_refs' => [
                    'import_run_id' => $importRun->id,
                    'compile_run_id' => $compileRun->id,
                    'scope_mode' => $compileRun->scope_mode,
                ],
            ]);

            $this->transitionPathWriter->rewriteForSnapshot($snapshot);

            return $snapshot;
        });
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function skillGraphPayload(array $normalized, Occupation $occupation, CareerImportRun $importRun): array
    {
        $input = is_array($normalized['skill_graph'] ?? null) ? $normalized['skill_graph'] : [];
        $explicit = $input !== [];
        $stackKey = (string) ($input['stack_key'] ?? ($explicit ? 'core' : 'authority_self_baseline'));
        $skillOverlapGraph = is_array($input['skill_overlap_graph'] ?? null)
            ? $input['skill_overlap_graph']
            : ['self_baseline' => 1.0];
        $taskOverlapGraph = is_array($input['task_overlap_graph'] ?? null)
            ? $input['task_overlap_graph']
            : ['self_baseline' => 1.0];
        $toolOverlapGraph = is_array($input['tool_overlap_graph'] ?? null)
            ? $input['tool_overlap_graph']
            : ['self_baseline' => 1.0];

        return [
            'occupation_id' => $occupation->id,
            'stack_key' => $stackKey,
            'skill_overlap_graph' => $skillOverlapGraph,
            'task_overlap_graph' => $taskOverlapGraph,
            'tool_overlap_graph' => $toolOverlapGraph,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => $this->fingerprint([
                'occupation_slug' => $occupation->canonical_slug,
                'stack_key' => $stackKey,
                'skill_overlap_graph' => $skillOverlapGraph,
                'task_overlap_graph' => $taskOverlapGraph,
                'tool_overlap_graph' => $toolOverlapGraph,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<array<string, mixed>>
     */
    private function aliasPayloads(
        array $normalized,
        Occupation $occupation,
        OccupationFamily $family,
        CareerImportRun $importRun
    ): array {
        $payloads = [];
        $hardening = $this->firstWaveAliasHardeningService->resolveAliasPayloads(
            (string) $normalized['canonical_slug'],
            $occupation,
            $family,
            $importRun,
        );
        $blockedSet = array_fill_keys($hardening['blocked_aliases'], true);

        foreach ([
            [
                'alias' => (string) $normalized['canonical_title_en'],
                'lang' => 'en',
                'register' => 'canonical_title',
            ],
            [
                'alias' => $normalized['canonical_title_zh'],
                'lang' => 'zh-CN',
                'register' => 'canonical_title',
            ],
        ] as $candidate) {
            $alias = trim((string) ($candidate['alias'] ?? ''));
            if ($alias === '') {
                continue;
            }
            if (isset($blockedSet[mb_strtolower($alias, 'UTF-8')])) {
                continue;
            }

            $payloads[] = [
                'occupation_id' => $occupation->id,
                'family_id' => $family->id,
                'alias' => $alias,
                'normalized' => mb_strtolower($alias),
                'lang' => $candidate['lang'],
                'register' => $candidate['register'],
                'intent_scope' => 'exact',
                'target_kind' => 'occupation',
                'precision_score' => 1.0,
                'confidence_score' => 1.0,
                'seniority_hint' => null,
                'function_hint' => null,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => null,
            ];
        }

        $payloads = [...$payloads, ...$hardening['alias_payloads']];

        $deduped = [];
        $seen = [];
        foreach ($payloads as $payload) {
            $normalizedAlias = mb_strtolower(trim((string) ($payload['normalized'] ?? '')), 'UTF-8');
            $lang = strtolower(trim((string) ($payload['lang'] ?? '')));
            $dedupeKey = sprintf('%s|%s', $lang, $normalizedAlias);

            if ($normalizedAlias === '' || isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $deduped[] = $payload;
        }

        return array_map(function (array $payload): array {
            $payload['row_fingerprint'] = $this->fingerprint(Arr::except($payload, ['occupation_id', 'family_id', 'import_run_id', 'row_fingerprint']));

            return $payload;
        }, $deduped);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded === false ? serialize($payload) : $encoded);
    }
}
