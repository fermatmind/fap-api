<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationTruthMetric;
use App\Models\ProfileProjection;
use App\Models\TrustManifest;

final class CareerScoringInputResolver
{
    /**
     * @param  array<string, mixed>  $preferredRefs
     * @return array<string,mixed>
     */
    public function resolve(ProfileProjection $profileProjection, Occupation $occupation, array $preferredRefs = []): array
    {
        $truthMetric = $this->resolveTruthMetric($occupation, $preferredRefs['truth_metric_id'] ?? null);
        $trustManifest = $this->resolveTrustManifest($occupation, $preferredRefs['trust_manifest_id'] ?? null);
        $indexState = $this->resolveIndexState($occupation, $preferredRefs['index_state_id'] ?? null);
        $editorialPatch = $occupation->editorialPatches()
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
        $skillGraph = $occupation->skillGraphs()
            ->orderBy('stack_key')
            ->orderByDesc('updated_at')
            ->first();
        $crosswalks = $occupation->crosswalks()->get();

        $projectionPayload = is_array($profileProjection->projection_payload ?? null)
            ? $profileProjection->projection_payload
            : [];
        $fitAxes = is_array($projectionPayload['fit_axes'] ?? null)
            ? $projectionPayload['fit_axes']
            : [];

        $taskSignature = is_array($occupation->task_prototype_signature ?? null)
            ? $occupation->task_prototype_signature
            : [];
        $trustInheritance = is_array($occupation->trust_inheritance_scope ?? null)
            ? $occupation->trust_inheritance_scope
            : [];
        $skillOverlap = $this->graphAverage($skillGraph?->skill_overlap_graph);
        $taskOverlap = $this->graphAverage($skillGraph?->task_overlap_graph);
        $toolOverlap = $this->graphAverage($skillGraph?->tool_overlap_graph);
        $crosswalkConfidence = $this->crosswalkConfidence($crosswalks->all());
        $crosswalkIds = array_values(array_map(
            static fn (OccupationCrosswalk $crosswalk): string => $crosswalk->id,
            array_values(array_filter(
                $crosswalks->all(),
                static fn (mixed $crosswalk): bool => $crosswalk instanceof OccupationCrosswalk
            ))
        ));
        $sourceTrace = $truthMetric?->sourceTrace;
        $quality = is_array($trustManifest?->quality ?? null) ? $trustManifest->quality : [];
        $methodology = is_array($trustManifest?->methodology ?? null) ? $trustManifest->methodology : [];

        return [
            'profile_projection_id' => $profileProjection->id,
            'context_snapshot_id' => $profileProjection->context_snapshot_id,
            'occupation_id' => $occupation->id,
            'trust_manifest_id' => $trustManifest?->id,
            'index_state_id' => $indexState?->id,
            'truth_metric_id' => $truthMetric?->id,
            'source_trace_id' => $sourceTrace?->id,
            'skill_graph_id' => $skillGraph?->id,
            'crosswalk_ids' => $crosswalkIds,
            'occupation_state_hash' => $this->occupationStateHash($occupation, $skillGraph?->id, $crosswalkIds),
            'trust_manifest' => $trustManifest instanceof TrustManifest,
            'reviewer_status' => $trustManifest?->reviewer_status,
            'quality_confidence' => $quality['confidence'] ?? $quality['confidence_score'] ?? null,
            'last_substantive_update_at' => $trustManifest?->last_substantive_update_at,
            'methodology_key_count' => count($methodology),
            'truth_reviewed_at' => $truthMetric?->reviewed_at,
            'source_trace_evidence' => $sourceTrace?->evidence_strength,
            'source_fields_used_count' => count(is_array($sourceTrace?->fields_used ?? null) ? $sourceTrace->fields_used : []),
            'cross_market_mismatch' => $occupation->truth_market !== $occupation->display_market,
            'allow_pay_direct_inheritance' => (bool) ($trustInheritance['allow_pay_direct_inheritance'] ?? false),
            'editorial_patch_required' => (bool) ($editorialPatch?->required ?? false),
            'editorial_patch_complete' => in_array((string) ($editorialPatch?->status ?? ''), ['completed', 'not_required', 'approved'], true),
            'index_state' => $indexState?->index_state,
            'index_eligible' => $indexState?->index_eligible,
            'reason_codes' => is_array($indexState?->reason_codes ?? null) ? $indexState->reason_codes : [],
            'structural_stability' => $occupation->structural_stability,
            'task_prototype_signature' => $taskSignature,
            'task_analysis' => $taskSignature['analysis'] ?? null,
            'task_build' => $taskSignature['build'] ?? null,
            'task_coordination' => $taskSignature['coordination'] ?? null,
            'market_semantics_gap' => $occupation->market_semantics_gap,
            'regulatory_divergence' => $occupation->regulatory_divergence,
            'toolchain_divergence' => $occupation->toolchain_divergence,
            'skill_gap_threshold' => $occupation->skill_gap_threshold,
            'psychometric_axis_coverage' => $profileProjection->psychometric_axis_coverage,
            'pref_abstraction' => $fitAxes['abstraction'] ?? null,
            'pref_autonomy' => $fitAxes['autonomy'] ?? null,
            'pref_collaboration' => $fitAxes['collaboration'] ?? null,
            'pref_variability' => $fitAxes['variability'] ?? null,
            'variant_trigger_load' => $fitAxes['variant_trigger_load'] ?? $projectionPayload['variant_trigger_load'] ?? 0.0,
            'risk_tolerance' => $profileProjection->contextSnapshot?->risk_tolerance,
            'switch_urgency' => $profileProjection->contextSnapshot?->switch_urgency,
            'family_constraint_level' => $profileProjection->contextSnapshot?->family_constraint_level,
            'manager_track_preference' => $profileProjection->contextSnapshot?->manager_track_preference,
            'time_horizon_fit' => $this->timeHorizonFit($profileProjection),
            'skill_overlap' => $skillOverlap,
            'task_overlap' => $taskOverlap,
            'tool_overlap' => $toolOverlap,
            'crosswalk_confidence' => $crosswalkConfidence,
            'median_pay_usd_annual' => $truthMetric?->median_pay_usd_annual,
            'entry_education' => $truthMetric?->entry_education,
            'work_experience' => $truthMetric?->work_experience,
            'on_the_job_training' => $truthMetric?->on_the_job_training,
            'ai_exposure' => $truthMetric?->ai_exposure !== null
                ? min(1.0, max(0.0, ((float) $truthMetric->ai_exposure) / 10.0))
                : null,
        ];
    }

