<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use Illuminate\Support\Facades\DB;

final class MbtiLongitudinalMemoryService
{
    private const VERSION = 'mbti.longitudinal_memory.v1';

    private const MEMORY_SCOPE = 'identity_recent_mbti_window';

    private const WINDOW_DAYS = 120;

    private const MAX_ATTEMPTS = 5;

    private const MAX_EVENTS = 180;

    /**
     * @var list<string>
     */
    private const TARGET_SECTION_KEYS = [
        'traits.why_this_type',
        'growth.stability_confidence',
        'growth.next_actions',
        'growth.watchouts',
        'career.next_step',
        'career.work_experiments',
        'relationships.try_this_week',
    ];

    /**
     * @var array<string, string>
     */
    private const SECTION_INTEREST_MAP = [
        'traits.why_this_type' => 'explainability',
        'traits.close_call_axes' => 'explainability',
        'traits.adjacent_type_contrast' => 'explainability',
        'traits.decision_style' => 'explainability',
        'growth.stability_confidence' => 'stability',
        'growth.next_actions' => 'growth',
        'growth.weekly_experiments' => 'growth',
        'growth.watchouts' => 'growth',
        'growth.summary' => 'growth',
        'career.next_step' => 'career',
        'career.work_experiments' => 'career',
        'career.summary' => 'career',
        'career.work_environment' => 'career',
        'relationships.try_this_week' => 'relationships',
        'relationships.communication_style' => 'relationships',
        'relationships.summary' => 'relationships',
    ];

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function attach(array $personalization, array $context = []): array
    {
        if ($personalization === []) {
            return [];
        }

        $memory = $this->buildMemory($personalization, $context);
        if ($memory === []) {
            return $personalization;
        }

        $personalization = $this->applyMemoryRewrites($personalization, $memory);
        $personalization['longitudinal_memory_v1'] = $memory;

        return $personalization;
    }

