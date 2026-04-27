<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

final class EnneagramAssetPreviewPayloadBuilder
{
    private const STATES = ['clear', 'close_call', 'diffuse', 'low_quality'];

    private const OBJECTION_AXES = [
        'anti_labeling_resistance',
        'behavior_yes_motivation_no',
        'complete_disagreement',
        'current_state_affected_answering',
        'growth_only_resonance',
        'relationship_only_resonance',
        'stress_only_resonance',
        'suspected_test_mismatch',
        'top2_feels_closer',
        'top3_none_fit',
        'type_label_resistance',
        'work_only_resonance',
    ];

    private const PARTIAL_AXES = [
        'work_only',
        'relationship_only',
        'stress_only',
        'growth_only',
        'strength_only',
        'blindspot_only',
        'motivation_only',
        'fear_only',
        'top2_only',
        'context_specific',
    ];

    private const DIFFUSE_AXES = [
        'top3_flat',
        'broad_distribution',
        'low_signal_top3',
        'contradictory_pattern',
        'center_clustered_body',
        'center_clustered_heart',
        'center_clustered_head',
        'cross_center_distribution',
        'three_center_spread',
        'behavior_vs_motivation',
        'scene_specific_top3',
        'next_step_convergence',
    ];

    private const SCENE_AXES = [
        'student_group_project',
        'student_exam_pressure',
        'student_dorm_relationship',
        'student_club_collaboration',
        'student_thesis_paper',
        'student_job_search',
        'early_career_internship',
        'early_career_probation',
        'early_career_reporting',
        'early_career_cross_team',
        'early_career_kpi_feedback',
        'work_leader_changes_requirements',
        'work_colleague_blame_shift',
        'relationship_intimacy',
        'relationship_family_expectation',
        'relationship_no_reply',
        'relationship_cold_war',
        'relationship_conflict_repair',
    ];

