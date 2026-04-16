<?php

declare(strict_types=1);

namespace App\Domain\Career\Feedback;

use App\DTO\Career\CareerFeedbackRecord as CareerFeedbackRecordDto;
use App\DTO\Career\CareerProjectionDeltaSummary;
use App\DTO\Career\CareerProjectionTimeline;
use App\DTO\Career\CareerProjectionTimelineEntry;
use App\Models\CareerFeedbackRecord;
use App\Models\ContextSnapshot;
use App\Models\ProfileProjection;
use App\Models\ProjectionLineage;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use Illuminate\Support\Facades\DB;

final class CareerFeedbackTimelineAuthorityService
{
    /**
     * @return array<string, mixed>
     */
    public function buildForRecommendationSnapshot(?RecommendationSnapshot $snapshot): array
    {
        if (! $snapshot instanceof RecommendationSnapshot || ! $snapshot->profileProjection) {
            return $this->emptyPayload();
        }

        $timeline = $this->buildTimeline($snapshot);
        $delta = $this->buildDeltaSummary($snapshot, $timeline);
        $latestFeedback = $this->findFeedbackByRecommendationSnapshotId($snapshot->id);

        return [
            'feedback_checkin' => $latestFeedback?->toArray(),
            'projection_timeline' => $timeline->toArray(),
            'projection_delta_summary' => $delta->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCompanionForJobSnapshot(?RecommendationSnapshot $snapshot): array
    {
        if (! $snapshot instanceof RecommendationSnapshot) {
            return [];
        }

        $payload = $this->buildForRecommendationSnapshot($snapshot);
        $timeline = is_array($payload['projection_timeline'] ?? null) ? $payload['projection_timeline'] : [];
        $entries = is_array($timeline['entries'] ?? null) ? $timeline['entries'] : [];
        $timeline['entries'] = array_slice($entries, -4);

        return [
            'timeline' => $timeline,
            'delta_summary' => is_array($payload['projection_delta_summary'] ?? null) ? $payload['projection_delta_summary'] : [],
            'latest_feedback' => is_array($payload['feedback_checkin'] ?? null) ? $payload['feedback_checkin'] : null,
        ];
    }

    public function resolveCurrentSnapshotByType(string $type): ?RecommendationSnapshot
    {
        $requestedType = strtoupper(trim($type));
        if ($requestedType === '') {
            return null;
        }
        $canonicalType = strtoupper(strtok($requestedType, '-') ?: $requestedType);

        /** @var RecommendationSnapshot|null $snapshot */
        $snapshot = RecommendationSnapshot::query()
            ->with(['profileProjection', 'contextSnapshot'])
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', function ($query) use ($requestedType, $canonicalType): void {
                $query->where(function ($inner) use ($requestedType, $canonicalType): void {
                    $inner->where('projection_payload->recommendation_subject_meta->type_code', $requestedType)
                        ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $requestedType)
                        ->orWhere('projection_payload->recommendation_subject_meta->canonical_type_code', $canonicalType);
                });
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->first();

        return $snapshot;
    }

    public function appendFeedbackRefresh(RecommendationSnapshot $snapshot, array $input): RecommendationSnapshot
    {
        /** @var RecommendationSnapshot $createdSnapshot */
        $createdSnapshot = DB::transaction(function () use ($snapshot, $input): RecommendationSnapshot {
            $context = $snapshot->contextSnapshot;
            $projection = $snapshot->profileProjection;
            if (! $context instanceof ContextSnapshot || ! $projection instanceof ProfileProjection) {
                return $snapshot;
            }

            $burnoutCheckin = $this->normalizeScaleValue($input['burnout_checkin'] ?? null);
            $careerSatisfaction = $this->normalizeScaleValue($input['career_satisfaction'] ?? null);
            $switchUrgency = $this->normalizeScaleValue($input['switch_urgency'] ?? null);

            $newContext = ContextSnapshot::query()->create([
                'identity_id' => $context->identity_id,
                'visitor_id' => $context->visitor_id,
                'captured_at' => now(),
                'current_occupation_id' => $context->current_occupation_id,
                'employment_status' => $context->employment_status,
                'monthly_comp_band' => $context->monthly_comp_band,
                'burnout_level' => $this->scaleToDecimal($burnoutCheckin) ?? $context->burnout_level,
                'switch_urgency' => $this->scaleToDecimal($switchUrgency) ?? $context->switch_urgency,
                'risk_tolerance' => $context->risk_tolerance,
                'geo_region' => $context->geo_region,
                'family_constraint_level' => $context->family_constraint_level,
                'manager_track_preference' => $context->manager_track_preference,
                'time_horizon_months' => $context->time_horizon_months,
                'context_payload' => array_merge(
                    is_array($context->context_payload) ? $context->context_payload : [],
                    [
                        'feedback_checkin' => [
                            'burnout_checkin' => $burnoutCheckin,
                            'career_satisfaction' => $careerSatisfaction,
                            'switch_urgency' => $switchUrgency,
                        ],
                    ],
                ),
                'compile_run_id' => $context->compile_run_id,
            ]);

            $newProjection = ProfileProjection::query()->create([
                'identity_id' => $projection->identity_id,
                'visitor_id' => $projection->visitor_id,
                'context_snapshot_id' => $newContext->id,
                'projection_version' => $projection->projection_version,
                'psychometric_axis_coverage' => $projection->psychometric_axis_coverage,
                'projection_payload' => array_merge(
                    is_array($projection->projection_payload) ? $projection->projection_payload : [],
                    [
                        'lifecycle_feedback' => [
                            'burnout_checkin' => $burnoutCheckin,
                            'career_satisfaction' => $careerSatisfaction,
                            'switch_urgency' => $switchUrgency,
                        ],
                    ],
                ),
                'compile_run_id' => $projection->compile_run_id,
            ]);

            ProjectionLineage::query()->create([
                'parent_projection_id' => $projection->id,
                'child_projection_id' => $newProjection->id,
                'trigger_context_snapshot_id' => $newContext->id,
                'trigger_assessment_id' => null,
                'lineage_reason' => 'context_refresh',
                'diff_summary' => [
                    'feedback_refresh' => true,
                    'burnout_checkin' => $burnoutCheckin,
                    'career_satisfaction' => $careerSatisfaction,
                    'switch_urgency' => $switchUrgency,
                ],
            ]);

            $newPayload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
            $newPayload['feedback_checkin'] = [
                'burnout_checkin' => $burnoutCheckin,
                'career_satisfaction' => $careerSatisfaction,
                'switch_urgency' => $switchUrgency,
            ];

            $newRecommendationSnapshot = RecommendationSnapshot::query()->create([
                'profile_projection_id' => $newProjection->id,
                'context_snapshot_id' => $newContext->id,
                'occupation_id' => $snapshot->occupation_id,
                'snapshot_payload' => $newPayload,
                'compiled_at' => now(),
                'compiler_version' => $snapshot->compiler_version,
                'truth_metric_id' => $snapshot->truth_metric_id,
                'trust_manifest_id' => $snapshot->trust_manifest_id,
                'index_state_id' => $snapshot->index_state_id,
                'compile_run_id' => $snapshot->compile_run_id,
            ]);

            CareerFeedbackRecord::query()->create([
                'subject_kind' => 'recommendation_type',
                'subject_slug' => strtolower(trim((string) ($input['subject_slug'] ?? ''))),
                'burnout_checkin' => $burnoutCheckin,
                'career_satisfaction' => $careerSatisfaction,
                'switch_urgency' => $switchUrgency,
                'context_snapshot_id' => $newContext->id,
                'profile_projection_id' => $newProjection->id,
                'recommendation_snapshot_id' => $newRecommendationSnapshot->id,
            ]);

            return $newRecommendationSnapshot;
        });

        return $createdSnapshot->fresh(['profileProjection', 'contextSnapshot']) ?? $createdSnapshot;
    }

    private function buildTimeline(RecommendationSnapshot $snapshot): CareerProjectionTimeline
    {
        $projectionIds = $this->resolveProjectionChain($snapshot->profile_projection_id);
        $entries = [];
        $firstProjectionId = $projectionIds[0] ?? null;

        foreach ($projectionIds as $projectionId) {
            /** @var RecommendationSnapshot|null $timelineSnapshot */
            $timelineSnapshot = RecommendationSnapshot::query()
                ->where('profile_projection_id', $projectionId)
                ->where('occupation_id', $snapshot->occupation_id)
                ->orderByDesc('created_at')
                ->first();

            if (! $timelineSnapshot instanceof RecommendationSnapshot) {
                continue;
            }

            $feedback = $this->findFeedbackByRecommendationSnapshotId($timelineSnapshot->id);
            $entryKind = $feedback instanceof CareerFeedbackRecordDto
                ? 'feedback_refresh'
                : (($projectionId === $firstProjectionId) ? 'initial' : 'feedback_refresh');
            $entryLabel = $entryKind === 'initial' ? 'Initial recommendation snapshot' : 'Feedback refresh snapshot';

            $entries[] = new CareerProjectionTimelineEntry(
                projectionUuid: (string) $timelineSnapshot->profile_projection_id,
                recommendationSnapshotUuid: (string) $timelineSnapshot->id,
                contextSnapshotUuid: is_string($timelineSnapshot->context_snapshot_id) ? $timelineSnapshot->context_snapshot_id : null,
                feedbackUuid: $feedback?->feedbackUuid,
                entryKind: $entryKind,
                entryLabel: $entryLabel,
                createdAt: optional($timelineSnapshot->created_at)->toISOString(),
            );
        }

        return new CareerProjectionTimeline(
            timelineKind: 'career_projection_timeline',
            timelineVersion: 'career.timeline.v1',
            currentProjectionUuid: is_string($snapshot->profile_projection_id) ? $snapshot->profile_projection_id : null,
            currentRecommendationSnapshotUuid: (string) $snapshot->id,
            entries: $entries,
        );
    }

    private function buildDeltaSummary(
        RecommendationSnapshot $snapshot,
        CareerProjectionTimeline $timeline,
    ): CareerProjectionDeltaSummary {
        $entries = $timeline->entries;
        if (count($entries) < 2) {
            return new CareerProjectionDeltaSummary(
                deltaAvailable: false,
                previousProjectionUuid: null,
                currentProjectionUuid: is_string($snapshot->profile_projection_id) ? $snapshot->profile_projection_id : null,
                scoreDeltas: [],
                feedbackDeltas: [],
                transitionChanged: false,
                targetJobsChanged: false,
                claimPermissionsChanged: [],
            );
        }

        $currentEntry = $entries[count($entries) - 1];
        $previousEntry = $entries[count($entries) - 2];

        /** @var RecommendationSnapshot|null $previousSnapshot */
        $previousSnapshot = RecommendationSnapshot::query()->find($previousEntry->recommendationSnapshotUuid);
        if (! $previousSnapshot instanceof RecommendationSnapshot) {
            return new CareerProjectionDeltaSummary(
                deltaAvailable: false,
                previousProjectionUuid: null,
                currentProjectionUuid: is_string($snapshot->profile_projection_id) ? $snapshot->profile_projection_id : null,
                scoreDeltas: [],
                feedbackDeltas: [],
                transitionChanged: false,
                targetJobsChanged: false,
                claimPermissionsChanged: [],
            );
        }

        $currentPayload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $previousPayload = is_array($previousSnapshot->snapshot_payload) ? $previousSnapshot->snapshot_payload : [];

        $scoreDeltas = [
            'fit_score' => $this->scoreDelta($previousPayload, $currentPayload, 'fit_score'),
            'strain_score' => $this->scoreDelta($previousPayload, $currentPayload, 'strain_score'),
            'ai_survival_score' => $this->scoreDelta($previousPayload, $currentPayload, 'ai_survival_score'),
            'mobility_score' => $this->scoreDelta($previousPayload, $currentPayload, 'mobility_score'),
            'confidence_score' => $this->scoreDelta($previousPayload, $currentPayload, 'confidence_score'),
        ];

        $currentFeedback = $this->feedbackValuesFromPayload($currentPayload);
        $previousFeedback = $this->feedbackValuesFromPayload($previousPayload);

        $currentTransitionTarget = TransitionPath::query()
            ->where('recommendation_snapshot_id', $snapshot->id)
            ->orderByDesc('created_at')
            ->value('to_occupation_id');
        $previousTransitionTarget = TransitionPath::query()
            ->where('recommendation_snapshot_id', $previousSnapshot->id)
            ->orderByDesc('created_at')
            ->value('to_occupation_id');

        return new CareerProjectionDeltaSummary(
            deltaAvailable: true,
            previousProjectionUuid: $previousEntry->projectionUuid,
            currentProjectionUuid: $currentEntry->projectionUuid,
            scoreDeltas: $scoreDeltas,
            feedbackDeltas: [
                'burnout_checkin' => $this->deltaValue($previousFeedback['burnout_checkin'], $currentFeedback['burnout_checkin']),
                'career_satisfaction' => $this->deltaValue($previousFeedback['career_satisfaction'], $currentFeedback['career_satisfaction']),
                'switch_urgency' => $this->deltaValue($previousFeedback['switch_urgency'], $currentFeedback['switch_urgency']),
            ],
            transitionChanged: $currentTransitionTarget !== $previousTransitionTarget,
            targetJobsChanged: $snapshot->occupation_id !== $previousSnapshot->occupation_id,
            claimPermissionsChanged: [
                'allow_strong_claim' => $this->claimChanged($previousPayload, $currentPayload, 'allow_strong_claim'),
                'allow_salary_comparison' => $this->claimChanged($previousPayload, $currentPayload, 'allow_salary_comparison'),
                'allow_ai_strategy' => $this->claimChanged($previousPayload, $currentPayload, 'allow_ai_strategy'),
                'allow_transition_recommendation' => $this->claimChanged($previousPayload, $currentPayload, 'allow_transition_recommendation'),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function resolveProjectionChain(?string $currentProjectionId): array
    {
        if (! is_string($currentProjectionId) || $currentProjectionId === '') {
            return [];
        }

        $chain = [];
        $cursor = $currentProjectionId;
        while (is_string($cursor) && $cursor !== '') {
            $chain[] = $cursor;
            /** @var ProjectionLineage|null $lineage */
            $lineage = ProjectionLineage::query()
                ->where('child_projection_id', $cursor)
                ->first();
            $parentId = is_string($lineage?->parent_projection_id) ? $lineage->parent_projection_id : null;
            if ($parentId === null) {
                break;
            }
            $cursor = $parentId;
        }

        return array_reverse($chain);
    }

    private function scoreDelta(array $previousPayload, array $currentPayload, string $scoreKey): array
    {
        $previous = $this->extractScore($previousPayload, $scoreKey);
        $current = $this->extractScore($currentPayload, $scoreKey);

        return [
            'previous' => $previous,
            'current' => $current,
            'delta' => $this->deltaValue($previous, $current),
        ];
    }

    private function extractScore(array $payload, string $scoreKey): ?float
    {
        $bundle = is_array($payload['score_bundle'] ?? null) ? $payload['score_bundle'] : [];
        $score = is_array($bundle[$scoreKey] ?? null) ? $bundle[$scoreKey] : [];
        $value = $score['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array{burnout_checkin: int|null, career_satisfaction: int|null, switch_urgency: int|null}
     */
    private function feedbackValuesFromPayload(array $payload): array
    {
        $feedback = is_array($payload['feedback_checkin'] ?? null) ? $payload['feedback_checkin'] : [];

        return [
            'burnout_checkin' => $this->normalizeScaleValue($feedback['burnout_checkin'] ?? null),
            'career_satisfaction' => $this->normalizeScaleValue($feedback['career_satisfaction'] ?? null),
            'switch_urgency' => $this->normalizeScaleValue($feedback['switch_urgency'] ?? null),
        ];
    }

    private function claimChanged(array $previousPayload, array $currentPayload, string $key): bool
    {
        $previousClaims = is_array($previousPayload['claim_permissions'] ?? null) ? $previousPayload['claim_permissions'] : [];
        $currentClaims = is_array($currentPayload['claim_permissions'] ?? null) ? $currentPayload['claim_permissions'] : [];

        return ($previousClaims[$key] ?? null) !== ($currentClaims[$key] ?? null);
    }

    private function deltaValue(?float $previous, ?float $current): ?float
    {
        if ($previous === null || $current === null) {
            return null;
        }

        return round($current - $previous, 2);
    }

    private function normalizeScaleValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;
        if ($normalized < 1 || $normalized > 5) {
            return null;
        }

        return $normalized;
    }

    private function scaleToDecimal(?int $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(($value - 1) / 4, 2);
    }

    private function findFeedbackByRecommendationSnapshotId(string $recommendationSnapshotId): ?CareerFeedbackRecordDto
    {
        /** @var CareerFeedbackRecord|null $record */
        $record = CareerFeedbackRecord::query()
            ->where('recommendation_snapshot_id', $recommendationSnapshotId)
            ->latest('created_at')
            ->first();

        if (! $record instanceof CareerFeedbackRecord) {
            return null;
        }

        return new CareerFeedbackRecordDto(
            feedbackUuid: (string) $record->id,
            subjectKind: (string) $record->subject_kind,
            subjectSlug: is_string($record->subject_slug) ? $record->subject_slug : null,
            burnoutCheckin: is_numeric($record->burnout_checkin) ? (int) $record->burnout_checkin : null,
            careerSatisfaction: is_numeric($record->career_satisfaction) ? (int) $record->career_satisfaction : null,
            switchUrgency: is_numeric($record->switch_urgency) ? (int) $record->switch_urgency : null,
            contextSnapshotUuid: is_string($record->context_snapshot_id) ? $record->context_snapshot_id : null,
            projectionUuid: is_string($record->profile_projection_id) ? $record->profile_projection_id : null,
            recommendationSnapshotUuid: is_string($record->recommendation_snapshot_id) ? $record->recommendation_snapshot_id : null,
            createdAt: optional($record->created_at)->toISOString(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'feedback_checkin' => null,
            'projection_timeline' => [
                'timeline_kind' => 'career_projection_timeline',
                'timeline_version' => 'career.timeline.v1',
                'current_projection_uuid' => null,
                'current_recommendation_snapshot_uuid' => null,
                'entries' => [],
            ],
            'projection_delta_summary' => [
                'delta_available' => false,
                'previous_projection_uuid' => null,
                'current_projection_uuid' => null,
                'score_deltas' => [],
                'feedback_deltas' => [],
                'transition_changed' => false,
                'target_jobs_changed' => false,
                'claim_permissions_changed' => [],
            ],
        ];
    }
}
