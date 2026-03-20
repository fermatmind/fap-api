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

        return array_merge($personalization, [
            'user_state' => $userState,
            'orchestration' => [
                'ordered_section_keys' => $this->resolveOrderedSectionKeys($primaryFocusKey, $secondaryFocusKeys),
                'primary_focus_key' => $primaryFocusKey,
                'secondary_focus_keys' => $secondaryFocusKeys,
                'cta_priority_keys' => $this->resolveCtaPriorityKeys($userState),
            ],
        ]);
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
