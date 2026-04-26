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
                'interpretation_context_id' => 'asset_preview_'.$context['type_id'].'_'.$context['interpretation_scope'],
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
