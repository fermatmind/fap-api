<?php

declare(strict_types=1);

namespace App\Services\Eq;

use App\Models\Attempt;
use App\Models\EqJourneyState;
use App\Models\Result;
use Illuminate\Support\Facades\Schema;

final class EqJourneyStateService
{
    public const VERSION = 'eq_journey_state.v1';

    private const SCALE_CODES = ['EQ_60', 'EQ_EMOTIONAL_INTELLIGENCE'];

    private const READ_DEPTHS = ['hero', 'evidence', 'matrix', 'mechanism', 'reality', 'career', 'action', 'boundary', 'complete'];

    private const RESONANCE_VALUES = ['strong', 'partial', 'low', 'uncertain'];

    private const ACTION_COMPLETION_VALUES = ['not_started', 'intended', 'started', 'completed', 'skipped'];

    private const RETEST_INTENTS = ['none', 'later', 'soon', 'after_practice'];

    /**
     * @return array<string,mixed>
     */
    public function view(Attempt $attempt, Result $result): array
    {
        return $this->contract($attempt, $result, $this->findStoredState($attempt), false);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function submit(Attempt $attempt, Result $result, array $payload): array
    {
        $basis = $this->resolveBasis($attempt, $result);
        $sanitized = $this->sanitizePayload($payload);
        $consentToStore = (bool) ($payload['consent_to_store'] ?? false);

        if (! $consentToStore || ! Schema::hasTable('eq_journey_states')) {
            return $this->contract($attempt, $result, null, true, [
                ...$sanitized,
                'consent_to_store' => $consentToStore,
                'persisted' => false,
                'status' => $this->statusFromPayload($sanitized, $basis),
            ]);
        }

        $state = $this->findStoredState($attempt) ?? new EqJourneyState([
            'org_id' => (int) ($attempt->org_id ?? 0),
            'attempt_id' => (string) $attempt->id,
            'scale_code' => $this->normalizeScaleCode((string) ($attempt->scale_code ?? 'EQ_60')),
            'eq_report_mode' => 'self_report',
            'status' => 'initial_result',
            'payload_json' => [],
        ]);

        $state->user_id = $this->nullableText($attempt->user_id);
        $state->anon_id = $this->nullableText($attempt->anon_id);
        $state->core_formulation_id = $this->nullableText($basis['core_formulation_id'] ?? null);
        $state->route_id = $this->nullableText($basis['route_id'] ?? null);
        $state->quality_level = $this->nullableText($basis['quality_level'] ?? null);
        $state->confidence_label = $this->nullableText($basis['confidence_label'] ?? null);
        $state->read_depth = $sanitized['read_depth'];
        $state->result_resonance = $sanitized['result_resonance'];
        $state->action_completion = $sanitized['action_completion'];
        $state->retest_intent = $sanitized['retest_intent'];
        $state->consent_to_store = true;
        $state->status = $this->statusFromPayload($sanitized, $basis);
        $state->payload_json = [
            'schema_version' => self::VERSION,
            'source_surface' => $sanitized['source_surface'],
            'primary_action_id' => $sanitized['primary_action_id'],
            'selected_scene_ids' => $sanitized['selected_scene_ids'],
            'stored_fields' => ['read_depth', 'result_resonance', 'action_completion', 'retest_intent'],
        ];

        if ($sanitized['result_resonance'] !== null) {
            $state->resonance_feedback_submitted_at = now();
        }
        if ($sanitized['action_completion'] === 'completed') {
            $state->action_completed_at = now();
        }
        if ($sanitized['retest_intent'] !== null && $sanitized['retest_intent'] !== 'none') {
            $state->retest_intent_recorded_at = now();
        }

        $state->save();

        return $this->contract($attempt, $result, $state->fresh() ?? $state, true);
    }

    private function findStoredState(Attempt $attempt): ?EqJourneyState
    {
        if (! Schema::hasTable('eq_journey_states')) {
            return null;
        }

        return EqJourneyState::query()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', (string) $attempt->id)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $transient
     * @return array<string,mixed>
     */
    private function contract(Attempt $attempt, Result $result, ?EqJourneyState $state, bool $submitted, array $transient = []): array
    {
        $basis = $this->resolveBasis($attempt, $result);
        $payload = is_array($state?->payload_json) ? $state->payload_json : [];
        $lowConfidence = $this->isLowConfidence($basis);

        $readDepth = $this->nullableText($transient['read_depth'] ?? null) ?? $this->nullableText($state?->read_depth);
        $resultResonance = $this->nullableText($transient['result_resonance'] ?? null) ?? $this->nullableText($state?->result_resonance);
        $actionCompletion = $this->nullableText($transient['action_completion'] ?? null) ?? $this->nullableText($state?->action_completion);
        $retestIntent = $this->nullableText($transient['retest_intent'] ?? null) ?? $this->nullableText($state?->retest_intent);
        $persisted = array_key_exists('persisted', $transient) ? (bool) $transient['persisted'] : $state instanceof EqJourneyState;

        return [
            'eq_journey_state_v1' => [
                'version' => self::VERSION,
                'attempt_id' => (string) ($attempt->id ?? ''),
                'scale_code' => $this->normalizeScaleCode((string) ($attempt->scale_code ?? 'EQ_60')),
                'eq_report_mode' => 'self_report',
                'status' => $this->nullableText($transient['status'] ?? null) ?? $this->nullableText($state?->status) ?? 'initial_result',
                'persisted' => $persisted,
                'consent' => [
                    'required_for_persistence' => true,
                    'consent_to_store' => array_key_exists('consent_to_store', $transient)
                        ? (bool) $transient['consent_to_store']
                        : (bool) ($state?->consent_to_store ?? false),
                ],
                'signals' => [
                    'read_depth' => $readDepth,
                    'result_resonance' => $resultResonance,
                    'action_completion' => $actionCompletion,
                    'retest_intent' => $retestIntent,
                    'primary_action_id' => $this->nullableText($transient['primary_action_id'] ?? null) ?? $this->nullableText($payload['primary_action_id'] ?? null),
                    'selected_scene_ids' => array_values(array_filter(array_map(
                        'strval',
                        (array) ($transient['selected_scene_ids'] ?? $payload['selected_scene_ids'] ?? [])
                    ))),
                ],
                'basis' => [
                    'core_formulation_id' => $this->nullableText($basis['core_formulation_id'] ?? null),
                    'route_id' => $this->nullableText($basis['route_id'] ?? null),
                    'quality_level' => $this->nullableText($basis['quality_level'] ?? null),
                    'confidence_label' => $this->nullableText($basis['confidence_label'] ?? null),
                ],
                'interpretation_guard' => [
                    'affects_scores' => false,
                    'formal_report_mutation_allowed' => false,
                    'raw_feedback_public_exposure_allowed' => false,
                    'profile_memory_write' => false,
                    'low_confidence_caution' => $lowConfidence,
                    'claim_boundary' => 'journey_feedback_is_user_reflection_not_measurement',
                ],
                'suggested_next_action' => $this->suggestedNextAction($readDepth, $resultResonance, $actionCompletion, $retestIntent, $lowConfidence),
                'submitted' => $submitted,
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
        $json = is_array($result->result_json) ? $result->result_json : [];

        return [
            'core_formulation_id' => $this->nullableText(data_get($json, 'interpretation.core_formulation_id'))
                ?? $this->nullableText(data_get($json, 'normed_json.interpretation.core_formulation_id')),
            'route_id' => $this->nullableText(data_get($json, 'interpretation.route_id'))
                ?? $this->nullableText(data_get($json, 'asset_refs.personalization_route_id')),
            'quality_level' => $this->nullableText(data_get($json, 'quality.level'))
                ?? $this->nullableText(data_get($json, 'normed_json.quality.level')),
            'confidence_label' => $this->nullableText(data_get($json, 'quality.confidence_label'))
                ?? $this->nullableText(data_get($json, 'normed_json.quality.confidence_label')),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        return [
            'read_depth' => $this->enumValue($payload['read_depth'] ?? null, self::READ_DEPTHS),
            'result_resonance' => $this->enumValue($payload['result_resonance'] ?? null, self::RESONANCE_VALUES),
            'action_completion' => $this->enumValue($payload['action_completion'] ?? null, self::ACTION_COMPLETION_VALUES),
            'retest_intent' => $this->enumValue($payload['retest_intent'] ?? null, self::RETEST_INTENTS),
            'source_surface' => $this->enumValue($payload['source_surface'] ?? null, ['result_page', 'share_page', 'email_revisit']) ?? 'result_page',
            'primary_action_id' => $this->safeIdentifier($payload['primary_action_id'] ?? null),
            'selected_scene_ids' => $this->safeIdentifierList($payload['selected_scene_ids'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $basis
     */
    private function statusFromPayload(array $payload, array $basis): string
    {
        if ($this->isLowConfidence($basis)) {
            return 'low_confidence_reflection';
        }
        if (($payload['action_completion'] ?? null) === 'completed') {
            return 'action_completed';
        }
        if (($payload['retest_intent'] ?? null) !== null && ($payload['retest_intent'] ?? null) !== 'none') {
            return 'retest_planned';
        }
        if (($payload['result_resonance'] ?? null) !== null) {
            return 'resonance_submitted';
        }
        if (($payload['read_depth'] ?? null) !== null) {
            return 'reading_in_progress';
        }

        return 'initial_result';
    }

    private function suggestedNextAction(?string $readDepth, ?string $resultResonance, ?string $actionCompletion, ?string $retestIntent, bool $lowConfidence): string
    {
        if ($lowConfidence) {
            return 'retest_reflection';
        }
        if ($actionCompletion === 'completed') {
            return 'schedule_retest_after_practice';
        }
        if ($actionCompletion === 'started') {
            return 'continue_seven_day_practice';
        }
        if ($resultResonance === 'low' || $resultResonance === 'uncertain') {
            return 'review_evidence_before_action';
        }
        if ($retestIntent === 'soon' || $retestIntent === 'after_practice') {
            return 'save_retest_intent';
        }
        if ($readDepth === 'complete') {
            return 'choose_one_action';
        }

        return 'read_action_prescription';
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enumValue(mixed $value, array $allowed): ?string
    {
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }

        return in_array($text, $allowed, true) ? $text : null;
    }

    private function isLowConfidence(array $basis): bool
    {
        $core = strtolower(trim((string) ($basis['core_formulation_id'] ?? '')));
        $quality = strtoupper(trim((string) ($basis['quality_level'] ?? '')));
        $confidence = strtolower(trim((string) ($basis['confidence_label'] ?? '')));

        return $core === 'low_confidence_result'
            || in_array($quality, ['C', 'D'], true)
            || in_array($confidence, ['low', 'very_low'], true);
    }

    private function normalizeScaleCode(string $scaleCode): string
    {
        $upper = strtoupper(trim($scaleCode));

        return in_array($upper, self::SCALE_CODES, true) ? $upper : 'EQ_60';
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function safeIdentifier(mixed $value): ?string
    {
        $text = $this->nullableText($value);
        if ($text === null) {
            return null;
        }

        return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,95}$/i', $text) === 1 ? $text : null;
    }

    /**
     * @return list<string>
     */
    private function safeIdentifierList(mixed $value): array
    {
        $items = [];
        foreach ((array) $value as $item) {
            $identifier = $this->safeIdentifier($item);
            if ($identifier !== null) {
                $items[] = $identifier;
            }
        }

        return array_values(array_unique(array_slice($items, 0, 6)));
    }

    private function dateString(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'toISOString') ? $date->toISOString() : null;
    }
}
