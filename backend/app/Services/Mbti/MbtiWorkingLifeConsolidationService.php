<?php

declare(strict_types=1);

namespace App\Services\Mbti;

final class MbtiWorkingLifeConsolidationService
{
    private const VERSION = 'mbti.working_life.v1';

    /**
     * @var list<string>
     */
    private const DEFAULT_JOURNEY_KEYS = [
        'career.next_step',
        'career.work_experiments',
        'career.work_environment',
        'career.collaboration_fit',
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

        $authority = $this->buildAuthority($personalization);
        if ($authority === []) {
            return $personalization;
        }

        $personalization['working_life_v1'] = $authority;
        $personalization['career_focus_key'] = $authority['career_focus_key'];
        $personalization['career_journey_keys'] = $authority['career_journey_keys'];
        $personalization['career_action_priority_keys'] = $authority['career_action_priority_keys'];

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function buildAuthority(array $personalization): array
    {
        $careerFocusKey = $this->resolveCareerFocusKey($personalization);
        $careerJourneyKeys = $this->resolveCareerJourneyKeys($careerFocusKey);
        $careerActionPriorityKeys = $this->resolveCareerActionPriorityKeys($careerFocusKey, $personalization);
        $careerReadingKeys = $this->resolveCareerReadingKeys($personalization);
        $supportingScales = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($personalization['supporting_scales'] ?? null) ? $personalization['supporting_scales'] : []
        )));
        $big5InfluenceKeys = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($personalization['big5_influence_keys'] ?? null) ? $personalization['big5_influence_keys'] : []
        )));

        return [
            'version' => self::VERSION,
            'career_focus_key' => $careerFocusKey,
            'career_journey_keys' => $careerJourneyKeys,
            'role_fit_keys' => $this->normalizeStringList($personalization['role_fit_keys'] ?? []),
            'collaboration_fit_keys' => $this->normalizeStringList($personalization['collaboration_fit_keys'] ?? []),
            'work_env_preference_keys' => $this->normalizeStringList($personalization['work_env_preference_keys'] ?? []),
            'career_next_step_keys' => $this->normalizeStringList($personalization['career_next_step_keys'] ?? []),
            'career_action_priority_keys' => $careerActionPriorityKeys,
            'career_reading_keys' => $careerReadingKeys,
            'supporting_scales' => $supportingScales,
            'big5_influence_keys' => $big5InfluenceKeys,
            'synthesis_keys' => $this->resolveCareerSynthesisKeys($personalization),
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function resolveCareerFocusKey(array $personalization): string
    {
        $primaryFocusKey = trim((string) data_get($personalization, 'orchestration.primary_focus_key', ''));
        if (str_starts_with($primaryFocusKey, 'career.')) {
            return $primaryFocusKey;
        }

        if (is_array(data_get($personalization, 'cross_assessment_v1.section_enhancements.career.next_step'))) {
            return 'career.next_step';
        }

        foreach ((array) data_get($personalization, 'orchestration.secondary_focus_keys', []) as $secondaryFocusKey) {
            $secondaryFocusKey = trim((string) $secondaryFocusKey);
            if (str_starts_with($secondaryFocusKey, 'career.')) {
                return $secondaryFocusKey;
            }
        }

        $currentIntentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));
        if ($currentIntentCluster === 'career_move') {
            return data_get($personalization, 'user_state.has_unlock') === true
                ? 'career.work_experiments'
                : 'career.next_step';
        }

        return 'career.next_step';
    }

    /**
     * @return list<string>
     */
    private function resolveCareerJourneyKeys(string $careerFocusKey): array
    {
        $journeyKeys = match ($careerFocusKey) {
            'career.work_experiments' => [
                'career.work_experiments',
                'career.next_step',
                'career.work_environment',
                'career.collaboration_fit',
            ],
            'career.collaboration_fit' => [
                'career.collaboration_fit',
                'career.work_environment',
                'career.next_step',
                'career.work_experiments',
            ],
            'career.work_environment' => [
                'career.work_environment',
                'career.collaboration_fit',
                'career.next_step',
                'career.work_experiments',
            ],
            default => self::DEFAULT_JOURNEY_KEYS,
        };

        return array_values(array_unique(array_filter($journeyKeys)));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return list<string>
     */
    private function resolveCareerActionPriorityKeys(string $careerFocusKey, array $personalization): array
    {
        $keys = [$careerFocusKey];
        foreach (['career.next_step', 'career.work_experiments', 'career_bridge'] as $candidate) {
            if (! in_array($candidate, $keys, true)) {
                $keys[] = $candidate;
            }
        }

        $ctaPriorityKeys = $this->normalizeStringList(data_get($personalization, 'orchestration.cta_priority_keys', []));
        if (in_array('workspace_lite', $ctaPriorityKeys, true) && ! in_array('workspace_lite', $keys, true)) {
            $keys[] = 'workspace_lite';
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return list<string>
     */
    private function resolveCareerReadingKeys(array $personalization): array
    {
        $orderedRecommendationKeys = $this->normalizeStringList($personalization['ordered_recommendation_keys'] ?? []);
        $candidates = is_array($personalization['recommended_read_candidates'] ?? null)
            ? $personalization['recommended_read_candidates']
            : [];

        $candidateByKey = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $key = trim((string) ($candidate['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $candidateByKey[$key] = $candidate;
        }

        $careerReadingKeys = [];
        foreach ($orderedRecommendationKeys as $key) {
            $candidate = $candidateByKey[$key] ?? null;
            $tags = is_array($candidate['tags'] ?? null) ? $candidate['tags'] : [];
            $normalizedTags = array_map(static fn (mixed $tag): string => strtolower(trim((string) $tag)), $tags);

            $isCareerCandidate = str_contains($key, 'career')
                || str_contains($key, 'work')
                || count(array_intersect($normalizedTags, ['career', 'work', 'growth', 'action'])) > 0;

            if ($isCareerCandidate) {
                $careerReadingKeys[] = $key;
            }
        }

        if ($careerReadingKeys === []) {
            $careerReadingKeys = array_slice($orderedRecommendationKeys, 0, 2);
        }

        return array_values(array_unique(array_filter($careerReadingKeys)));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return list<string>
     */
    private function resolveCareerSynthesisKeys(array $personalization): array
    {
        $sectionEnhancements = is_array(data_get($personalization, 'cross_assessment_v1.section_enhancements'))
            ? data_get($personalization, 'cross_assessment_v1.section_enhancements')
            : [];

        $synthesisKeys = [];
        foreach (['career.next_step', 'career.work_environment', 'career.collaboration_fit'] as $sectionKey) {
            $synthesisKey = trim((string) data_get($sectionEnhancements, $sectionKey.'.synthesis_key', ''));
            if ($synthesisKey !== '') {
                $synthesisKeys[] = $synthesisKey;
            }
        }

        foreach ($this->normalizeStringList($personalization['synthesis_keys'] ?? []) as $synthesisKey) {
            if (str_contains($synthesisKey, 'career_') || str_contains($synthesisKey, '.career_')) {
                $synthesisKeys[] = $synthesisKey;
            }
        }

        return array_values(array_unique($synthesisKeys));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ))));
    }
}