    private function resolveTruthMetric(Occupation $occupation, mixed $truthMetricId): ?OccupationTruthMetric
    {
        if (is_string($truthMetricId) && $truthMetricId !== '') {
            return $occupation->truthMetrics()->whereKey($truthMetricId)->first();
        }

        return $occupation->truthMetrics()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolveTrustManifest(Occupation $occupation, mixed $trustManifestId): ?TrustManifest
    {
        if (is_string($trustManifestId) && $trustManifestId !== '') {
            return $occupation->trustManifests()->whereKey($trustManifestId)->first();
        }

        return $occupation->trustManifests()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolveIndexState(Occupation $occupation, mixed $indexStateId): ?IndexState
    {
        if (is_string($indexStateId) && $indexStateId !== '') {
            return $occupation->indexStates()->whereKey($indexStateId)->first();
        }

        return $occupation->indexStates()
            ->orderByDesc('changed_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function graphAverage(mixed $graph): ?float
    {
        if (! is_array($graph) || $graph === []) {
            return null;
        }

        $values = [];
        foreach ($graph as $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $values[] = (float) $value;
        }

        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param  array<int,OccupationCrosswalk>  $crosswalks
     */
    private function crosswalkConfidence(array $crosswalks): ?float
    {
        $values = [];

        foreach ($crosswalks as $crosswalk) {
            if (! $crosswalk instanceof OccupationCrosswalk || $crosswalk->confidence_score === null) {
                continue;
            }

            $values[] = (float) $crosswalk->confidence_score;
        }

        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    private function timeHorizonFit(ProfileProjection $profileProjection): float
    {
        $months = (int) ($profileProjection->contextSnapshot?->time_horizon_months ?? 0);

        return match (true) {
            $months >= 24 => 0.9,
            $months >= 12 => 0.72,
            $months >= 6 => 0.55,
            $months > 0 => 0.38,
            default => 0.5,
        };
    }

    /**
     * @param  list<string>  $crosswalkIds
     */
    private function occupationStateHash(Occupation $occupation, ?string $skillGraphId, array $crosswalkIds): string
    {
        $payload = [
            'occupation_id' => $occupation->id,
            'canonical_slug' => $occupation->canonical_slug,
            'truth_market' => $occupation->truth_market,
            'display_market' => $occupation->display_market,
            'crosswalk_mode' => $occupation->crosswalk_mode,
            'structural_stability' => $occupation->structural_stability,
            'task_prototype_signature' => $occupation->task_prototype_signature,
            'market_semantics_gap' => $occupation->market_semantics_gap,
            'regulatory_divergence' => $occupation->regulatory_divergence,
            'toolchain_divergence' => $occupation->toolchain_divergence,
            'skill_gap_threshold' => $occupation->skill_gap_threshold,
            'trust_inheritance_scope' => $occupation->trust_inheritance_scope,
            'skill_graph_id' => $skillGraphId,
            'crosswalk_ids' => $crosswalkIds,
            'updated_at' => optional($occupation->updated_at)?->toISOString(),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded === false ? serialize($payload) : $encoded);
    }
}
