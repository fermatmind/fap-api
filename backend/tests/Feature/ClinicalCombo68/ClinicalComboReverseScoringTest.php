<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Tests\Feature\ClinicalCombo68\Concerns\BuildsClinicalComboScorerInput;
use Tests\TestCase;

final class ClinicalComboReverseScoringTest extends TestCase
{
    use BuildsClinicalComboScorerInput;

    public function test_q18_q19_are_reverse_scored_on_0_to_4_track(): void
    {
        $allA = $this->scoreClinical([
            17 => 'A',
            18 => 'A',
            19 => 'A',
            20 => 'A',
        ]);
        $this->assertSame(8, (int) data_get($allA, 'scores.stress.raw'));

        $allE = $this->scoreClinical([
            17 => 'A',
            18 => 'E',
            19 => 'E',
            20 => 'A',
        ]);
        $this->assertSame(0, (int) data_get($allE, 'scores.stress.raw'));
    }
}

