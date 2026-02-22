<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20SomaticMaskGateTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_somatic_exhaustion_mask_tag_is_added_when_gate_matches(): void
    {
        $answers = $this->buildAnswers('C');
        $answers[1] = 'B';
        $answers[20] = 'C';

        $dto = $this->scoreSdsFromAnswers($answers, ['duration_ms' => 98000]);

        $this->assertGreaterThanOrEqual(53, (int) data_get($dto, 'scores.global.index_score', 0));
        $this->assertContains('profile:somatic_exhaustion_mask', (array) data_get($dto, 'report_tags', []));
        $this->assertContains('symptom:physical_drain', (array) data_get($dto, 'report_tags', []));
        $this->assertContains('recommendation:rest_and_recovery', (array) data_get($dto, 'report_tags', []));
    }
}
