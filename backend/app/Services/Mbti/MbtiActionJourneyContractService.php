<?php

declare(strict_types=1);

namespace App\Services\Mbti;

final class MbtiActionJourneyContractService
{
    private const JOURNEY_VERSION = 'action_journey.v1';

    private const JOURNEY_FINGERPRINT_VERSION = 'action_journey.fingerprint.v1';

    private const PULSE_VERSION = 'pulse_check.v1';

    private const JOURNEY_SCOPE = 'result_revisit';

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function attach(array $personalization): array
    {
        if ($personalization === []) {
            return [];
        }

        $journey = $this->buildJourney($personalization);
        if ($journey === []) {
            return $personalization;
        }

        $personalization['action_journey_v1'] = $journey;

        $pulseCheck = $this->buildPulseCheck($personalization, $journey);
        if ($pulseCheck !== []) {
            $personalization['pulse_check_v1'] = $pulseCheck;
        }

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function buildJourney(array $personalization): array
    {
        $userState = is_array($personalization['user_state'] ?? null) ? $personalization['user_state'] : [];
        $continuity = is_array($personalization['continuity'] ?? null) ? $personalization['continuity'] : [];
        $workingLife = is_array($personalization['working_life_v1'] ?? null) ? $personalization['working_life_v1'] : [];

        $actionFocusKey = $this->normalizeText($personalization['action_focus_key'] ?? '');
        $actionPriorityKeys = $this->normalizeStringList($personalization['action_priority_keys'] ?? []);
        $carryoverActionKeys = $this->normalizeStringList($continuity['carryover_action_keys'] ?? []);
        $recommendedNextPulseKeys = $this->resolveRecommendedNextPulseKeys(
            $personalization,
            $userState,
            $continuity,
            $workingLife,
            $actionFocusKey,
            $actionPriorityKeys
        );

        $journeyState = $this->resolveJourneyState($userState, $continuity);
        $progressState = $this->resolveProgressState($userState);
        $completedActionKeys = $this->resolveCompletedActionKeys($userState, $carryoverActionKeys, $actionPriorityKeys);
        $revisitReorderReason = $this->resolveRevisitReorderReason($userState, $continuity, $journeyState);
        $lastPulseSignal = $this->resolveLastPulseSignal($userState, $continuity);

        return [
            'journey_contract_version' => self::JOURNEY_VERSION,
            'journey_fingerprint_version' => self::JOURNEY_FINGERPRINT_VERSION,
            'journey_fingerprint' => $this->buildJourneyFingerprint([
                'journey_scope' => self::JOURNEY_SCOPE,
                'journey_state' => $journeyState,
                'progress_state' => $progressState,
                'action_focus_key' => $actionFocusKey,
                'completed_action_keys' => $completedActionKeys,
                'recommended_next_pulse_keys' => $recommendedNextPulseKeys,
                'revisit_reorder_reason' => $revisitReorderReason,
                'last_pulse_signal' => $lastPulseSignal,
            ]),
            'journey_scope' => self::JOURNEY_SCOPE,
            'journey_state' => $journeyState,
            'progress_state' => $progressState,
            'action_focus_key' => $actionFocusKey,
            'completed_action_keys' => $completedActionKeys,
            'recommended_next_pulse_keys' => $recommendedNextPulseKeys,
            'action_priority_keys' => $actionPriorityKeys,
            'carryover_action_keys' => $carryoverActionKeys,
            'last_pulse_signal' => $lastPulseSignal,
            'revisit_reorder_reason' => $revisitReorderReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $journey
     * @return array<string, mixed>
     */
    public function buildPulseCheck(array $personalization, array $journey): array
    {
        if ($journey === []) {
            return [];
        }

        $pulseState = $this->resolvePulseState(
            $this->normalizeText($journey['journey_state'] ?? ''),
            $this->normalizeText($journey['progress_state'] ?? '')
        );
        $pulsePromptKeys = $this->resolvePulsePromptKeys(
            $pulseState,
            $this->normalizeText($journey['revisit_reorder_reason'] ?? ''),
            $this->normalizeText($journey['action_focus_key'] ?? '')
        );
        $nextPulseTarget = $this->normalizeText(
            $this->firstString($journey['recommended_next_pulse_keys'] ?? []),
            $journey['action_focus_key'] ?? '',
            $personalization['reading_focus_key'] ?? ''
        );

        return [
            'pulse_contract_version' => self::PULSE_VERSION,
            'pulse_state' => $pulseState,
            'pulse_prompt_keys' => $pulsePromptKeys,
            'pulse_feedback_mode' => 'event_feedback',
            'next_pulse_target' => $nextPulseTarget,
        ];
    }

    /**
     * @param  array<string, mixed>  $userState
     * @param  array<string, mixed>  $continuity
     */
    private function resolveJourneyState(array $userState, array $continuity): string
    {
        if (($userState['is_revisit'] ?? false) !== true) {
            return 'first_view_activation';
        }

        $feedbackSentiment = $this->normalizeText($userState['feedback_sentiment'] ?? '');
        $currentIntentCluster = $this->normalizeText($userState['current_intent_cluster'] ?? '');
        $carryoverReason = $this->normalizeText($continuity['carryover_reason'] ?? '');

        if (in_array($feedbackSentiment, ['negative', 'mixed'], true)) {
            return 'refine_after_feedback';
        }

        if ($currentIntentCluster === 'career_move') {
            return 'career_move';
        }

        if ($currentIntentCluster === 'relationship_tuning') {
            return 'relationship_tuning';
        }

        if (($userState['has_action_engagement'] ?? false) === true) {
            return 'resume_action_loop';
        }

        return match ($carryoverReason) {
            'continue_career_bridge' => 'career_bridge_resume',
            'continue_relationship_practice' => 'relationship_resume',
            'continue_explainability_focus' => 'clarify_resume',
            default => 'revisit_resume',
        };
    }

    /**
     * @param  array<string, mixed>  $userState
     */
    private function resolveProgressState(array $userState): string
    {
        return match ($this->normalizeText($userState['action_completion_tendency'] ?? '')) {
            'warming_up' => 'warming_up',
            'repeatable' => 'repeatable',
            'committed' => 'committed',
            default => 'not_started',
        };
    }

    /**
     * @param  list<string>  $carryoverActionKeys
     * @param  list<string>  $actionPriorityKeys
     * @return list<string>
     */
    private function resolveCompletedActionKeys(array $userState, array $carryoverActionKeys, array $actionPriorityKeys): array
    {
        if (($userState['has_action_engagement'] ?? false) !== true) {
            return [];
        }

        $progressState = $this->resolveProgressState($userState);
        $limit = match ($progressState) {
            'repeatable' => 1,
            'committed' => 2,
            default => 0,
        };
        if ($limit === 0) {
            return [];
        }

        $candidates = $carryoverActionKeys !== [] ? $carryoverActionKeys : $actionPriorityKeys;

        return array_values(array_slice($candidates, 0, $limit));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $userState
     * @param  array<string, mixed>  $continuity
     * @param  array<string, mixed>  $workingLife
     * @param  list<string>  $actionPriorityKeys
     * @return list<string>
     */
    private function resolveRecommendedNextPulseKeys(
        array $personalization,
        array $userState,
        array $continuity,
        array $workingLife,
        string $actionFocusKey,
        array $actionPriorityKeys
    ): array {
        $feedbackSentiment = $this->normalizeText($userState['feedback_sentiment'] ?? '');
        $currentIntentCluster = $this->normalizeText($userState['current_intent_cluster'] ?? '');
        $lastDeepReadSection = $this->normalizeText($userState['last_deep_read_section'] ?? '');
        $careerFocusKey = $this->normalizeText($workingLife['career_focus_key'] ?? '');
        $readingFocusKey = $this->normalizeText($personalization['reading_focus_key'] ?? '');
        $recommendedResumeKeys = $this->normalizeStringList($continuity['recommended_resume_keys'] ?? []);

        if (in_array($feedbackSentiment, ['negative', 'mixed'], true)) {
            return $this->uniqueStringList([
                $lastDeepReadSection,
                'growth.watchouts',
                $readingFocusKey,
            ]);
        }

        if ($currentIntentCluster === 'career_move') {
            return $this->uniqueStringList([
                $careerFocusKey,
                'career.next_step',
                'career.work_experiments',
            ]);
        }

        if ($currentIntentCluster === 'relationship_tuning') {
            return $this->uniqueStringList([
                'relationships.try_this_week',
                $actionFocusKey,
                'growth.weekly_experiments',
            ]);
        }

        if (($userState['has_action_engagement'] ?? false) === true) {
            return $this->uniqueStringList([
                $actionFocusKey,
                $this->firstString($actionPriorityKeys),
                $readingFocusKey,
            ]);
        }

        return $this->uniqueStringList([
            $this->firstString($recommendedResumeKeys),
            $actionFocusKey,
            'growth.next_actions',
        ]);
    }

    /**
     * @param  array<string, mixed>  $userState
     * @param  array<string, mixed>  $continuity
     */
    private function resolveRevisitReorderReason(array $userState, array $continuity, string $journeyState): string
    {
        if (($userState['is_revisit'] ?? false) !== true) {
            return 'initial_action_activation';
        }

        if ($journeyState === 'refine_after_feedback') {
            return 'reorder_after_feedback';
        }

        $currentIntentCluster = $this->normalizeText($userState['current_intent_cluster'] ?? '');
        if ($currentIntentCluster === 'career_move') {
            return 'reorder_for_career_move';
        }

        if ($currentIntentCluster === 'relationship_tuning') {
            return 'reorder_for_relationship_tuning';
        }

        if (($userState['has_action_engagement'] ?? false) === true) {
            return 'resume_action_loop';
        }

        return match ($this->normalizeText($continuity['carryover_reason'] ?? '')) {
            'continue_explainability_focus' => 'resume_explainability_focus',
            'continue_career_bridge' => 'resume_career_bridge',
            'continue_relationship_practice' => 'resume_relationship_practice',
            default => 'resume_previous_focus',
        };
    }

    /**
     * @param  array<string, mixed>  $userState
     * @param  array<string, mixed>  $continuity
     */
    private function resolveLastPulseSignal(array $userState, array $continuity): string
    {
        $feedbackSentiment = $this->normalizeText($userState['feedback_sentiment'] ?? '');
        if ($feedbackSentiment !== '' && $feedbackSentiment !== 'none') {
            return 'feedback:'.$feedbackSentiment;
        }

        $lastDeepReadSection = $this->normalizeText($userState['last_deep_read_section'] ?? '');
        if ($lastDeepReadSection !== '') {
            return 'deep_read:'.$lastDeepReadSection;
        }

        if (($userState['has_action_engagement'] ?? false) === true) {
            return 'action:'.$this->normalizeText($userState['action_completion_tendency'] ?? 'warming_up');
        }

        return 'carryover:'.$this->normalizeText($continuity['carryover_reason'] ?? 'baseline');
    }

    private function resolvePulseState(string $journeyState, string $progressState): string
    {
        if ($journeyState === 'first_view_activation') {
            return 'not_due';
        }

        if ($journeyState === 'refine_after_feedback') {
            return 'recalibrate';
        }

        return match ($progressState) {
            'repeatable' => 'reinforce',
            'committed' => 'advance',
            'warming_up' => 'check_in',
            default => 'start',
        };
    }

    /**
     * @return list<string>
     */
    private function resolvePulsePromptKeys(string $pulseState, string $revisitReorderReason, string $actionFocusKey): array
    {
        return match ($pulseState) {
            'recalibrate' => $this->uniqueStringList([
                'pulse.review_feedback_signal',
                $actionFocusKey,
                'pulse.refine_focus',
            ]),
            'reinforce' => $this->uniqueStringList([
                'pulse.repeat_winning_action',
                $actionFocusKey,
                'pulse.expand_scope',
            ]),
            'advance' => $this->uniqueStringList([
                'pulse.raise_scope',
                $actionFocusKey,
                'pulse.select_next_read',
            ]),
            'check_in' => $this->uniqueStringList([
                'pulse.check_small_signal',
                $actionFocusKey,
                $revisitReorderReason,
            ]),
            default => $this->uniqueStringList([
                'pulse.start_small',
                $actionFocusKey,
                $revisitReorderReason,
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildJourneyFingerprint(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return substr(sha1((string) $encoded), 0, 24);
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return $this->uniqueStringList($value);
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function uniqueStringList(iterable $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $text = $this->normalizeText($value);
            if ($text === '' || in_array($text, $normalized, true)) {
                continue;
            }

            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * @param  iterable<mixed>  $values
     */
    private function firstString(iterable $values): string
    {
        foreach ($values as $value) {
            $text = $this->normalizeText($value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function normalizeText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
