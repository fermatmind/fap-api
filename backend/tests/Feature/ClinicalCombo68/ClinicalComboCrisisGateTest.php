<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Tests\Feature\ClinicalCombo68\Concerns\BuildsClinicalComboScorerInput;
use Tests\TestCase;

final class ClinicalComboCrisisGateTest extends TestCase
{
    use BuildsClinicalComboScorerInput;

    public function test_q9_threshold_triggers_crisis_alert(): void
    {
        $dto = $this->scoreClinical([
            9 => 'C',
            68 => 'A',
        ]);

        $this->assertTrue((bool) data_get($dto, 'quality.crisis_alert'));
        $this->assertContains('SUICIDAL_IDEATION', (array) data_get($dto, 'quality.crisis_reasons', []));
        $this->assertContains(9, (array) data_get($dto, 'quality.crisis_triggered_by', []));
    }

    public function test_q68_threshold_triggers_crisis_alert(): void
    {
        $dto = $this->scoreClinical([
            9 => 'A',
            68 => 'D',
        ]);

        $this->assertTrue((bool) data_get($dto, 'quality.crisis_alert'));
        $this->assertContains('FUNCTION_IMPAIRMENT', (array) data_get($dto, 'quality.crisis_reasons', []));
        $this->assertContains(68, (array) data_get($dto, 'quality.crisis_triggered_by', []));
        $this->assertSame(0, (int) data_get($dto, 'scores.ocd.raw'));
    }
}

