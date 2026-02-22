<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20CrisisGateTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_q19_c_or_higher_triggers_crisis_alert_and_tag(): void
    {
        $dto = $this->scoreSds([19 => 'C']);

        $this->assertTrue((bool) data_get($dto, 'quality.crisis_alert', false));
        $this->assertContains('CRISIS_Q19', (array) data_get($dto, 'quality.flags', []));
        $this->assertContains('crisis:self_harm_ideation', (array) data_get($dto, 'report_tags', []));
    }
}
