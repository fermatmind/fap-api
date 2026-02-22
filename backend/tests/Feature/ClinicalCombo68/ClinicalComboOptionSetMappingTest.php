<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Tests\Feature\ClinicalCombo68\Concerns\BuildsClinicalComboScorerInput;
use Tests\TestCase;

final class ClinicalComboOptionSetMappingTest extends TestCase
{
    use BuildsClinicalComboScorerInput;

    public function test_module3_uses_1_to_5_mapping(): void
    {
        $allA = $this->scoreClinical();
        $this->assertSame(27, (int) data_get($allA, 'scores.perfectionism.raw'));

        $overrides = [];
        for ($qid = 31; $qid <= 57; $qid++) {
            $overrides[$qid] = 'E';
        }

        $allE = $this->scoreClinical($overrides);
        $this->assertSame(135, (int) data_get($allE, 'scores.perfectionism.raw'));
    }
}