    public function __construct(
        private readonly EnneagramAssetSelector $selector,
        private readonly EnneagramAssetPublicPayloadSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string,mixed>  $merged
     * @return list<array<string,mixed>>
     */
    public function buildAll(array $merged): array
    {
        $payloads = [];
        foreach (range(1, 9) as $typeId) {
            foreach (self::STATES as $state) {
                $payloads[] = $this->build($merged, $this->contextFor((string) $typeId, $state));
            }
        }

        return $payloads;
    }

    /**
     * @param  array<string,mixed>  $merged
     * @return list<array<string,mixed>>
     */
    public function buildLowResonanceObjectionMatrix(array $merged): array
    {
        $payloads = [];
        foreach ((array) ($merged['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['_preview_batch'] ?? '') !== '1R-C') {
                continue;
            }
            if (trim((string) ($item['category'] ?? '')) !== 'low_resonance_response') {
                continue;
            }

            $payloads[] = $this->build($merged, $this->contextForObjectionItem($item));
        }

        usort($payloads, static function (array $left, array $right): int {
            $leftKey = sprintf(
                '%s:%s',
                (string) data_get($left, 'preview_context.type_id', ''),
                (string) data_get($left, 'preview_context.objection_axis', '')
            );
            $rightKey = sprintf(
                '%s:%s',
                (string) data_get($right, 'preview_context.type_id', ''),
                (string) data_get($right, 'preview_context.objection_axis', '')
            );

            return $leftKey <=> $rightKey;
        });

        return $payloads;
    }

    /**
     * @param  array<string,mixed>  $merged
     * @return list<array<string,mixed>>
     */
    public function buildPartialResonanceMatrix(array $merged): array
    {
        $payloads = [];
        foreach ((array) ($merged['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['_preview_batch'] ?? '') !== '1R-D') {
                continue;
            }
            if (trim((string) ($item['category'] ?? '')) !== 'partial_resonance_response') {
                continue;
            }

            $payloads[] = $this->build($merged, $this->contextForPartialItem($item));
        }

        usort($payloads, static function (array $left, array $right): int {
            $leftKey = sprintf(
                '%s:%s',
                (string) data_get($left, 'preview_context.type_id', ''),
                (string) data_get($left, 'preview_context.partial_axis', '')
            );
            $rightKey = sprintf(
                '%s:%s',
                (string) data_get($right, 'preview_context.type_id', ''),
                (string) data_get($right, 'preview_context.partial_axis', '')
            );

            return $leftKey <=> $rightKey;
        });

        return $payloads;
    }

    /**
     * @param  array<string,mixed>  $merged
     * @return list<array<string,mixed>>
     */
    public function buildDiffuseConvergenceMatrix(array $merged): array
    {
        $payloads = [];
        foreach ((array) ($merged['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['_preview_batch'] ?? '') !== '1R-E') {
                continue;
            }
            if (trim((string) ($item['category'] ?? '')) !== 'diffuse_convergence_response') {
                continue;
            }

            $payloads[] = $this->build($merged, $this->contextForDiffuseItem($item));
        }

        usort($payloads, static function (array $left, array $right): int {
            $leftKey = sprintf(
                '%s:%s',
                (string) data_get($left, 'preview_context.type_id', ''),
                (string) data_get($left, 'preview_context.diffuse_axis', '')
            );
            $rightKey = sprintf(
                '%s:%s',
                (string) data_get($right, 'preview_context.type_id', ''),
                (string) data_get($right, 'preview_context.diffuse_axis', '')
            );

            return $leftKey <=> $rightKey;
        });

        return $payloads;
    }

    /**
     * @param  array<string,mixed>  $merged
     * @return list<array<string,mixed>>
     */
    public function buildCloseCallPairMatrix(array $merged): array
    {
        $payloads = [];
        foreach ((array) ($merged['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['_preview_batch'] ?? '') !== '1R-F') {
                continue;
            }
            if (trim((string) ($item['category'] ?? '')) !== 'close_call_pair') {
                continue;
            }

            $payloads[] = $this->build($merged, $this->contextForPairItem($item));
        }

        usort($payloads, static function (array $left, array $right): int {
            return ((string) data_get($left, 'preview_context.pair_key', ''))
                <=> ((string) data_get($right, 'preview_context.pair_key', ''));
        });

        return $payloads;
    }

    /**
     * @param  array<string,mixed>  $merged
     * @return list<array<string,mixed>>
     */
    public function buildSceneLocalizationMatrix(array $merged): array
    {
        $payloads = [];
        foreach ((array) ($merged['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['_preview_batch'] ?? '') !== '1R-G') {
                continue;
            }
            if (trim((string) ($item['category'] ?? '')) !== 'scene_localization_response') {
                continue;
            }

            $payloads[] = $this->build($merged, $this->contextForSceneItem($item));
        }

        usort($payloads, static function (array $left, array $right): int {
            $leftKey = sprintf(
                '%s:%s',
                (string) data_get($left, 'preview_context.type_id', ''),
                (string) data_get($left, 'preview_context.scene_axis', '')
            );
            $rightKey = sprintf(
                '%s:%s',
                (string) data_get($right, 'preview_context.type_id', ''),
                (string) data_get($right, 'preview_context.scene_axis', '')
            );

            return $leftKey <=> $rightKey;
        });

        return $payloads;
    }

    /**
     * @param  array<string,mixed>  $merged
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function build(array $merged, array $context): array
    {
        $selectedByCategory = $this->selector->selectByCategory($merged, $context);
        $modules = [];
        $blocked = [];

        foreach ($selectedByCategory as $category => $item) {
            $public = $this->sanitizer->sanitizeItem($item);
            $public = $this->sanitizer->stripInternalMetadata($public);
            if (trim((string) ($public['body_zh'] ?? '')) === '') {
                $blocked[] = 'missing_body_zh:'.$category;

                continue;
            }

            $modules[] = [
                'module_key' => 'asset_preview_'.$category,
                'kind' => 'asset_backed_card',
                'visibility' => 'visible',
                'state' => (string) ($context['interpretation_scope'] ?? 'unknown'),
                'form_variant' => 'all',
                'content' => $public,
                'data_refs' => [
                    'scores.primary_candidate',
                    'classification.interpretation_scope',
                    'classification.confidence_level',
                ],
                'registry_refs' => [],
                'provenance' => [
                    'projection_refs' => [],
                    'registry_refs' => [],
                    'policy_refs' => ['enneagram.asset_preview.phase_0'],
                    'content_maturity' => (string) ($public['content_maturity'] ?? 'preview'),
                    'evidence_level' => (string) ($public['evidence_level'] ?? 'descriptive'),
                ],
                'fallback_policy' => 'validation_error_only',
            ];
        }

        $pages = [[
            'page_key' => 'asset_preview_phase_0',
            'title' => 'ENNEAGRAM asset preview',
            'purpose' => 'staging preview only',
            'visibility' => 'visible',
            'source_registry_refs' => [],
            'modules' => $modules,
        ]];

        return [
            'schema_version' => 'enneagram.report.v2',
            'scale_code' => 'ENNEAGRAM',
            'preview_mode' => true,
            'production_import_allowed' => false,
            'full_replacement_allowed' => false,
            'form' => [
                'form_code' => (string) ($context['selected_form'] ?? 'enneagram_likert_105'),
                'form_kind' => (string) ($context['selected_form_kind'] ?? 'likert'),
                'methodology_variant' => (string) ($context['methodology_variant'] ?? 'asset_preview_only'),
            ],
            'registry' => [
                'registry_version' => 'asset_preview_phase_0',
                'registry_release_hash' => null,
                'content_maturity' => 'staging_preview',
                'release_id' => null,
            ],
            'classification' => [
                'interpretation_scope' => (string) ($context['interpretation_scope'] ?? ''),
                'confidence_level' => (string) ($context['confidence_level'] ?? ''),
                'interpretation_reason' => 'asset_preview_fixture',
            ],
            'preview_context' => $context,
            'pages' => $pages,
            'modules' => $modules,
            'blocked_reasons' => $blocked,
            'provenance' => [
                'projection_version' => null,
                'report_schema_version' => 'enneagram.report.v2',
                'report_engine_version' => 'enneagram_asset_preview.phase_0',
                'interpretation_context_id' => 'asset_preview_'.($context['type_id'] ?? $context['pair_key'] ?? 'preview').'_'.$context['interpretation_scope'],
                'content_release_hash' => null,
                'content_snapshot_status' => 'not_written',
                'registry_release_hash' => null,
                'close_call_rule_version' => null,
                'confidence_policy_version' => null,
                'quality_policy_version' => null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function contextFor(string $typeId, string $state): array
    {
        return [
            'type_id' => $typeId,
            'interpretation_scope' => $state,
            'confidence_level' => match ($state) {
                'clear' => 'high_confidence',
                'close_call' => 'close_call',
                'diffuse' => 'diffuse',
                'low_quality' => 'low_quality',
                default => 'medium_confidence',
            },
            'score_profile' => match ($state) {
                'clear' => 'high_primary_clear',
                'close_call' => 'close_call',
                'diffuse' => 'diffuse_profile',
                'low_quality' => 'low_quality_signal',
                default => 'general',
            },
            'scenario' => match ($state) {
                'clear' => 'deep_reading',
                'close_call' => 'comparison',
                'diffuse' => 'low_resonance',
                'low_quality' => 'quality_boundary',
                default => 'general',
            },
            'user_signal' => match ($state) {
                'clear' => 'high_resonance',
                'close_call' => 'partial_resonance',
                'diffuse' => 'low_resonance',
                'low_quality' => 'low_quality',
                default => 'general',
            },
            'audience_segment' => 'general',
            'selected_form' => 'enneagram_likert_105',
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function contextForObjectionItem(array $item): array
    {
        $appliesTo = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : [];
        $typeId = trim((string) ($item['type_id'] ?? ''));
        $objectionAxis = trim((string) ($item['objection_axis'] ?? ''));
        $scope = $this->preferredAllowedValue(
            $appliesTo,
            'interpretation_scope',
            ['close_call', 'diffuse', 'clear', 'low_quality']
        );
        $confidence = $this->preferredAllowedValue(
            $appliesTo,
            'confidence_level',
            ['medium_confidence', 'low_confidence', 'any']
        );
        $scoreProfile = $this->preferredAllowedValue(
            $appliesTo,
            'score_profile',
            ['top2_close_call', 'primary_with_strong_secondary', 'broad_distribution', 'top3_flat', 'high_variance', 'contradictory_pattern', 'low_signal', 'any']
        );
        $scenario = $this->preferredAllowedValue(
            $appliesTo,
            'scenario',
            ['deep_reading', 'self_observation', 'work_context', 'relationship_context', 'stress_context', 'growth_context', 'retest_context', 'any']
        );
        $userSignal = $this->preferredAllowedValue(
            $appliesTo,
            'user_signal',
            ['result_disagreement', 'type_label_resistance', 'only_top2_resonates', 'low_resonance', 'partial_resonance', 'only_work_resonates', 'only_relationship_resonates', 'stress_focus', 'growth_focus', 'uncertain_result', 'diffuse_distribution', 'low_quality_signal', 'any']
        );
        $audienceSegment = $this->preferredAllowedValue(
            $appliesTo,
            'audience_segment',
            ['general', 'deep_reader', 'quick_reader', 'any']
        );

        return [
            'type_id' => $typeId,
            'interpretation_scope' => $scope !== '' && $scope !== 'any' ? $scope : 'diffuse',
            'confidence_level' => $confidence !== '' && $confidence !== 'any' ? $confidence : 'medium_confidence',
            'score_profile' => $scoreProfile !== '' && $scoreProfile !== 'any' ? $scoreProfile : 'broad_distribution',
            'scenario' => $scenario !== '' && $scenario !== 'any' ? $scenario : 'self_observation',
            'user_signal' => $userSignal !== '' && $userSignal !== 'any' ? $userSignal : 'low_resonance',
            'audience_segment' => $audienceSegment !== '' && $audienceSegment !== 'any' ? $audienceSegment : 'general',
            'selected_form' => 'enneagram_likert_105',
            'selected_form_kind' => 'likert',
            'methodology_variant' => 'asset_preview_only',
            'objection_axis' => in_array($objectionAxis, self::OBJECTION_AXES, true) ? $objectionAxis : '',
            'body_context' => 'matching_primary_or_top3',
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function contextForPartialItem(array $item): array
    {
        $appliesTo = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : [];
        $typeId = trim((string) ($item['type_id'] ?? ''));
        $partialAxis = trim((string) ($item['partial_axis'] ?? ''));
        $scope = $this->preferredAllowedValue(
            $appliesTo,
            'interpretation_scope',
            ['close_call', 'diffuse', 'clear', 'low_quality']
        );
        $confidence = $this->preferredAllowedValue(
            $appliesTo,
            'confidence_level',
            ['medium_confidence', 'low_confidence', 'any']
        );
        $scoreProfile = $this->preferredAllowedValue(
            $appliesTo,
            'score_profile',
            ['primary_with_strong_secondary', 'top2_close_call', 'broad_distribution', 'high_variance', 'top3_flat', 'low_signal', 'any']
        );
        $scenario = $this->preferredAllowedValue(
            $appliesTo,
            'scenario',
            ['work_context', 'relationship_context', 'stress_context', 'growth_context', 'deep_reading', 'self_observation', 'any']
        );
        $userSignal = $this->preferredAllowedValue(
            $appliesTo,
            'user_signal',
            ['partial_resonance', 'only_work_resonates', 'only_relationship_resonates', 'stress_focus', 'growth_focus', 'uncertain_result', 'only_top2_resonates', 'any']
        );
        $audienceSegment = $this->preferredAllowedValue(
            $appliesTo,
            'audience_segment',
            ['general', 'work_focus', 'relationship_focus', 'stress_focus', 'student', 'early_career', 'deep_reader', 'any']
        );

        return [
            'type_id' => $typeId,
            'interpretation_scope' => $scope !== '' && $scope !== 'any' ? $scope : 'close_call',
            'confidence_level' => $confidence !== '' && $confidence !== 'any' ? $confidence : 'medium_confidence',
            'score_profile' => $scoreProfile !== '' && $scoreProfile !== 'any' ? $scoreProfile : 'primary_with_strong_secondary',
            'scenario' => $scenario !== '' && $scenario !== 'any' ? $scenario : 'deep_reading',
            'user_signal' => $userSignal !== '' && $userSignal !== 'any' ? $userSignal : 'partial_resonance',
            'audience_segment' => $audienceSegment !== '' && $audienceSegment !== 'any' ? $audienceSegment : 'general',
            'selected_form' => 'enneagram_likert_105',
            'selected_form_kind' => 'likert',
            'methodology_variant' => 'asset_preview_only',
            'partial_axis' => in_array($partialAxis, self::PARTIAL_AXES, true) ? $partialAxis : '',
            'body_context' => 'matching_partial_resonance_signal',
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function contextForDiffuseItem(array $item): array
    {
        $appliesTo = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : [];
        $typeId = trim((string) ($item['type_id'] ?? ''));
        $diffuseAxis = trim((string) ($item['diffuse_axis'] ?? ''));
        $scope = $this->preferredAllowedValue(
            $appliesTo,
            'interpretation_scope',
            ['diffuse', 'close_call', 'clear', 'low_quality']
        );
        $confidence = $this->preferredAllowedValue(
            $appliesTo,
            'confidence_level',
            ['low_confidence', 'medium_confidence', 'any']
        );
        $scoreProfile = $this->preferredAllowedValue(
            $appliesTo,
            'score_profile',
            ['top3_flat', 'broad_distribution', 'low_signal', 'contradictory_pattern', 'center_clustered_body', 'center_clustered_heart', 'center_clustered_head', 'cross_center_distribution', 'three_center_spread', 'behavior_vs_motivation', 'scene_specific_top3', 'any']
        );
        $scenario = $this->preferredAllowedValue(
            $appliesTo,
            'scenario',
            ['self_observation', 'deep_reading', 'work_context', 'relationship_context', 'stress_context', 'growth_context', 'any']
        );
        $userSignal = $this->preferredAllowedValue(
            $appliesTo,
            'user_signal',
            ['diffuse_distribution', 'uncertain_result', 'low_resonance', 'partial_resonance', 'only_top2_resonates', 'any']
        );
        $audienceSegment = $this->preferredAllowedValue(
            $appliesTo,
            'audience_segment',
            ['general', 'deep_reader', 'returning_user', 'quick_reader', 'any']
        );

        return [
            'type_id' => $typeId,
            'interpretation_scope' => $scope !== '' && $scope !== 'any' ? $scope : 'diffuse',
            'confidence_level' => $confidence !== '' && $confidence !== 'any' ? $confidence : 'low_confidence',
            'score_profile' => $scoreProfile !== '' && $scoreProfile !== 'any' ? $scoreProfile : 'top3_flat',
            'scenario' => $scenario !== '' && $scenario !== 'any' ? $scenario : 'self_observation',
            'user_signal' => $userSignal !== '' && $userSignal !== 'any' ? $userSignal : 'diffuse_distribution',
            'audience_segment' => $audienceSegment !== '' && $audienceSegment !== 'any' ? $audienceSegment : 'general',
            'selected_form' => 'enneagram_likert_105',
            'selected_form_kind' => 'likert',
            'methodology_variant' => 'asset_preview_only',
            'diffuse_axis' => in_array($diffuseAxis, self::DIFFUSE_AXES, true) ? $diffuseAxis : '',
            'body_context' => 'matching_diffuse_top3_convergence_signal',
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function contextForPairItem(array $item): array
    {
        $appliesTo = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : [];
        $typeA = trim((string) ($item['type_a'] ?? ''));
        $typeB = trim((string) ($item['type_b'] ?? ''));
        $pairKey = trim((string) ($item['canonical_pair_key'] ?? $item['pair_key'] ?? ''));
        $scope = $this->preferredAllowedValue(
            $appliesTo,
            'interpretation_scope',
            ['close_call', 'diffuse', 'clear', 'low_quality']
        );
        $confidence = $this->preferredAllowedValue(
            $appliesTo,
            'confidence_level',
            ['medium_confidence', 'low_confidence', 'any']
        );
        $scoreProfile = $this->preferredAllowedValue(
            $appliesTo,
            'score_profile',
            ['primary_with_strong_secondary', 'top2_close_call', 'any']
        );
        $scenario = $this->preferredAllowedValue(
            $appliesTo,
            'scenario',
            ['deep_reading', 'first_screen', 'self_observation', 'any']
        );
        $userSignal = $this->preferredAllowedValue(
            $appliesTo,
            'user_signal',
            ['partial_resonance', 'only_top2_resonates', 'top2_resonance', 'uncertain_result', 'any']
        );
        $audienceSegment = $this->preferredAllowedValue(
            $appliesTo,
            'audience_segment',
            ['general', 'deep_reader', 'returning_user', 'any']
        );

        return [
            'type_id' => $typeA,
            'type_a' => $typeA,
            'type_b' => $typeB,
            'top1_type' => $typeA,
            'top2_type' => $typeB,
            'pair_key' => $pairKey,
            'interpretation_scope' => $scope !== '' && $scope !== 'any' ? $scope : 'close_call',
            'confidence_level' => $confidence !== '' && $confidence !== 'any' ? $confidence : 'medium_confidence',
            'score_profile' => $scoreProfile !== '' && $scoreProfile !== 'any' ? $scoreProfile : 'primary_with_strong_secondary',
            'scenario' => $scenario !== '' && $scenario !== 'any' ? $scenario : 'deep_reading',
            'user_signal' => $userSignal !== '' && $userSignal !== 'any' ? $userSignal : 'uncertain_result',
            'audience_segment' => $audienceSegment !== '' && $audienceSegment !== 'any' ? $audienceSegment : 'general',
            'selected_form' => 'enneagram_likert_105',
            'selected_form_kind' => 'likert',
            'methodology_variant' => 'asset_preview_only',
            'body_context' => 'matching_close_call_pair',
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function contextForSceneItem(array $item): array
    {
        $appliesTo = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : [];
        $typeId = trim((string) ($item['type_id'] ?? ''));
        $sceneAxis = trim((string) ($item['scene_axis'] ?? ''));
        $sceneDomain = trim((string) ($item['scene_domain'] ?? ''));
        $scope = $this->preferredAllowedValue(
            $appliesTo,
            'interpretation_scope',
            ['clear', 'close_call', 'diffuse', 'low_quality']
        );
        $confidence = $this->preferredAllowedValue(
            $appliesTo,
            'confidence_level',
            ['high_confidence', 'medium_confidence', 'low_confidence', 'any']
        );
        $scoreProfile = $this->preferredAllowedValue(
            $appliesTo,
            'score_profile',
            ['high_primary_clear', 'top2_close_call', 'top3_flat', 'broad_distribution', 'any']
        );
        $scenario = $this->preferredAllowedValue(
            $appliesTo,
            'scenario',
            ['scene_localization_context', 'student_context', 'work_context', 'relationship_context', 'self_observation', 'any']
        );
        $userSignal = $this->preferredAllowedValue(
            $appliesTo,
            'user_signal',
            ['scene_resonance', 'context_specific', 'self_observation', 'any']
        );
        $audienceSegment = $this->preferredAllowedValue(
            $appliesTo,
            'audience_segment',
            ['student', 'early_career', 'relationship_focus', 'work_focus', 'general', 'any']
        );

        return [
            'type_id' => $typeId,
            'scene_axis' => in_array($sceneAxis, self::SCENE_AXES, true) ? $sceneAxis : '',
            'scene_domain' => $sceneDomain,
            'interpretation_scope' => $scope !== '' && $scope !== 'any' ? $scope : 'clear',
            'confidence_level' => $confidence !== '' && $confidence !== 'any' ? $confidence : 'medium_confidence',
            'score_profile' => $scoreProfile !== '' && $scoreProfile !== 'any' ? $scoreProfile : 'high_primary_clear',
            'scenario' => $scenario !== '' && $scenario !== 'any' ? $scenario : 'scene_localization_context',
            'user_signal' => $userSignal !== '' && $userSignal !== 'any' ? $userSignal : 'scene_resonance',
            'audience_segment' => $audienceSegment !== '' && $audienceSegment !== 'any' ? $audienceSegment : 'general',
            'selected_form' => 'enneagram_likert_105',
            'selected_form_kind' => 'likert',
            'methodology_variant' => 'asset_preview_only',
            'body_context' => 'matching_scene_localization_signal',
        ];
    }

    /**
     * @param  array<string,mixed>  $appliesTo
     * @param  list<string>  $preferredOrder
     */
    private function preferredAllowedValue(array $appliesTo, string $key, array $preferredOrder): string
    {
        $allowed = array_values(array_filter(array_map(
            static fn ($entry): string => is_scalar($entry) ? trim((string) $entry) : '',
            is_array($appliesTo[$key] ?? null) ? $appliesTo[$key] : []
        )));

        foreach ($preferredOrder as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        return $allowed[0] ?? '';
    }
}
