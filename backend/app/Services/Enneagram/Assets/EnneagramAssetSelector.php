<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

final class EnneagramAssetSelector
{
    /**
     * @param  array<string,mixed>  $merged
     * @param  array<string,mixed>  $context
     * @return array<string,array<string,mixed>>
     */
    public function selectByCategory(array $merged, array $context): array
    {
        $typeId = trim((string) ($context['type_id'] ?? ''));
        $items = is_array($merged['items'] ?? null) ? $merged['items'] : [];
        $candidatesByCategory = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemTypeId = trim((string) ($item['type_id'] ?? ''));
            if ($typeId !== '' && $itemTypeId !== '' && $itemTypeId !== $typeId) {
                continue;
            }
            $category = trim((string) ($item['category'] ?? ''));
            if ($category === '') {
                continue;
            }

            $score = $this->score($item, $context);
            if ($score === null) {
                continue;
            }

            $current = $candidatesByCategory[$category] ?? null;
            if (! is_array($current) || $score > (int) ($current['_selection_score'] ?? PHP_INT_MIN)) {
                $candidatesByCategory[$category] = array_merge($item, ['_selection_score' => $score]);
            }
        }

        ksort($candidatesByCategory);

        return $candidatesByCategory;
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $context
     */
    private function score(array $item, array $context): ?int
    {
        $score = (int) ($item['selection_priority'] ?? 0);

        $avoidWhen = is_array($item['avoid_when'] ?? null) ? $item['avoid_when'] : [];
        foreach ($avoidWhen as $avoid) {
            if (is_string($avoid) && $avoid !== '') {
                foreach ([
                    'interpretation_scope',
                    'confidence_level',
                    'score_profile',
                    'scenario',
                    'user_signal',
                    'selected_form',
                    'objection_axis',
                    'partial_axis',
                    'diffuse_axis',
                    'scene_axis',
                    'fc144_recommendation_context',
                    'pair_key',
                ] as $key) {
                    if ($avoid === (string) ($context[$key] ?? '')) {
                        return null;
                    }
                }

                continue;
            }

            if (! is_array($avoid)) {
                continue;
            }

            if (! $this->matchesCondition($avoid, $context)) {
                continue;
            }

            $action = (string) ($avoid['action'] ?? 'suppress');
            if ($action === 'suppress') {
                return null;
            }
            if ($action === 'downgrade') {
                $score -= 4;

                continue;
            }
            if ($action === 'use_boundary_first') {
                $score -= 2;
            }
        }

        $appliesTo = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : [];
        foreach ([
            'interpretation_scope',
            'confidence_level',
            'score_profile',
            'scenario',
            'user_signal',
            'audience_segment',
            'objection_axis',
            'partial_axis',
            'diffuse_axis',
            'scene_axis',
            'fc144_recommendation_context',
            'pair_key',
        ] as $key) {
            $allowed = is_array($appliesTo[$key] ?? null) ? $appliesTo[$key] : [];
            $value = (string) ($context[$key] ?? '');
            if ($allowed === []) {
                continue;
            }

            $normalizedAllowed = array_values(array_filter(array_map(
                static fn ($entry): string => is_scalar($entry) ? trim((string) $entry) : '',
                $allowed
            )));

            if ($normalizedAllowed === [] || in_array('any', $normalizedAllowed, true)) {
                $score += 2;

                continue;
            }

            if ($value === '') {
                $score -= 2;

                continue;
            }

            $score += in_array($value, $normalizedAllowed, true) ? 10 : -2;
        }

        $contextObjectionAxis = trim((string) ($context['objection_axis'] ?? ''));
        $itemObjectionAxis = trim((string) ($item['objection_axis'] ?? ''));
        if ($contextObjectionAxis !== '') {
            if ($itemObjectionAxis === $contextObjectionAxis) {
                $score += 20;
            } elseif ($itemObjectionAxis === '') {
                $score -= 12;
            } else {
                $score -= 20;
            }
        }

        $contextPartialAxis = trim((string) ($context['partial_axis'] ?? ''));
        $itemPartialAxis = trim((string) ($item['partial_axis'] ?? ''));
        if ($contextPartialAxis !== '') {
            if ($itemPartialAxis === $contextPartialAxis) {
                $score += 20;
            } elseif ($itemPartialAxis === '') {
                $score -= 12;
            } else {
                $score -= 20;
            }
        }

        $contextDiffuseAxis = trim((string) ($context['diffuse_axis'] ?? ''));
        $itemDiffuseAxis = trim((string) ($item['diffuse_axis'] ?? ''));
        if ($contextDiffuseAxis !== '') {
            if ($itemDiffuseAxis === $contextDiffuseAxis) {
                $score += 20;
            } elseif ($itemDiffuseAxis === '') {
                $score -= 12;
            } else {
                $score -= 20;
            }
        }

        $contextPairKey = $this->canonicalPairKey(
            trim((string) ($context['pair_key'] ?? '')),
            false
        );
        $itemPairKey = $this->canonicalPairKey(
            trim((string) ($item['canonical_pair_key'] ?? $item['pair_key'] ?? '')),
            (bool) ($item['directional'] ?? false)
        );
        if ($contextPairKey !== '') {
            if ($itemPairKey === $contextPairKey) {
                $score += 20;
            } elseif ($itemPairKey === '') {
                $score -= 12;
            } else {
                $score -= 20;
            }
        }

        $contextSceneAxis = trim((string) ($context['scene_axis'] ?? ''));
        $itemSceneAxis = trim((string) ($item['scene_axis'] ?? ''));
        if ($contextSceneAxis !== '') {
            if ($itemSceneAxis === $contextSceneAxis) {
                $score += 20;
            } elseif ($itemSceneAxis === '') {
                $score -= 12;
            } else {
                $score -= 20;
            }
        }

        $contextFc144RecommendationContext = trim((string) ($context['fc144_recommendation_context'] ?? ''));
        $itemFc144RecommendationContext = trim((string) ($item['fc144_recommendation_context'] ?? ''));
        if ($contextFc144RecommendationContext !== '') {
            if ($itemFc144RecommendationContext === $contextFc144RecommendationContext) {
                $score += 20;
            } elseif ($itemFc144RecommendationContext === '') {
                $score -= 12;
            } else {
                $score -= 20;
            }
        }

        if ((bool) ($item['suppress_if_seen'] ?? false)) {
            $score -= 1;
        }

        return $score;
    }

    private function canonicalPairKey(string $pairKey, bool $directional): string
    {
        if (! preg_match('/^([1-9])_([1-9])$/', $pairKey, $matches)) {
            return $pairKey;
        }

        $left = (int) $matches[1];
        $right = (int) $matches[2];
        if ($left === $right) {
            return $pairKey;
        }

        if ($directional) {
            return $left.'_'.$right;
        }

        $values = [$left, $right];
        sort($values, SORT_NUMERIC);

        return $values[0].'_'.$values[1];
    }

    /**
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $context
     */
    private function matchesCondition(array $rule, array $context): bool
    {
        $field = trim((string) ($rule['field'] ?? ''));
        if ($field === '') {
            return false;
        }

        $operator = trim((string) ($rule['operator'] ?? 'equals'));
        $expected = trim((string) ($rule['value'] ?? ''));
        $actual = trim((string) ($context[$field] ?? ''));

        return match ($operator) {
            'equals' => $actual !== '' && $actual === $expected,
            'mismatch' => $actual !== '' && $actual !== $expected,
            'missing' => $actual === '',
            default => false,
        };
    }
}
