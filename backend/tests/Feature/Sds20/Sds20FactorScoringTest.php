<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20FactorScoringTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_factor_scores_and_severity_are_stable_for_all_a(): void
    {
        $dto = $this->scoreSds();

        $this->assertSame(2, (int) data_get($dto, 'scores.factors.psycho_affective.score', -1));
        $this->assertSame('low', (string) data_get($dto, 'scores.factors.psycho_affective.severity', ''));

        $this->assertSame(17, (int) data_get($dto, 'scores.factors.somatic.score', -1));
        $this->assertSame('medium', (string) data_get($dto, 'scores.factors.somatic.severity', ''));

        $this->assertSame(12, (int) data_get($dto, 'scores.factors.psychomotor.score', -1));
        $this->assertSame('high', (string) data_get($dto, 'scores.factors.psychomotor.severity', ''));

        $this->assertSame(19, (int) data_get($dto, 'scores.factors.cognitive.score', -1));
        $this->assertSame('high', (string) data_get($dto, 'scores.factors.cognitive.severity', ''));
    }
}
