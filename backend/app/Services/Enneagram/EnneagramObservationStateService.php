<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

use App\Models\Attempt;
use App\Models\EnneagramObservationState;
use App\Models\ReportSnapshot;
use App\Models\Result;
use App\Services\Content\EnneagramPackLoader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class EnneagramObservationStateService
{
    public const VERSION = 'enneagram_observation_state.v1';

    /**
     * @var list<string>
     */
    public const SUPPORTED_STATUSES = [
        'initial_result',
        'observation_assigned',
        'observation_in_progress',
        'day3_feedback_submitted',
        'day7_feedback_submitted',
        'resonance_feedback_submitted',
        'user_confirmed',
        'user_disagreed',
        'fc144_recommended',
        'fc144_completed',
        'verified_by_followup',
        'historical_stable',
        'historical_superseded_but_preserved',
    ];

    public function __construct(
        private readonly EnneagramPackLoader $packLoader,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function view(Attempt $attempt, Result $result): array
    {
        $state = $this->findStoredState($attempt);

        return $this->contract($attempt, $result, $state);
    }

    /**
     * @return array<string,mixed>
     */
    public function assign(Attempt $attempt, Result $result): array
    {
        $state = $this->upsertState($attempt, $result, function (EnneagramObservationState $state, array $basis): void {
            $state->status = $state->assigned_at === null ? 'observation_assigned' : $this->normalizeStatus($state->status);
            $state->assigned_at = $state->assigned_at ?? now();
            $state->observation_completion_rate = max(0, (int) ($state->observation_completion_rate ?? 0));
            $state->suggested_next_action = $this->defaultSuggestedNextAction($basis);
        });

        return $this->contract($attempt, $result, $state);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function submitDay3(Attempt $attempt, Result $result, array $payload): array
    {
        $state = $this->upsertState($attempt, $result, function (EnneagramObservationState $state, array $basis) use ($payload): void {
            $storedPayload = is_array($state->payload_json) ? $state->payload_json : [];
            $storedPayload['day3_observation_feedback'] = [
                'more_like' => $this->nullableText($payload['more_like'] ?? null),
                'evidence_sentence' => $this->nullableText($payload['evidence_sentence'] ?? null),
                'confidence_self_rating' => isset($payload['confidence_self_rating']) ? (int) $payload['confidence_self_rating'] : null,
                'scene_type' => $this->nullableText($payload['scene_type'] ?? null),
            ];

            $state->payload_json = $storedPayload;
            $state->assigned_at = $state->assigned_at ?? now();
            $state->day3_submitted_at = now();
            $state->status = 'day3_feedback_submitted';
            $state->observation_completion_rate = max(50, (int) ($state->observation_completion_rate ?? 0));
            $state->resonance_score = $this->provisionalResonanceScore($payload);
            $state->suggested_next_action = $this->day3SuggestedNextAction($basis, $payload);
        });

        return $this->contract($attempt, $result, $state);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function submitDay7(Attempt $attempt, Result $result, array $payload): array
    {
        $state = $this->upsertState($attempt, $result, function (EnneagramObservationState $state, array $basis) use ($payload): void {
            $storedPayload = is_array($state->payload_json) ? $state->payload_json : [];
            $normalizedConfirmedType = $this->normalizeUserConfirmedType($payload['user_confirmed_type'] ?? null);
            $normalizedDisagreedReason = $this->nullableText($payload['user_disagreed_reason'] ?? null);

            $storedPayload['day7_resonance_feedback'] = [
                'final_resonance' => $this->nullableText($payload['final_resonance'] ?? null),
                'user_confirmed_type' => $normalizedConfirmedType,
                'wants_fc144' => (bool) ($payload['wants_fc144'] ?? false),
                'wants_retake_same_form' => (bool) ($payload['wants_retake_same_form'] ?? false),
                'user_disagreed_reason' => $normalizedDisagreedReason,
            ];

            $state->payload_json = $storedPayload;
            $state->assigned_at = $state->assigned_at ?? now();
            $state->day7_submitted_at = now();
            $state->resonance_feedback_submitted_at = now();
            $state->user_confirmed_type = $normalizedConfirmedType;
            $state->user_disagreed_reason = $normalizedDisagreedReason;
            $state->resonance_score = $this->finalResonanceScore($payload);
            $state->observation_completion_rate = 100;
            $state->status = $this->day7Status($basis, $payload, $normalizedConfirmedType, $normalizedDisagreedReason);
            $state->suggested_next_action = $this->day7SuggestedNextAction($basis, $payload, $normalizedConfirmedType, $normalizedDisagreedReason);
            if ($state->status === 'user_confirmed') {
                $state->user_confirmed_at = now();
            }
        });

        return $this->contract($attempt, $result, $state);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeForHistory(Attempt $attempt, ?Result $result): array
    {
        if (! $result instanceof Result) {
            return [];
        }

        $state = $this->findStoredState($attempt);
        $basis = $this->resolveBasis($attempt, $result);

        if (! $state instanceof EnneagramObservationState) {
            return [
                'version' => self::VERSION,
                'status' => 'initial_result',
                'observation_completion_rate' => 0,
                'user_confirmed_type' => null,
                'suggested_next_action' => $this->defaultSuggestedNextAction($basis),
                'day7_submitted' => false,
            ];
        }

        return [
            'version' => self::VERSION,
            'status' => $this->normalizeStatus($state->status),
            'observation_completion_rate' => (int) ($state->observation_completion_rate ?? 0),
            'user_confirmed_type' => $this->nullableText($state->user_confirmed_type),
            'suggested_next_action' => $this->nullableText($state->suggested_next_action) ?? $this->defaultSuggestedNextAction($basis),
            'day7_submitted' => $state->day7_submitted_at !== null,
        ];
    }

    private function findStoredState(Attempt $attempt): ?EnneagramObservationState
    {
        if (! Schema::hasTable('enneagram_observation_states')) {
            return null;
        }

        return EnneagramObservationState::query()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', (string) $attempt->id)
            ->first();
    }

    /**
     * @param  callable(EnneagramObservationState,array<string,mixed>):void  $mutator
     */
    private function upsertState(Attempt $attempt, Result $result, callable $mutator): EnneagramObservationState
    {
        $basis = $this->resolveBasis($attempt, $result);
        $state = $this->findStoredState($attempt) ?? new EnneagramObservationState([
            'org_id' => (int) ($attempt->org_id ?? 0),
            'attempt_id' => (string) $attempt->id,
            'scale_code' => 'ENNEAGRAM',
            'user_id' => $this->nullableText($attempt->user_id),
            'anon_id' => $this->nullableText($attempt->anon_id),
            'form_code' => $this->nullableText($basis['form_code'] ?? null),
            'interpretation_context_id' => $this->nullableText($basis['interpretation_context_id'] ?? null),
            'status' => 'initial_result',
            'payload_json' => [],
        ]);

        $state->user_id = $this->nullableText($attempt->user_id);
        $state->anon_id = $this->nullableText($attempt->anon_id);
        $state->form_code = $this->nullableText($basis['form_code'] ?? null);
        $state->interpretation_context_id = $this->nullableText($basis['interpretation_context_id'] ?? null);

        $mutator($state, $basis);
        $state->status = $this->normalizeStatus($state->status);
        $state->observation_completion_rate = max(0, min(100, (int) ($state->observation_completion_rate ?? 0)));
        $state->save();

        return $state->fresh() ?? $state;
    }

    /**
     * @return array<string,mixed>
     */
    private function contract(Attempt $attempt, Result $result, ?EnneagramObservationState $state): array
    {
        $basis = $this->resolveBasis($attempt, $result);
        $storedPayload = is_array($state?->payload_json) ? $state->payload_json : [];
        $tasks = $this->buildTasks($basis);
        $status = $state instanceof EnneagramObservationState
            ? $this->normalizeStatus($state->status)
            : 'initial_result';
        $suggestedNextAction = $state instanceof EnneagramObservationState
            ? $this->nullableText($state->suggested_next_action)
            : null;

        return [
            'observation_state_v1' => [
                'version' => self::VERSION,
                'attempt_id' => (string) ($attempt->id ?? ''),
                'scale_code' => 'ENNEAGRAM',
                'form_code' => $this->nullableText($basis['form_code'] ?? null),
                'interpretation_context_id' => $this->nullableText($basis['interpretation_context_id'] ?? null),
                'status' => $status,
                'interpretation_scope' => $this->nullableText($basis['interpretation_scope'] ?? null),
                'close_call_pair' => $this->closeCallPairSummary($basis),
                'tasks' => $tasks,
                'day3_observation_feedback' => is_array($storedPayload['day3_observation_feedback'] ?? null)
                    ? $storedPayload['day3_observation_feedback']
                    : null,
                'day7_resonance_feedback' => is_array($storedPayload['day7_resonance_feedback'] ?? null)
                    ? $storedPayload['day7_resonance_feedback']
                    : null,
                'user_confirmed_type' => $this->nullableText($state?->user_confirmed_type),
                'user_disagreed_reason' => $this->nullableText($state?->user_disagreed_reason),
                'resonance_score' => $state?->resonance_score !== null ? (int) $state->resonance_score : null,
                'observation_completion_rate' => $state instanceof EnneagramObservationState
                    ? (int) ($state->observation_completion_rate ?? 0)
                    : 0,
                'suggested_next_action' => $suggestedNextAction ?? $this->defaultSuggestedNextAction($basis),
                'created_at' => $this->dateString($state?->created_at),
                'updated_at' => $this->dateString($state?->updated_at),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveBasis(Attempt $attempt, Result $result): array
    {
        $projection = $this->extractProjectionV2($result->result_json);
        $snapshotBinding = [];
        if ($projection === []) {
            $snapshot = ReportSnapshot::query()
                ->where('org_id', (int) ($attempt->org_id ?? 0))
                ->where('attempt_id', (string) $attempt->id)
                ->where('status', 'ready')
                ->first();
            if ($snapshot instanceof ReportSnapshot) {
                $report = is_array($snapshot->report_full_json) ? $snapshot->report_full_json : [];
                if ($report === []) {
                    $report = is_array($snapshot->report_json) ? $snapshot->report_json : [];
                }
                $projection = $this->extractProjectionV2($report);
                $snapshotBinding = is_array(data_get($report, '_meta.snapshot_binding_v1'))
                    ? data_get($report, '_meta.snapshot_binding_v1')
                    : [];
            }
        }

        return [
            'form_code' => $this->nullableText(data_get($projection, 'form.form_code') ?? $attempt->form_code),
            'interpretation_context_id' => $this->nullableText(data_get($projection, 'content_binding.interpretation_context_id'))
                ?? $this->nullableText(data_get($snapshotBinding, 'interpretation_context_id')),
            'interpretation_scope' => $this->nullableText(data_get($projection, 'classification.interpretation_scope')) ?? 'clear',
            'confidence_level' => $this->nullableText(data_get($projection, 'classification.confidence_level')),
            'primary_candidate' => $this->normalizeTypeCode(data_get($projection, 'top_types.0.type_code')),
            'second_candidate' => $this->normalizeTypeCode(data_get($projection, 'top_types.1.type_code')),
            'third_candidate' => $this->normalizeTypeCode(data_get($projection, 'top_types.2.type_code')),
            'close_call_pair' => is_array(data_get($projection, 'dynamics.close_call_pair'))
                ? data_get($projection, 'dynamics.close_call_pair')
                : [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildTasks(array $basis): array
    {
        $registry = $this->packLoader->loadRegistryPack();
        $observationRegistry = is_array($registry['observation_registry'] ?? null) ? $registry['observation_registry'] : [];
        $entries = is_array($observationRegistry['entries'] ?? null) ? $observationRegistry['entries'] : [];
        $tasks = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $tasks[] = [
                'day' => (int) ($entry['day'] ?? 0),
                'phase' => $this->nullableText($entry['phase'] ?? null),
                'prompt' => $this->nullableText($entry['prompt'] ?? null),
                'user_input_schema' => is_array($entry['user_input_schema'] ?? null) ? $entry['user_input_schema'] : [],
                'event_key' => $this->nullableText($entry['analytics_event_key'] ?? null),
                'suggested_next_action' => $this->taskSuggestedNextAction($basis, $entry),
            ];
        }

        return $tasks;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function taskSuggestedNextAction(array $basis, array $entry): string
    {
        $phase = (string) ($entry['phase'] ?? '');
        if ($phase === 'day7_resonance') {
            return $this->defaultSuggestedNextAction($basis);
        }

        return 'observe_7_days';
    }

    /**
     * @return array<string,mixed>
     */
    private function extractProjectionV2(mixed $payload): array
    {
        $decoded = $this->decodeArray($payload);
        $candidates = [
            data_get($decoded, 'enneagram_public_projection_v2'),
            data_get($decoded, 'report._meta.enneagram_public_projection_v2'),
            data_get($decoded, '_meta.enneagram_public_projection_v2'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && (string) ($candidate['schema_version'] ?? '') === 'enneagram.public_projection.v2') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, self::SUPPORTED_STATUSES, true) ? $status : 'initial_result';
    }

    private function defaultSuggestedNextAction(array $basis): string
    {
        $scope = (string) ($basis['interpretation_scope'] ?? 'clear');
        $formCode = (string) ($basis['form_code'] ?? '');

        return match ($scope) {
            'low_quality' => 'retest_same_form',
            'diffuse' => 'read_top3',
            'close_call' => $formCode === 'enneagram_forced_choice_144' ? 'observe_7_days' : 'do_fc144',
            default => 'observe_7_days',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function day3SuggestedNextAction(array $basis, array $payload): string
    {
        $scope = (string) ($basis['interpretation_scope'] ?? 'clear');
        if ($scope === 'low_quality') {
            return 'retest_same_form';
        }

        $moreLike = (string) ($payload['more_like'] ?? '');
        if ($scope === 'close_call' && in_array($moreLike, ['top2', 'unclear', 'other'], true)) {
            return $this->defaultSuggestedNextAction($basis);
        }

        return $scope === 'diffuse' ? 'read_top3' : 'observe_7_days';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function provisionalResonanceScore(array $payload): int
    {
        return max(1, min(5, (int) ($payload['confidence_self_rating'] ?? 3)));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function finalResonanceScore(array $payload): int
    {
        return match ((string) ($payload['final_resonance'] ?? 'still_uncertain')) {
            'top1' => 5,
            'top2' => 4,
            'top3' => 3,
            'other' => 2,
            default => 1,
        };
    }

    /**
     * @param  array<string,mixed>  $basis
     * @param  array<string,mixed>  $payload
     */
    private function day7Status(array $basis, array $payload, ?string $confirmedType, ?string $disagreedReason): string
    {
        if ($confirmedType !== null) {
            return 'user_confirmed';
        }

        if ((string) ($payload['final_resonance'] ?? '') === 'other' || $disagreedReason !== null) {
            return 'user_disagreed';
        }

        if ((bool) ($payload['wants_fc144'] ?? false) && (string) ($basis['form_code'] ?? '') !== 'enneagram_forced_choice_144') {
            return 'fc144_recommended';
        }

        return 'resonance_feedback_submitted';
    }

    /**
     * @param  array<string,mixed>  $basis
     * @param  array<string,mixed>  $payload
     */
    private function day7SuggestedNextAction(array $basis, array $payload, ?string $confirmedType, ?string $disagreedReason): string
    {
        if ((bool) ($payload['wants_retake_same_form'] ?? false)) {
            return 'retest_same_form';
        }

        if ((bool) ($payload['wants_fc144'] ?? false) && (string) ($basis['form_code'] ?? '') !== 'enneagram_forced_choice_144') {
            return 'do_fc144';
        }

        if ($confirmedType !== null) {
            return 'no_action';
        }

        if ((string) ($payload['final_resonance'] ?? '') === 'still_uncertain') {
            return $this->defaultSuggestedNextAction($basis);
        }

        if ($disagreedReason !== null) {
            return (string) ($basis['form_code'] ?? '') === 'enneagram_forced_choice_144' ? 'read_top3' : 'do_fc144';
        }

        return $this->defaultSuggestedNextAction($basis);
    }

    /**
     * @return array<string,mixed>
     */
    private function closeCallPairSummary(array $basis): array
    {
        $pair = is_array($basis['close_call_pair'] ?? null) ? $basis['close_call_pair'] : [];

        return [
            'pair_key' => $this->nullableText($pair['pair_key'] ?? null),
            'type_a' => $this->nullableText($pair['type_a'] ?? null),
            'type_b' => $this->nullableText($pair['type_b'] ?? null),
        ];
    }

    private function normalizeUserConfirmedType(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^[1-9]$/', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^T([1-9])$/i', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeTypeCode(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));

        return preg_match('/^T[1-9]$/', $normalized) === 1 ? $normalized : null;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function dateString(null|Carbon|string $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}
