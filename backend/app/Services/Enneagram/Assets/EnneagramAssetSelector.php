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
            if ($typeId !== '' && trim((string) ($item['type_id'] ?? '')) !== $typeId) {
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
        $avoidWhen = is_array($item['avoid_when'] ?? null) ? $item['avoid_when'] : [];
        foreach ($avoidWhen as $avoid) {
            if (! is_string($avoid) || $avoid === '') {
                continue;
            }
            foreach (['interpretation_scope', 'confidence_level', 'score_profile', 'scenario', 'user_signal', 'selected_form'] as $key) {
                if ($avoid === (string) ($context[$key] ?? '')) {
                    return null;
                }
            }
        }

        $score = (int) ($item['selection_priority'] ?? 0);
        $appliesTo = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : [];
        foreach ([
            'interpretation_scope',
            'confidence_level',
            'score_profile',
            'scenario',
            'user_signal',
            'audience_segment',
        ] as $key) {
            $allowed = is_array($appliesTo[$key] ?? null) ? $appliesTo[$key] : [];
            $value = (string) ($context[$key] ?? '');
            if ($value === '' || $allowed === []) {
                continue;
            }
            $score += in_array($value, $allowed, true) ? 10 : -2;
        }

        if ((bool) ($item['suppress_if_seen'] ?? false)) {
            $score -= 1;
        }

        return $score;
    }
}
