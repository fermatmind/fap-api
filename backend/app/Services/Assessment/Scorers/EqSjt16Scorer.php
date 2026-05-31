<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

use InvalidArgumentException;

final class EqSjt16Scorer
{
    private const STRATEGIES = ['CUE', 'PAUSE', 'EMP', 'BND', 'REPAIR', 'INFL'];

    /**
     * @param  array<int|string,mixed>  $answersByItemId
     * @param  array<int|string,array<string,mixed>>  $itemIndex
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function score(array $answersByItemId, array $itemIndex, array $policy = []): array
    {
        $items = $this->normalizeItems($itemIndex);
        $requiredCount = (int) ($policy['item_count'] ?? 16);
        if ($requiredCount <= 0) {
            $requiredCount = 16;
        }
        if (count($items) !== $requiredCount) {
            throw new InvalidArgumentException("EQ_SJT_16 requires exactly {$requiredCount} items.");
        }

        $answers = $this->normalizeAnswers($answersByItemId);
        $missing = array_values(array_diff(array_keys($items), array_keys($answers)));
        if ($missing !== []) {
            throw new InvalidArgumentException('EQ_SJT_16 answers missing required items: '.implode(',', array_slice($missing, 0, 8)));
        }

        $domainScores = [];
        $strategyScores = [];
        foreach (self::STRATEGIES as $strategy) {
            $strategyScores[$strategy] = ['raw_score' => 0.0, 'max_score' => 0.0, 'item_count' => 0];
        }

        $rawTotal = 0.0;
        $maxTotal = 0.0;
        $selectedCredits = [];
        $selectedOptions = [];

        foreach ($items as $itemId => $item) {
            $domain = $this->normalizeCode($item['domain_code'] ?? '');
            if ($domain === '') {
                throw new InvalidArgumentException("EQ_SJT_16 item {$itemId} missing domain_code.");
            }

            $optionCode = strtoupper(trim((string) ($answers[$itemId] ?? '')));
            $option = $this->resolveOption($item, $optionCode, $itemId);
            $credit = $this->boundedCredit($option['partial_credit'] ?? null, "item {$itemId} option {$optionCode}");

            $rawTotal += $credit;
            $maxTotal += 3.0;
            $selectedCredits[] = $credit;
            $selectedOptions[$itemId] = $optionCode;

            $domainScores[$domain] ??= ['raw_score' => 0.0, 'max_score' => 0.0, 'item_count' => 0];
            $domainScores[$domain]['raw_score'] += $credit;
            $domainScores[$domain]['max_score'] += 3.0;
            $domainScores[$domain]['item_count']++;

            $strategyInputs = $this->strategyInputsForItem($item, $option);
            $optionStrategyScores = is_array($option['strategy_scores'] ?? null) ? $option['strategy_scores'] : [];
            foreach ($strategyInputs as $strategy) {
                $strategyScores[$strategy]['max_score'] += 3.0;
                $strategyScores[$strategy]['item_count']++;
                $strategyScores[$strategy]['raw_score'] += $this->boundedCredit(
                    $optionStrategyScores[$strategy] ?? $credit,
                    "item {$itemId} strategy {$strategy}"
                );
            }
        }

        $domainScores = $this->finalizeScoreMap($domainScores);
        $strategyScores = $this->finalizeScoreMap($strategyScores);
        $rankedStrategies = $this->rankScores($strategyScores, self::STRATEGIES);
        $lowestStrategies = $this->rankScores($strategyScores, self::STRATEGIES, ascending: true);
        $scorePct = $this->percent($rawTotal, $maxTotal);

        return [
            'scale_code' => 'EQ_SJT_16',
            'score_method' => 'eq_sjt_16_likely_response_partial_credit_v1',
            'measurement_type' => 'scenario_based_emotional_judgment',
            'answer_mode' => 'likely_response',
            'answer_count' => count($answers),
            'scored_item_count' => count($items),
            'raw_score' => $this->round($rawTotal),
            'max_score' => $this->round($maxTotal),
            'score_pct' => $scorePct,
            'band' => $this->band($scorePct),
            'domain_scores' => $domainScores,
            'strategy_scores' => $strategyScores,
            'top_strategy' => (string) ($rankedStrategies[0]['code'] ?? ''),
            'lowest_strategy' => (string) ($lowestStrategies[0]['code'] ?? ''),
            'ranked_strategies' => $rankedStrategies,
            'quality' => $this->quality($selectedCredits),
            'selected_options' => $selectedOptions,
            'version_snapshot' => [
                'scoring_spec_version' => (string) ($policy['scoring_spec_version'] ?? 'eq_sjt_16_partial_credit_v1'),
                'rubric_version' => (string) ($policy['rubric_version'] ?? 'eq_sjt_16.rubric.v1_draft'),
                'content_version' => (string) ($policy['content_version'] ?? 'EQ_SJT_16/v1'),
            ],
            'claim_boundary' => [
                'not_clinical' => true,
                'not_hiring' => true,
                'not_certified_capability_evaluation' => true,
                'not_msceit_equivalent' => true,
            ],
        ];
    }

    /**
     * @param  array<int|string,array<string,mixed>>  $itemIndex
     * @return array<string,array<string,mixed>>
     */
    private function normalizeItems(array $itemIndex): array
    {
        $items = [];
        foreach ($itemIndex as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemId = trim((string) ($item['item_id'] ?? $key));
            if ($itemId === '') {
                continue;
            }
            $items[$itemId] = $item + ['item_id' => $itemId];
        }
        ksort($items, SORT_NATURAL);

        return $items;
    }

