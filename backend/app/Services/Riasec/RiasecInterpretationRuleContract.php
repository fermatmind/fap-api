<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecInterpretationRuleContract
{
    public const VERSION = 'riasec_interpretation_rule_spec_v2';

    public const PROFILE_SHAPE_VERSION = 'riasec_profile_shape_v2_0';

    public const MODULE_VISIBILITY_POLICY_ID = 'riasec_module_visibility_policy_v1';

    /** @var list<string> */
    private const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $qualitySummary
     * @return array<string,mixed>
     */
    public function build(array $payload, ?array $qualitySummary = null): array
    {
        $scores = $this->scores($payload);
        $ranked = $this->rankScores($scores);
        $qualityState = $this->qualityState($payload, $qualitySummary);
        $helpers = $this->helpers($ranked, $scores);
        $nearTieState = $this->nearTieState($ranked, $helpers, $qualityState);
        $profileShape = $this->profileShape($helpers, $nearTieState, $qualityState);
        $alternateCode = $this->alternateCode($ranked, $nearTieState, $qualityState);

        return [
            'interpretation_rule_version' => self::VERSION,
            'profile_shape' => $profileShape,
            'profile_shape_version' => self::PROFILE_SHAPE_VERSION,
            'clarity_label' => $this->clarityLabel($helpers, $qualityState),
            'near_tie_state' => $nearTieState,
            'alternate_code' => $alternateCode,
            'alternate_code_reason' => $alternateCode['reason'],
            'top_code_confidence' => $this->topCodeConfidence($profileShape, $helpers, $qualityState),
            'reading_strength' => $this->readingStrength($profileShape, $qualityState),
            'result_page_strategy' => $this->resultPageStrategy($profileShape),
            'module_visibility_policy_id' => self::MODULE_VISIBILITY_POLICY_ID,
            'validation_status' => 'theory_based_under_validation',
            'field_authority' => $this->fieldAuthority(),
            'score_interpretation_helpers' => $helpers,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,float>
     */
    private function scores(array $payload): array
    {
        $source = is_array($payload['scores_0_100'] ?? null) ? $payload['scores_0_100'] : [];
        if ($source === []) {
            foreach (self::DIMENSIONS as $dimension) {
                $scoreKey = 'score_'.$dimension;
                if (array_key_exists($scoreKey, $payload)) {
                    $source[$dimension] = $payload[$scoreKey];
                }
            }
        }

        $scores = [];
        foreach (self::DIMENSIONS as $dimension) {
            $scores[$dimension] = $this->roundScore((float) ($source[$dimension] ?? 0.0));
        }

        return $scores;
    }

    /**
     * @param  array<string,float>  $scores
     * @return list<array{type:string,score:float}>
     */
    private function rankScores(array $scores): array
    {
        $order = array_flip(self::DIMENSIONS);
        uksort($scores, static function (string $leftType, string $rightType) use ($scores, $order): int {
            $leftScore = (float) ($scores[$leftType] ?? 0.0);
            $rightScore = (float) ($scores[$rightType] ?? 0.0);
            if (abs($rightScore - $leftScore) > 0.00001) {
                return $rightScore <=> $leftScore;
            }

            return ($order[$leftType] ?? 0) <=> ($order[$rightType] ?? 0);
        });

        $ranked = [];
        foreach ($scores as $type => $score) {
            $ranked[] = ['type' => $type, 'score' => $this->roundScore($score)];
        }

        return $ranked;
    }

    /**
     * @param  list<array{type:string,score:float}>  $ranked
     * @param  array<string,float>  $scores
     * @return array<string,mixed>
     */
    private function helpers(array $ranked, array $scores): array
    {
        $scoreOrder = array_map(static fn (array $row): string => $row['type'], $ranked);

        return [
            'score_order' => $scoreOrder,
            'measured_holland_code' => implode('', array_slice($scoreOrder, 0, 3)),
            'score_gap_1_2' => $this->scoreGap($ranked, 0, 1),
            'score_gap_2_3' => $this->scoreGap($ranked, 1, 2),
            'score_gap_3_4' => $this->scoreGap($ranked, 2, 3),
            'top3_spread' => $this->roundScore((float) ($ranked[0]['score'] ?? 0.0) - (float) ($ranked[2]['score'] ?? 0.0)),
            'high_interest_count' => count(array_filter($scores, static fn (float $score): bool => $score >= 65.0)),
            'mid_or_high_count' => count(array_filter($scores, static fn (float $score): bool => $score >= 45.0)),
            'low_interest_count' => count(array_filter($scores, static fn (float $score): bool => $score < 45.0)),
            'profile_stddev' => $this->roundScore($this->standardDeviation(array_values($scores))),
        ];
    }

    /**
     * @param  list<array{type:string,score:float}>  $ranked
     */
    private function scoreGap(array $ranked, int $left, int $right): float
    {
        return $this->roundScore((float) ($ranked[$left]['score'] ?? 0.0) - (float) ($ranked[$right]['score'] ?? 0.0));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $qualitySummary
     */
    private function qualityState(array $payload, ?array $qualitySummary): string
    {
        $candidate = strtolower(trim((string) (
            $qualitySummary['quality_state']
            ?? $payload['quality_state']
            ?? $payload['response_quality']
            ?? ''
        )));

        return in_array($candidate, ['normal', 'caution', 'low_quality'], true) ? $candidate : 'normal';
    }

    /**
     * @param  list<array{type:string,score:float}>  $ranked
     * @param  array<string,mixed>  $helpers
     * @return array{state:string,dimensions:list<string>,threshold_rule:string}
     */
    private function nearTieState(array $ranked, array $helpers, string $qualityState): array
    {
        $fallback = [
            'state' => 'none',
            'dimensions' => [],
            'threshold_rule' => 'adjacent_gap_lt_5_points_provisional',
        ];
        if ($qualityState === 'low_quality') {
            return $fallback;
        }

        $gap12 = (float) ($helpers['score_gap_1_2'] ?? 0.0);
        $gap23 = (float) ($helpers['score_gap_2_3'] ?? 0.0);
        if ($gap12 < 5.0 && $gap23 < 5.0) {
            return ['state' => 'multi_near_tie', 'dimensions' => $this->rankedDimensions($ranked, 0, 3), 'threshold_rule' => $fallback['threshold_rule']];
        }
        if ($gap12 < 5.0) {
            return ['state' => 'top1_top2_near_tie', 'dimensions' => $this->rankedDimensions($ranked, 0, 2), 'threshold_rule' => $fallback['threshold_rule']];
        }
        if ($gap23 < 5.0) {
            return ['state' => 'top2_top3_near_tie', 'dimensions' => $this->rankedDimensions($ranked, 1, 2), 'threshold_rule' => $fallback['threshold_rule']];
        }

        return $fallback;
    }

    /**
     * @param  list<array{type:string,score:float}>  $ranked
     * @return list<string>
     */
    private function rankedDimensions(array $ranked, int $offset, int $length): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['type'],
            array_slice($ranked, $offset, $length)
        ));
    }

    /**
     * @param  array<string,mixed>  $helpers
     */
    private function clarityLabel(array $helpers, string $qualityState): string
    {
        if ($qualityState === 'low_quality') {
            return 'not_readable';
        }

        $gap12 = (float) ($helpers['score_gap_1_2'] ?? 0.0);
        $stddev = (float) ($helpers['profile_stddev'] ?? 0.0);
        if ($gap12 >= 15.0 && $stddev >= 15.0) {
            return 'high';
        }
        if ($gap12 >= 10.0 && $stddev >= 10.0) {
            return 'medium_high';
        }
        if ($gap12 >= 5.0) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string,mixed>  $helpers
     * @param  array{state:string,dimensions:list<string>,threshold_rule:string}  $nearTieState
     */
    private function profileShape(array $helpers, array $nearTieState, string $qualityState): string
    {
        if ($qualityState === 'low_quality') {
            return 'low_quality';
        }
        if (
            (float) ($helpers['profile_stddev'] ?? 0.0) < 10.0
            || (int) ($helpers['high_interest_count'] ?? 0) >= 4
            || (float) ($helpers['top3_spread'] ?? 0.0) < 8.0
        ) {
            return 'broad_profile';
        }
        if ($nearTieState['state'] !== 'none') {
            return 'near_tie';
        }
        if ((float) ($helpers['score_gap_1_2'] ?? 0.0) >= 10.0 && (float) ($helpers['profile_stddev'] ?? 0.0) >= 10.0) {
            return 'clear_code';
        }
        if ((float) ($helpers['score_gap_1_2'] ?? 0.0) < 10.0 || (float) ($helpers['score_gap_2_3'] ?? 0.0) < 8.0) {
            return 'blended_code';
        }

        return 'low_clarity';
    }

    /**
     * @param  list<array{type:string,score:float}>  $ranked
     * @param  array{state:string,dimensions:list<string>,threshold_rule:string}  $nearTieState
     * @return array{show:bool,codes:list<string>,reason:?string,display_boundary:string}
     */
    private function alternateCode(array $ranked, array $nearTieState, string $qualityState): array
    {
        $boundary = 'Alternate code is a reading aid, not a second measured result.';
        if ($qualityState === 'low_quality' || $nearTieState['state'] === 'none') {
            return ['show' => false, 'codes' => [], 'reason' => null, 'display_boundary' => $boundary];
        }

        $top = array_values(array_map(static fn (array $row): string => $row['type'], array_slice($ranked, 0, 3)));
        $codes = match ($nearTieState['state']) {
            'top1_top2_near_tie' => [$this->code([$top[1] ?? '', $top[0] ?? '', $top[2] ?? ''])],
            'top2_top3_near_tie' => [$this->code([$top[0] ?? '', $top[2] ?? '', $top[1] ?? ''])],
            'multi_near_tie' => [
                $this->code([$top[1] ?? '', $top[0] ?? '', $top[2] ?? '']),
                $this->code([$top[0] ?? '', $top[2] ?? '', $top[1] ?? '']),
            ],
            default => [],
        };
        $codes = array_values(array_slice(array_unique(array_filter($codes)), 0, 2));

        return [
            'show' => $codes !== [],
            'codes' => $codes,
            'reason' => 'Adjacent RIASEC dimensions are close in this form. Read the result as a bounded combination, not a fixed identity.',
            'display_boundary' => $boundary,
        ];
    }

    /**
     * @param  list<string>  $letters
     */
    private function code(array $letters): string
    {
        $code = implode('', array_slice($letters, 0, 3));

        return strlen($code) === 3 ? $code : '';
    }

    /**
     * @param  array<string,mixed>  $helpers
     * @return array{level:string,user_label:string,meaning:string}
     */
    private function topCodeConfidence(string $profileShape, array $helpers, string $qualityState): array
    {
        $level = 'low';
        if ($qualityState === 'low_quality') {
            $level = 'not_available';
        } elseif ($qualityState === 'caution' || $profileShape === 'low_clarity') {
            $level = 'low';
        } elseif ($profileShape === 'clear_code' && (float) ($helpers['score_gap_1_2'] ?? 0.0) >= 15.0) {
            $level = 'high';
        } elseif (in_array($profileShape, ['clear_code', 'blended_code'], true) && (float) ($helpers['score_gap_1_2'] ?? 0.0) >= 10.0) {
            $level = 'medium_high';
        } elseif (in_array($profileShape, ['blended_code', 'near_tie'], true)) {
            $level = 'medium';
        }

        return [
            'level' => $level,
            'user_label' => match ($level) {
                'high' => '高',
                'medium_high' => '中高',
                'medium' => '中等',
                'low' => '谨慎阅读',
                default => '暂不显示',
            },
            'meaning' => 'readability strength, not probability',
        ];
    }

    private function readingStrength(string $profileShape, string $qualityState): string
    {
        if ($qualityState === 'low_quality') {
            return 'retake_recommended';
        }
        if ($qualityState === 'caution' || in_array($profileShape, ['low_clarity', 'broad_profile'], true)) {
            return 'cautious_reading';
        }

        return 'normal_reading';
    }

    /**
     * @return array{primary_reading_mode:string,tone:string}
     */
    private function resultPageStrategy(string $profileShape): array
    {
        return match ($profileShape) {
            'clear_code' => ['primary_reading_mode' => 'single_chain', 'tone' => 'stable_not_absolute'],
            'blended_code' => ['primary_reading_mode' => 'combination_chain', 'tone' => 'blended'],
            'broad_profile' => ['primary_reading_mode' => 'activity_filter_first', 'tone' => 'exploratory'],
            'near_tie' => ['primary_reading_mode' => 'candidate_chains', 'tone' => 'blended'],
            'low_quality' => ['primary_reading_mode' => 'retake_first', 'tone' => 'retake_first'],
            default => ['primary_reading_mode' => 'cautious_overview', 'tone' => 'cautious'],
        };
    }

    /**
     * @return array<string,string>
     */
    private function fieldAuthority(): array
    {
        return [
            'profile_shape' => 'backend_owned',
            'profile_shape_version' => 'backend_owned',
            'clarity_label' => 'backend_owned',
            'near_tie_state' => 'backend_owned',
            'alternate_code' => 'backend_owned',
            'alternate_code_reason' => 'backend_owned',
            'top_code_confidence' => 'backend_owned',
            'reading_strength' => 'backend_owned',
            'interpretation_rule_version' => 'backend_owned',
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function standardDeviation(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += (($value - $mean) ** 2);
        }

        return sqrt($variance / count($values));
    }

    private function roundScore(float $score): float
    {
        return round(max(0.0, min(100.0, $score)), 2);
    }
}