    /**
     * Reapply an already-issued memory contract without rebuilding it from live DB reads.
     *
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @return array<string, mixed>
     */
    public function attachExistingMemory(array $personalization, array $memory): array
    {
        if ($personalization === [] || $memory === []) {
            return $personalization;
        }

        $personalization = $this->applyMemoryRewrites($personalization, $memory);
        $personalization['longitudinal_memory_v1'] = $memory;

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildMemory(array $personalization, array $context = []): array
    {
        $orgId = (int) ($context['org_id'] ?? 0);
        $attemptId = trim((string) ($context['attempt_id'] ?? ''));
        $userId = $this->normalizeNullableText($context['user_id'] ?? null);
        $anonId = $this->normalizeNullableText($context['anon_id'] ?? null);
        $asOfAt = $this->resolveAsOfAt($context, $orgId, $attemptId);

        if ($attemptId !== '' && $asOfAt === null) {
            return [];
        }

        $attemptRows = $this->fetchRecentAttempts($orgId, $attemptId, $userId, $anonId, $asOfAt);
        $attemptIds = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['id'] ?? '')),
            $attemptRows
        )));
        $eventRows = $this->fetchRecentEvents($orgId, $attemptIds, $attemptId, $asOfAt);
        $hasHistoricalAnchor = $this->hasHistoricalAnchor($eventRows);
        if (! $hasHistoricalAnchor) {
            return [];
        }

        $historySummary = $this->buildHistorySummary($eventRows, $personalization, true);
        $sectionHistoryKeys = array_slice(array_keys($historySummary['section_scores']), 0, 4);
        $dominantInterestKeys = array_slice(array_keys($historySummary['interest_scores']), 0, 3);
        $resumeBiasKeys = $this->buildResumeBiasKeys($personalization, $sectionHistoryKeys, $dominantInterestKeys);
        $behaviorDeltaKeys = $this->buildBehaviorDeltaKeys($historySummary, $personalization);
        $rewriteReason = $this->resolveMemoryRewriteReason($dominantInterestKeys, $behaviorDeltaKeys, $resumeBiasKeys, $personalization);
        $memoryState = $this->resolveMemoryState($historySummary, $behaviorDeltaKeys, $personalization);
        $progressionState = $this->resolveProgressionState($historySummary, $personalization);
        $memoryRewriteKeys = $this->buildMemoryRewriteKeys($rewriteReason, $resumeBiasKeys, $dominantInterestKeys);

        $memoryEvidence = [
            'attempt_ids' => $attemptIds,
            'event_count' => count($eventRows),
            'section_scores' => $historySummary['section_scores'],
            'interest_scores' => $historySummary['interest_scores'],
            'negative_feedback_scores' => $historySummary['negative_feedback_scores'],
            'continue_target_scores' => $historySummary['continue_scores'],
            'resume_bias_candidates' => $resumeBiasKeys,
            'action_engagement_count' => $historySummary['action_engagement_count'],
            'revisit_view_count' => $historySummary['revisit_view_count'],
            'dwell_count' => $historySummary['dwell_count'],
        ];

        return [
            'version' => self::VERSION,
            'memory_contract_version' => self::VERSION,
            'memory_fingerprint' => hash('sha256', json_encode([
                'version' => self::VERSION,
                'attempt_ids' => $attemptIds,
                'section_history_keys' => $sectionHistoryKeys,
                'behavior_delta_keys' => $behaviorDeltaKeys,
                'dominant_interest_keys' => $dominantInterestKeys,
                'resume_bias_keys' => $resumeBiasKeys,
                'memory_rewrite_keys' => $memoryRewriteKeys,
                'memory_rewrite_reason' => $rewriteReason,
                'memory_state' => $memoryState,
                'progression_state' => $progressionState,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            'memory_scope' => self::MEMORY_SCOPE,
            'memory_state' => $memoryState,
            'progression_state' => $progressionState,
            'section_history_keys' => $sectionHistoryKeys,
            'behavior_delta_keys' => $behaviorDeltaKeys,
            'dominant_interest_keys' => $dominantInterestKeys,
            'resume_bias_keys' => $resumeBiasKeys,
            'memory_rewrite_keys' => $memoryRewriteKeys,
            'memory_rewrite_reason' => $rewriteReason,
            'memory_confidence' => $this->resolveMemoryConfidence($attemptRows, $eventRows, $sectionHistoryKeys, $behaviorDeltaKeys),
            'memory_window' => [
                'days' => self::WINDOW_DAYS,
                'attempt_count' => count($attemptRows),
                'event_count' => count($eventRows),
            ],
            'memory_evidence' => $memoryEvidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @return array<string, mixed>
     */
    private function applyMemoryRewrites(array $personalization, array $memory): array
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

            $selectionMode = $this->resolveSectionSelectionMode($sectionKey, $memory);
            if ($selectionMode === '') {
                continue;
            }

            $selectedBlocks = $this->selectBlocksForMode($sectionKey, $section, $selectionMode);
            if ($selectedBlocks === []) {
                continue;
            }

            $sections[$sectionKey]['selected_blocks'] = $selectedBlocks;
            $sections[$sectionKey]['selection_mode'] = $selectionMode;

            $baseSelectionKey = trim((string) ($sectionSelectionKeys[$sectionKey] ?? ''));
            $sectionSelectionKeys[$sectionKey] = $baseSelectionKey !== ''
                ? $baseSelectionKey.':memory.'.$this->normalizeKey((string) ($memory['memory_rewrite_reason'] ?? '')).':mode.'.$this->normalizeKey($selectionMode)
                : implode(':', array_values(array_filter([
                    $sectionKey,
                    'memory.'.$this->normalizeKey((string) ($memory['memory_rewrite_reason'] ?? '')),
                    'mode.'.$this->normalizeKey($selectionMode),
                    'blocks.'.implode('+', array_map([$this, 'normalizeKey'], $selectedBlocks)),
                ])));
        }

        $personalization['sections'] = $sections;
        $personalization['section_selection_keys'] = $sectionSelectionKeys;

        $resumeActionKey = $this->resolveResumeActionKey($personalization, $memory);
        if ($resumeActionKey !== '') {
            $personalization['action_focus_key'] = $resumeActionKey;
            $orderedActionKeys = $this->normalizeStringList($personalization['ordered_action_keys'] ?? []);
            array_unshift($orderedActionKeys, $resumeActionKey);
            $personalization['ordered_action_keys'] = array_values(array_unique(array_filter($orderedActionKeys)));
        }

        $personalization['action_selection_keys'] = $this->buildActionSelectionKeys(
            $sections,
            $actionSelectionKeys,
            $memory,
            $resumeActionKey
        );
        $personalization['recommendation_selection_keys'] = $this->buildRecommendationSelectionKeys($personalization, $memory);
        $personalization['selection_evidence'] = $this->mergeSelectionEvidence(
            is_array($personalization['selection_evidence'] ?? null) ? $personalization['selection_evidence'] : [],
            $memory
        );
        $personalization['selection_fingerprint'] = $this->buildSelectionFingerprint($personalization, $memory);
        $personalization['continuity'] = $this->mergeContinuity($personalization, $memory);

        return $personalization;
    }

    /**
     * @return list<array{id:string}>
     */
    private function fetchRecentAttempts(int $orgId, string $attemptId, ?string $userId, ?string $anonId, ?string $asOfAt = null): array
    {
        if ($attemptId === '' && $userId === null && $anonId === null) {
            return [];
        }

        $rows = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where('scale_code', 'MBTI')
            ->where(function ($query) use ($attemptId, $userId, $anonId): void {
                if ($attemptId !== '') {
                    $query->orWhere('id', $attemptId);
                }

                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);

                    return;
                }

                if ($anonId !== null) {
                    $query->orWhere('anon_id', $anonId);
                }
            })
            ->where(function ($query): void {
                $query->where('submitted_at', '>=', now()->subDays(self::WINDOW_DAYS))
                    ->orWhere(function ($fallback): void {
                        $fallback->whereNull('submitted_at')
                            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS));
                    });
            });

        if ($asOfAt !== null) {
            $rows->whereRaw('coalesce(submitted_at, created_at) <= ?', [$asOfAt]);
        }

        $rows = $rows
            ->orderByRaw('coalesce(submitted_at, created_at) desc')
            ->limit(self::MAX_ATTEMPTS)
            ->get(['id', 'submitted_at', 'created_at']);

        return array_map(static function (object $row): array {
            return [
                'id' => trim((string) ($row->id ?? '')),
                'submitted_at' => $row->submitted_at,
                'created_at' => $row->created_at,
            ];
        }, $rows->all());
    }

    /**
     * @param  list<string>  $attemptIds
     * @return list<array{attempt_id:string,event_code:string,meta:array<string,mixed>}>
     */
    private function fetchRecentEvents(int $orgId, array $attemptIds, string $currentAttemptId = '', ?string $asOfAt = null): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $query = DB::table('events')
            ->where('org_id', $orgId)
            ->whereIn('attempt_id', $attemptIds)
            ->orderByDesc('occurred_at');

        if ($currentAttemptId !== '') {
            $query->where('attempt_id', '!=', $currentAttemptId);
        }

        if ($asOfAt !== null) {
            $query->where('occurred_at', '<=', $asOfAt);
        }

        $rows = $query
            ->limit(self::MAX_EVENTS)
            ->get(['attempt_id', 'event_code', 'meta_json']);

        $events = [];
        foreach ($rows as $row) {
            $eventCode = trim((string) ($row->event_code ?? ''));
            if ($eventCode === '') {
                continue;
            }

            $events[] = [
                'attempt_id' => trim((string) ($row->attempt_id ?? '')),
                'event_code' => $eventCode,
                'meta' => $this->normalizeMetaJson($row->meta_json ?? null),
            ];
        }

        return $events;
    }

    /**
     * @param  list<array{attempt_id:string,event_code:string,meta:array<string,mixed>}>  $eventRows
     * @param  array<string, mixed>  $personalization
     * @return array{
     *   section_scores:array<string,int>,
     *   interest_scores:array<string,int>,
     *   negative_feedback_scores:array<string,int>,
     *   continue_scores:array<string,int>,
     *   action_engagement_count:int,
     *   revisit_view_count:int,
     *   dwell_count:int
     * }
     */
    private function buildHistorySummary(array $eventRows, array $personalization, bool $includeSupplementalState): array
    {
        $sectionScores = [];
        $interestScores = [];
        $negativeFeedbackScores = [];
        $continueScores = [];
        $actionEngagementCount = 0;
        $revisitViewCount = 0;
        $dwellCount = 0;

        foreach ($eventRows as $row) {
            $eventCode = $row['event_code'];
            $meta = $row['meta'];
            $sectionKey = trim((string) ($meta['sectionKey'] ?? $meta['section_key'] ?? ''));
            $actionKey = trim((string) ($meta['actionKey'] ?? $meta['action_key'] ?? ''));
            $recommendationKey = trim((string) ($meta['recommendationKey'] ?? $meta['recommendation_key'] ?? ''));
            $continueTarget = trim((string) ($meta['continueTarget'] ?? $meta['continue_target'] ?? ''));
            $interaction = trim((string) ($meta['interaction'] ?? ''));

            if (in_array($eventCode, ['result_view', 'report_view'], true)) {
                $revisitViewCount++;
            }

            if ($eventCode === 'ui_card_interaction') {
                if ($sectionKey !== '') {
                    $sectionScores[$sectionKey] = ($sectionScores[$sectionKey] ?? 0) + ($interaction === 'dwell_2500ms' ? 3 : 1);
                    $interestKey = $this->sectionToInterestKey($sectionKey);
                    if ($interestKey !== '') {
                        $interestScores[$interestKey] = ($interestScores[$interestKey] ?? 0) + ($interaction === 'dwell_2500ms' ? 3 : 1);
                    }
                }

                if ($interaction === 'dwell_2500ms') {
                    $dwellCount++;
                }

                if ($actionKey !== '' || in_array($sectionKey, ['growth.next_actions', 'growth.watchouts', 'career.work_experiments', 'relationships.try_this_week'], true)) {
                    $actionEngagementCount++;
                    $theme = $this->classifyActionTheme($actionKey, $sectionKey);
                    if ($theme !== '') {
                        $interestScores[$theme] = ($interestScores[$theme] ?? 0) + 2;
                    }
                }

                if ($recommendationKey !== '') {
                    $theme = $this->classifyRecommendationTheme($recommendationKey);
                    if ($theme !== '') {
                        $interestScores[$theme] = ($interestScores[$theme] ?? 0) + 2;
                    }
                }

                if ($continueTarget !== '') {
                    $continueKey = $this->classifyContinueTarget($continueTarget, $sectionKey);
                    if ($continueKey !== '') {
                        $continueScores[$continueKey] = ($continueScores[$continueKey] ?? 0) + 1;
                    }
                }

                continue;
            }

            if ($eventCode === 'accuracy_feedback') {
                if ($sectionKey !== '') {
                    $sectionScores[$sectionKey] = ($sectionScores[$sectionKey] ?? 0) + 2;
                    $interestKey = $this->sectionToInterestKey($sectionKey);
                    if ($interestKey !== '') {
                        $interestScores[$interestKey] = ($interestScores[$interestKey] ?? 0) + 1;
                    }
                }

                $feedback = trim((string) ($meta['feedback'] ?? ''));
                if (in_array($feedback, ['unclear', 'not_me', 'off', 'mixed'], true)) {
                    $category = $this->sectionToInterestKey($sectionKey);
                    if ($category !== '') {
                        $negativeFeedbackScores[$category] = ($negativeFeedbackScores[$category] ?? 0) + 1;
                    }
                }
            }
        }

        $lastDeepReadSection = trim((string) data_get($personalization, 'user_state.last_deep_read_section', ''));
        if ($includeSupplementalState && $lastDeepReadSection !== '') {
            $sectionScores[$lastDeepReadSection] = ($sectionScores[$lastDeepReadSection] ?? 0) + 2;
            $interestKey = $this->sectionToInterestKey($lastDeepReadSection);
            if ($interestKey !== '') {
                $interestScores[$interestKey] = ($interestScores[$interestKey] ?? 0) + 1;
            }
        }

        arsort($sectionScores);
        arsort($interestScores);
        arsort($negativeFeedbackScores);
        arsort($continueScores);

        return [
            'section_scores' => $sectionScores,
            'interest_scores' => $interestScores,
            'negative_feedback_scores' => $negativeFeedbackScores,
            'continue_scores' => $continueScores,
            'action_engagement_count' => $actionEngagementCount,
            'revisit_view_count' => $revisitViewCount,
            'dwell_count' => $dwellCount,
        ];
    }

    /**
     * @param  list<string>  $sectionHistoryKeys
     * @param  list<string>  $dominantInterestKeys
     * @param  array<string, mixed>  $personalization
     * @return list<string>
     */
    private function buildResumeBiasKeys(array $personalization, array $sectionHistoryKeys, array $dominantInterestKeys): array
    {
        $keys = [
            trim((string) data_get($personalization, 'continuity.carryover_focus_key', '')),
            trim((string) data_get($personalization, 'orchestration.primary_focus_key', '')),
        ];

        $keys = array_merge(
            $keys,
            array_slice($sectionHistoryKeys, 0, 3),
            array_slice($this->normalizeStringList(data_get($personalization, 'continuity.recommended_resume_keys', [])), 0, 3)
        );

        foreach ($dominantInterestKeys as $interestKey) {
            $keys[] = match ($interestKey) {
                'career' => 'career.next_step',
                'growth', 'stability' => 'growth.next_actions',
                'explainability' => 'traits.why_this_type',
                'relationships' => 'relationships.try_this_week',
                default => '',
            };
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param  array{
     *   section_scores:array<string,int>,
     *   interest_scores:array<string,int>,
     *   negative_feedback_scores:array<string,int>,
     *   continue_scores:array<string,int>,
     *   action_engagement_count:int,
     *   revisit_view_count:int,
     *   dwell_count:int
     * }  $historySummary
     * @param  array<string, mixed>  $personalization
     * @return list<string>
     */
    private function buildBehaviorDeltaKeys(array $historySummary, array $personalization): array
    {
        $keys = [];

        if (($historySummary['revisit_view_count'] ?? 0) >= 2) {
            $keys[] = 'behavior.revisit.repeat';
        }

        if (($historySummary['dwell_count'] ?? 0) >= 2) {
            $keys[] = 'behavior.reading.deep_repeat';
        }

        if (($historySummary['action_engagement_count'] ?? 0) >= 2) {
            $keys[] = 'behavior.action.repeat';
        }

        foreach (array_slice(array_keys($historySummary['section_scores'] ?? []), 0, 2) as $sectionKey) {
            $keys[] = 'behavior.section.'.$this->normalizeKey($sectionKey).'.repeat';
        }

        foreach (array_keys($historySummary['negative_feedback_scores'] ?? []) as $interestKey) {
            $keys[] = 'behavior.feedback.'.$this->normalizeKey($interestKey).'.negative';
        }

        foreach (array_keys($historySummary['continue_scores'] ?? []) as $continueKey) {
            $keys[] = 'behavior.resume.'.$this->normalizeKey($continueKey);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param  list<string>  $dominantInterestKeys
     * @param  list<string>  $behaviorDeltaKeys
     * @param  list<string>  $resumeBiasKeys
     * @param  array<string, mixed>  $personalization
     */
    private function resolveMemoryRewriteReason(
        array $dominantInterestKeys,
        array $behaviorDeltaKeys,
        array $resumeBiasKeys,
        array $personalization
    ): string {
        if ($this->containsPrefix($behaviorDeltaKeys, 'behavior.feedback.explainability.') || $this->containsSection($resumeBiasKeys, 'traits.')) {
            return 'refine_type_clarity';
        }

        if (in_array('career', $dominantInterestKeys, true) || $this->containsSection($resumeBiasKeys, 'career.')) {
            return 'resume_career_focus';
        }

        if (in_array('relationships', $dominantInterestKeys, true) || $this->containsSection($resumeBiasKeys, 'relationships.')) {
            return 'resume_relationship_practice';
        }

        if (
            in_array('growth', $dominantInterestKeys, true)
            || in_array('stability', $dominantInterestKeys, true)
            || $this->containsSection($resumeBiasKeys, 'growth.')
        ) {
            return 'resume_growth_actions';
        }

        $lastDeepReadSection = trim((string) data_get($personalization, 'user_state.last_deep_read_section', ''));
        if ($lastDeepReadSection !== '') {
            return 'resume_previous_focus';
        }

        return 'build_memory_context';
    }

    /**
     * @param  array{
     *   action_engagement_count:int,
     *   revisit_view_count:int,
     *   dwell_count:int
     * }  $historySummary
     * @param  list<string>  $behaviorDeltaKeys
     * @param  array<string, mixed>  $personalization
     */
    private function resolveMemoryState(array $historySummary, array $behaviorDeltaKeys, array $personalization): string
    {
        if ($this->containsPrefix($behaviorDeltaKeys, 'behavior.feedback.')) {
            return 'refining';
        }

        if (($historySummary['action_engagement_count'] ?? 0) >= 2) {
            return 'active';
        }

        if (($historySummary['revisit_view_count'] ?? 0) >= 2) {
            return 'resume_ready';
        }

        if (($historySummary['dwell_count'] ?? 0) >= 1) {
            return 'warming';
        }

        return 'building';
    }

    /**
     * @param  array{
     *   action_engagement_count:int,
     *   revisit_view_count:int
     * }  $historySummary
     * @param  array<string, mixed>  $personalization
     */
    private function resolveProgressionState(array $historySummary, array $personalization): string
    {
        if (($historySummary['action_engagement_count'] ?? 0) >= 2) {
            return 'repeatable';
        }

        if (($historySummary['revisit_view_count'] ?? 0) >= 2 && ($historySummary['action_engagement_count'] ?? 0) === 0) {
            return 'reading_loop';
        }

        if (($historySummary['action_engagement_count'] ?? 0) >= 1) {
            return 'warming_up';
        }

        return 'fresh';
    }

    /**
     * @param  list<array{attempt_id:string,event_code:string,meta:array<string,mixed>}>  $eventRows
     */
    private function hasHistoricalAnchor(array $eventRows): bool
    {
        return $eventRows !== [];
    }

    /**
     * @param  list<string>  $resumeBiasKeys
     * @param  list<string>  $dominantInterestKeys
     * @return list<string>
     */
    private function buildMemoryRewriteKeys(string $rewriteReason, array $resumeBiasKeys, array $dominantInterestKeys): array
    {
        $keys = ['rewrite.reason.'.$this->normalizeKey($rewriteReason)];

        foreach (array_slice($resumeBiasKeys, 0, 3) as $resumeKey) {
            $keys[] = 'rewrite.resume.'.$this->normalizeKey($resumeKey);
        }

        foreach ($dominantInterestKeys as $interestKey) {
            $keys[] = 'rewrite.interest.'.$this->normalizeKey($interestKey);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function resolveMemoryConfidence(array $attemptRows, array $eventRows, array $sectionHistoryKeys, array $behaviorDeltaKeys): float
    {
        $score = (count($attemptRows) * 0.18) + (min(count($eventRows), 20) * 0.02) + (count($sectionHistoryKeys) * 0.08) + (count($behaviorDeltaKeys) * 0.06);

        return round((float) min(1, max(0.2, $score)), 2);
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    private function selectBlocksForMode(string $sectionKey, array $section, string $selectionMode): array
    {
        $availableBlocks = array_values(array_filter(
            array_map(static fn (mixed $block): array => is_array($block) ? $block : [], (array) ($section['blocks'] ?? [])),
            static fn (array $block): bool => trim((string) ($block['id'] ?? '')) !== ''
        ));
        if ($availableBlocks === []) {
            return [];
        }

        $blocksByKind = [];
        foreach ($availableBlocks as $block) {
            $kind = trim((string) ($block['kind'] ?? ''));
            $id = trim((string) ($block['id'] ?? ''));
            if ($kind === '' || $id === '') {
                continue;
            }

            $blocksByKind[$kind] ??= [];
            $blocksByKind[$kind][] = $id;
        }

        $selected = [];
        foreach ($this->preferredKindsForMode($sectionKey, $selectionMode) as $kind) {
            foreach ((array) ($blocksByKind[$kind] ?? []) as $blockId) {
                $selected[] = $blockId;
            }
        }

        foreach ($this->normalizeStringList($section['selected_blocks'] ?? []) as $blockId) {
            $selected[] = $blockId;
        }

        return array_slice(array_values(array_unique(array_filter($selected))), 0, $this->maxBlocksForSection($sectionKey, $selectionMode));
    }

    /**
     * @return list<string>
     */
    private function preferredKindsForMode(string $sectionKey, string $selectionMode): array
    {
        return match ($sectionKey) {
            'traits.why_this_type' => ['why_this_type', 'misunderstanding_fix', 'boundary', 'identity'],
            'growth.stability_confidence' => ['stability_explanation', 'stability_reframe', 'stress_recovery', 'boundary'],
            'growth.next_actions' => $selectionMode === 'memory.action_refine'
                ? ['next_action', 'revisit_resume', 'adaptive_retry', 'action_experiment', 'boundary']
                : ['next_action', 'action_experiment', 'action_momentum_start', 'identity', 'boundary'],
            'growth.watchouts' => ['watchout', 'recovery_reset', 'watchout_overextension', 'boundary', 'identity'],
            'career.next_step' => ['career_next_step', 'work_scene_transition', 'work_scene_role_fit', 'boundary', 'axis_strength'],
            'career.work_experiments' => ['work_experiment', 'work_scene_focus_recovery', 'work_scene_collaboration', 'boundary', 'identity'],
            'relationships.try_this_week' => ['relationship_practice', 'relationship_misread_repair', 'recovery_reentry', 'boundary', 'identity'],
            default => [],
        };
    }

    private function maxBlocksForSection(string $sectionKey, string $selectionMode): int
    {
        return match ($sectionKey) {
            'growth.stability_confidence' => 3,
            'traits.why_this_type' => $selectionMode === 'memory.explainability_resume' ? 3 : 2,
            'growth.next_actions',
            'growth.watchouts',
            'career.next_step',
            'career.work_experiments',
            'relationships.try_this_week' => 4,
            default => 3,
        };
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function resolveSectionSelectionMode(string $sectionKey, array $memory): string
    {
        $rewriteReason = trim((string) ($memory['memory_rewrite_reason'] ?? ''));

        return match ($sectionKey) {
            'traits.why_this_type' => $rewriteReason === 'refine_type_clarity' ? 'memory.explainability_resume' : '',
            'growth.stability_confidence' => $rewriteReason === 'refine_type_clarity' ? 'memory.stability_refine' : '',
            'growth.next_actions' => in_array($rewriteReason, ['resume_growth_actions', 'resume_previous_focus'], true)
                ? ($rewriteReason === 'resume_previous_focus' ? 'memory.action_refine' : 'memory.action_resume')
                : '',
            'growth.watchouts' => in_array($rewriteReason, ['resume_growth_actions', 'refine_type_clarity'], true) ? 'memory.watchout_resume' : '',
            'career.next_step' => $rewriteReason === 'resume_career_focus' ? 'memory.career_resume' : '',
            'career.work_experiments' => $rewriteReason === 'resume_career_focus' ? 'memory.career_experiment_resume' : '',
            'relationships.try_this_week' => $rewriteReason === 'resume_relationship_practice' ? 'memory.relationship_resume' : '',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function resolveResumeActionKey(array $personalization, array $memory): string
    {
        $rewriteReason = trim((string) ($memory['memory_rewrite_reason'] ?? ''));
        $continuityActionKeys = $this->normalizeStringList(data_get($personalization, 'continuity.carryover_action_keys', []));
        $journeyActionKeys = $this->normalizeStringList(data_get($personalization, 'action_journey_v1.carryover_action_keys', []));
        $orderedActionKeys = $this->normalizeStringList($personalization['ordered_action_keys'] ?? []);
        $careerActionPriorityKeys = $this->normalizeStringList(data_get($personalization, 'working_life_v1.career_action_priority_keys', []));

        return match ($rewriteReason) {
            'resume_career_focus' => $this->firstMatchingKey(
                array_merge($careerActionPriorityKeys, $continuityActionKeys, $journeyActionKeys, $orderedActionKeys),
                ['career', 'work_experiment', 'bridge']
            ),
            'resume_relationship_practice' => $this->firstMatchingKey(
                array_merge($continuityActionKeys, $journeyActionKeys, $orderedActionKeys),
                ['relationship_action', 'relationship', 'communication']
            ),
            default => $this->firstNonEmptyKey(
                array_merge($continuityActionKeys, $journeyActionKeys, $orderedActionKeys)
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $sections
     * @param  array<string, string>  $existingKeys
     * @param  array<string, mixed>  $memory
     * @return array<string, string>
     */
    private function buildActionSelectionKeys(array $sections, array $existingKeys, array $memory, string $resumeActionKey): array
    {
        $keys = $existingKeys;
        $rewriteReason = $this->normalizeKey((string) ($memory['memory_rewrite_reason'] ?? ''));

        foreach (['growth.next_actions', 'career.work_experiments', 'career.next_step', 'growth.watchouts', 'relationships.try_this_week'] as $sectionKey) {
            $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : [];
            if ($section === []) {
                continue;
            }

            $actionKey = trim((string) ($section['action_key'] ?? ''));
            $selectionMode = trim((string) ($section['selection_mode'] ?? ''));
            $base = trim((string) ($keys[$sectionKey] ?? ''));
            $resolvedActionKey = $resumeActionKey !== '' && $sectionKey !== 'career.next_step' ? $resumeActionKey : $actionKey;

            if ($resolvedActionKey === '' && $base === '') {
                continue;
            }

            $keys[$sectionKey] = $base !== ''
                ? $base.':memory.'.$rewriteReason.($selectionMode !== '' ? ':mode.'.$this->normalizeKey($selectionMode) : '')
                : implode(':', array_values(array_filter([
                    $sectionKey,
                    'memory.'.$rewriteReason,
                    $selectionMode !== '' ? 'mode.'.$this->normalizeKey($selectionMode) : null,
                    $resolvedActionKey !== '' ? 'action.'.$this->normalizeKey($resolvedActionKey) : null,
                ])));
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @return list<string>
     */
    private function buildRecommendationSelectionKeys(array $personalization, array $memory): array
    {
        $candidates = is_array($personalization['recommended_read_candidates'] ?? null)
            ? $personalization['recommended_read_candidates']
            : [];
        if ($candidates === []) {
            return $this->normalizeStringList($personalization['recommendation_selection_keys'] ?? []);
        }

        $dominantInterestKeys = $this->normalizeStringList($memory['dominant_interest_keys'] ?? []);
        $resumeBiasKeys = $this->normalizeStringList($memory['resume_bias_keys'] ?? []);
        $orderedRecommendationKeys = $this->normalizeStringList($personalization['ordered_recommendation_keys'] ?? []);
        $orderMap = [];
        foreach ($orderedRecommendationKeys as $index => $key) {
            $orderMap[$key] = $index;
        }
        $tagWeights = $this->buildRecommendationTagWeights($memory);

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

            foreach ($dominantInterestKeys as $interestIndex => $interestKey) {
                if (in_array($interestKey, $themes, true)) {
                    $score += max(0, 180 - ($interestIndex * 20));
                }
            }

            foreach ($resumeBiasKeys as $resumeKey) {
                if ($resumeKey !== '' && str_contains(strtolower($key), $this->normalizeKey($resumeKey))) {
                    $score += 50;
                }
            }

            $score += $this->scoreCandidateTags($candidate, $tagWeights);

            if (isset($orderMap[$key])) {
                $score += max(0, 70 - ((int) $orderMap[$key] * 5));
            }

            $priority = is_numeric($candidate['priority'] ?? null) ? (int) round((float) $candidate['priority']) : 0;
            $score += max(0, 30 - min(30, $priority));

            $scored[] = [
                'key' => $key,
                'score' => $score,
                'priority' => $priority,
                'index' => $index,
            ];
        }

        usort($scored, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            if ($left['priority'] !== $right['priority']) {
                return $left['priority'] <=> $right['priority'];
            }

            return $left['index'] <=> $right['index'];
        });

        $selected = array_map(
            static fn (array $row): string => trim((string) ($row['key'] ?? '')),
            array_slice($scored, 0, 4)
        );

        if (count(array_filter($selected)) < min(3, count($orderedRecommendationKeys))) {
            $selected = array_merge($selected, array_slice($orderedRecommendationKeys, 0, 4));
        }

        return array_values(array_slice(array_unique(array_filter($selected)), 0, 4));
    }

    /**
     * @param  array<string, mixed>  $memory
     * @return array<string, int>
     */
    private function buildRecommendationTagWeights(array $memory): array
    {
        $weights = [];

        $memoryState = strtolower(trim((string) ($memory['memory_state'] ?? '')));
        if ($memoryState !== '') {
            $weights['memory:'.$memoryState] = 100;
        }

        foreach ($this->normalizeStringList($memory['dominant_interest_keys'] ?? []) as $interestKey) {
            $weights['focus:'.$this->normalizeKey($interestKey)] = 90;
        }

        foreach ($this->normalizeStringList($memory['resume_bias_keys'] ?? []) as $resumeKey) {
            foreach ($this->resumeBiasKeyToTags($resumeKey) as $tag) {
                $weights[$tag] = max($weights[$tag] ?? 0, 85);
            }
        }

        return $weights;
    }

    /**
     * @return list<string>
     */
    private function resumeBiasKeyToTags(string $resumeKey): array
    {
        $normalized = strtolower(trim($resumeKey));
        if ($normalized === '') {
            return [];
        }

        return match (true) {
            str_contains($normalized, 'career') || str_contains($normalized, 'work') => ['focus:career_next_step', 'scene:work'],
            str_contains($normalized, 'relationship') || str_contains($normalized, 'communication') => ['focus:relationship_repair', 'scene:communication'],
            str_contains($normalized, 'watchout') || str_contains($normalized, 'stability') => ['focus:growth_recovery', 'scene:stress_recovery'],
            default => ['focus:revisit_resume', 'scene:growth'],
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, int>  $weights
     */
    private function scoreCandidateTags(array $candidate, array $weights): int
    {
        $score = 0;
        foreach ($this->normalizeCandidateTags($candidate) as $tag) {
            $score += (int) ($weights[$tag] ?? 0);
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function normalizeCandidateTags(array $candidate): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $tag): string => strtolower(trim((string) $tag)),
            is_array($candidate['tags'] ?? null) ? $candidate['tags'] : []
        ))));
    }

    /**
     * @param  array<string, mixed>  $selectionEvidence
     * @param  array<string, mixed>  $memory
     * @return array<string, mixed>
     */
    private function mergeSelectionEvidence(array $selectionEvidence, array $memory): array
    {
        $selectionEvidence['longitudinal_memory'] = [
            'memory_contract_version' => trim((string) ($memory['memory_contract_version'] ?? '')),
            'memory_fingerprint' => trim((string) ($memory['memory_fingerprint'] ?? '')),
            'memory_state' => trim((string) ($memory['memory_state'] ?? '')),
            'progression_state' => trim((string) ($memory['progression_state'] ?? '')),
            'section_history_keys' => $this->normalizeStringList($memory['section_history_keys'] ?? []),
            'behavior_delta_keys' => $this->normalizeStringList($memory['behavior_delta_keys'] ?? []),
            'resume_bias_keys' => $this->normalizeStringList($memory['resume_bias_keys'] ?? []),
            'memory_rewrite_keys' => $this->normalizeStringList($memory['memory_rewrite_keys'] ?? []),
            'memory_rewrite_reason' => trim((string) ($memory['memory_rewrite_reason'] ?? '')),
        ];

        return $selectionEvidence;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     */
    private function buildSelectionFingerprint(array $personalization, array $memory): string
    {
        return hash('sha256', json_encode([
            'selection_fingerprint' => trim((string) ($personalization['selection_fingerprint'] ?? '')),
            'section_selection_keys' => is_array($personalization['section_selection_keys'] ?? null) ? $personalization['section_selection_keys'] : [],
            'action_selection_keys' => is_array($personalization['action_selection_keys'] ?? null) ? $personalization['action_selection_keys'] : [],
            'recommendation_selection_keys' => is_array($personalization['recommendation_selection_keys'] ?? null) ? $personalization['recommendation_selection_keys'] : [],
            'memory_fingerprint' => trim((string) ($memory['memory_fingerprint'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $memory
     * @return array<string, mixed>
     */
    private function mergeContinuity(array $personalization, array $memory): array
    {
        $continuity = is_array($personalization['continuity'] ?? null) ? $personalization['continuity'] : [];
        $resumeBiasKeys = $this->normalizeStringList($memory['resume_bias_keys'] ?? []);
        $existingResumeKeys = $this->normalizeStringList($continuity['recommended_resume_keys'] ?? []);
        $continuity['recommended_resume_keys'] = array_values(array_unique(array_filter(array_merge($resumeBiasKeys, $existingResumeKeys))));

        if (trim((string) ($continuity['carryover_focus_key'] ?? '')) === '' && $resumeBiasKeys !== []) {
            $continuity['carryover_focus_key'] = $resumeBiasKeys[0];
        }

        return $continuity;
    }

    private function sectionToInterestKey(string $sectionKey): string
    {
        $normalized = trim($sectionKey);
        if ($normalized === '') {
            return '';
        }

        if (array_key_exists($normalized, self::SECTION_INTEREST_MAP)) {
            return self::SECTION_INTEREST_MAP[$normalized];
        }

        return match (true) {
            str_starts_with($normalized, 'career.') => 'career',
            str_starts_with($normalized, 'growth.') => 'growth',
            str_starts_with($normalized, 'traits.') => 'explainability',
            str_starts_with($normalized, 'relationships.') => 'relationships',
            default => '',
        };
    }

    private function classifyActionTheme(string $actionKey, string $sectionKey): string
    {
        $normalized = strtolower(trim($actionKey));
        if ($normalized === '') {
            return $this->sectionToInterestKey($sectionKey);
        }

        return match (true) {
            str_contains($normalized, 'career') || str_contains($normalized, 'work_experiment') => 'career',
            str_contains($normalized, 'relationship') || str_contains($normalized, 'communication') => 'relationships',
            str_contains($normalized, 'watchout') || str_contains($normalized, 'protect_energy') => 'stability',
            default => 'growth',
        };
    }

    private function classifyRecommendationTheme(string $recommendationKey): string
    {
        $normalized = strtolower(trim($recommendationKey));

        return match (true) {
            $normalized === '' => '',
            str_contains($normalized, 'career') || str_contains($normalized, 'work') => 'career',
            str_contains($normalized, 'relationship') || str_contains($normalized, 'communication') => 'relationships',
            str_contains($normalized, 'explain') || str_contains($normalized, 'type') || str_contains($normalized, 'contrast') => 'explainability',
            str_contains($normalized, 'stress') || str_contains($normalized, 'energy') || str_contains($normalized, 'watchout') => 'stability',
            default => 'growth',
        };
    }

    private function classifyContinueTarget(string $continueTarget, string $sectionKey): string
    {
        $normalized = strtolower(trim($continueTarget));

        return match (true) {
            str_contains($normalized, 'career') || str_starts_with($sectionKey, 'career.') => 'career',
            str_contains($normalized, 'history') => 'history',
            str_contains($normalized, 'relationship') || str_starts_with($sectionKey, 'relationships.') => 'relationships',
            str_contains($normalized, 'share') => 'continuity',
            default => $this->sectionToInterestKey($sectionKey),
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
        foreach ([
            'career' => ['career', 'job', 'role', 'work', '职业', '工作', '岗位'],
            'relationships' => ['relationship', 'connection', 'boundary', '关系', '边界', '相处'],
            'explainability' => ['type', 'mbti', 'borderline', 'contrast', 'adjacent', 'why', '人格', '类型', '边界', '解释'],
            'stability' => ['stability', 'stress', 'recovery', 'watchout', '稳定', '压力', '恢复', '风险', 'energy'],
            'growth' => ['growth', 'action', 'experiment', 'practice', 'next', 'step', '成长', '行动', '实验', '练习'],
        ] as $theme => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    $themes[] = $theme;
                    break;
                }
            }
        }

        return $themes === [] ? ['growth'] : array_values(array_unique($themes));
    }

    /**
     * @param  list<string>  $values
     */
    private function containsPrefix(array $values, string $prefix): bool
    {
        foreach ($values as $value) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $values
     */
    private function containsSection(array $values, string $prefix): bool
    {
        foreach ($values as $value) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $needles
     */
    private function firstMatchingKey(array $keys, array $needles): string
    {
        foreach ($needles as $needle) {
            foreach ($keys as $key) {
                if ($key !== '' && str_contains(strtolower($key), strtolower($needle))) {
                    return $key;
                }
            }
        }

        return $this->firstNonEmptyKey($keys);
    }

    /**
     * @param  list<string>  $keys
     */
    private function firstNonEmptyKey(array $keys): string
    {
        foreach ($keys as $key) {
            $normalized = trim((string) $key);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeMetaJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Freeze the memory window to the current attempt timestamp so newer attempts/events
     * cannot retroactively rewrite an older result page.
     */
    private function resolveAsOfAt(array $context, int $orgId, string $attemptId): ?string
    {
        $explicit = trim((string) ($context['as_of_at'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if ($attemptId === '') {
            return null;
        }

        $row = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where('id', $attemptId)
            ->first(['submitted_at', 'created_at']);

        if ($row === null) {
            return null;
        }

        $submittedAt = trim((string) ($row->submitted_at ?? ''));
        if ($submittedAt !== '') {
            return $submittedAt;
        }

        $createdAt = trim((string) ($row->created_at ?? ''));

        return $createdAt !== '' ? $createdAt : null;
    }

    private function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }
}
