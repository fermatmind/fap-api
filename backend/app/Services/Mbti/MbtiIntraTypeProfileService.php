<?php

declare(strict_types=1);

namespace App\Services\Mbti;

final class MbtiIntraTypeProfileService
{
    private const VERSION = 'mbti.intra_type_profile.v1';

    /**
     * @var list<string>
     */
    private const TARGET_SECTION_KEYS = [
        'traits.why_this_type',
        'growth.stability_confidence',
        'growth.next_actions',
        'career.next_step',
        'career.work_experiments',
        'growth.watchouts',
        'traits.adjacent_type_contrast',
        'relationships.try_this_week',
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

        $profileSeedKey = $this->resolveProfileSeedKey($personalization);
        $sameTypeDivergenceKeys = $this->buildSameTypeDivergenceKeys($personalization, $profileSeedKey);
        $personalization = $this->applyFirstWaveSectionSelections(
            $personalization,
            $profileSeedKey,
            $sameTypeDivergenceKeys
        );

        $profile = $this->buildAuthority($personalization, $profileSeedKey, $sameTypeDivergenceKeys);
        if ($profile === []) {
            return $personalization;
        }

        $personalization['intra_type_profile_v1'] = $profile;
        $personalization['profile_seed_key'] = $profile['profile_seed_key'];
        $personalization['same_type_divergence_keys'] = $profile['same_type_divergence_keys'];
        $personalization['section_selection_keys'] = $profile['section_selection_keys'];
        $personalization['action_selection_keys'] = $profile['action_selection_keys'];
        $personalization['recommendation_selection_keys'] = $profile['recommendation_selection_keys'];
        $personalization['selection_fingerprint'] = $profile['selection_fingerprint'];
        $personalization['selection_evidence'] = $profile['selection_evidence'];

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function buildAuthority(
        array $personalization,
        ?string $profileSeedKey = null,
        ?array $sameTypeDivergenceKeys = null
    ): array
    {
        $profileSeedKey ??= $this->resolveProfileSeedKey($personalization);
        $sameTypeDivergenceKeys ??= $this->buildSameTypeDivergenceKeys($personalization, $profileSeedKey);
        $sectionSelectionKeys = $this->buildSectionSelectionKeys($personalization, $profileSeedKey);
        $actionSelectionKeys = $this->buildActionSelectionKeys($personalization, $profileSeedKey);
        $recommendationSelectionKeys = $this->buildRecommendationSelectionKeys(
            $personalization,
            $profileSeedKey,
            $sameTypeDivergenceKeys,
            $sectionSelectionKeys
        );
        $selectionEvidence = $this->buildSelectionEvidence($personalization, $profileSeedKey, $sameTypeDivergenceKeys);
        $selectionFingerprint = hash('sha256', json_encode([
            'version' => self::VERSION,
            'type_code' => trim((string) ($personalization['type_code'] ?? '')),
            'identity' => trim((string) ($personalization['identity'] ?? '')),
            'profile_seed_key' => $profileSeedKey,
            'same_type_divergence_keys' => $sameTypeDivergenceKeys,
            'section_selection_keys' => $sectionSelectionKeys,
            'action_selection_keys' => $actionSelectionKeys,
            'recommendation_selection_keys' => $recommendationSelectionKeys,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'version' => self::VERSION,
            'profile_seed_key' => $profileSeedKey,
            'same_type_divergence_keys' => $sameTypeDivergenceKeys,
            'section_selection_keys' => $sectionSelectionKeys,
            'action_selection_keys' => $actionSelectionKeys,
            'recommendation_selection_keys' => $recommendationSelectionKeys,
            'selection_fingerprint' => $selectionFingerprint,
            'selection_evidence' => $selectionEvidence,
            'persona_cluster_key' => $profileSeedKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $sameTypeDivergenceKeys
     * @return array<string, mixed>
     */
    private function applyFirstWaveSectionSelections(
        array $personalization,
        string $profileSeedKey,
        array $sameTypeDivergenceKeys
    ): array {
        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];
        if ($sections === []) {
            return $personalization;
        }

        foreach (self::TARGET_SECTION_KEYS as $sectionKey) {
            $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : [];
            if ($section === []) {
                continue;
            }

            $selection = $this->resolveSectionSelection(
                $sectionKey,
                $section,
                $personalization,
                $profileSeedKey,
                $sameTypeDivergenceKeys
            );
            if ($selection === []) {
                continue;
            }

            $sections[$sectionKey]['selected_blocks'] = $selection['selected_blocks'];
            $sections[$sectionKey]['selection_mode'] = $selection['selection_mode'];
        }

        $personalization['sections'] = $sections;

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     */
    private function resolveProfileSeedKey(array $personalization): string
    {
        $actionTheme = $this->extractThemeFromActionKey($this->firstNonEmpty([
            $personalization['action_focus_key'] ?? null,
            data_get($personalization, 'sections.growth.next_actions.action_key'),
            data_get($personalization, 'sections.career.work_experiments.action_key'),
            data_get($personalization, 'sections.growth.watchouts.action_key'),
        ]));

        $pivotAxis = trim((string) (
            data_get($personalization, 'sections.growth.stability_confidence.boundary_axes.0')
            ?? data_get($personalization, 'sections.career.next_step.boundary_axes.0')
            ?? data_get($personalization, 'close_call_axes.0.axis')
            ?? data_get($personalization, 'dominant_axes.0.axis')
            ?? ''
        ));

        $themePart = $actionTheme !== '' ? $actionTheme : 'repeat_high_fit_experiment';
        $axisPart = $pivotAxis !== '' ? strtolower($pivotAxis) : 'overview';

        return sprintf('same_type.seed.%s.%s', $themePart, $axisPart);
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return list<string>
     */
    private function buildSameTypeDivergenceKeys(array $personalization, string $profileSeedKey): array
    {
        $keys = [$profileSeedKey];

        $dominantAxis = is_array(data_get($personalization, 'dominant_axes.0')) ? data_get($personalization, 'dominant_axes.0') : [];
        $dominantAxisCode = trim((string) ($dominantAxis['axis'] ?? ''));
        $dominantSide = trim((string) ($dominantAxis['side'] ?? ''));
        $dominantBand = trim((string) ($dominantAxis['band'] ?? ''));
        if ($dominantAxisCode !== '') {
            $keys[] = sprintf(
                'same_type.dominant_axis.%s.%s.%s',
                strtolower($dominantAxisCode),
                strtolower($dominantSide !== '' ? $dominantSide : 'na'),
                strtolower($dominantBand !== '' ? $dominantBand : 'na')
            );
        }

        foreach ((array) ($personalization['boundary_flags'] ?? []) as $axisCode => $enabled) {
            if ($enabled === true) {
                $keys[] = sprintf('same_type.boundary_axis.%s', strtolower(trim((string) $axisCode)));
            }
        }

        foreach (['work', 'growth', 'decision', 'stress_recovery'] as $sceneKey) {
            $styleKey = trim((string) data_get($personalization, 'scene_fingerprint.'.$sceneKey.'.style_key', ''));
            if ($styleKey !== '') {
                $keys[] = sprintf('same_type.scene.%s.%s', $sceneKey, $this->normalizeKey($styleKey));
            }
        }

        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));
        if ($intentCluster !== '') {
            $keys[] = sprintf('same_type.intent.%s', $intentCluster);
        }

        $journeyState = trim((string) data_get($personalization, 'action_journey_v1.journey_state', ''));
        if ($journeyState !== '') {
            $keys[] = sprintf('same_type.journey.%s', $journeyState);
        }

        foreach (array_slice($this->normalizeStringList($personalization['synthesis_keys'] ?? []), 0, 2) as $synthesisKey) {
            $keys[] = sprintf('same_type.cross_assessment.%s', $this->normalizeKey($synthesisKey));
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, string>
     */
    private function buildSectionSelectionKeys(array $personalization, string $profileSeedKey): array
    {
        $sectionSelections = [];
        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];

        foreach (self::TARGET_SECTION_KEYS as $sectionKey) {
            $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : [];
            if ($section === []) {
                continue;
            }

            $selectedBlocks = $this->normalizeStringList($section['selected_blocks'] ?? []);
            $actionKey = trim((string) ($section['action_key'] ?? ''));
            $contrastKey = trim((string) ($section['contrast_key'] ?? ''));
            $selectionMode = trim((string) ($section['selection_mode'] ?? ''));
            $synthesisKey = trim((string) data_get($personalization, 'cross_assessment_v1.section_enhancements.'.$sectionKey.'.synthesis_key', ''));

            $parts = [
                $sectionKey,
                'seed.'.$this->normalizeKey($profileSeedKey),
                $selectionMode !== '' ? 'mode.'.$this->normalizeKey($selectionMode) : null,
                $selectedBlocks !== [] ? 'blocks.'.implode('+', array_map([$this, 'normalizeKey'], $selectedBlocks)) : null,
                $actionKey !== '' ? 'action.'.$this->normalizeKey($actionKey) : null,
                $contrastKey !== '' ? 'contrast.'.$this->normalizeKey($contrastKey) : null,
                $synthesisKey !== '' ? 'synth.'.$this->normalizeKey($synthesisKey) : null,
            ];

            $sectionSelections[$sectionKey] = implode(':', array_values(array_filter($parts)));
        }

        return $sectionSelections;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @return array<string, string>
     */
    private function buildActionSelectionKeys(array $personalization, string $profileSeedKey): array
    {
        $keys = [];
        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];

        foreach ([
            'growth.next_actions' => is_array($sections['growth.next_actions'] ?? null) ? $sections['growth.next_actions']['action_key'] ?? null : null,
            'career.work_experiments' => is_array($sections['career.work_experiments'] ?? null) ? $sections['career.work_experiments']['action_key'] ?? null : null,
            'growth.watchouts' => is_array($sections['growth.watchouts'] ?? null) ? $sections['growth.watchouts']['action_key'] ?? null : null,
            'relationships.try_this_week' => is_array($sections['relationships.try_this_week'] ?? null) ? $sections['relationships.try_this_week']['action_key'] ?? null : null,
        ] as $sectionKey => $actionKey) {
            $actionKey = trim((string) $actionKey);
            if ($actionKey === '') {
                continue;
            }

            $selectionMode = trim((string) (
                is_array($sections[$sectionKey] ?? null)
                    ? ($sections[$sectionKey]['selection_mode'] ?? '')
                    : ''
            ));
            $keys[$sectionKey] = sprintf(
                '%s:seed.%s%s:action.%s',
                $sectionKey,
                $this->normalizeKey($profileSeedKey),
                $selectionMode !== '' ? ':mode.'.$this->normalizeKey($selectionMode) : '',
                $this->normalizeKey($actionKey)
            );
        }

        $careerNextStepKey = $this->selectPreferredKey((array) ($personalization['career_next_step_keys'] ?? []), ['.theme.', '.identity.', '.boundary.']);
        if ($careerNextStepKey !== '') {
            $careerNextStepMode = trim((string) (
                is_array($sections['career.next_step'] ?? null)
                    ? ($sections['career.next_step']['selection_mode'] ?? '')
                    : ''
            ));
            $keys['career.next_step'] = sprintf(
                'career.next_step:seed.%s%s:action.%s',
                $this->normalizeKey($profileSeedKey),
                $careerNextStepMode !== '' ? ':mode.'.$this->normalizeKey($careerNextStepMode) : '',
                $this->normalizeKey($careerNextStepKey)
            );
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $sameTypeDivergenceKeys
     * @param  array<string, string>  $sectionSelectionKeys
     * @return list<string>
     */
    private function buildRecommendationSelectionKeys(
        array $personalization,
        string $profileSeedKey,
        array $sameTypeDivergenceKeys,
        array $sectionSelectionKeys
    ): array {
        $candidates = is_array($personalization['recommended_read_candidates'] ?? null)
            ? $personalization['recommended_read_candidates']
            : [];
        if ($candidates === []) {
            return [];
        }

        $orderedRecommendationKeys = $this->normalizeStringList($personalization['ordered_recommendation_keys'] ?? []);
        $orderMap = [];
        foreach ($orderedRecommendationKeys as $index => $key) {
            $orderMap[$key] = $index;
        }

        $primaryThemes = $this->resolveRecommendationThemes($profileSeedKey, $sameTypeDivergenceKeys, $sectionSelectionKeys, $personalization);
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

            foreach ($primaryThemes as $themeIndex => $theme) {
                if (in_array($theme, $themes, true)) {
                    $score += max(0, 180 - ($themeIndex * 20));
                }
            }

            if (isset($orderMap[$key])) {
                $score += max(0, 80 - ((int) $orderMap[$key] * 5));
            }

            $priority = is_numeric($candidate['priority'] ?? null) ? (int) round((float) $candidate['priority']) : 0;
            $score += max(0, 40 - min(40, $priority));

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

        return array_values(array_slice(array_values(array_unique(array_filter($selected))), 0, 4));
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $sameTypeDivergenceKeys
     * @return array<string, mixed>
     */
    private function buildSelectionEvidence(array $personalization, string $profileSeedKey, array $sameTypeDivergenceKeys): array
    {
        return [
            'profile_seed_key' => $profileSeedKey,
            'selection_modes' => array_filter(array_map(
                static fn (mixed $mode): string => trim((string) $mode),
                array_map(
                    static fn (mixed $section): mixed => is_array($section) ? ($section['selection_mode'] ?? null) : null,
                    (array) ($personalization['sections'] ?? [])
                )
            )),
            'axis' => [
                'dominant_axes' => array_values(array_filter(
                    array_map(
                        static fn (array $axis): array => [
                            'axis' => trim((string) ($axis['axis'] ?? '')),
                            'side' => trim((string) ($axis['side'] ?? '')),
                            'band' => trim((string) ($axis['band'] ?? '')),
                        ],
                        array_values(array_filter(
                            (array) ($personalization['dominant_axes'] ?? []),
                            static fn (mixed $axis): bool => is_array($axis)
                        ))
                    ),
                    static fn (array $axis): bool => $axis['axis'] !== ''
                )),
                'boundary_axes' => array_keys(array_filter(
                    (array) ($personalization['boundary_flags'] ?? []),
                    static fn (mixed $enabled): bool => $enabled === true
                )),
                'axis_bands' => is_array($personalization['axis_bands'] ?? null) ? $personalization['axis_bands'] : [],
            ],
            'scene' => [
                'work_style_key' => trim((string) data_get($personalization, 'scene_fingerprint.work.style_key', '')),
                'growth_style_key' => trim((string) data_get($personalization, 'scene_fingerprint.growth.style_key', '')),
                'decision_style_key' => trim((string) data_get($personalization, 'scene_fingerprint.decision.style_key', '')),
                'stress_style_key' => trim((string) data_get($personalization, 'scene_fingerprint.stress_recovery.style_key', '')),
            ],
            'explainability' => [
                'close_call_axes' => $this->normalizeStringList(array_map(
                    static fn (mixed $axis): string => is_array($axis) ? trim((string) ($axis['axis'] ?? '')) : '',
                    (array) ($personalization['close_call_axes'] ?? [])
                )),
                'stability_keys' => $this->normalizeStringList($personalization['confidence_or_stability_keys'] ?? []),
            ],
            'user_state' => [
                'current_intent_cluster' => trim((string) data_get($personalization, 'user_state.current_intent_cluster', '')),
                'feedback_sentiment' => trim((string) data_get($personalization, 'user_state.feedback_sentiment', '')),
                'feedback_coverage' => trim((string) data_get($personalization, 'user_state.feedback_coverage', '')),
                'action_completion_tendency' => trim((string) data_get($personalization, 'user_state.action_completion_tendency', '')),
                'last_deep_read_section' => trim((string) data_get($personalization, 'user_state.last_deep_read_section', '')),
            ],
            'action_journey' => [
                'journey_state' => trim((string) data_get($personalization, 'action_journey_v1.journey_state', '')),
                'progress_state' => trim((string) data_get($personalization, 'action_journey_v1.progress_state', '')),
                'action_focus_key' => trim((string) data_get($personalization, 'action_journey_v1.action_focus_key', '')),
            ],
            'cross_assessment' => [
                'synthesis_keys' => $this->normalizeStringList($personalization['synthesis_keys'] ?? []),
                'big5_influence_keys' => $this->normalizeStringList($personalization['big5_influence_keys'] ?? []),
                'adjusted_focus_keys' => $this->normalizeStringList($personalization['mbti_adjusted_focus_keys'] ?? []),
            ],
            'same_type_divergence_keys' => $sameTypeDivergenceKeys,
        ];
    }

    /**
     * @param  list<string>  $sameTypeDivergenceKeys
     * @param  array<string, string>  $sectionSelectionKeys
     * @return list<string>
     */
    private function resolveRecommendationThemes(
        string $profileSeedKey,
        array $sameTypeDivergenceKeys,
        array $sectionSelectionKeys,
        array $personalization
    ): array {
        $themes = [];

        foreach ([$profileSeedKey, ...$sameTypeDivergenceKeys, ...array_values($sectionSelectionKeys)] as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, 'career') || str_contains($normalized, 'work')) {
                $themes[] = 'career';
                $themes[] = 'work';
            }
            if (str_contains($normalized, 'relationship') || str_contains($normalized, 'communication')) {
                $themes[] = 'relationship';
                $themes[] = 'communication';
            }
            if (str_contains($normalized, 'stability') || str_contains($normalized, 'energy') || str_contains($normalized, 'watchout')) {
                $themes[] = 'stability';
            }
            if (str_contains($normalized, 'action') || str_contains($normalized, 'experiment') || str_contains($normalized, 'next_actions')) {
                $themes[] = 'action';
                $themes[] = 'growth';
            }
            if (str_contains($normalized, 'why_this_type') || str_contains($normalized, 'contrast') || str_contains($normalized, 'explain')) {
                $themes[] = 'explainability';
            }
        }

        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', ''));
        foreach (match ($intentCluster) {
            'career_move' => ['career', 'work', 'action'],
            'relationship_tuning' => ['relationship', 'communication', 'action'],
            'clarify_type' => ['explainability', 'stability', 'growth'],
            default => ['action', 'growth'],
        } as $theme) {
            $themes[] = $theme;
        }

        if ($themes === []) {
            $themes = ['action', 'growth', 'career'];
        }

        return array_values(array_unique($themes));
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
            'work' => ['work', 'career', 'team', 'workspace', '职场', '团队'],
            'relationship' => ['relationship', 'connection', 'boundary', '关系', '边界', '相处'],
            'communication' => ['communication', 'conversation', 'feedback', '沟通', '反馈', '协作'],
            'action' => ['action', 'experiment', 'practice', 'next', 'step', '行动', '实验', '练习', '下一步'],
            'growth' => ['growth', 'habit', 'improve', 'reflection', '成长', '提升', '复盘'],
            'explainability' => ['type', 'mbti', 'borderline', 'contrast', 'adjacent', 'why', '人格', '类型', '边界', '解释'],
            'stability' => ['stability', 'stress', 'recovery', 'watchout', 'burnout', '稳定', '压力', '恢复', '风险', 'energy'],
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

    /**
     * @param  list<mixed>  $keys
     * @param  list<string>  $preferredNeedles
     */
    private function selectPreferredKey(array $keys, array $preferredNeedles = []): string
    {
        $normalized = $this->normalizeStringList($keys);
        if ($normalized === []) {
            return '';
        }

        foreach ($preferredNeedles as $needle) {
            foreach ($normalized as $key) {
                if (str_contains($key, $needle)) {
                    return $key;
                }
            }
        }

        return $normalized[0];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $sameTypeDivergenceKeys
     * @return array{selection_mode:string,selected_blocks:list<string>}
     */
    private function resolveSectionSelection(
        string $sectionKey,
        array $section,
        array $personalization,
        string $profileSeedKey,
        array $sameTypeDivergenceKeys
    ): array {
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

        $selectionMode = $this->resolveSectionSelectionMode(
            $sectionKey,
            $section,
            $personalization,
            $profileSeedKey,
            $sameTypeDivergenceKeys
        );
        $selectedBlocks = $this->selectBlocksForMode($sectionKey, $section, $availableBlocks, $selectionMode);
        if ($selectedBlocks === []) {
            $selectedBlocks = $this->normalizeStringList($section['selected_blocks'] ?? []);
        }

        return [
            'selection_mode' => $selectionMode,
            'selected_blocks' => $selectedBlocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $personalization
     * @param  list<string>  $sameTypeDivergenceKeys
     */
    private function resolveSectionSelectionMode(
        string $sectionKey,
        array $section,
        array $personalization,
        string $profileSeedKey,
        array $sameTypeDivergenceKeys
    ): string {
        $intentCluster = trim((string) data_get($personalization, 'user_state.current_intent_cluster', 'default'));
        $hasBoundary = $this->normalizeStringList($section['boundary_axes'] ?? []) !== []
            || array_filter(
                $sameTypeDivergenceKeys,
                static fn (string $key): bool => str_contains($key, 'same_type.boundary_axis.')
            ) !== [];
        $hasSynthesis = trim((string) data_get(
            $personalization,
            'cross_assessment_v1.section_enhancements.'.$sectionKey.'.synthesis_key',
            ''
        )) !== '';
        $isContextSensitive = str_contains($profileSeedKey, 'context_sensitive')
            || str_contains(trim((string) ($section['action_key'] ?? '')), 'context_sensitive')
            || in_array('stability.bucket.context_sensitive', $this->normalizeStringList($personalization['confidence_or_stability_keys'] ?? []), true);

        return match ($sectionKey) {
            'traits.why_this_type' => $intentCluster === 'clarify_type' || $hasBoundary
                ? 'explain_boundary'
                : 'identity_core',
            'growth.stability_confidence' => $hasBoundary || $intentCluster === 'clarify_type'
                ? 'boundary_buffered'
                : 'stability_core',
            'growth.next_actions' => $intentCluster === 'career_move'
                ? 'action_career_bridge'
                : ($intentCluster === 'clarify_type'
                    ? 'action_explainable'
                    : ($isContextSensitive || $hasBoundary ? 'action_boundary_buffered' : 'action_identity_anchor')),
            'career.next_step' => $intentCluster === 'career_move' || $hasSynthesis
                ? 'career_decision_bridge'
                : ($intentCluster === 'clarify_type' ? 'career_identity_check' : 'career_next_step_core'),
            'career.work_experiments' => $intentCluster === 'career_move'
                ? 'career_experiment_bridge'
                : ($hasBoundary ? 'career_experiment_boundary' : 'career_experiment_identity'),
            'growth.watchouts' => $isContextSensitive || $hasBoundary
                ? 'watchout_boundary_buffered'
                : 'watchout_identity_anchor',
            'traits.adjacent_type_contrast' => $intentCluster === 'clarify_type' || $hasBoundary
                ? 'contrast_boundary'
                : 'contrast_neighbor',
            'relationships.try_this_week' => $intentCluster === 'relationship_tuning'
                ? 'relationship_action_bridge'
                : ($hasBoundary ? 'relationship_boundary' : 'relationship_identity_anchor'),
            default => 'core',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $availableBlocks
     * @return list<string>
     */
    private function selectBlocksForMode(
        string $sectionKey,
        array $section,
        array $availableBlocks,
        string $selectionMode
    ): array {
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

        foreach ($this->normalizeStringList($section['selected_blocks'] ?? []) as $blockId) {
            $selected[] = $blockId;
        }

        $normalizedSelected = [];
        foreach (array_values(array_unique(array_filter($selected))) as $blockId) {
            if (in_array($blockId, $availableIds, true)) {
                $normalizedSelected[] = $blockId;
            }
        }

        if ($normalizedSelected === []) {
            $normalizedSelected = $availableIds;
        }

        return array_slice($normalizedSelected, 0, $this->maxBlocksForSection($sectionKey, $selectionMode));
    }

    /**
     * @return list<string>
     */
    private function preferredKindsForSection(string $sectionKey, string $selectionMode): array
    {
        return match ($sectionKey) {
            'traits.why_this_type' => match ($selectionMode) {
                'explain_boundary' => ['why_this_type', 'boundary', 'axis_strength', 'identity'],
                default => ['why_this_type', 'identity', 'axis_strength'],
            },
            'growth.stability_confidence' => match ($selectionMode) {
                'boundary_buffered' => ['stability_explanation', 'boundary'],
                default => ['stability_explanation'],
            },
            'growth.next_actions' => match ($selectionMode) {
                'action_career_bridge' => ['next_action', 'axis_strength', 'boundary'],
                'action_explainable' => ['next_action', 'axis_strength', 'identity'],
                'action_boundary_buffered' => ['next_action', 'boundary', 'identity'],
                default => ['next_action', 'identity', 'axis_strength'],
            },
            'career.next_step' => match ($selectionMode) {
                'career_decision_bridge' => ['career_next_step', 'boundary', 'axis_strength'],
                'career_identity_check' => ['career_next_step', 'identity', 'axis_strength'],
                default => ['career_next_step', 'axis_strength', 'identity'],
            },
            'career.work_experiments' => match ($selectionMode) {
                'career_experiment_bridge' => ['work_experiment', 'axis_strength', 'boundary'],
                'career_experiment_boundary' => ['work_experiment', 'boundary', 'identity'],
                default => ['work_experiment', 'identity', 'axis_strength'],
            },
            'growth.watchouts' => match ($selectionMode) {
                'watchout_boundary_buffered' => ['watchout', 'boundary', 'identity'],
                default => ['watchout', 'identity', 'axis_strength'],
            },
            'traits.adjacent_type_contrast' => match ($selectionMode) {
                'contrast_boundary' => ['adjacent_type_contrast', 'boundary', 'identity'],
                default => ['adjacent_type_contrast', 'identity'],
            },
            'relationships.try_this_week' => match ($selectionMode) {
                'relationship_action_bridge' => ['relationship_practice', 'identity', 'boundary'],
                'relationship_boundary' => ['relationship_practice', 'boundary', 'axis_strength'],
                default => ['relationship_practice', 'identity', 'axis_strength'],
            },
            default => [],
        };
    }

    private function maxBlocksForSection(string $sectionKey, string $selectionMode): int
    {
        return match ($sectionKey) {
            'growth.stability_confidence' => $selectionMode === 'stability_core' ? 1 : 2,
            'traits.adjacent_type_contrast' => $selectionMode === 'contrast_neighbor' ? 2 : 3,
            default => 3,
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

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function extractThemeFromActionKey(string $actionKey): string
    {
        if ($actionKey === '') {
            return '';
        }

        if (preg_match('/\.theme\.([a-z0-9_]+)/i', $actionKey, $matches) === 1) {
            return strtolower(trim((string) ($matches[1] ?? '')));
        }

        return '';
    }

    private function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }
}
