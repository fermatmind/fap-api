<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use Illuminate\Support\Facades\DB;

final class MbtiUserStateOrchestrationService
{
    /**
     * @var list<string>
     */
    private const POSITIVE_FEEDBACK_VALUES = ['accurate', 'helpful_action'];

    /**
     * @var list<string>
     */
    private const MIXED_FEEDBACK_VALUES = ['mixed', 'not_now'];

    /**
     * @var list<string>
     */
    private const NEGATIVE_FEEDBACK_VALUES = ['unclear'];

    /**
     * @var array<string, list<string>>
     */
    private const CHAPTER_SECTIONS = [
        'career' => [
            'career.summary',
            'career.collaboration_fit',
            'career.work_environment',
            'career.work_experiments',
            'career.advantages',
            'career.weaknesses',
            'career.preferred_roles',
            'career.next_step',
            'career.upgrade_suggestions',
        ],
        'growth' => [
            'growth.summary',
            'growth.stability_confidence',
            'growth.next_actions',
            'growth.weekly_experiments',
            'growth.strengths',
            'growth.weaknesses',
            'growth.stress_recovery',
            'growth.watchouts',
            'growth.motivators',
            'growth.drainers',
        ],
        'traits' => [
            'letters_intro',
            'overview',
            'trait_overview',
            'traits.why_this_type',
            'traits.close_call_axes',
            'traits.adjacent_type_contrast',
            'traits.decision_style',
        ],
        'relationships' => [
            'relationships.summary',
            'relationships.strengths',
            'relationships.weaknesses',
            'relationships.communication_style',
            'relationships.try_this_week',
            'relationships.rel_advantages',
            'relationships.rel_risks',
        ],
    ];

    /**
     * @var list<string>
     */
    private const SECTION_FOCUS_FIRST_VIEW = [
        'traits.close_call_axes',
        'traits.adjacent_type_contrast',
        'career.work_experiments',
        'relationships.try_this_week',
        'growth.watchouts',
    ];

    /**
     * @var list<string>
     */
    private const SECTION_FOCUS_REVISIT = [
        'career.work_experiments',
        'relationships.try_this_week',
        'growth.watchouts',
        'traits.close_call_axes',
        'traits.adjacent_type_contrast',
    ];

