<?php

declare(strict_types=1);

namespace App\Services\Mbti;

final class MbtiAdaptiveSelectionService
{
    private const VERSION = 'mbti.adaptive_selection.v1';

    /**
     * @var list<string>
     */
    private const TARGET_SECTION_KEYS = [
        'growth.next_actions',
        'growth.watchouts',
        'career.next_step',
        'career.work_experiments',
        'relationships.try_this_week',
        'traits.why_this_type',
    ];

    /**
     * @var list<string>
     */
    private const CTA_KEYS = [
        'career_bridge',
        'workspace_lite',
        'share_result',
        'unlock_full_report',
    ];

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function attach(array $personalization): array
    {
        if ($personalization === []) {
            return [];
        }

        $adaptive = $this->buildAdaptiveSelection($personalization);
        if ($adaptive === []) {
            return $personalization;
        }

        return $this->attachExistingAdaptive($personalization, $adaptive);
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $adaptive
     * @return array<string, mixed>
     */
    public function attachExistingAdaptive(array $personalization, array $adaptive): array
    {
        if ($personalization === [] || $adaptive === []) {
            return $personalization;
        }

        $personalization = $this->applyAdaptiveRewrites($personalization, $adaptive);
        $personalization['adaptive_selection_v1'] = $adaptive;

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function buildAdaptiveSelection(array $personalization): array
    {
        $memory = is_array($personalization['longitudinal_memory_v1'] ?? null)
            ? $personalization['longitudinal_memory_v1']
            : [];
        $userState = is_array($personalization['user_state'] ?? null) ? $personalization['user_state'] : [];
        $actionJourney = is_array($personalization['action_journey_v1'] ?? null)
            ? $personalization['action_journey_v1']
            : [];
        $continuity = is_array($personalization['continuity'] ?? null) ? $personalization['continuity'] : [];

        if (! $this->hasAdaptiveAnchor($userState, $actionJourney, $memory, $continuity)) {
            return [];
        }

        $contentFeedbackWeights = $this->buildContentFeedbackWeights($personalization, $memory);
        $actionEffectWeights = $this->buildActionEffectWeights($personalization, $memory);
        $recommendationEffectWeights = $this->buildRecommendationEffectWeights($personalization, $memory);
        $ctaEffectWeights = $this->buildCtaEffectWeights($personalization, $memory, $actionEffectWeights, $recommendationEffectWeights);
        $selectionRewriteReason = $this->resolveSelectionRewriteReason(
            $personalization,
            $memory,
            $actionEffectWeights,
            $recommendationEffectWeights
        );
        $nextBestAction = $this->resolveNextBestAction($personalization, $actionEffectWeights, $selectionRewriteReason);
        $adaptiveEvidence = $this->buildAdaptiveEvidence(
            $personalization,
            $memory,
            $contentFeedbackWeights,
            $actionEffectWeights,
            $recommendationEffectWeights,
            $ctaEffectWeights
        );

        $adaptiveFingerprint = hash('sha256', json_encode([
            'version' => self::VERSION,
            'profile_seed_key' => trim((string) ($personalization['profile_seed_key'] ?? '')),
            'selection_fingerprint' => trim((string) ($personalization['selection_fingerprint'] ?? '')),
            'memory_fingerprint' => trim((string) ($memory['memory_fingerprint'] ?? '')),
            'selection_rewrite_reason' => $selectionRewriteReason,
            'content_feedback_weights' => $contentFeedbackWeights,
            'action_effect_weights' => $actionEffectWeights,
            'recommendation_effect_weights' => $recommendationEffectWeights,
            'cta_effect_weights' => $ctaEffectWeights,
            'next_best_action_v1' => $nextBestAction,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'version' => self::VERSION,
            'adaptive_contract_version' => self::VERSION,
            'adaptive_fingerprint' => $adaptiveFingerprint,
            'selection_rewrite_reason' => $selectionRewriteReason,
            'content_feedback_weights' => $contentFeedbackWeights,
            'action_effect_weights' => $actionEffectWeights,
            'recommendation_effect_weights' => $recommendationEffectWeights,
            'cta_effect_weights' => $ctaEffectWeights,
            'next_best_action_v1' => $nextBestAction,
            'adaptive_evidence' => $adaptiveEvidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $adaptive
     * @return array<string, mixed>
     */
    private function applyAdaptiveRewrites(array $personalization, array $adaptive): array
    {
        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];
        $sectionSelectionKeys = is_array($personalization['section_selection_keys'] ?? null)
            ? $personalization['section_selection_keys']
            : [];
        $actionSelectionKeys = is_array($personalization['action_selection_keys'] ?? null)
            ? $personalization['action_selection_keys']
            : [];

        foreach (self::TARGET_SECTION_KEYS as $sectionKey) {
            $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : [];
            if ($section === []) {
                continue;
            }

            $selectionMode = $this->resolveSectionSelectionMode($sectionKey, $adaptive, $personalization);
            if ($selectionMode === '') {
                continue;
            }

            $selectedBlocks = $this->selectBlocksForMode($sectionKey, $section, $selectionMode);
            if ($selectedBlocks === []) {
                continue;
            }

            $sections[$sectionKey]['selected_blocks'] = $selectedBlocks;
            $sections[$sectionKey]['selection_mode'] = $selectionMode;

            $sectionSelectionKeys[$sectionKey] = $this->appendAdaptiveSuffix(
                trim((string) ($sectionSelectionKeys[$sectionKey] ?? '')),
                $adaptive,
                $selectionMode,
                $selectedBlocks
            );
        }

        $personalization['sections'] = $sections;
        $personalization['section_selection_keys'] = $sectionSelectionKeys;

        $nextBestAction = is_array($adaptive['next_best_action_v1'] ?? null)
            ? $adaptive['next_best_action_v1']
            : [];
        $nextBestActionKey = trim((string) ($nextBestAction['key'] ?? ''));
        $nextBestActionSectionKey = trim((string) ($nextBestAction['section_key'] ?? ''));

        if ($nextBestActionKey !== '') {
            $personalization['action_focus_key'] = $nextBestActionKey;
            $orderedActionKeys = $this->normalizeStringList($personalization['ordered_action_keys'] ?? []);
            array_unshift($orderedActionKeys, $nextBestActionKey);
            $personalization['ordered_action_keys'] = array_values(array_unique(array_filter($orderedActionKeys)));
        }

        $personalization['action_selection_keys'] = $this->buildActionSelectionKeys(
            $personalization,
            $sections,
            $actionSelectionKeys,
            $adaptive,
            $nextBestActionKey,
            $nextBestActionSectionKey
        );
        $personalization['recommendation_selection_keys'] = $this->buildRecommendationSelectionKeys($personalization, $adaptive);
        $personalization['selection_evidence'] = $this->mergeSelectionEvidence(
            is_array($personalization['selection_evidence'] ?? null) ? $personalization['selection_evidence'] : [],
            $adaptive
        );
        $personalization['selection_fingerprint'] = $this->buildSelectionFingerprint($personalization, $adaptive);
        $personalization['orchestration'] = $this->mergeOrchestration($personalization, $adaptive);
        $personalization['continuity'] = $this->mergeContinuity($personalization, $adaptive);

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $userState
     * @param  array<string, mixed>  $actionJourney
     * @param  array<string, mixed>  $memory
     * @param  array<string, mixed>  $continuity
     */
    private function hasAdaptiveAnchor(array $userState, array $actionJourney, array $memory, array $continuity): bool
    {
        if ($memory !== []) {
            return true;
        }

        if (($userState['has_feedback'] ?? false) === true || ($userState['has_action_engagement'] ?? false) === true) {
            return true;
        }

        if ($this->normalizeStringList($actionJourney['completed_action_keys'] ?? []) !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @return array<string, int>
     */
    private function buildContentFeedbackWeights(array $personalization, array $memory): array
    {
        $weights = [
            'explainability' => 0,
            'growth' => 0,
            'stability' => 0,
            'career' => 0,
            'relationships' => 0,
        ];

        $feedbackSentiment = trim((string) data_get($personalization, 'user_state.feedback_sentiment', ''));
        $feedbackCoverage = trim((string) data_get($personalization, 'user_state.feedback_coverage', ''));
        $lastDeepReadSection = trim((string) data_get($personalization, 'user_state.last_deep_read_section', ''));
        $currentIntentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));

        if ($feedbackSentiment === 'negative' && $feedbackCoverage === 'explainability_only') {
            $weights['explainability'] -= 3;
            $weights['growth'] += 2;
            $weights['stability'] += 1;
        } elseif ($feedbackSentiment === 'positive') {
            $positiveTheme = $this->sectionToTheme($lastDeepReadSection);
            if ($positiveTheme !== '') {
                $weights[$positiveTheme] = ($weights[$positiveTheme] ?? 0) + 2;
            }
        }

        $deepReadTheme = $this->sectionToTheme($lastDeepReadSection);
        if ($deepReadTheme !== '') {
            $weights[$deepReadTheme] = ($weights[$deepReadTheme] ?? 0) + 2;
        }

        foreach ($this->normalizeStringList($memory['dominant_interest_keys'] ?? []) as $interestKey) {
            $weights[$interestKey] = ($weights[$interestKey] ?? 0) + 2;
        }

        foreach ((array) data_get($memory, 'memory_evidence.negative_feedback_scores', []) as $interestKey => $score) {
            if (! is_numeric($score)) {
                continue;
            }

            $normalizedInterest = $this->normalizeThemeKey((string) $interestKey);
            if ($normalizedInterest === '') {
                continue;
            }

            $weights[$normalizedInterest] = ($weights[$normalizedInterest] ?? 0) - min(3, (int) round((float) $score));
        }

        foreach (match ($currentIntentCluster) {
            'career_move' => ['career' => 3, 'growth' => 1],
            'clarify_type' => ['explainability' => 2, 'stability' => 1],
            'relationship_tuning' => ['relationships' => 3],
            default => ['growth' => 1],
        } as $theme => $delta) {
            $weights[$theme] = ($weights[$theme] ?? 0) + $delta;
        }

        arsort($weights);

        return $weights;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @return array<string, int>
     */
    private function buildActionEffectWeights(array $personalization, array $memory): array
    {
        $weights = [
            'growth.next_actions' => 0,
            'growth.watchouts' => 0,
            'career.next_step' => 0,
            'career.work_experiments' => 0,
            'relationships.try_this_week' => 0,
        ];

        $actionCompletionTendency = trim((string) data_get($personalization, 'user_state.action_completion_tendency', ''));
        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));
        $memoryRewriteReason = trim((string) ($memory['memory_rewrite_reason'] ?? ''));

        foreach ($this->normalizeStringList($memory['resume_bias_keys'] ?? []) as $resumeKey) {
            if (isset($weights[$resumeKey])) {
                $weights[$resumeKey] += 2;
            }
        }

        foreach ($this->normalizeStringList($memory['section_history_keys'] ?? []) as $sectionKey) {
            if (isset($weights[$sectionKey])) {
                $weights[$sectionKey] += 1;
            }
        }

        foreach ((array) data_get($memory, 'memory_evidence.continue_target_scores', []) as $targetKey => $score) {
            if (! is_numeric($score)) {
                continue;
            }

            foreach ($this->mapContinueTargetToSections((string) $targetKey) as $sectionKey) {
                $weights[$sectionKey] = ($weights[$sectionKey] ?? 0) + (int) round((float) $score);
            }
        }

        foreach ($this->normalizeStringList(data_get($personalization, 'action_journey_v1.completed_action_keys', [])) as $actionKey) {
            foreach ($this->mapActionKeyToSections($actionKey) as $sectionKey) {
                $weights[$sectionKey] = ($weights[$sectionKey] ?? 0) + 2;
            }
        }

        foreach (match ($actionCompletionTendency) {
            'repeatable' => ['growth.next_actions' => 2, 'career.work_experiments' => 2],
            'warming_up' => ['growth.next_actions' => 1, 'growth.watchouts' => 1],
            'idle', 'stalled' => ['growth.watchouts' => 2, 'career.next_step' => 1],
            default => [],
        } as $sectionKey => $delta) {
            $weights[$sectionKey] = ($weights[$sectionKey] ?? 0) + $delta;
        }

        foreach (match ($intentCluster) {
            'career_move' => ['career.next_step' => 3, 'career.work_experiments' => 2],
            'clarify_type' => ['growth.next_actions' => 2, 'growth.watchouts' => 1],
            'relationship_tuning' => ['relationships.try_this_week' => 3],
            default => ['growth.next_actions' => 1],
        } as $sectionKey => $delta) {
            $weights[$sectionKey] = ($weights[$sectionKey] ?? 0) + $delta;
        }

        if ($memoryRewriteReason === 'resume_career_focus') {
            $weights['career.next_step'] += 2;
            $weights['career.work_experiments'] += 2;
        } elseif ($memoryRewriteReason === 'resume_growth_actions') {
            $weights['growth.next_actions'] += 2;
            $weights['growth.watchouts'] += 1;
        }

        arsort($weights);

        return $weights;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @return array<string, int>
     */
    private function buildRecommendationEffectWeights(array $personalization, array $memory): array
    {
        $weights = $this->buildContentFeedbackWeights($personalization, $memory);

        foreach ([
            'growth.next_actions' => 'growth',
            'growth.watchouts' => 'stability',
            'career.next_step' => 'career',
            'career.work_experiments' => 'career',
            'relationships.try_this_week' => 'relationships',
        ] as $sectionKey => $theme) {
            if (in_array($sectionKey, $this->normalizeStringList($memory['resume_bias_keys'] ?? []), true)) {
                $weights[$theme] = ($weights[$theme] ?? 0) + 2;
            }
        }

        return $weights;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @param  array<string, int>  $actionEffectWeights
     * @param  array<string, int>  $recommendationEffectWeights
     * @return array<string, int>
     */
    private function buildCtaEffectWeights(
        array $personalization,
        array $memory,
        array $actionEffectWeights,
        array $recommendationEffectWeights
    ): array {
        $weights = array_fill_keys(self::CTA_KEYS, 0);

        if (($personalization['user_state']['has_unlock'] ?? false) === true) {
            $weights['workspace_lite'] += 3;
        } else {
            $weights['unlock_full_report'] += 2;
        }

        $weights['career_bridge'] += max(0, (int) ($recommendationEffectWeights['career'] ?? 0));
        $weights['share_result'] += ($personalization['user_state']['has_share'] ?? false) === true ? 0 : 1;

        foreach ($this->normalizeStringList($memory['resume_bias_keys'] ?? []) as $resumeKey) {
            if (str_starts_with($resumeKey, 'career.')) {
                $weights['career_bridge'] += 2;
            }
            if (str_starts_with($resumeKey, 'growth.') || str_starts_with($resumeKey, 'traits.')) {
                $weights['workspace_lite'] += 1;
            }
        }

        if (($actionEffectWeights['career.next_step'] ?? 0) > ($actionEffectWeights['growth.next_actions'] ?? 0)) {
            $weights['career_bridge'] += 1;
        } else {
            $weights['workspace_lite'] += 1;
        }

        arsort($weights);

        return $weights;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @param  array<string, int>  $actionEffectWeights
     * @param  array<string, int>  $recommendationEffectWeights
     */
    private function resolveSelectionRewriteReason(
        array $personalization,
        array $memory,
        array $actionEffectWeights,
        array $recommendationEffectWeights
    ): string {
        $feedbackSentiment = trim((string) data_get($personalization, 'user_state.feedback_sentiment', ''));
        $feedbackCoverage = trim((string) data_get($personalization, 'user_state.feedback_coverage', ''));
        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));

        if ($feedbackSentiment === 'negative' && $feedbackCoverage === 'explainability_only') {
            return 'feedback_redirect_to_action';
        }

        if (($actionEffectWeights['career.next_step'] ?? 0) >= 4 || ($recommendationEffectWeights['career'] ?? 0) >= 4) {
            return 'career_followthrough_loop';
        }

        if (($actionEffectWeights['growth.next_actions'] ?? 0) >= 4) {
            return 'repeatable_action_reinforcement';
        }

        if (in_array('behavior.revisit.repeat', $this->normalizeStringList($memory['behavior_delta_keys'] ?? []), true)) {
            return 'resume_bias_reinforcement';
        }

        return match ($intentCluster) {
            'clarify_type' => 'clarify_then_action',
            'relationship_tuning' => 'relationship_followthrough_loop',
            default => 'observed_usefulness_shift',
        };
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, int>  $actionEffectWeights
     * @return array<string, string>
     */
    private function resolveNextBestAction(array $personalization, array $actionEffectWeights, string $selectionRewriteReason): array
    {
        $orderedSections = array_keys($actionEffectWeights);
        usort($orderedSections, static function (string $left, string $right) use ($actionEffectWeights): int {
            return ($actionEffectWeights[$right] ?? 0) <=> ($actionEffectWeights[$left] ?? 0);
        });

        foreach ($orderedSections as $sectionKey) {
            $resolved = $this->resolveActionKeyForSection($sectionKey, $personalization);
            if ($resolved['key'] === '') {
                continue;
            }

            return [
                'key' => $resolved['key'],
                'section_key' => $sectionKey,
                'family' => $resolved['family'],
                'reason' => $selectionRewriteReason,
            ];
        }

        return [
            'key' => '',
            'section_key' => '',
            'family' => '',
            'reason' => $selectionRewriteReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @param  array<string, int>  $contentFeedbackWeights
     * @param  array<string, int>  $actionEffectWeights
     * @param  array<string, int>  $recommendationEffectWeights
     * @param  array<string, int>  $ctaEffectWeights
     * @return array<string, mixed>
     */
    private function buildAdaptiveEvidence(
        array $personalization,
        array $memory,
        array $contentFeedbackWeights,
        array $actionEffectWeights,
        array $recommendationEffectWeights,
        array $ctaEffectWeights
    ): array {
        return [
            'user_state' => [
                'feedback_sentiment' => trim((string) data_get($personalization, 'user_state.feedback_sentiment', '')),
                'feedback_coverage' => trim((string) data_get($personalization, 'user_state.feedback_coverage', '')),
                'action_completion_tendency' => trim((string) data_get($personalization, 'user_state.action_completion_tendency', '')),
                'last_deep_read_section' => trim((string) data_get($personalization, 'user_state.last_deep_read_section', '')),
                'current_intent_cluster' => trim((string) data_get($personalization, 'user_state.current_intent_cluster', '')),
            ],
            'action_journey' => [
                'journey_state' => trim((string) data_get($personalization, 'action_journey_v1.journey_state', '')),
                'progress_state' => trim((string) data_get($personalization, 'action_journey_v1.progress_state', '')),
                'completed_action_keys' => $this->normalizeStringList(data_get($personalization, 'action_journey_v1.completed_action_keys', [])),
            ],
            'longitudinal_memory' => [
                'memory_fingerprint' => trim((string) ($memory['memory_fingerprint'] ?? '')),
                'memory_state' => trim((string) ($memory['memory_state'] ?? '')),
                'behavior_delta_keys' => $this->normalizeStringList($memory['behavior_delta_keys'] ?? []),
                'dominant_interest_keys' => $this->normalizeStringList($memory['dominant_interest_keys'] ?? []),
                'resume_bias_keys' => $this->normalizeStringList($memory['resume_bias_keys'] ?? []),
                'memory_rewrite_reason' => trim((string) ($memory['memory_rewrite_reason'] ?? '')),
                'memory_evidence' => is_array($memory['memory_evidence'] ?? null) ? $memory['memory_evidence'] : [],
            ],
            'content_feedback_weights' => $contentFeedbackWeights,
            'action_effect_weights' => $actionEffectWeights,
            'recommendation_effect_weights' => $recommendationEffectWeights,
            'cta_effect_weights' => $ctaEffectWeights,
        ];
    }

    /**
     * @param  array<string, mixed>  $adaptive
     * @param  array<string, mixed>  $personalization
     */
    private function resolveSectionSelectionMode(string $sectionKey, array $adaptive, array $personalization): string
    {
        $reason = trim((string) ($adaptive['selection_rewrite_reason'] ?? ''));
        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));

        return match ($sectionKey) {
            'growth.next_actions' => match (true) {
                $reason === 'feedback_redirect_to_action' => 'adaptive_redirect_to_action',
                $reason === 'career_followthrough_loop' => 'adaptive_career_followthrough',
                default => 'adaptive_reinforce_repeatable',
            },
            'growth.watchouts' => $reason === 'feedback_redirect_to_action'
                ? 'adaptive_reduce_resistance'
                : 'adaptive_guardrail_followthrough',
            'career.next_step' => $intentCluster === 'career_move' || $reason === 'career_followthrough_loop'
                ? 'adaptive_career_followthrough'
                : 'adaptive_career_resume',
            'career.work_experiments' => $intentCluster === 'career_move' || $reason === 'career_followthrough_loop'
                ? 'adaptive_experiment_followthrough'
                : 'adaptive_experiment_resume',
            'relationships.try_this_week' => $reason === 'relationship_followthrough_loop'
                ? 'adaptive_relationship_followthrough'
                : 'adaptive_relationship_resume',
            'traits.why_this_type' => $reason === 'feedback_redirect_to_action'
                ? 'adaptive_clarify_boundary'
                : 'adaptive_identity_reset',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    private function selectBlocksForMode(string $sectionKey, array $section, string $selectionMode): array
    {
        $availableBlocks = array_values(array_filter(
            array_map(
                static fn (mixed $block): array => is_array($block) ? $block : [],
                (array) ($section['blocks'] ?? [])
            ),
            static fn (array $block): bool => trim((string) ($block['id'] ?? '')) !== ''
        ));
        if ($availableBlocks === []) {
            return [];
        }

        $blocksByKind = [];
        $availableIds = [];
        foreach ($availableBlocks as $block) {
            $blockId = trim((string) ($block['id'] ?? ''));
            if ($blockId === '') {
                continue;
            }

            $availableIds[] = $blockId;
            $kind = trim((string) ($block['kind'] ?? 'rich_text'));
            $blocksByKind[$kind] ??= [];
            $blocksByKind[$kind][] = $blockId;
        }

        $selected = [];
        foreach ($this->preferredKindsForSection($sectionKey, $selectionMode) as $kind) {
            foreach ((array) ($blocksByKind[$kind] ?? []) as $blockId) {
                $selected[] = $blockId;
            }
        }

        $selected = array_values(array_unique(array_filter($selected)));
        $selected = array_values(array_filter($selected, static fn (string $blockId): bool => in_array($blockId, $availableIds, true)));

        if ($selected === []) {
            $selected = $this->normalizeStringList($section['selected_blocks'] ?? []);
        }

        if ($selected === []) {
            $selected = $availableIds;
        }

        return array_slice($selected, 0, $this->maxBlocksForSection($sectionKey, $selectionMode));
    }

    /**
     * @return list<string>
     */
    private function preferredKindsForSection(string $sectionKey, string $selectionMode): array
    {
        return match ($sectionKey) {
            'growth.next_actions' => match ($selectionMode) {
                'adaptive_redirect_to_action' => ['next_action', 'action_momentum_start', 'action_resistance_break', 'boundary', 'identity'],
                'adaptive_career_followthrough' => ['next_action', 'action_bridge_step', 'action_experiment', 'boundary', 'axis_strength'],
                default => ['next_action', 'action_experiment', 'action_momentum_start', 'identity', 'boundary'],
            },
            'growth.watchouts' => match ($selectionMode) {
                'adaptive_reduce_resistance' => ['watchout', 'watchout_overextension', 'watchout_energy_leak', 'boundary', 'identity'],
                default => ['watchout', 'watchout_energy_leak', 'watchout_overextension', 'identity', 'boundary'],
            },
            'career.next_step' => match ($selectionMode) {
                'adaptive_career_followthrough' => ['career_next_step', 'work_scene_transition', 'work_scene_role_fit', 'boundary', 'axis_strength'],
                default => ['career_next_step', 'work_scene_role_fit', 'work_scene_execution', 'identity', 'boundary'],
            },
            'career.work_experiments' => match ($selectionMode) {
                'adaptive_experiment_followthrough' => ['work_experiment', 'work_scene_focus_recovery', 'work_scene_transition', 'boundary', 'axis_strength'],
                default => ['work_experiment', 'work_scene_collaboration', 'work_scene_execution', 'identity', 'boundary'],
            },
            'relationships.try_this_week' => match ($selectionMode) {
                'adaptive_relationship_followthrough' => ['relationship_practice', 'relationship_misread_repair', 'relationship_boundary_negotiation', 'boundary', 'identity'],
                default => ['relationship_practice', 'relationship_low_intensity_reconnect', 'relationship_bridge', 'identity', 'boundary'],
            },
            'traits.why_this_type' => match ($selectionMode) {
                'adaptive_clarify_boundary' => ['why_this_type', 'misunderstanding_fix', 'boundary', 'axis_strength'],
                default => ['why_this_type', 'identity', 'axis_strength'],
            },
            default => [],
        };
    }

    private function maxBlocksForSection(string $sectionKey, string $selectionMode): int
    {
        return match ($sectionKey) {
            'growth.next_actions',
            'growth.watchouts',
            'career.next_step',
            'career.work_experiments',
            'relationships.try_this_week' => 4,
            default => 3,
        };
    }

    /**
     * @param  array<string, mixed>  $adaptive
     * @param  list<string>  $selectedBlocks
     */
    private function appendAdaptiveSuffix(string $base, array $adaptive, string $selectionMode, array $selectedBlocks): string
    {
        $reason = $this->normalizeKey((string) ($adaptive['selection_rewrite_reason'] ?? ''));
        $blockPart = $selectedBlocks !== []
            ? 'blocks.'.implode('+', array_map([$this, 'normalizeKey'], $selectedBlocks))
            : null;

        if ($base !== '') {
            return $base
                .($reason !== '' ? ':adaptive.'.$reason : '')
                .($selectionMode !== '' ? ':mode.'.$this->normalizeKey($selectionMode) : '')
                .($blockPart !== null ? ':'.$blockPart : '');
        }

        return implode(':', array_values(array_filter([
            'adaptive',
            $reason !== '' ? 'reason.'.$reason : null,
            $selectionMode !== '' ? 'mode.'.$this->normalizeKey($selectionMode) : null,
            $blockPart,
        ])));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $sections
     * @param  array<string, string>  $existingKeys
     * @param  array<string, mixed>  $adaptive
     * @return array<string, string>
     */
    private function buildActionSelectionKeys(
        array $personalization,
        array $sections,
        array $existingKeys,
        array $adaptive,
        string $nextBestActionKey,
        string $nextBestActionSectionKey
    ): array {
        $keys = $existingKeys;
        $reason = $this->normalizeKey((string) ($adaptive['selection_rewrite_reason'] ?? ''));

        foreach (['growth.next_actions', 'career.work_experiments', 'growth.watchouts', 'relationships.try_this_week', 'career.next_step'] as $sectionKey) {
            $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : [];
            $selectionMode = trim((string) ($section['selection_mode'] ?? ''));
            $actionKey = trim((string) ($section['action_key'] ?? ''));
            if ($sectionKey === 'career.next_step' && $actionKey === '') {
                $actionKey = $this->resolveActionKeyForSection($sectionKey, $personalization)['key'];
            }
            if ($sectionKey === $nextBestActionSectionKey && $nextBestActionKey !== '') {
                $actionKey = $nextBestActionKey;
            }
            if ($actionKey === '') {
                continue;
            }

            $base = trim((string) ($keys[$sectionKey] ?? ''));
            $keys[$sectionKey] = $base !== ''
                ? $base.':adaptive.'.$reason.($selectionMode !== '' ? ':mode.'.$this->normalizeKey($selectionMode) : '')
                : implode(':', array_values(array_filter([
                    $sectionKey,
                    'adaptive.'.$reason,
                    $selectionMode !== '' ? 'mode.'.$this->normalizeKey($selectionMode) : null,
                    'action.'.$this->normalizeKey($actionKey),
                ])));
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $adaptive
     * @return list<string>
     */
    private function buildRecommendationSelectionKeys(array $personalization, array $adaptive): array
    {
        $candidates = is_array($personalization['recommended_read_candidates'] ?? null)
            ? $personalization['recommended_read_candidates']
            : [];
        if ($candidates === []) {
            return $this->normalizeStringList($personalization['recommendation_selection_keys'] ?? []);
        }

        $weights = is_array($adaptive['recommendation_effect_weights'] ?? null)
            ? $adaptive['recommendation_effect_weights']
            : [];
        $orderedRecommendationKeys = $this->normalizeStringList($personalization['ordered_recommendation_keys'] ?? []);
        $baselineSelection = $this->normalizeStringList($personalization['recommendation_selection_keys'] ?? []);
        $orderMap = [];
        foreach ($orderedRecommendationKeys as $index => $key) {
            $orderMap[$key] = $index;
        }

        $scored = [];
        foreach ($candidates as $index => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $key = trim((string) ($candidate['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $themes = $this->classifyCandidateThemes($candidate);
            $score = 0;
            foreach ($themes as $theme) {
                $score += (int) ($weights[$theme] ?? 0) * 12;
            }

            if (isset($orderMap[$key])) {
                $score += max(0, 30 - ((int) $orderMap[$key] * 3));
            }

            if (in_array($key, $baselineSelection, true)) {
                $score += 10;
            }

            $scored[] = [
                'key' => $key,
                'score' => $score,
                'index' => $index,
            ];
        }

        usort($scored, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            return $left['index'] <=> $right['index'];
        });

        $selected = array_map(
            static fn (array $row): string => trim((string) ($row['key'] ?? '')),
            array_slice($scored, 0, 4)
        );
        $selected = array_values(array_unique(array_filter(array_merge($selected, array_slice($baselineSelection, 0, 2)))));

        return array_slice($selected, 0, 4);
    }

    /**
     * @param  array<string, mixed>  $selectionEvidence
     * @param  array<string, mixed>  $adaptive
     * @return array<string, mixed>
     */
    private function mergeSelectionEvidence(array $selectionEvidence, array $adaptive): array
    {
        $selectionEvidence['adaptive'] = [
            'adaptive_contract_version' => trim((string) ($adaptive['adaptive_contract_version'] ?? $adaptive['version'] ?? '')),
            'adaptive_fingerprint' => trim((string) ($adaptive['adaptive_fingerprint'] ?? '')),
            'selection_rewrite_reason' => trim((string) ($adaptive['selection_rewrite_reason'] ?? '')),
            'content_feedback_weights' => is_array($adaptive['content_feedback_weights'] ?? null) ? $adaptive['content_feedback_weights'] : [],
            'action_effect_weights' => is_array($adaptive['action_effect_weights'] ?? null) ? $adaptive['action_effect_weights'] : [],
            'recommendation_effect_weights' => is_array($adaptive['recommendation_effect_weights'] ?? null) ? $adaptive['recommendation_effect_weights'] : [],
            'cta_effect_weights' => is_array($adaptive['cta_effect_weights'] ?? null) ? $adaptive['cta_effect_weights'] : [],
            'next_best_action_v1' => is_array($adaptive['next_best_action_v1'] ?? null) ? $adaptive['next_best_action_v1'] : [],
            'adaptive_evidence' => is_array($adaptive['adaptive_evidence'] ?? null) ? $adaptive['adaptive_evidence'] : [],
        ];

        return $selectionEvidence;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $adaptive
     */
    private function buildSelectionFingerprint(array $personalization, array $adaptive): string
    {
        return hash('sha256', json_encode([
            'base_selection_fingerprint' => trim((string) ($personalization['selection_fingerprint'] ?? '')),
            'section_selection_keys' => is_array($personalization['section_selection_keys'] ?? null) ? $personalization['section_selection_keys'] : [],
            'action_selection_keys' => is_array($personalization['action_selection_keys'] ?? null) ? $personalization['action_selection_keys'] : [],
            'recommendation_selection_keys' => $this->normalizeStringList($personalization['recommendation_selection_keys'] ?? []),
            'adaptive_fingerprint' => trim((string) ($adaptive['adaptive_fingerprint'] ?? '')),
            'selection_rewrite_reason' => trim((string) ($adaptive['selection_rewrite_reason'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $adaptive
     * @return array<string, mixed>
     */
    private function mergeOrchestration(array $personalization, array $adaptive): array
    {
        $orchestration = is_array($personalization['orchestration'] ?? null) ? $personalization['orchestration'] : [];
        $existingCtas = $this->normalizeStringList($orchestration['cta_priority_keys'] ?? []);
        $existingCtas = $existingCtas === [] ? self::CTA_KEYS : $existingCtas;
        $weights = is_array($adaptive['cta_effect_weights'] ?? null) ? $adaptive['cta_effect_weights'] : [];
        $orderMap = [];
        foreach ($existingCtas as $index => $key) {
            $orderMap[$key] = $index;
        }

        usort($existingCtas, static function (string $left, string $right) use ($weights, $orderMap): int {
            $leftScore = (int) ($weights[$left] ?? 0);
            $rightScore = (int) ($weights[$right] ?? 0);
            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            return ((int) ($orderMap[$left] ?? PHP_INT_MAX)) <=> ((int) ($orderMap[$right] ?? PHP_INT_MAX));
        });

        $orchestration['cta_priority_keys'] = $existingCtas;

        return $orchestration;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $adaptive
     * @return array<string, mixed>
     */
    private function mergeContinuity(array $personalization, array $adaptive): array
    {
        $continuity = is_array($personalization['continuity'] ?? null) ? $personalization['continuity'] : [];
        $nextBestAction = is_array($adaptive['next_best_action_v1'] ?? null)
            ? $adaptive['next_best_action_v1']
            : [];
        $recommendedResumeKeys = $this->normalizeStringList($continuity['recommended_resume_keys'] ?? []);
        $nextBestSectionKey = trim((string) ($nextBestAction['section_key'] ?? ''));
        $nextBestActionKey = trim((string) ($nextBestAction['key'] ?? ''));
        $selectionRewriteReason = trim((string) ($adaptive['selection_rewrite_reason'] ?? ''));
        if ($nextBestSectionKey !== '') {
            array_unshift($recommendedResumeKeys, $nextBestSectionKey);
            $continuity['carryover_focus_key'] = $nextBestSectionKey;
            $continuity['carryover_reason'] = 'adaptive_next_best_action';
        }

        $carryoverActionKeys = $this->normalizeStringList($continuity['carryover_action_keys'] ?? []);
        if ($nextBestActionKey !== '') {
            array_unshift($carryoverActionKeys, $nextBestActionKey);
        }

        if ($selectionRewriteReason !== '') {
            $continuity['adaptive_rewrite_reason'] = $selectionRewriteReason;
        }

        $continuity['carryover_action_keys'] = array_slice(array_values(array_unique(array_filter($carryoverActionKeys))), 0, 4);
        $continuity['recommended_resume_keys'] = array_slice(array_values(array_unique(array_filter($recommendedResumeKeys))), 0, 4);

        return $continuity;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array{key:string,family:string}
     */
    private function resolveActionKeyForSection(string $sectionKey, array $personalization): array
    {
        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];
        $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : [];
        $sectionActionKey = trim((string) ($section['action_key'] ?? ''));
        if ($sectionActionKey !== '') {
            return [
                'key' => $sectionActionKey,
                'family' => $this->classifyActionFamily($sectionActionKey, $sectionKey),
            ];
        }

        if ($sectionKey === 'career.next_step') {
            $careerKey = $this->normalizeStringList($personalization['career_next_step_keys'] ?? []);
            if ($careerKey !== []) {
                return [
                    'key' => $careerKey[0],
                    'family' => 'career',
                ];
            }
        }

        return ['key' => '', 'family' => ''];
    }

    /**
     * @return list<string>
     */
    private function mapContinueTargetToSections(string $continueTarget): array
    {
        return match ($continueTarget) {
            'career_bridge', 'career_recommendation' => ['career.next_step', 'career.work_experiments'],
            'recommended_read' => ['growth.next_actions', 'traits.why_this_type'],
            'workspace_lite' => ['growth.next_actions', 'career.next_step'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function mapActionKeyToSections(string $actionKey): array
    {
        $normalized = strtolower($actionKey);
        return match (true) {
            str_contains($normalized, 'work_experiment') => ['career.work_experiments'],
            str_contains($normalized, 'career_next_step') => ['career.next_step'],
            str_contains($normalized, 'relationship_action') => ['relationships.try_this_week'],
            str_contains($normalized, 'watchout') => ['growth.watchouts'],
            default => ['growth.next_actions'],
        };
    }

    private function sectionToTheme(string $sectionKey): string
    {
        return match (true) {
            str_starts_with($sectionKey, 'career.') => 'career',
            str_starts_with($sectionKey, 'growth.watchouts'), str_starts_with($sectionKey, 'growth.stability') => 'stability',
            str_starts_with($sectionKey, 'growth.') => 'growth',
            str_starts_with($sectionKey, 'relationships.') => 'relationships',
            str_starts_with($sectionKey, 'traits.') => 'explainability',
            default => '',
        };
    }

    private function normalizeThemeKey(string $theme): string
    {
        $normalized = strtolower(trim($theme));

        return match ($normalized) {
            'career', 'work' => 'career',
            'growth', 'action' => 'growth',
            'stability', 'stress' => 'stability',
            'relationship', 'relationships', 'communication' => 'relationships',
            'explainability', 'type' => 'explainability',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function classifyCandidateThemes(array $candidate): array
    {
        $haystack = strtolower(implode(' ', array_filter([
            trim((string) ($candidate['key'] ?? '')),
            trim((string) ($candidate['type'] ?? '')),
            trim((string) ($candidate['title'] ?? '')),
            trim((string) ($candidate['url'] ?? '')),
            implode(' ', array_map(static fn (mixed $tag): string => trim((string) $tag), (array) ($candidate['tags'] ?? []))),
        ])));

        $themes = [];
        $matchers = [
            'career' => ['career', 'job', 'role', 'work', '职业', '工作', '岗位'],
            'relationships' => ['relationship', 'communication', 'boundary', '关系', '沟通', '边界'],
            'growth' => ['growth', 'action', 'experiment', 'practice', 'next', 'step', '成长', '行动', '实验', '下一步'],
            'stability' => ['stability', 'stress', 'recovery', 'watchout', 'burnout', '稳定', '压力', '恢复', '风险'],
            'explainability' => ['type', 'mbti', 'borderline', 'contrast', 'adjacent', 'why', '人格', '类型', '边界', '解释'],
        ];

        foreach ($matchers as $theme => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    $themes[] = $theme;
                    break;
                }
            }
        }

        return $themes === []
            ? ['growth']
            : array_values(array_unique($themes));
    }

    private function classifyActionFamily(string $actionKey, string $sectionKey): string
    {
        $normalized = strtolower(trim($actionKey));
        if ($normalized === '') {
            return $this->sectionToTheme($sectionKey);
        }

        return match (true) {
            str_contains($normalized, 'work_experiment'), str_contains($normalized, 'career_next_step') => 'career',
            str_contains($normalized, 'relationship_action') => 'relationships',
            str_contains($normalized, 'watchout') => 'stability',
            default => 'growth',
        };
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        ))));
    }

    private function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }
}
