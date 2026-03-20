<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use Illuminate\Support\Facades\DB;

final class MbtiUserStateOrchestrationService
{
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

        $userState = [
            'is_first_view' => true,
            'is_revisit' => false,
            'has_unlock' => $hasUnlock,
            'has_feedback' => false,
            'has_share' => false,
            'has_action_engagement' => false,
        ];

        return $this->mergeAuthority($personalization, $userState);
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

        $isRevisit = $this->attemptHasAnyEvent($orgId, $attemptId, ['result_view', 'report_view']);
        $hasFeedback = $this->attemptHasAnyEvent($orgId, $attemptId, ['accuracy_feedback']);
        $hasShare = $this->attemptHasAnyEvent($orgId, $attemptId, ['share_result']) || $this->attemptHasShareRow($attemptId);
        $hasActionEngagement = $this->attemptHasActionEngagement($orgId, $attemptId);

        $userState = [
            'is_first_view' => ! $isRevisit,
            'is_revisit' => $isRevisit,
            'has_unlock' => $hasUnlock,
            'has_feedback' => $hasFeedback,
            'has_share' => $hasShare,
            'has_action_engagement' => $hasActionEngagement,
        ];

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
            $secondaryFocusKeys
        );
        $orderedActionKeys = $this->resolveOrderedActionKeys(
            $personalization,
            $primaryFocusKey,
            $secondaryFocusKeys
        );
        $continuity = $this->resolveContinuity($personalization, $userState, $primaryFocusKey, $secondaryFocusKeys);

        return array_merge($personalization, [
            'user_state' => $userState,
            'orchestration' => [
                'ordered_section_keys' => $this->resolveOrderedSectionKeys($primaryFocusKey, $secondaryFocusKeys),
                'primary_focus_key' => $primaryFocusKey,
                'secondary_focus_keys' => $secondaryFocusKeys,
                'cta_priority_keys' => $this->resolveCtaPriorityKeys($userState),
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
        if (($userState['has_feedback'] ?? false) && $this->isLowConfidencePath($personalization)) {
            return 'growth.stability_confidence';
        }

        if (($userState['is_revisit'] ?? false) === false) {
            return ($userState['has_unlock'] ?? false) ? 'career.next_step' : 'growth.next_actions';
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
        $candidates = ($userState['is_revisit'] ?? false) ? self::SECTION_FOCUS_REVISIT : self::SECTION_FOCUS_FIRST_VIEW;
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
    private function resolveCtaPriorityKeys(array $userState): array
    {
        $hasUnlock = (bool) ($userState['has_unlock'] ?? false);
        $isRevisit = (bool) ($userState['is_revisit'] ?? false);
        $hasFeedback = (bool) ($userState['has_feedback'] ?? false);
        $hasShare = (bool) ($userState['has_share'] ?? false);
        $hasActionEngagement = (bool) ($userState['has_action_engagement'] ?? false);

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
        array $secondaryFocusKeys
    ): array {
        $candidates = $this->normalizeRecommendationCandidates(
            (array) ($personalization['recommended_read_candidates'] ?? [])
        );

        if ($candidates === []) {
            return [];
        }

        $primaryThemes = $this->resolveRecommendationThemesForFocus($primaryFocusKey);
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
        array $secondaryFocusKeys
    ): array {
        $orderedFields = $this->resolveActionFieldOrder($primaryFocusKey, $secondaryFocusKeys);
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
    private function resolveActionFieldOrder(string $primaryFocusKey, array $secondaryFocusKeys): array
    {
        $ordered = [];

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

    private function attemptHasActionEngagement(int $orgId, string $attemptId): bool
    {
        $rows = DB::table('events')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->where('event_code', 'ui_card_interaction')
            ->orderByDesc('occurred_at')
            ->limit(25)
            ->pluck('meta_json');

        foreach ($rows as $rawMeta) {
            $meta = $this->normalizeMetaJson($rawMeta);
            $sectionKey = trim((string) ($meta['sectionKey'] ?? $meta['section_key'] ?? ''));
            $actionKey = trim((string) ($meta['actionKey'] ?? $meta['action_key'] ?? ''));

            if ($actionKey !== '' || in_array($sectionKey, self::ACTION_SECTION_KEYS, true)) {
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