    /**
     * @var list<string>
     */
    private const ACTION_SECTION_KEYS = [
        'growth.next_actions',
        'growth.weekly_experiments',
        'relationships.try_this_week',
        'career.work_experiments',
        'growth.watchouts',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const SECTION_CARRYOVER_SCENE_KEYS = [
        'career.summary' => ['work'],
        'career.collaboration_fit' => ['work', 'communication'],
        'career.work_environment' => ['work'],
        'career.work_experiments' => ['work', 'decision'],
        'career.next_step' => ['work', 'decision'],
        'growth.summary' => ['growth'],
        'growth.stability_confidence' => ['stability'],
        'growth.next_actions' => ['growth'],
        'growth.weekly_experiments' => ['growth'],
        'growth.stress_recovery' => ['stress_recovery'],
        'growth.watchouts' => ['stress_recovery', 'growth'],
        'traits.why_this_type' => ['explainability'],
        'traits.close_call_axes' => ['explainability'],
        'traits.adjacent_type_contrast' => ['explainability'],
        'traits.decision_style' => ['decision'],
        'relationships.communication_style' => ['communication', 'relationships'],
        'relationships.try_this_week' => ['relationships', 'communication'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const SECTION_CARRYOVER_ACTION_FIELDS = [
        'career.next_step' => ['career_next_step_keys'],
        'career.work_experiments' => ['work_experiment_keys'],
        'growth.next_actions' => ['weekly_action_keys'],
        'growth.weekly_experiments' => ['weekly_action_keys'],
        'growth.stability_confidence' => ['watchout_keys'],
        'growth.watchouts' => ['watchout_keys'],
        'relationships.try_this_week' => ['relationship_action_keys'],
    ];

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function withBaseline(array $personalization, bool $hasUnlock): array
    {
        if ($personalization === []) {
            return [];
        }

        return $this->mergeAuthority($personalization, $this->buildBaselineUserState($hasUnlock));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function overlayEffective(array $personalization, int $orgId, string $attemptId, bool $hasUnlock): array
    {
        if ($personalization === []) {
            return [];
        }

        if ($attemptId === '') {
            return $this->withBaseline($personalization, $hasUnlock);
        }

        $eventRows = $this->fetchAttemptEvents($orgId, $attemptId);
        $isRevisit = $this->eventRowsContainAny($eventRows, ['result_view', 'report_view']);
        $hasFeedback = $this->eventRowsContainAny($eventRows, ['accuracy_feedback']);
        $hasShare = $this->eventRowsContainAny($eventRows, ['share_result']) || $this->attemptHasShareRow($attemptId);
        $hasActionEngagement = $this->eventRowsContainActionEngagement($eventRows);
        $feedbackSentiment = $this->resolveFeedbackSentiment($eventRows);
        $feedbackCoverage = $this->resolveFeedbackCoverage($eventRows);
        $actionCompletionTendency = $this->resolveActionCompletionTendency(
            $eventRows,
            $isRevisit,
            $hasUnlock
        );
        $lastDeepReadSection = $this->resolveLastDeepReadSection($eventRows);
        $currentIntentCluster = $this->resolveCurrentIntentCluster(
            $eventRows,
            $hasUnlock,
            $feedbackSentiment,
            $feedbackCoverage,
            $actionCompletionTendency,
            $lastDeepReadSection,
            $hasActionEngagement
        );

        $userState = array_merge($this->buildBaselineUserState($hasUnlock), [
            'is_first_view' => ! $isRevisit,
            'is_revisit' => $isRevisit,
            'has_feedback' => $hasFeedback,
            'has_share' => $hasShare,
            'has_action_engagement' => $hasActionEngagement,
            'feedback_sentiment' => $feedbackSentiment,
            'feedback_coverage' => $feedbackCoverage,
            'action_completion_tendency' => $actionCompletionTendency,
            'last_deep_read_section' => $lastDeepReadSection,
            'current_intent_cluster' => $currentIntentCluster,
        ]);

        return $this->mergeAuthority($personalization, $userState);
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, bool>  $userState
     * @return array<string, mixed>
     */
    private function mergeAuthority(array $personalization, array $userState): array
    {
        $primaryFocusKey = $this->resolvePrimaryFocusKey($personalization, $userState);
        $secondaryFocusKeys = $this->resolveSecondaryFocusKeys($primaryFocusKey, $userState);
        $orderedRecommendationKeys = $this->resolveOrderedRecommendationKeys(
            $personalization,
            $primaryFocusKey,
            $secondaryFocusKeys,
            $userState
        );
        $orderedActionKeys = $this->resolveOrderedActionKeys(
            $personalization,
            $primaryFocusKey,
            $secondaryFocusKeys,
            $userState
        );
        $continuity = $this->resolveContinuity($personalization, $userState, $primaryFocusKey, $secondaryFocusKeys);

        return array_merge($personalization, [
            'user_state' => $userState,
            'orchestration' => [
                'ordered_section_keys' => $this->resolveOrderedSectionKeys($primaryFocusKey, $secondaryFocusKeys),
                'primary_focus_key' => $primaryFocusKey,
                'secondary_focus_keys' => $secondaryFocusKeys,
                'cta_priority_keys' => $this->resolveCtaPriorityKeys($userState, $primaryFocusKey),
            ],
            'ordered_recommendation_keys' => $orderedRecommendationKeys,
            'ordered_action_keys' => $orderedActionKeys,
            'recommendation_priority_keys' => array_values(array_slice($orderedRecommendationKeys, 0, 3)),
            'action_priority_keys' => array_values(array_slice($orderedActionKeys, 0, 4)),
            'reading_focus_key' => (string) ($orderedRecommendationKeys[0] ?? ''),
            'action_focus_key' => (string) ($orderedActionKeys[0] ?? ''),
            'continuity' => $continuity,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBaselineUserState(bool $hasUnlock): array
    {
        return [
            'is_first_view' => true,
            'is_revisit' => false,
            'has_unlock' => $hasUnlock,
            'has_feedback' => false,
            'has_share' => false,
            'has_action_engagement' => false,
            'feedback_sentiment' => 'none',
            'feedback_coverage' => 'none',
            'action_completion_tendency' => 'idle',
            'last_deep_read_section' => '',
            'current_intent_cluster' => 'default',
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, bool>  $userState
     * @param  list<string>  $secondaryFocusKeys
     * @return array<string, mixed>
     */
    private function resolveContinuity(
        array $personalization,
        array $userState,
        string $primaryFocusKey,
        array $secondaryFocusKeys
    ): array {
        return [
            'carryover_focus_key' => $primaryFocusKey,
            'carryover_reason' => $this->resolveCarryoverReason($primaryFocusKey, $userState),
            'recommended_resume_keys' => $this->resolveRecommendedResumeKeys($primaryFocusKey, $secondaryFocusKeys, $userState),
            'carryover_scene_keys' => $this->resolveCarryoverSceneKeys($personalization, $primaryFocusKey, $secondaryFocusKeys),
            'carryover_action_keys' => $this->resolveCarryoverActionKeys($personalization, $primaryFocusKey, $secondaryFocusKeys),
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, bool>  $userState
     */
    private function resolvePrimaryFocusKey(array $personalization, array $userState): string
    {
        $feedbackSentiment = trim((string) ($userState['feedback_sentiment'] ?? ''));
        $feedbackCoverage = trim((string) ($userState['feedback_coverage'] ?? ''));
        $actionCompletionTendency = trim((string) ($userState['action_completion_tendency'] ?? ''));
        $lastDeepReadSection = trim((string) ($userState['last_deep_read_section'] ?? ''));
        $currentIntentCluster = trim((string) ($userState['current_intent_cluster'] ?? ''));
        $hasUnlock = (bool) ($userState['has_unlock'] ?? false);

        if (
            in_array($feedbackSentiment, ['negative', 'mixed'], true)
            && in_array($feedbackCoverage, ['scene_only', 'explainability_only', 'mixed'], true)
        ) {
            if ($this->isKnownSectionKey($lastDeepReadSection) && $this->isClarifySection($lastDeepReadSection)) {
                return $lastDeepReadSection;
            }

            return 'traits.close_call_axes';
        }

        if ($currentIntentCluster === 'career_move') {
            return $hasUnlock ? 'career.work_experiments' : 'career.next_step';
        }

        if ($currentIntentCluster === 'relationship_tuning') {
            return 'relationships.try_this_week';
        }

        if (
            $currentIntentCluster === 'action_activation'
            && in_array($actionCompletionTendency, ['repeatable', 'committed'], true)
        ) {
            if ($this->isKnownSectionKey($lastDeepReadSection) && in_array($lastDeepReadSection, self::ACTION_SECTION_KEYS, true)) {
                return $lastDeepReadSection;
            }

            return $hasUnlock ? 'career.work_experiments' : 'growth.weekly_experiments';
        }

        if ($currentIntentCluster === 'deep_reading' && $this->isKnownSectionKey($lastDeepReadSection)) {
            return $lastDeepReadSection;
        }

        if (($userState['has_feedback'] ?? false) && $this->isLowConfidencePath($personalization)) {
            return 'growth.stability_confidence';
        }

        if (($userState['is_revisit'] ?? false) === false) {
            return $hasUnlock ? 'career.next_step' : 'growth.next_actions';
        }

        if ($userState['has_action_engagement'] ?? false) {
            return 'growth.watchouts';
        }

        return 'growth.weekly_experiments';
    }

    /**
     * @param  array<string, bool>  $userState
     * @return list<string>
     */
    private function resolveSecondaryFocusKeys(string $primaryFocusKey, array $userState): array
    {
        $candidates = [];
        $currentIntentCluster = trim((string) ($userState['current_intent_cluster'] ?? ''));
        $lastDeepReadSection = trim((string) ($userState['last_deep_read_section'] ?? ''));

        if ($this->isKnownSectionKey($lastDeepReadSection) && $lastDeepReadSection !== $primaryFocusKey) {
            $candidates[] = $lastDeepReadSection;
        }

        $intentCandidates = match ($currentIntentCluster) {
            'career_move' => ['career.next_step', 'growth.weekly_experiments', 'relationships.try_this_week'],
            'relationship_tuning' => ['relationships.communication_style', 'growth.watchouts', 'career.work_experiments'],
            'clarify_type' => ['traits.adjacent_type_contrast', 'growth.stability_confidence', 'career.work_experiments'],
            'action_activation' => ['growth.weekly_experiments', 'career.work_experiments', 'relationships.try_this_week'],
            'deep_reading' => ['career.work_experiments', 'traits.close_call_axes', 'growth.watchouts'],
            default => [],
        };

        foreach ($intentCandidates as $candidate) {
            if ($this->isKnownSectionKey($candidate)) {
                $candidates[] = $candidate;
            }
        }

        $fallbackCandidates = ($userState['is_revisit'] ?? false) ? self::SECTION_FOCUS_REVISIT : self::SECTION_FOCUS_FIRST_VIEW;
        foreach ($fallbackCandidates as $candidate) {
            $candidates[] = $candidate;
        }

        $selected = [];

        foreach ($candidates as $candidate) {
            if ($candidate === $primaryFocusKey || in_array($candidate, $selected, true)) {
                continue;
            }

            $selected[] = $candidate;
            if (count($selected) >= 2) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param  list<string>  $secondaryFocusKeys
     * @return list<string>
     */
    private function resolveOrderedSectionKeys(string $primaryFocusKey, array $secondaryFocusKeys): array
    {
        $ordered = [];

        foreach (self::CHAPTER_SECTIONS as $sections) {
            $chapterSections = $sections;

            if (in_array($primaryFocusKey, $chapterSections, true)) {
                $chapterSections = $this->promoteSection($chapterSections, $primaryFocusKey, 0);
            }

            $insertionIndex = in_array($primaryFocusKey, $chapterSections, true) ? 1 : 0;
            foreach ($secondaryFocusKeys as $secondaryFocusKey) {
                if (! in_array($secondaryFocusKey, $chapterSections, true)) {
                    continue;
                }

                $chapterSections = $this->promoteSection($chapterSections, $secondaryFocusKey, $insertionIndex);
                $insertionIndex++;
            }

            $ordered = array_merge($ordered, $chapterSections);
        }

        return array_values(array_unique(array_filter($ordered)));
    }

    /**
     * @param  array<string, bool>  $userState
     * @return list<string>
     */
    private function resolveCtaPriorityKeys(array $userState, string $primaryFocusKey): array
    {
        $hasUnlock = (bool) ($userState['has_unlock'] ?? false);
        $isRevisit = (bool) ($userState['is_revisit'] ?? false);
        $hasFeedback = (bool) ($userState['has_feedback'] ?? false);
        $hasShare = (bool) ($userState['has_share'] ?? false);
        $hasActionEngagement = (bool) ($userState['has_action_engagement'] ?? false);
        $feedbackSentiment = trim((string) ($userState['feedback_sentiment'] ?? ''));
        $actionCompletionTendency = trim((string) ($userState['action_completion_tendency'] ?? ''));
        $currentIntentCluster = trim((string) ($userState['current_intent_cluster'] ?? ''));

        if ($currentIntentCluster === 'career_move' || str_starts_with($primaryFocusKey, 'career.')) {
            return $hasUnlock
                ? ['career_bridge', 'workspace_lite', 'share_result']
                : ['career_bridge', 'unlock_full_report', 'share_result'];
        }

        if ($currentIntentCluster === 'clarify_type' || in_array($feedbackSentiment, ['negative', 'mixed'], true)) {
            return $hasUnlock
                ? ['workspace_lite', 'career_bridge', 'share_result']
                : ['unlock_full_report', 'share_result', 'career_bridge'];
        }

        if (
            $currentIntentCluster === 'action_activation'
            && in_array($actionCompletionTendency, ['repeatable', 'committed'], true)
        ) {
            return $hasUnlock
                ? ['workspace_lite', 'career_bridge', 'share_result']
                : ['career_bridge', 'unlock_full_report', 'share_result'];
        }

        if (! $hasUnlock) {
            if ($isRevisit && ($hasFeedback || $hasShare)) {
                return ['career_bridge', 'unlock_full_report', 'share_result'];
            }

            return ['unlock_full_report', 'career_bridge', 'share_result'];
        }

        if ($isRevisit && $hasActionEngagement) {
            return ['career_bridge', 'workspace_lite', 'share_result'];
        }

        return ['career_bridge', 'share_result', 'workspace_lite'];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $secondaryFocusKeys
     * @return list<string>
     */
    private function resolveOrderedRecommendationKeys(
        array $personalization,
        string $primaryFocusKey,
        array $secondaryFocusKeys,
        array $userState
    ): array {
        $candidates = $this->normalizeRecommendationCandidates(
            (array) ($personalization['recommended_read_candidates'] ?? [])
        );

        if ($candidates === []) {
            return [];
        }

        $primaryThemes = $this->resolveRecommendationThemesForState($primaryFocusKey, $userState);
        $secondaryThemes = [];

        foreach ($secondaryFocusKeys as $secondaryFocusKey) {
            foreach ($this->resolveRecommendationThemesForFocus($secondaryFocusKey) as $theme) {
                if (! in_array($theme, $secondaryThemes, true)) {
                    $secondaryThemes[] = $theme;
                }
            }
        }

        usort($candidates, function (array $left, array $right) use ($primaryThemes, $secondaryThemes): int {
            $leftScore = $this->scoreRecommendationCandidate($left, $primaryThemes, $secondaryThemes);
            $rightScore = $this->scoreRecommendationCandidate($right, $primaryThemes, $secondaryThemes);

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            $leftPriority = (int) ($left['priority'] ?? 0);
            $rightPriority = (int) ($right['priority'] ?? 0);
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });

        return array_values(array_unique(array_filter(array_map(
            static fn (array $candidate): string => trim((string) ($candidate['key'] ?? '')),
            $candidates
        ))));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $secondaryFocusKeys
     * @return list<string>
     */
    private function resolveOrderedActionKeys(
        array $personalization,
        string $primaryFocusKey,
        array $secondaryFocusKeys,
        array $userState
    ): array {
        $orderedFields = $this->resolveActionFieldOrder($primaryFocusKey, $secondaryFocusKeys, $userState);
        $ordered = [];

        foreach ($orderedFields as $field) {
            $preferredKey = $this->selectPreferredCarryoverActionKey((array) ($personalization[$field] ?? []));
            if ($preferredKey !== '' && ! in_array($preferredKey, $ordered, true)) {
                $ordered[] = $preferredKey;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $secondaryFocusKeys
     * @return list<string>
     */
    private function resolveActionFieldOrder(string $primaryFocusKey, array $secondaryFocusKeys, array $userState): array
    {
        $ordered = [];
        $currentIntentCluster = trim((string) ($userState['current_intent_cluster'] ?? ''));
        $actionCompletionTendency = trim((string) ($userState['action_completion_tendency'] ?? ''));

        $intentHints = match ($currentIntentCluster) {
            'career_move' => ['work_experiment_keys', 'weekly_action_keys'],
            'relationship_tuning' => ['relationship_action_keys', 'weekly_action_keys'],
            'clarify_type' => ['watchout_keys', 'weekly_action_keys'],
            'action_activation' => in_array($actionCompletionTendency, ['repeatable', 'committed'], true)
                ? ['work_experiment_keys', 'weekly_action_keys', 'relationship_action_keys']
                : ['weekly_action_keys', 'watchout_keys'],
            default => [],
        };

        foreach ($intentHints as $field) {
            if (! in_array($field, $ordered, true)) {
                $ordered[] = $field;
            }
        }

        foreach ($this->resolvePrimaryActionFieldHints($primaryFocusKey) as $field) {
            if (! in_array($field, $ordered, true)) {
                $ordered[] = $field;
            }
        }

        foreach ($secondaryFocusKeys as $secondaryFocusKey) {
            foreach ($this->resolveSecondaryActionFieldHints($secondaryFocusKey) as $field) {
                if (! in_array($field, $ordered, true)) {
                    $ordered[] = $field;
                }
            }
        }

        foreach (['weekly_action_keys', 'work_experiment_keys', 'relationship_action_keys', 'watchout_keys'] as $field) {
            if (! in_array($field, $ordered, true)) {
                $ordered[] = $field;
            }
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function resolvePrimaryActionFieldHints(string $primaryFocusKey): array
    {
        return match (true) {
            str_starts_with($primaryFocusKey, 'career.') => ['work_experiment_keys', 'weekly_action_keys'],
            str_starts_with($primaryFocusKey, 'relationships.') => ['relationship_action_keys', 'weekly_action_keys'],
            $primaryFocusKey === 'growth.stability_confidence',
            $primaryFocusKey === 'growth.watchouts' => ['watchout_keys', 'weekly_action_keys'],
            str_starts_with($primaryFocusKey, 'traits.') => ['weekly_action_keys', 'watchout_keys'],
            default => ['weekly_action_keys', 'work_experiment_keys'],
        };
    }

    /**
     * @return list<string>
     */
    private function resolveSecondaryActionFieldHints(string $sectionKey): array
    {
        return match ($sectionKey) {
            'career.work_experiments' => ['work_experiment_keys'],
            'relationships.try_this_week' => ['relationship_action_keys'],
            'growth.watchouts', 'growth.stability_confidence' => ['watchout_keys'],
            'growth.next_actions', 'growth.weekly_experiments' => ['weekly_action_keys'],
            default => [],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function normalizeRecommendationCandidates(array $candidates): array
    {
        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $key = trim((string) ($candidate['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'type' => trim((string) ($candidate['type'] ?? '')),
                'title' => trim((string) ($candidate['title'] ?? '')),
                'priority' => is_numeric($candidate['priority'] ?? null) ? (int) round((float) $candidate['priority']) : 0,
                'tags' => array_values(array_filter(array_map(
                    static fn (mixed $tag): string => trim((string) $tag),
                    is_array($candidate['tags'] ?? null) ? $candidate['tags'] : []
                ))),
                'url' => trim((string) ($candidate['url'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function resolveRecommendationThemesForFocus(string $focusKey): array
    {
        return match (true) {
            str_starts_with($focusKey, 'career.') => ['career', 'work', 'action'],
            str_starts_with($focusKey, 'relationships.') => ['relationship', 'communication', 'action'],
            $focusKey === 'growth.stability_confidence',
            $focusKey === 'growth.watchouts' => ['stability', 'action', 'growth'],
            str_starts_with($focusKey, 'traits.') => ['explainability', 'growth'],
            default => ['action', 'growth', 'career'],
        };
    }

    /**
     * @param  array<string, mixed>  $userState
     * @return list<string>
     */
    private function resolveRecommendationThemesForState(string $focusKey, array $userState): array
    {
        $currentIntentCluster = trim((string) ($userState['current_intent_cluster'] ?? ''));
        $feedbackSentiment = trim((string) ($userState['feedback_sentiment'] ?? ''));
        $feedbackCoverage = trim((string) ($userState['feedback_coverage'] ?? ''));

        if ($currentIntentCluster === 'career_move') {
            return ['career', 'work', 'action'];
        }

        if ($currentIntentCluster === 'relationship_tuning') {
            return ['relationship', 'communication', 'action'];
        }

        if (
            $currentIntentCluster === 'clarify_type'
            || (
                in_array($feedbackSentiment, ['negative', 'mixed'], true)
                && in_array($feedbackCoverage, ['scene_only', 'explainability_only', 'mixed'], true)
            )
        ) {
            return ['explainability', 'stability', 'growth'];
        }

        if ($currentIntentCluster === 'action_activation') {
            return ['action', 'growth', 'career'];
        }

        return $this->resolveRecommendationThemesForFocus($focusKey);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $primaryThemes
     * @param  list<string>  $secondaryThemes
     */
    private function scoreRecommendationCandidate(array $candidate, array $primaryThemes, array $secondaryThemes): int
    {
        $themes = $this->classifyRecommendationCandidateThemes($candidate);
        $score = 0;

        foreach ($primaryThemes as $index => $theme) {
            if (in_array($theme, $themes, true)) {
                $score += max(0, 140 - ($index * 10));
            }
        }

        foreach ($secondaryThemes as $index => $theme) {
            if (in_array($theme, $themes, true)) {
                $score += max(0, 70 - ($index * 5));
            }
        }

        $priority = (int) ($candidate['priority'] ?? 0);
        $score += max(0, 50 - min(50, $priority));

        return $score;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function classifyRecommendationCandidateThemes(array $candidate): array
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($candidate['key'] ?? ''),
            (string) ($candidate['type'] ?? ''),
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['url'] ?? ''),
            implode(' ', array_map(static fn (mixed $tag): string => trim((string) $tag), (array) ($candidate['tags'] ?? []))),
        ])));

        $themes = [];
        $matchers = [
            'career' => ['career', 'job', 'role', 'work', 'occupation', '职业', '岗位', '工作'],
            'work' => ['work', 'career', 'job', 'team', 'workspace', '工作', '职场', '团队'],
            'relationship' => ['relationship', 'communication', 'boundary', 'connection', '沟通', '关系', '边界', '相处'],
            'communication' => ['communication', 'conversation', 'feedback', 'collaboration', '沟通', '反馈', '协作'],
            'action' => ['action', 'experiment', 'practice', 'next', 'step', 'guide', 'experiment', '行动', '实验', '练习', '下一步', '建议'],
            'growth' => ['growth', 'habit', 'improve', 'reflection', '成长', '提升', '复盘'],
            'explainability' => ['type', 'mbti', 'borderline', 'contrast', 'adjacent', 'why', 'explain', '人格', '类型', '边界', '解释'],
            'stability' => ['stability', 'stress', 'recovery', 'watchout', 'burnout', '稳定', '压力', '恢复', '风险'],
        ];

        foreach ($matchers as $theme => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    $themes[] = $theme;
                    break;
                }
            }
        }

        if ($themes === []) {
            $themes[] = 'growth';
        }

        return array_values(array_unique($themes));
    }

    /**
     * @param  array<string, bool>  $userState
     */
    private function resolveCarryoverReason(string $primaryFocusKey, array $userState): string
    {
        $isRevisit = (bool) ($userState['is_revisit'] ?? false);
        $hasUnlock = (bool) ($userState['has_unlock'] ?? false);
        $hasFeedback = (bool) ($userState['has_feedback'] ?? false);
        $hasShare = (bool) ($userState['has_share'] ?? false);
        $hasActionEngagement = (bool) ($userState['has_action_engagement'] ?? false);

        if ($isRevisit && $hasActionEngagement) {
            return 'resume_action_loop';
        }

        if ($isRevisit && $hasShare) {
            return 'return_from_share';
        }

        if ($isRevisit && $hasFeedback) {
            return 'refine_after_feedback';
        }

        if (! $hasUnlock) {
            return 'unlock_to_continue_focus';
        }

        if (str_starts_with($primaryFocusKey, 'career.')) {
            return 'continue_career_bridge';
        }

        if (str_starts_with($primaryFocusKey, 'relationships.')) {
            return 'continue_relationship_practice';
        }

        if (str_starts_with($primaryFocusKey, 'traits.')) {
            return 'continue_explainability_focus';
        }

        if ($isRevisit) {
            return 'resume_previous_focus';
        }

        return 'continue_action_plan';
    }

    /**
     * @param  list<string>  $secondaryFocusKeys
     * @param  array<string, bool>  $userState
     * @return list<string>
     */
    private function resolveRecommendedResumeKeys(
        string $primaryFocusKey,
        array $secondaryFocusKeys,
        array $userState
    ): array {
        $keys = [$primaryFocusKey, ...$secondaryFocusKeys];
        $defaultKey = (bool) ($userState['has_unlock'] ?? false) ? 'career.next_step' : 'growth.next_actions';
        $keys[] = $defaultKey;

        return array_values(array_slice(array_values(array_unique(array_filter($keys))), 0, 3));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $secondaryFocusKeys
     * @return list<string>
     */
    private function resolveCarryoverSceneKeys(
        array $personalization,
        string $primaryFocusKey,
        array $secondaryFocusKeys
    ): array {
        $sceneKeys = [];

        foreach ([$primaryFocusKey, ...$secondaryFocusKeys] as $sectionKey) {
            foreach (self::SECTION_CARRYOVER_SCENE_KEYS[$sectionKey] ?? [] as $sceneKey) {
                $sceneKeys[] = $sceneKey;
            }
        }

        if ($sceneKeys === []) {
            foreach (array_keys((array) ($personalization['scene_fingerprint'] ?? [])) as $sceneKey) {
                $sceneKeys[] = trim((string) $sceneKey);
                if (count($sceneKeys) >= 2) {
                    break;
                }
            }
        }

        return array_values(array_slice(array_values(array_unique(array_filter($sceneKeys))), 0, 3));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $secondaryFocusKeys
     * @return list<string>
     */
    private function resolveCarryoverActionKeys(
        array $personalization,
        string $primaryFocusKey,
        array $secondaryFocusKeys
    ): array {
        $actionKeys = [];

        foreach ([$primaryFocusKey, ...$secondaryFocusKeys] as $sectionKey) {
            foreach (self::SECTION_CARRYOVER_ACTION_FIELDS[$sectionKey] ?? [] as $field) {
                $preferredKey = $this->selectPreferredCarryoverActionKey((array) ($personalization[$field] ?? []));
                if ($preferredKey !== '') {
                    $actionKeys[] = $preferredKey;
                }
            }
        }

        return array_values(array_slice(array_values(array_unique($actionKeys)), 0, 4));
    }

    /**
     * @param  list<mixed>  $keys
     */
    private function selectPreferredCarryoverActionKey(array $keys): string
    {
        $normalizedKeys = array_values(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $keys
        )));

        if ($normalizedKeys === []) {
            return '';
        }

        foreach (['.theme.', '.stability.', '.close_call.'] as $needle) {
            foreach ($normalizedKeys as $key) {
                if (str_contains($key, $needle)) {
                    return $key;
                }
            }
        }

        return $normalizedKeys[0];
    }

    /**
     * @param  list<string>  $sections
     * @return list<string>
     */
    private function promoteSection(array $sections, string $target, int $position): array
    {
        $remaining = array_values(array_filter($sections, static fn (string $section): bool => $section !== $target));
        array_splice($remaining, max(0, min($position, count($remaining))), 0, [$target]);

        return $remaining;
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function isLowConfidencePath(array $personalization): bool
    {
        foreach ((array) ($personalization['confidence_or_stability_keys'] ?? []) as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, 'context_sensitive') || str_contains($normalized, 'mixed')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $eventCodes
     */
    private function attemptHasAnyEvent(int $orgId, string $attemptId, array $eventCodes): bool
    {
        if ($eventCodes === []) {
            return false;
        }

        return DB::table('events')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->whereIn('event_code', $eventCodes)
            ->exists();
    }

    private function attemptHasShareRow(string $attemptId): bool
    {
        return DB::table('shares')
            ->where('attempt_id', $attemptId)
            ->exists();
    }

    /**
     * @return list<array{event_code:string, meta:array<string, mixed>}>
     */
    private function fetchAttemptEvents(int $orgId, string $attemptId): array
    {
        $rows = DB::table('events')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['event_code', 'meta_json']);

        $events = [];

        foreach ($rows as $row) {
            $eventCode = trim((string) ($row->event_code ?? ''));
            if ($eventCode === '') {
                continue;
            }

            $events[] = [
                'event_code' => $eventCode,
                'meta' => $this->normalizeMetaJson($row->meta_json ?? null),
            ];
        }

        return $events;
    }

    /**
     * @param  list<array{event_code:string, meta:array<string, mixed>}>  $eventRows
     * @param  list<string>  $eventCodes
     */
    private function eventRowsContainAny(array $eventRows, array $eventCodes): bool
    {
        if ($eventCodes === []) {
            return false;
        }

        foreach ($eventRows as $row) {
            if (in_array($row['event_code'], $eventCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{event_code:string, meta:array<string, mixed>}>  $eventRows
     */
    private function eventRowsContainActionEngagement(array $eventRows): bool
    {
        foreach ($eventRows as $row) {
            if ($row['event_code'] !== 'ui_card_interaction') {
                continue;
            }

            $meta = $row['meta'];
            $sectionKey = trim((string) ($meta['sectionKey'] ?? $meta['section_key'] ?? ''));
            $actionKey = trim((string) ($meta['actionKey'] ?? $meta['action_key'] ?? ''));

            if ($actionKey !== '' || in_array($sectionKey, self::ACTION_SECTION_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{event_code:string, meta:array<string, mixed>}>  $eventRows
     */
    private function resolveFeedbackSentiment(array $eventRows): string
    {
        $hasPositive = false;
        $hasMixed = false;
        $hasNegative = false;

        foreach ($eventRows as $row) {
            if ($row['event_code'] !== 'accuracy_feedback') {
                continue;
            }

            $feedback = trim((string) ($row['meta']['feedback'] ?? ''));
            if (in_array($feedback, self::POSITIVE_FEEDBACK_VALUES, true)) {
                $hasPositive = true;
                continue;
            }

            if (in_array($feedback, self::MIXED_FEEDBACK_VALUES, true)) {
                $hasMixed = true;
                continue;
            }

            if (in_array($feedback, self::NEGATIVE_FEEDBACK_VALUES, true)) {
                $hasNegative = true;
            }
        }

        if (! $hasPositive && ! $hasMixed && ! $hasNegative) {
            return 'none';
        }

        if ($hasNegative && ! $hasPositive && ! $hasMixed) {
            return 'negative';
        }

        if ($hasNegative || $hasMixed) {
            return 'mixed';
        }

        return 'positive';
    }

    /**
     * @param  list<array{event_code:string, meta:array<string, mixed>}>  $eventRows
     */
    private function resolveFeedbackCoverage(array $eventRows): string
    {
        $categories = [];

        foreach ($eventRows as $row) {
            if ($row['event_code'] !== 'accuracy_feedback') {
                continue;
            }

            $sectionKey = trim((string) ($row['meta']['sectionKey'] ?? $row['meta']['section_key'] ?? ''));
            $category = $this->categorizeFeedbackSection($sectionKey);
            if ($category !== '' && ! in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        if ($categories === []) {
            return 'none';
        }

        if (count($categories) === 1) {
            return $categories[0] . '_only';
        }

        return 'mixed';
    }

    /**
     * @param  list<array{event_code:string, meta:array<string, mixed>}>  $eventRows
     */
    private function resolveActionCompletionTendency(array $eventRows, bool $isRevisit, bool $hasUnlock): string
    {
        $actionInteractionCount = 0;
        $positiveActionFeedback = false;
        $hasCommerceIntent = false;

        foreach ($eventRows as $row) {
            $eventCode = $row['event_code'];
            $meta = $row['meta'];

            if (in_array($eventCode, ['click_unlock', 'create_order'], true)) {
                $hasCommerceIntent = true;
            }

            if ($eventCode === 'accuracy_feedback') {
                $feedback = trim((string) ($meta['feedback'] ?? ''));
                $sectionKey = trim((string) ($meta['sectionKey'] ?? $meta['section_key'] ?? ''));
                if ($feedback === 'helpful_action' || in_array($sectionKey, self::ACTION_SECTION_KEYS, true)) {
                    $positiveActionFeedback = true;
                }
            }

            if ($eventCode !== 'ui_card_interaction') {
                continue;
            }

            $sectionKey = trim((string) ($meta['sectionKey'] ?? $meta['section_key'] ?? ''));
            $actionKey = trim((string) ($meta['actionKey'] ?? $meta['action_key'] ?? ''));
            if ($actionKey !== '' || in_array($sectionKey, self::ACTION_SECTION_KEYS, true)) {
                $actionInteractionCount++;
            }
        }

        if ($hasCommerceIntent && ($actionInteractionCount > 0 || $positiveActionFeedback)) {
            return 'committed';
        }

        if (
            $actionInteractionCount >= 2
            || ($isRevisit && $actionInteractionCount >= 1)
            || ($positiveActionFeedback && $actionInteractionCount >= 1)
        ) {
            return 'repeatable';
        }

        if ($actionInteractionCount >= 1 || $positiveActionFeedback) {
            return 'warming_up';
        }

        return $hasUnlock ? 'available' : 'idle';
    }

    /**
     * @param  list<array{event_code:string, meta:array<string, mixed>}>  $eventRows
     */
    private function resolveLastDeepReadSection(array $eventRows): string
    {
        foreach ($eventRows as $row) {
            if ($row['event_code'] !== 'ui_card_interaction') {
                continue;
            }

            $interaction = trim((string) ($row['meta']['interaction'] ?? ''));
            $sectionKey = trim((string) ($row['meta']['sectionKey'] ?? $row['meta']['section_key'] ?? ''));
            if ($sectionKey !== '' && $interaction === 'dwell_2500ms') {
                return $sectionKey;
            }
        }

        foreach ($eventRows as $row) {
            if ($row['event_code'] !== 'ui_card_interaction') {
                continue;
            }

            $sectionKey = trim((string) ($row['meta']['sectionKey'] ?? $row['meta']['section_key'] ?? ''));
            if ($sectionKey !== '') {
                return $sectionKey;
            }
        }

        foreach ($eventRows as $row) {
            if ($row['event_code'] !== 'accuracy_feedback') {
                continue;
            }

            $sectionKey = trim((string) ($row['meta']['sectionKey'] ?? $row['meta']['section_key'] ?? ''));
            if ($sectionKey !== '') {
                return $sectionKey;
            }
        }

        return '';
    }

    /**
     * @param  list<array{event_code:string, meta:array<string, mixed>}>  $eventRows
     */
    private function resolveCurrentIntentCluster(
        array $eventRows,
        bool $hasUnlock,
        string $feedbackSentiment,
        string $feedbackCoverage,
        string $actionCompletionTendency,
        string $lastDeepReadSection,
        bool $hasActionEngagement
    ): string {
        foreach ($eventRows as $row) {
            $eventCode = $row['event_code'];
            $meta = $row['meta'];
            $sectionKey = trim((string) ($meta['sectionKey'] ?? $meta['section_key'] ?? ''));
            $actionKey = trim((string) ($meta['actionKey'] ?? $meta['action_key'] ?? ''));
            $ctaKey = trim((string) ($meta['ctaKey'] ?? $meta['cta_key'] ?? ''));
            $continueTarget = trim((string) ($meta['continueTarget'] ?? $meta['continue_target'] ?? ''));
            $recommendationKey = trim((string) ($meta['recommendationKey'] ?? $meta['recommendation_key'] ?? ''));

            if (in_array($eventCode, ['click_unlock', 'create_order'], true)) {
                return 'unlock_readiness';
            }

            if ($eventCode === 'share_result') {
                return 'continuity_return';
            }

            if ($eventCode === 'accuracy_feedback') {
                return in_array($this->categorizeFeedbackSection($sectionKey), ['action', 'career'], true)
                    ? 'action_activation'
                    : 'clarify_type';
            }

            if ($eventCode !== 'ui_card_interaction') {
                continue;
            }

            if ($ctaKey === 'career_bridge' || $continueTarget === 'career_recommendation') {
                return 'career_move';
            }

            if ($ctaKey === 'workspace_lite' || str_starts_with($continueTarget, 'history_') || $continueTarget === 'share_take_flow') {
                return 'continuity_return';
            }

            if ($recommendationKey !== '' || $continueTarget === 'recommended_read') {
                return 'deep_reading';
            }

            if ($actionKey !== '' || in_array($sectionKey, self::ACTION_SECTION_KEYS, true)) {
                return 'action_activation';
            }

            if (str_starts_with($sectionKey, 'career.')) {
                return 'career_move';
            }

            if (str_starts_with($sectionKey, 'relationships.')) {
                return 'relationship_tuning';
            }

            if ($this->isClarifySection($sectionKey) || $sectionKey === 'scene_fingerprint') {
                return 'clarify_type';
            }
        }

        if (
            in_array($feedbackSentiment, ['negative', 'mixed'], true)
            && in_array($feedbackCoverage, ['scene_only', 'explainability_only', 'mixed'], true)
        ) {
            return 'clarify_type';
        }

        if ($this->isKnownSectionKey($lastDeepReadSection)) {
            if (str_starts_with($lastDeepReadSection, 'career.')) {
                return 'career_move';
            }

            if (str_starts_with($lastDeepReadSection, 'relationships.')) {
                return 'relationship_tuning';
            }

            if ($this->isClarifySection($lastDeepReadSection)) {
                return 'clarify_type';
            }
        }

        if ($hasActionEngagement || in_array($actionCompletionTendency, ['repeatable', 'committed'], true)) {
            return 'action_activation';
        }

        return $hasUnlock ? 'default' : 'unlock_readiness';
    }

    private function categorizeFeedbackSection(string $sectionKey): string
    {
        if ($sectionKey === '') {
            return '';
        }

        if ($sectionKey === 'scene_fingerprint') {
            return 'scene';
        }

        if ($this->isClarifySection($sectionKey)) {
            return 'explainability';
        }

        if (in_array($sectionKey, self::ACTION_SECTION_KEYS, true)) {
            return 'action';
        }

        if (str_starts_with($sectionKey, 'career.')) {
            return 'career';
        }

        if (str_starts_with($sectionKey, 'relationships.')) {
            return 'relationship';
        }

        if (str_starts_with($sectionKey, 'growth.')) {
            return 'growth';
        }

        return '';
    }

    private function isClarifySection(string $sectionKey): bool
    {
        return $sectionKey === 'scene_fingerprint'
            || $sectionKey === 'growth.stability_confidence'
            || str_starts_with($sectionKey, 'traits.');
    }

    private function isKnownSectionKey(string $sectionKey): bool
    {
        if ($sectionKey === '') {
            return false;
        }

        foreach (self::CHAPTER_SECTIONS as $sections) {
            if (in_array($sectionKey, $sections, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMetaJson(mixed $rawMeta): array
    {
        if (is_array($rawMeta)) {
            return $rawMeta;
        }

        if (is_string($rawMeta) && $rawMeta !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
