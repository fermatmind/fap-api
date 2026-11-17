<?php

declare(strict_types=1);

namespace App\Services\Assessment\Jung;

final class CognitiveFunctionScorer
{
    private const STACKS = [
        'ISTJ' => ['Si', 'Te', 'Fi', 'Ne'],
        'ISFJ' => ['Si', 'Fe', 'Ti', 'Ne'],
        'INFJ' => ['Ni', 'Fe', 'Ti', 'Se'],
        'INTJ' => ['Ni', 'Te', 'Fi', 'Se'],
        'ISTP' => ['Ti', 'Se', 'Ni', 'Fe'],
        'ISFP' => ['Fi', 'Se', 'Ni', 'Te'],
        'INFP' => ['Fi', 'Ne', 'Si', 'Te'],
        'INTP' => ['Ti', 'Ne', 'Si', 'Fe'],
        'ESTP' => ['Se', 'Ti', 'Fe', 'Ni'],
        'ESFP' => ['Se', 'Fi', 'Te', 'Ni'],
        'ENFP' => ['Ne', 'Fi', 'Te', 'Si'],
        'ENTP' => ['Ne', 'Ti', 'Fe', 'Si'],
        'ESTJ' => ['Te', 'Si', 'Ne', 'Fi'],
        'ESFJ' => ['Fe', 'Si', 'Ne', 'Ti'],
        'ENFJ' => ['Fe', 'Ni', 'Se', 'Ti'],
        'ENTJ' => ['Te', 'Ni', 'Se', 'Fi'],
    ];

    private const AXES = [['Ni', 'Se'], ['Ne', 'Si'], ['Ti', 'Fe'], ['Te', 'Fi']];

    public function score(array $answers): array
    {
        $bank = new JungBank(JungBank::FUNCTIONS);
        $totals = $bank->totals($answers);
        $scores = $bank->rangeScores($totals);
        $axes = [];
        foreach (self::AXES as [$left, $right]) {
            $difference = $scores[$left] - $scores[$right];
            $axes[] = [
                'left' => $left,
                'right' => $right,
                'difference' => $difference,
                'lean' => abs($difference) <= 3.125 ? null : ($difference > 0 ? $left : $right),
            ];
        }

        $fits = $this->fits($totals);
        $first = array_key_first($fits);
        $values = array_values($fits);
        [$dominant, $auxiliary, $tertiary, $inferior] = self::STACKS[$first];
        $differentiated = max($totals) - min($totals) > 1;
        $hasFit = $differentiated && ($totals[$dominant] > $totals[$inferior] || $totals[$auxiliary] > $totals[$tertiary]);
        $stability = 'insufficient';
        $candidates = [];
        if ($hasFit) {
            $sensitive = $values[0] - $values[1] <= 100
                || $totals[$dominant] - $totals[$inferior] <= 1
                || $totals[$auxiliary] - $totals[$tertiary] <= 1
                || $this->leaderChanges($totals, $first);
            $stability = $sensitive ? 'unstable' : 'concentrated';
            foreach ($fits as $type => $fit) {
                if ($fit < $values[2]) {
                    break;
                }
                $candidates[] = ['type' => $type, 'stack' => self::STACKS[$type], 'fit' => $fit / 32];
            }
        }

        return [
            'scores' => $scores,
            'axes' => $axes,
            'candidates' => $candidates,
            'stability' => $stability,
        ];
    }

    private function fits(array $totals): array
    {
        $fits = [];
        foreach (self::STACKS as $type => [$dominant, $auxiliary, $tertiary, $inferior]) {
            // Keep ties exact. One raw total point represents 100/32 range points.
            $fits[$type] = 55 * ($totals[$dominant] - $totals[$inferior])
                + 30 * ($totals[$auxiliary] - $totals[$tertiary])
                + 15 * ($totals[$dominant] - $totals[$auxiliary]);
        }
        arsort($fits);

        return $fits;
    }

    private function leaderChanges(array $totals, string $first): bool
    {
        foreach ($totals as $dimension => $total) {
            foreach ([-1, 1] as $delta) {
                if ($total + $delta < 8 || $total + $delta > 40) {
                    continue;
                }
                $changed = $totals;
                $changed[$dimension] += $delta;
                $fits = $this->fits($changed);
                $top = array_values($fits);
                if (array_key_first($fits) !== $first || $top[0] === $top[1]) {
                    return true;
                }
            }
        }

        return false;
    }
}
