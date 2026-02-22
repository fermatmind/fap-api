<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Tests\Feature\ClinicalCombo68\Concerns\BuildsClinicalComboScorerInput;
use Tests\TestCase;

final class ClinicalComboMaskedDepressionTest extends TestCase
{
    use BuildsClinicalComboScorerInput;

    public function test_masked_depression_flag_hits_when_core_dep_is_low_but_raw_is_high(): void
    {
        $overrides = [
            1 => 'A',
            2 => 'A',
            3 => 'D',
            4 => 'D',
            5 => 'D',
            6 => 'D',
            7 => 'D',
            8 => 'D',
            9 => 'D',
        ];

        $dto = $this->scoreClinical($overrides);

        $this->assertGreaterThanOrEqual(14, (int) data_get($dto, 'scores.depression.raw'));
        $this->assertContains('masked_depression', (array) data_get($dto, 'scores.depression.flags', []));
    }
}

