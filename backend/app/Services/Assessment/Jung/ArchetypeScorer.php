<?php

declare(strict_types=1);

namespace App\Services\Assessment\Jung;

final class ArchetypeScorer
{
    public function score(array $answers): array
    {
        $bank = new JungBank(JungBank::ARCHETYPES);
        $scores = $bank->rangeScores($bank->totals($answers));
        arsort($scores);
        $tiers = array_values(array_unique($scores, SORT_NUMERIC));
        $flat = $tiers[0] - $tiers[count($tiers) - 1] <= 6.25;
        $leaders = array_keys($scores, $tiers[0], true);
        $secondary = ! $flat && count($leaders) === 1
            ? array_keys($scores, $tiers[1], true)
            : [];

        return [
            'scores' => $scores,
            'primary' => $flat ? [] : $leaders,
            'secondary' => $secondary,
            'near_leaders' => array_keys(array_filter($scores, fn (float $score): bool => $tiers[0] - $score <= 6.25)),
            'pattern' => $flat ? 'flat' : 'differentiated',
        ];
    }
}