    /**
     * @param  array<int|string,mixed>  $answers
     * @return array<string,string>
     */
    private function normalizeAnswers(array $answers): array
    {
        $out = [];
        foreach ($answers as $key => $value) {
            if (is_array($value)) {
                $itemId = trim((string) ($value['item_id'] ?? $value['question_id'] ?? $key));
                $option = strtoupper(trim((string) ($value['option_id'] ?? $value['option_code'] ?? $value['answer'] ?? '')));
            } else {
                $itemId = trim((string) $key);
                $option = strtoupper(trim((string) $value));
            }
            if ($itemId !== '' && $option !== '') {
                $out[$itemId] = $option;
            }
        }
        ksort($out, SORT_NATURAL);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function resolveOption(array $item, string $optionCode, string $itemId): array
    {
        foreach ((array) ($item['response_options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }
            if (strtoupper(trim((string) ($option['option_id'] ?? ''))) === $optionCode) {
                return $option;
            }
        }

        throw new InvalidArgumentException("EQ_SJT_16 invalid option {$optionCode} for item {$itemId}.");
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $option
     * @return list<string>
     */
    private function strategyInputsForItem(array $item, array $option): array
    {
        $raw = $item['strategy_score_inputs'] ?? array_keys((array) ($option['strategy_scores'] ?? []));
        $strategies = [];
        foreach ((array) $raw as $strategy) {
            $strategy = strtoupper(trim((string) $strategy));
            if (in_array($strategy, self::STRATEGIES, true)) {
                $strategies[] = $strategy;
            }
        }

        return array_values(array_unique($strategies));
    }

    private function normalizeCode(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function boundedCredit(mixed $value, string $label): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("EQ_SJT_16 invalid partial credit for {$label}.");
        }
        $credit = (float) $value;
        if ($credit < 0.0 || $credit > 3.0) {
            throw new InvalidArgumentException("EQ_SJT_16 partial credit out of range for {$label}.");
        }

        return $credit;
    }

    /**
     * @param  array<string,array{raw_score:float,max_score:float,item_count:int}>  $scores
     * @return array<string,array{raw_score:float,max_score:float,score_pct:float,item_count:int,band:string}>
     */
    private function finalizeScoreMap(array $scores): array
    {
        $out = [];
        foreach ($scores as $code => $score) {
            $pct = $this->percent((float) $score['raw_score'], (float) $score['max_score']);
            $out[$code] = [
                'raw_score' => $this->round((float) $score['raw_score']),
                'max_score' => $this->round((float) $score['max_score']),
                'score_pct' => $pct,
                'item_count' => (int) $score['item_count'],
                'band' => $this->band($pct),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,array{score_pct:float}>  $scores
     * @param  list<string>  $order
     * @return list<array{code:string,score_pct:float}>
     */
    private function rankScores(array $scores, array $order, bool $ascending = false): array
    {
        $orderMap = array_flip($order);
        $rows = [];
        foreach ($scores as $code => $score) {
            $rows[] = ['code' => (string) $code, 'score_pct' => (float) $score['score_pct']];
        }
        usort($rows, static function (array $left, array $right) use ($orderMap, $ascending): int {
            $cmp = $ascending
                ? ($left['score_pct'] <=> $right['score_pct'])
                : ($right['score_pct'] <=> $left['score_pct']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($orderMap[$left['code']] ?? 999) <=> ($orderMap[$right['code']] ?? 999);
        });

        return $rows;
    }

    /**
     * @param  list<float>  $credits
     * @return array{level:string,flags:list<string>,idealized_count:int}
     */
    private function quality(array $credits): array
    {
        $idealized = 0;
        foreach ($credits as $credit) {
            if ($credit >= 3.0) {
                $idealized++;
            }
        }

        $flags = [];
        if ($idealized >= 14) {
            $flags[] = 'idealized_answering_review';
        }
        if (array_sum($credits) <= 16.0) {
            $flags[] = 'low_effectiveness_pattern';
        }

        return [
            'level' => $flags === [] ? 'A' : 'B',
            'flags' => $flags,
            'idealized_count' => $idealized,
        ];
    }

    private function band(float $scorePct): string
    {
        if ($scorePct < 40.0) {
            return 'low_effectiveness';
        }
        if ($scorePct < 60.0) {
            return 'mixed_effectiveness';
        }
        if ($scorePct < 80.0) {
            return 'effective';
        }

        return 'strong';
    }

    private function percent(float $raw, float $max): float
    {
        if ($max <= 0.0) {
            return 0.0;
        }

        return $this->round(($raw / $max) * 100.0);
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
